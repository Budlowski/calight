<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\DailyReportPdfService;
use App\Application\DailyReportService;
use App\Application\MaterialRequestService;
use App\Application\EmployeeService;
use App\Domain\DailyReportValidationException;
use App\Http\AccessDeniedException;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Persistence\AttachmentStore;
use App\Infrastructure\Persistence\DailyReportRepository;
use App\Infrastructure\Persistence\DocumentRepository;
use App\Infrastructure\Persistence\DocumentSettingsRepository;
use App\Infrastructure\Persistence\MaterialRequestRepository;
use App\Infrastructure\Persistence\EmployeeRepository;
use App\Infrastructure\Persistence\OrderRepository;
use App\Infrastructure\Persistence\UserRepository;
use App\Support\Clock;
use App\Support\Uuid;
use App\Tests\TestCase;

final class DailyReportTest extends TestCase
{
    public function testEmployeeLinkTimeSnapshotStableTargetsAndExactTotals():void
    {
        [$pdo,$tmp,$ids]=$this->fixture();
        try{
            $employees=new EmployeeRepository($pdo);$employeeService=new EmployeeService($employees,new UserRepository($pdo));
            $employee=$employeeService->save(null,['displayName'=>'Monteur Max','personnelNumber'=>'M-7','userId'=>$ids['technician'],'hourlyCostRate'=>'42.3333','active'=>true]);
            $service=new DailyReportService($pdo,new DailyReportRepository($pdo),$employees,new OrderRepository($pdo));$office=['id'=>$ids['office'],'role'=>'office'];
            $targets=$service->orderTargets($ids['order'],$office);$keys=array_column($targets,'key');
            $this->assertTrue(in_array('order:'.$ids['order'].':title-1',$keys,true));
            $this->assertTrue(in_array('order:'.$ids['order'].':position-1',$keys,true));
            $this->assertTrue(in_array('addendum:'.$ids['addendum'].':add-position',$keys,true));
            $report=$service->create(['orderId'=>$ids['order'],'reportDate'=>'2026-11-10','title'=>'Montage','activities'=>'Verteiler montiert','entries'=>[['employeeId'=>$employee['id'],'durationHours'=>'1.500','targetKey'=>'order:'.$ids['order'].':position-1']]],$office);
            $this->assertSame('42.3333',$report['entries'][0]['hourly_cost_rate_snapshot']);$this->assertSame('63.50',$report['entries'][0]['labor_cost']);
            $employeeService->save($employee['id'],['displayName'=>'Monteur Max','personnelNumber'=>'M-7','userId'=>$ids['technician'],'hourlyCostRate'=>'50.0000','active'=>true]);
            $entry=$report['entries'][0];$updated=$service->update($report['id'],['version'=>$report['version'],'reportDate'=>'2026-11-10','title'=>'Montage','activities'=>'Verteiler fertig','entries'=>[['id'=>$entry['id'],'employeeId'=>$employee['id'],'workDate'=>'2026-11-10','durationHours'=>'2.000','targetKey'=>$entry['targetKey']]]],$office);
            $this->assertSame('42.3333',$updated['entries'][0]['hourly_cost_rate_snapshot']);$this->assertSame('84.67',$updated['entries'][0]['labor_cost']);$this->assertSame(['hours'=>'2.000','costs'=>'84.67'],$service->orderTotals($ids['order']));
            $officeOverview=$service->overview($office);$this->assertSame('84.67',$officeOverview[0]['total_labor_cost']);
            $technicianOverview=$service->overview(['id'=>$ids['technician'],'role'=>'technician']);$this->assertTrue(!isset($technicianOverview[0]['total_labor_cost']));
            $duplicateEntry=$updated['entries'][0];$this->assertThrows(DailyReportValidationException::class,fn()=>$service->update($report['id'],['version'=>$updated['version'],'reportDate'=>'2026-11-10','title'=>'Montage','activities'=>'Doppelte Zeile','entries'=>[['id'=>$duplicateEntry['id'],'employeeId'=>$employee['id'],'durationHours'=>'1.000','targetKey'=>$duplicateEntry['targetKey']],['id'=>$duplicateEntry['id'],'employeeId'=>$employee['id'],'durationHours'=>'1.000','targetKey'=>$duplicateEntry['targetKey']]]],$office));
            $this->assertThrows(DailyReportValidationException::class,fn()=>$service->update($report['id'],['version'=>$report['version'],'reportDate'=>'2026-11-10','title'=>'Veraltet','activities'=>'Veralteter Stand','entries'=>[]],$office));
            $shell=$service->create(['orderId'=>$ids['order'],'reportDate'=>'2026-11-10','title'=>'Grunddaten','activities'=>'','entries'=>[]],$office);$this->assertSame('',$shell['activities']);
            $this->assertThrows(DailyReportValidationException::class,fn()=>$service->update($shell['id'],['version'=>$shell['version'],'reportDate'=>'2026-11-10','title'=>'Grunddaten','activities'=>'','entries'=>[]],$office));
        }finally{$this->remove($tmp);}
    }

