<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Calculation\Decimal;
use App\Domain\DailyReportValidationException;
use App\Infrastructure\Persistence\DailyReportRepository;
use App\Infrastructure\Persistence\EmployeeRepository;
use App\Infrastructure\Persistence\OrderRepository;
use App\Http\AccessDeniedException;
use App\Support\Clock;
use App\Support\Uuid;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use PDO;
use Throwable;

final readonly class DailyReportService
{
    public function __construct(private PDO $pdo,private DailyReportRepository $reports,private EmployeeRepository $employees,private OrderRepository $orders) {}

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function referenceData(array $actor):array
    {
        $technician=($actor['role']??'')==='technician';$orders=array_values(array_filter($this->orders->all(),static fn(array $o):bool=>!$technician||$o['status']!=='open'));$allEmployees=$this->employees->all();
        $employees=array_values(array_filter($allEmployees,static fn(array $e):bool=>(int)$e['active']===1&&(!$technician||(string)$e['user_id']===(string)$actor['id'])));$filterEmployees=$technician?array_values(array_filter($allEmployees,static fn(array $e):bool=>(string)$e['user_id']===(string)$actor['id'])):$allEmployees;
        return['orders'=>$orders,'employees'=>$this->visibleEmployees($employees,$actor),'filterEmployees'=>$this->visibleEmployees($filterEmployees,$actor)];
    }

    /** @param array<string,mixed> $actor @return list<array<string,mixed>> */
    public function overview(array $actor):array
    {
        $rows=$this->reports->all();if(($actor['role']??'')==='technician')$rows=array_values(array_filter($rows,static fn(array $r):bool=>$r['order_status']!=='open'));
        foreach($rows as &$row){$hours=BigDecimal::zero();foreach(array_filter(explode('|',(string)($row['duration_values']??''))) as $duration)$hours=$hours->plus($duration);$row['total_hours']=(string)$hours->toScale(Decimal::TIME_SCALE,RoundingMode::HalfUp);unset($row['basis_json'],$row['duration_values']);$row['canEdit']=$this->canEdit($row,$actor);}unset($row);return$rows;
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function detail(string $id,array $actor):array
    {
        $report=$this->reports->find($id);if($report===null)throw new DailyReportValidationException(['Bautagesbericht wurde nicht gefunden.']);$this->assertOrderAccess($report,$actor);
        $entries=$this->reports->entries($id);foreach($entries as &$entry)$entry['targetKey']=$entry['target_kind']==='order'?'order':$entry['target_basis_type'].':'.$entry['target_basis_id'].':'.$entry['target_node_id'];unset($entry);if(!$this->canReadCosts($actor))foreach($entries as &$entry)unset($entry['hourly_cost_rate_snapshot'],$entry['labor_cost']);unset($entry);
        unset($report['basis_json']);$report['entries']=$entries;$report['targets']=$this->targets((string)$report['order_id']);$report['canEdit']=$this->canEdit($report,$actor);return$report;
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function create(array $input,array $actor):array
    {
        $orderId=trim((string)($input['orderId']??''));$order=$this->orders->find($orderId);if($order===null)throw new DailyReportValidationException(['Auftrag wurde nicht gefunden.']);$this->assertOrderAccess($order,$actor);
        $values=$this->validateReport($input);$id=Uuid::v4();$now=Clock::now();$entries=$this->validateEntries($input['entries']??[],$id,$orderId,$values['report_date'],$actor,$now,[]);
        $this->pdo->exec('BEGIN IMMEDIATE');try{$this->reports->insertReport(['id'=>$id,'order_id'=>$orderId,'report_date'=>$values['report_date'],'title'=>$values['title'],'activities'=>$values['activities'],'notes'=>$values['notes'],'created_by_user_id'=>$actor['id'],'created_at'=>$now,'updated_at'=>$now]);$this->reports->replaceEntries($id,$entries);$this->pdo->commit();}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
        return$this->detail($id,$actor);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function update(string $id,array $input,array $actor):array
    {
        $current=$this->reports->find($id);if($current===null)throw new DailyReportValidationException(['Bautagesbericht wurde nicht gefunden.']);$this->assertOrderAccess($current,$actor);if(!$this->canEdit($current,$actor))throw new AccessDeniedException('reports.write.own');
        $version=(int)($input['version']??0);if($version<1)throw new DailyReportValidationException(['Versionsangabe fehlt.']);$values=$this->validateReport($input);$now=Clock::now();$existing=[];foreach($this->reports->entries($id) as $entry)$existing[(string)$entry['id']]=$entry;$entries=$this->validateEntries($input['entries']??[],$id,(string)$current['order_id'],$values['report_date'],$actor,$now,$existing);
        $this->pdo->exec('BEGIN IMMEDIATE');try{if(!$this->reports->updateReport(['id'=>$id,'expected_version'=>$version,'report_date'=>$values['report_date'],'title'=>$values['title'],'activities'=>$values['activities'],'notes'=>$values['notes'],'updated_at'=>$now]))throw new DailyReportValidationException(['Der Bautagesbericht wurde zwischenzeitlich geändert. Bitte neu laden.']);$this->reports->replaceEntries($id,$entries);$this->pdo->commit();}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
        return$this->detail($id,$actor);
    }

    /** @param array<string,mixed> $actor */
    public function delete(string $id,int $version,array $actor):void
    {
        $current=$this->reports->find($id);if($current===null)throw new DailyReportValidationException(['Bautagesbericht wurde nicht gefunden.']);$this->assertOrderAccess($current,$actor);if(!$this->canEdit($current,$actor))throw new AccessDeniedException('reports.write.own');if(!$this->reports->delete($id,$version))throw new DailyReportValidationException(['Der Bautagesbericht wurde zwischenzeitlich geändert. Bitte neu laden.']);
    }

    /** @return array{hours:string,costs:string} */
    public function orderTotals(string $orderId):array
    {
        $hours=BigDecimal::zero();$costs=BigDecimal::zero();foreach($this->reports->costRowsForOrder($orderId) as $row){$hours=$hours->plus($row['duration_hours']);$costs=$costs->plus($row['labor_cost']);}return['hours'=>(string)$hours->toScale(Decimal::TIME_SCALE,RoundingMode::HalfUp),'costs'=>Decimal::money($costs)];
    }

    /** @param array<string,mixed> $actor @return list<array<string,mixed>> */
    public function orderTargets(string $orderId,array $actor):array
    {
        $order=$this->orders->find($orderId);if($order===null)throw new DailyReportValidationException(['Auftrag wurde nicht gefunden.']);$this->assertOrderAccess($order,$actor);return$this->targets($orderId);
    }

    /** @param array<string,mixed> $input @return array{report_date:string,title:string,activities:string,notes:string} */
    private function validateReport(array $input):array
    {
        $date=(string)($input['reportDate']??'');$title=trim((string)($input['title']??''));$activities=trim((string)($input['activities']??''));$notes=trim((string)($input['notes']??''));$errors=[];
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)||!checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4)))$errors[]='Das Berichtsdatum ist ungültig.';if(mb_strlen($title)>200)$errors[]='Die Bezeichnung darf höchstens 200 Zeichen lang sein.';if($activities===''||mb_strlen($activities)>10000)$errors[]='Die Tätigkeitsbeschreibung ist erforderlich und darf höchstens 10.000 Zeichen lang sein.';if(mb_strlen($notes)>10000)$errors[]='Bemerkungen dürfen höchstens 10.000 Zeichen lang sein.';if($errors!==[])throw new DailyReportValidationException($errors);return['report_date'=>$date,'title'=>$title,'activities'=>$activities,'notes'=>$notes];
    }

    /** @param mixed $raw @param array<string,mixed> $actor @return list<array<string,mixed>> */
    private function validateEntries(mixed $raw,string $reportId,string $orderId,string $defaultDate,array $actor,string $now,array $existing):array
    {
        if(!is_array($raw))throw new DailyReportValidationException(['Zeiteinträge müssen als Liste übergeben werden.']);$targets=[];foreach($this->targets($orderId) as $target)$targets[$target['key']]=$target;$result=[];
        foreach(array_values($raw) as $index=>$entry){if(!is_array($entry))throw new DailyReportValidationException(['Zeiteintrag '.($index+1).' ist ungültig.']);$entryId=(string)($entry['id']??'');$old=$existing[$entryId]??null;$employee=$this->employees->find((string)($entry['employeeId']??''));$sameEmployee=$employee!==null&&$old!==null&&(string)$old['employee_id']===(string)$employee['id'];if($employee===null||((int)$employee['active']!==1&&!$sameEmployee))throw new DailyReportValidationException(['Zeiteintrag '.($index+1).': Mitarbeiter ist nicht aktiv oder unbekannt.']);if(($actor['role']??'')==='technician'&&(string)$employee['user_id']!==(string)$actor['id'])throw new AccessDeniedException('reports.write.own');
            $date=(string)($entry['workDate']??$defaultDate);if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)||!checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4)))throw new DailyReportValidationException(['Zeiteintrag '.($index+1).': Arbeitsdatum ist ungültig.']);try{$duration=Decimal::parse((string)($entry['durationHours']??''),Decimal::TIME_SCALE,'Dauer');}catch(\InvalidArgumentException $e){throw new DailyReportValidationException(['Zeiteintrag '.($index+1).': '.$e->getMessage()]);}if($duration->isLessThanOrEqualTo(0))throw new DailyReportValidationException(['Zeiteintrag '.($index+1).': Dauer muss größer als null sein.']);$description=trim((string)($entry['description']??''));if(mb_strlen($description)>1000)throw new DailyReportValidationException(['Zeiteintrag '.($index+1).': Tätigkeit darf höchstens 1.000 Zeichen lang sein.']);$rate=BigDecimal::of((string)($sameEmployee?$old['hourly_cost_rate_snapshot']:$employee['hourly_cost_rate']));$cost=Decimal::money($duration->multipliedBy($rate));$targetKey=(string)($entry['targetKey']??'order');$target=$targets[$targetKey]??null;if($target===null)throw new DailyReportValidationException(['Zeiteintrag '.($index+1).': Zuordnungsziel gehört nicht zur unveränderlichen Auftragsgrundlage.']);
            $result[]=['id'=>$old!==null?$entryId:Uuid::v4(),'report_id'=>$reportId,'employee_id'=>$employee['id'],'work_date'=>$date,'duration_hours'=>(string)$duration->toScale(Decimal::TIME_SCALE,RoundingMode::HalfUp),'description'=>$description,'target_kind'=>$target['kind'],'target_basis_type'=>$target['basisType'],'target_basis_id'=>$target['basisId'],'target_node_id'=>$target['nodeId'],'hourly_cost_rate_snapshot'=>(string)$rate->toScale(Decimal::COST_SCALE,RoundingMode::HalfUp),'labor_cost'=>$cost,'created_at'=>(string)($old['created_at']??$now),'updated_at'=>$now];
        }return$result;
    }

    /** @return list<array<string,mixed>> */
    private function targets(string $orderId):array
    {
        $order=$this->orders->find($orderId);if($order===null)return[];$targets=[['key'=>'order','kind'=>'order','basisType'=>null,'basisId'=>null,'nodeId'=>null,'label'=>'Gesamter Auftrag','depth'=>0]];$sources=[];if($order['basis_json']!==null)$sources[]=['type'=>'order','id'=>$orderId,'label'=>'Auftragsgrundlage','json'=>$order['basis_json']];foreach($this->reports->acceptedAddenda($orderId) as $addendum)$sources[]=['type'=>'addendum','id'=>$addendum['id'],'label'=>'Angenommener Nachtrag','json'=>$addendum['basis_json']];
        foreach($sources as $source){$document=json_decode((string)$source['json'],true);if(!is_array($document))continue;$walk=function(array $nodes,array $path,int $depth)use(&$walk,&$targets,$source):void{$titleIndex=0;$positionIndex=0;foreach($nodes as $node){if(!is_array($node)||!isset($node['id'],$node['type']))continue;if($node['type']==='title'){$titleIndex++;$number=implode('.',array_merge($path,[$titleIndex]));$key=$source['type'].':'.$source['id'].':'.$node['id'];$targets[]=['key'=>$key,'kind'=>'title','basisType'=>$source['type'],'basisId'=>$source['id'],'nodeId'=>$node['id'],'label'=>$source['label'].' · '.$number.' '.($node['name']??'Titel'),'depth'=>$depth];$walk(is_array($node['children']??null)?$node['children']:[],array_merge($path,[$titleIndex]),$depth+1);}elseif($node['type']==='position'){$positionIndex++;$number=implode('.',$path).($path!==[]?'.':'').str_pad((string)$positionIndex,2,'0',STR_PAD_LEFT);$key=$source['type'].':'.$source['id'].':'.$node['id'];$targets[]=['key'=>$key,'kind'=>'position','basisType'=>$source['type'],'basisId'=>$source['id'],'nodeId'=>$node['id'],'label'=>$source['label'].' · '.$number.' '.($node['shortText']??'Position'),'depth'=>$depth];}}};$walk(is_array($document['nodes']??null)?$document['nodes']:[],[],1);}
        return$targets;
    }

    /** @param array<string,mixed> $resource @param array<string,mixed> $actor */
    private function assertOrderAccess(array $resource,array $actor):void{if(($actor['role']??'')==='technician'&&($resource['order_status']??$resource['status']??'open')==='open')throw new AccessDeniedException('reports.released.read');}
    /** @param array<string,mixed> $report @param array<string,mixed> $actor */
    private function canEdit(array $report,array $actor):bool{return($actor['role']??'')!=='technician'||(string)$report['created_by_user_id']===(string)$actor['id'];}
    /** @param list<array<string,mixed>> $employees @param array<string,mixed> $actor @return list<array<string,mixed>> */
    private function visibleEmployees(array $employees,array $actor):array{if($this->canReadCosts($actor))return$employees;foreach($employees as &$employee)unset($employee['hourly_cost_rate']);unset($employee);return$employees;}
    /** @param array<string,mixed> $actor */ private function canReadCosts(array $actor):bool{return\App\Security\RolePermissions::allows((string)($actor['role']??''),'costs.read');}
}