    public function testTechnicianCanOnlyBookOwnTimeOnReleasedOrderAndCannotEditForeignReport():void
    {
        [$pdo,$tmp,$ids]=$this->fixture();
        try{
            $employees=new EmployeeRepository($pdo);$employeeService=new EmployeeService($employees,new UserRepository($pdo));$own=$employeeService->save(null,['displayName'=>'Techniker','userId'=>$ids['technician'],'hourlyCostRate'=>'30','active'=>true]);$other=$employeeService->save(null,['displayName'=>'Kollege','hourlyCostRate'=>'35','active'=>true]);
            $service=new DailyReportService($pdo,new DailyReportRepository($pdo),$employees,new OrderRepository($pdo));$office=['id'=>$ids['office'],'role'=>'office'];$tech=['id'=>$ids['technician'],'role'=>'technician'];
            $foreign=$service->create(['orderId'=>$ids['order'],'reportDate'=>'2026-11-10','activities'=>'Bürobericht','entries'=>[['employeeId'=>$other['id'],'durationHours'=>'1','targetKey'=>'order']]],$office);
            $this->assertThrows(AccessDeniedException::class,fn()=>$service->update($foreign['id'],['version'=>$foreign['version'],'reportDate'=>'2026-11-10','activities'=>'Manipuliert','entries'=>[]],$tech));
            $this->assertThrows(AccessDeniedException::class,fn()=>$service->create(['orderId'=>$ids['order'],'reportDate'=>'2026-11-10','activities'=>'Falsche Person','entries'=>[['employeeId'=>$other['id'],'durationHours'=>'1','targetKey'=>'order']]],$tech));
            $ownReport=$service->create(['orderId'=>$ids['order'],'reportDate'=>'2026-11-10','activities'=>'Eigene Zeit','entries'=>[['employeeId'=>$own['id'],'durationHours'=>'1','targetKey'=>'order']]],$tech);$this->assertTrue($ownReport['canEdit']);$this->assertTrue(!isset($ownReport['entries'][0]['labor_cost']));
            $pdo->prepare("UPDATE orders SET status='open' WHERE id=:id")->execute(['id'=>$ids['order']]);$this->assertThrows(AccessDeniedException::class,fn()=>$service->orderTargets($ids['order'],$tech));
        }finally{$this->remove($tmp);}
    }

    public function testReportsWithoutOrderAreValidAndLaterAssignmentDoesNotCreateOrderCosts(): void
    {
        [$pdo, $tmp, $ids] = $this->fixture();
        try {
            $now = Clock::now();
            $employees = new EmployeeRepository($pdo);
            $own = (new EmployeeService($employees, new UserRepository($pdo)))->save(null, ['displayName' => 'Techniker', 'userId' => $ids['technician'], 'hourlyCostRate' => '30', 'active' => true]);
            $repository = new DailyReportRepository($pdo);
            $service = new DailyReportService($pdo, $repository, $employees, new OrderRepository($pdo), new AttachmentStore($tmp . '/documents'));
            $office = ['id' => $ids['office'], 'role' => 'office'];
            $tech = ['id' => $ids['technician'], 'role' => 'technician'];
            $colleagueId = Uuid::v4();
            $pdo->prepare("INSERT INTO users(id,username,password_hash,display_name,role,active,created_at,updated_at) VALUES(:id,'kollege','hash','Kollege','technician',1,:now,:now)")->execute(['id' => $colleagueId, 'now' => $now]);
            $colleague = ['id' => $colleagueId, 'role' => 'technician'];
            $otherCustomer = Uuid::v4();
            $pdo->prepare("INSERT INTO customers(id,customer_number,customer_type,company_name,active,created_at,updated_at) VALUES(:id,'10004','company','Fremd',1,:now,:now)")->execute(['id' => $otherCustomer, 'now' => $now]);
            $project = Uuid::v4();
            $foreignProject = Uuid::v4();
            $insertProject = $pdo->prepare("INSERT INTO projects(id,project_number,name,customer_id,status,created_at,updated_at) VALUES(:id,:number,:name,:customer,'active',:now,:now)");
            $insertProject->execute(['id' => $project, 'number' => 'P1', 'name' => 'Neubau Halle', 'customer' => $ids['customer'], 'now' => $now]);
            $insertProject->execute(['id' => $foreignProject, 'number' => 'P2', 'name' => 'Fremdprojekt', 'customer' => $otherCustomer, 'now' => $now]);
            $entry = ['employeeId' => $own['id'], 'durationHours' => '2', 'targetKey' => 'order', 'description' => 'Besichtigung'];
            $base = ['reportDate' => '2026-11-12', 'title' => 'Besichtigung vor Angebot', 'activities' => 'Bestandsaufnahme Verteilung', 'entries' => [$entry]];

            // Kunde ist Pflicht; Projekt muss zum Kunden gehören; Auftrag zum Kunden und zum Projekt.
            $this->assertThrows(DailyReportValidationException::class, fn () => $service->create($base, $tech));
            $this->assertThrows(DailyReportValidationException::class, fn () => $service->create(['customerId' => $ids['customer'], 'projectId' => $foreignProject] + $base, $tech));
            $this->assertThrows(DailyReportValidationException::class, fn () => $service->create(['customerId' => $otherCustomer, 'orderId' => $ids['order']] + $base, $office));
            $this->assertThrows(DailyReportValidationException::class, fn () => $service->create(['orderId' => $ids['order'], 'projectId' => $project] + $base, $office), 'Projekt passt nicht zum Auftrag.');
            $reference = $service->referenceData($tech);
            $this->assertSame(['id', 'customerNumber', 'name'], array_keys($reference['customers'][0]), 'Monteure erhalten nur Nummer und Name der Kunden.');

            // Ohne Auftrag – ohne künstlichen Auftrag, dauerhaft gültig.
            $ordersBefore = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
            $report = $service->create(['customerId' => $ids['customer'], 'projectId' => $project] + $base, $tech);
            $this->assertSame([null, $ids['customer'], $project, false], [$report['order_id'], $report['customer_id'], $report['project_id'], $report['labor_counts_for_order']]);
            $this->assertSame('Ohne Auftrag', $report['targets'][0]['label']);
            $this->assertSame('2.000', $report['entries'][0]['duration_hours'], 'Mitarbeiterzeiten ohne Auftrag.');
            $this->assertSame($ordersBefore, (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
            $this->assertTrue(!$report['canAssignOrder'] && !$report['canCountLabor'], 'Monteure ordnen nicht zu.');
            $updated = $service->update($report['id'], ['version' => $report['version'], 'reportDate' => '2026-11-12', 'title' => 'Besichtigung', 'activities' => 'Bestandsaufnahme ergänzt', 'entries' => [$entry]], $tech);
            $this->assertSame(null, $updated['order_id']);

            // Mit Auftrag: Kunde (und Projekt) aus dem Auftrag; Stunden zählen wie bisher.
            $withOrder = $service->create(['orderId' => $ids['order']] + $base, $tech);
            $this->assertSame([$ids['customer'], true], [$withOrder['customer_id'], $withOrder['labor_counts_for_order']]);
            $this->assertSame('2.000', $service->orderTotals($ids['order'])['hours'], 'Nur der Bericht mit Auftrag zählt.');

            // Sichtbarkeit: fremde Berichte ohne Auftrag sieht ein Kollege nie.
            $this->assertThrows(AccessDeniedException::class, fn () => $service->detail($report['id'], $colleague));
            $this->assertSame(1, $service->page([], $colleague)['total'], 'Nur der Bericht des freigegebenen Auftrags.');
            $this->assertSame(2, $service->page([], $tech)['total']);
            $this->assertSame(1, $service->page(['assignment' => 'without_order'], $office)['total']);
            $this->assertSame(1, $service->page(['project' => $project], $office)['total']);
            $this->assertSame(2, $service->page(['customer' => $ids['customer']], $office)['total']);
            $this->assertSame(1, $service->page(['q' => 'Neubau'], $office)['total'], 'Suche nach Projekt.');

            // PDF ohne Auftragsnummer, mit Projekt.
            $pdf = (new DailyReportPdfService($service, new DocumentSettingsRepository($pdo)))->pdf($report['id'], $tech)['pdf'];
            $this->assertTrue(!str_contains($pdf, '(Auftrag) Tj') && str_contains($pdf, '(Projekt) Tj') && str_contains($pdf, '(Kundennummer) Tj'));
            $this->assertTrue(str_contains((new DailyReportPdfService($service, new DocumentSettingsRepository($pdo)))->pdf($withOrder['id'], $office)['pdf'], '(Auftrag) Tj'));

            // Materialanforderung ohne Auftrag: Kunde aus dem Bericht, kein Auftrag wird erzeugt.
            $materials = new MaterialRequestService(new MaterialRequestRepository($pdo), new OrderRepository($pdo), $repository);
            $request = $materials->create(['dailyReportId' => $report['id'], 'description' => 'Kabel NYM 3x1,5'], $tech);
            $this->assertSame([null, $ids['customer']], [$request['orderId'], $request['customerId']]);
            $this->assertThrows(AccessDeniedException::class, fn () => $materials->create(['dailyReportId' => $report['id'], 'description' => 'x'], $colleague));
            $this->assertThrows(AccessDeniedException::class, fn () => $materials->detail($request['id'], $colleague));
            $this->assertSame(1, $materials->page(['customer' => $ids['customer']], $office)['total']);
            $this->assertSame($ordersBefore, (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());

            // Anhänge und Dokumentenzugriff ohne Auftrag.
            $file = $tmp . '/foto.png';
            file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
            $attachment = $service->addAttachment($report['id'], $file, 'foto.png', $tech);
            $this->assertSame('image/png', $service->attachmentFile($report['id'], $attachment['id'], $office)['mimeType']);
            $this->assertThrows(AccessDeniedException::class, fn () => $service->attachmentFile($report['id'], $attachment['id'], $colleague));
            $documents = array_values(array_filter((new DocumentRepository($pdo))->dailyReports(), static fn (array $row): bool => $row['id'] === $report['id']));
            $this->assertSame([$ids['customer'], $project, null], [$documents[0]['customer_id'], $documents[0]['project_id'], $documents[0]['order_id']], 'Bericht erscheint in der Dokumentenübersicht.');

            // Nachträgliche Zuordnung: nur Büro, nur Aufträge desselben Kunden; organisatorisch.
            $foreignOrder = Uuid::v4();
            $pdo->prepare("INSERT INTO orders(id,order_number,customer_id,order_type,title,order_date,status,created_at,updated_at) VALUES(:id,'AU9',:customer,'regie','Fremd','2026-11-10','in_progress',:now,:now)")
                ->execute(['id' => $foreignOrder, 'customer' => $otherCustomer, 'now' => $now]);
            $current = $service->detail($report['id'], $office);
            $this->assertTrue($current['canAssignOrder'] && !$current['canCountLabor']);
            $this->assertThrows(AccessDeniedException::class, fn () => $service->assignOrder($report['id'], ['version' => $current['version'], 'orderId' => $ids['order']], $tech));
            $this->assertThrows(DailyReportValidationException::class, fn () => $service->assignOrder($report['id'], ['version' => $current['version'], 'orderId' => $foreignOrder], $office));
            $this->assertThrows(DailyReportValidationException::class, fn () => $service->assignOrder($report['id'], ['version' => $current['version'] - 1, 'orderId' => $ids['order']], $office));
            $entriesBefore = $pdo->query('SELECT id, duration_hours, labor_cost, hourly_cost_rate_snapshot, updated_at FROM daily_report_time_entries WHERE report_id = ' . $pdo->quote($report['id']))->fetchAll();
            $assigned = $service->assignOrder($report['id'], ['version' => $current['version'], 'orderId' => $ids['order']], $office);
            $this->assertSame([$ids['order'], 'Büro', false], [$assigned['order_id'], $assigned['order_assigned_by_name'], $assigned['labor_counts_for_order']]);
            $this->assertSame([$report['created_at'], 'Bestandsaufnahme ergänzt', $project], [$assigned['created_at'], $assigned['activities'], $assigned['project_id']], 'Erstellungszeitpunkt, Tätigkeiten und Projekt bleiben.');
            $this->assertSame($entriesBefore, $pdo->query('SELECT id, duration_hours, labor_cost, hourly_cost_rate_snapshot, updated_at FROM daily_report_time_entries WHERE report_id = ' . $pdo->quote($report['id']))->fetchAll(), 'Zeiten unverändert.');
            $this->assertSame('2.000', $service->orderTotals($ids['order'])['hours'], 'Keine automatische Übernahme als Auftragskosten.');
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM daily_reports WHERE id = ' . $pdo->quote($report['id']))->fetchColumn(), 'Kein Duplikat.');
            $this->assertThrows(DailyReportValidationException::class, fn () => $service->assignOrder($report['id'], ['version' => $assigned['version'], 'orderId' => $ids['order']], $office));
            $this->assertSame($report['id'], $service->detail($report['id'], $tech)['id'], 'Ersteller sieht seinen Bericht weiterhin.');

            // Bewusste Übernahme der Stunden (Büro mit timesheet.manage).
            $this->assertTrue($assigned['canCountLabor']);
            $this->assertThrows(AccessDeniedException::class, fn () => $service->countLaborForOrder($report['id'], ['version' => $assigned['version']], $tech));
            $counted = $service->countLaborForOrder($report['id'], ['version' => $assigned['version']], $office);
            $this->assertTrue($counted['labor_counts_for_order'] && $counted['labor_assigned_at'] !== null);
            $this->assertSame('4.000', $service->orderTotals($ids['order'])['hours']);
            $this->assertThrows(DailyReportValidationException::class, fn () => $service->countLaborForOrder($report['id'], ['version' => $counted['version']], $office));
        } finally {
            $this->remove($tmp);
        }
    }

    /** @return array{0:\PDO,1:string,2:array<string,string>} */
    private function fixture():array
    {
        $root=dirname(__DIR__,2);$tmp=sys_get_temp_dir().'/calight-reports-'.bin2hex(random_bytes(6));mkdir($tmp,0770,true);$pdo=ConnectionFactory::create($tmp.'/db.sqlite');(new Migrator($pdo,$root.'/migrations'))->migrate();$now=Clock::now();$ids=['office'=>Uuid::v4(),'technician'=>Uuid::v4(),'customer'=>Uuid::v4(),'order'=>Uuid::v4(),'offer'=>Uuid::v4(),'addendum'=>Uuid::v4()];
        $u=$pdo->prepare('INSERT INTO users(id,username,password_hash,display_name,role,active,created_at,updated_at) VALUES(:id,:username,\'hash\',:name,:role,1,:now,:now)');$u->execute(['id'=>$ids['office'],'username'=>'office','name'=>'Büro','role'=>'office','now'=>$now]);$u->execute(['id'=>$ids['technician'],'username'=>'tech','name'=>'Techniker','role'=>'technician','now'=>$now]);
        $pdo->prepare("INSERT INTO customers(id,customer_number,customer_type,company_name,active,created_at,updated_at) VALUES(:id,'10003','company','Kunde',1,:now,:now)")->execute(['id'=>$ids['customer'],'now'=>$now]);
        $basis=json_encode(['nodes'=>[['id'=>'title-1','type'=>'title','name'=>'Elektro','children'=>[['id'=>'position-1','type'=>'position','shortText'=>'Montage']]]]],JSON_THROW_ON_ERROR);$pdo->prepare("INSERT INTO orders(id,order_number,customer_id,order_type,title,order_date,status,basis_json,basis_sha256,created_at,updated_at) VALUES(:id,'AU1',:customer,'regie','Auftrag','2026-11-10','in_progress',:basis,'sha',:now,:now)")->execute(['id'=>$ids['order'],'customer'=>$ids['customer'],'basis'=>$basis,'now'=>$now]);
        $addBasis=json_encode(['nodes'=>[['id'=>'add-position','type'=>'position','shortText'=>'Nachtrag']]],JSON_THROW_ON_ERROR);$pdo->prepare("INSERT INTO offers(id,customer_id,storage_key,status,draft_relative_path,draft_etag,revision_root_id,document_type,order_id,created_at,updated_at) VALUES(:id,:customer,:id,'commissioned',:path,'etag',:id,'change_order',:order,:now,:now)")->execute(['id'=>$ids['offer'],'customer'=>$ids['customer'],'path'=>$ids['offer'].'.json','order'=>$ids['order'],'now'=>$now]);$pdo->prepare("INSERT INTO order_addenda(id,order_id,offer_revision_id,revision_root_id,basis_json,basis_sha256,net_total,accepted_at) VALUES(:id,:order,:offer,:offer,:basis,'sha','10.00',:now)")->execute(['id'=>$ids['addendum'],'order'=>$ids['order'],'offer'=>$ids['offer'],'basis'=>$addBasis,'now'=>$now]);return[$pdo,$tmp,$ids];
    }
    private function remove(string $path):void{if(!is_dir($path))return;foreach(scandir($path)?:[] as $item){if($item==='.'||$item==='..')continue;$child=$path.'/'.$item;is_dir($child)?$this->remove($child):unlink($child);}rmdir($path);}
}
