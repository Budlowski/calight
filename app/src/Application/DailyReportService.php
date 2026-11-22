<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Calculation\Decimal;
use App\Domain\DailyReportValidationException;
use App\Infrastructure\Persistence\AttachmentStore;
use App\Infrastructure\Persistence\OrderVisibility;
use App\Infrastructure\Persistence\DailyReportRepository;
use App\Infrastructure\Persistence\DocumentSettingsRepository;
use App\Infrastructure\Persistence\EmployeeRepository;
use App\Infrastructure\Persistence\OrderRepository;
use App\Infrastructure\Persistence\ReportTimeBookings;
use App\Http\AccessDeniedException;
use App\Support\Clock;
use App\Support\ListQuery;
use App\Support\Uuid;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use PDO;
use Throwable;

/**
 * Tagesberichte: interne Dokumentation tatsächlicher Arbeiten und Termine.
 *
 * Tagesberichte (fachlich vormals „Tagesberichte“, technisch daily_reports): interne
 * Dokumentation von Tätigkeiten und tatsächlichen Mitarbeiterstunden – für Kundenaufträge wie für
 * interne Tätigkeiten. Primär optional ein Kostenziel: Kundenauftrag ODER allgemeine Kostenstelle
 * ODER keines. Kunde und Projekt sind optionale Kontextangaben (bei Auftrag dessen Kunde).
 * Jede Mitarbeiterstundenposition ist tatsächliche Arbeitszeit und hat genau eine zentrale
 * Zeitbuchung (ReportTimeBookings, gleiche Transaktion); Stundenkorrekturen am Bericht wirken
 * unmittelbar. Die Stunden folgen dem Kostenziel des Berichts; das Büro kann es nachträglich
 * setzen, wechseln oder entfernen (protokolliert).
 *
 * Lebenszyklus (Migration 0045): Entwurf → eingereicht (für den Mitarbeiter gesperrt) →
 * festgeschrieben (unveränderliches PDF mit festem Datenstand, Revisionen); das Büro
 * (orders.write) kann begründet zurückgeben, wieder öffnen oder stornieren. Nur nie eingereichte
 * Entwürfe werden gelöscht. Stornierte Berichte bleiben erhalten; ihre Zeitbuchungen entfallen
 * (gesperrte Zeiterfassungstage müssen vorher wieder geöffnet werden). Die Sperren gelten auch
 * in der Datenbank (Trigger calight_report_locked bzw. calight_time_locked).
 */
final readonly class DailyReportService
{
    /** Erlaubte Anhangstypen (am Dateiinhalt erkannt) mit Dateiendung; Bilder werden als Vorschau angezeigt. */
    public const ATTACHMENT_TYPES = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
        'image/heic' => 'heic', 'image/heif' => 'heif', 'application/pdf' => 'pdf', 'text/plain' => 'txt',
    ];
    public const PREVIEW_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    public const MAX_ATTACHMENT_BYTES = 15 * 1024 * 1024;
    public const MAX_ATTACHMENTS_PER_REPORT = 30;
    public const STATUS = ['draft' => 'Entwurf', 'submitted' => 'Eingereicht', 'approved' => 'Festgeschrieben', 'reopened' => 'Zur Korrektur geöffnet', 'cancelled' => 'Storniert'];

    public function __construct(
        private PDO $pdo,
        private DailyReportRepository $reports,
        private EmployeeRepository $employees,
        private OrderRepository $orders,
        private ?AttachmentStore $attachmentStore = null,
        private ?DocumentSettingsRepository $documentSettings = null,
    ) {
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function referenceData(array $actor):array
    {
        $technician=($actor['role']??'')==='technician';$allOrders=$this->orders->all();
        // Monteure: nur für sie sichtbare Aufträge (OrderVisibility).
        $visibleOrders=OrderVisibility::visibleIds($this->pdo,$actor,array_map(static fn(array $o):string=>(string)$o['id'],$allOrders));
        $orders=array_values(array_filter($allOrders,static fn(array $o):bool=>isset($visibleOrders[(string)$o['id']])));$allEmployees=$this->employees->all();
        $employees=array_values(array_filter($allEmployees,static fn(array $e):bool=>(int)$e['active']===1&&(!$technician||(string)$e['user_id']===(string)$actor['id'])));$filterEmployees=$technician?array_values(array_filter($allEmployees,static fn(array $e):bool=>(string)$e['user_id']===(string)$actor['id'])):$allEmployees;
        // Kunden und Projekte nur mit Nummer und Name (wie bei Rapporten), damit Monteure Berichte ohne Auftrag anlegen können.
        $customers=array_map(static fn(array $row):array=>['id'=>$row['id'],'customerNumber'=>$row['customer_number'],'name'=>$row['name']],$this->reports->activeCustomers());
        $projects=array_map(static fn(array $row):array=>['id'=>$row['id'],'projectNumber'=>$row['project_number'],'name'=>$row['name'],'customerId'=>$row['customer_id']],$this->reports->projectsForSelection());
        // Gemeinsames Suchfeld „Auftrag / Kostenstelle“ (Aufträge mit denselben Freigaberegeln).
        $costTargets=(new CostTargets($this->pdo))->options(array_map(static fn(array $o):array=>['id'=>$o['id'],'orderNumber'=>$o['order_number'],'title'=>$o['title']],$orders));
        return['orders'=>$orders,'costTargets'=>$costTargets,'customers'=>$customers,'projects'=>$projects,'employees'=>$this->visibleEmployees($employees,$actor),'filterEmployees'=>$this->visibleEmployees($filterEmployees,$actor),
            // Eigener aktiver Mitarbeiter (nur über employees.user_id) für die Vorbelegung neuer Stundenpositionen.
            'ownEmployeeId'=>array_values(array_filter($employees,static fn(array $e):bool=>(string)$e['user_id']===(string)($actor['id']??'')))[0]['id']??null];
    }

    /** @param array<string,mixed> $actor @return list<array<string,mixed>> */
    public function overview(array $actor):array
    {
        $rows=$this->reports->all();if(($actor['role']??'')==='technician')$rows=array_values(array_filter($rows,fn(array $r):bool=>$this->technicianMaySee($r,$actor)));
        return array_map(fn (array $row): array => $this->withTotals($row, $actor), $rows);
    }

    /**
     * Seite der Berichtsübersicht. Kosten nur mit costs.read, Techniker nur freigegebene Aufträge.
     *
     * @param array<string,mixed> $parameters URL-Parameter (q, order, date, employee, sort, dir, page, per_page)
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function page(array $parameters, array $actor): array
    {
        $query = ListQuery::fromArray($parameters, DailyReportRepository::LIST_SORTS, 'date', DailyReportRepository::LIST_FILTERS);
        $page = $this->reports->page($query, ($actor['role'] ?? '') === 'technician' ? (string) $actor['id'] : null);
        $page['items'] = array_map(fn (array $row): array => $this->withTotals($row, $actor), $page['items']);
        return $page;
    }

    /**
     * Summiert Stunden und Ist-Lohnkosten exakt aus den Zeiteinträgen eines Berichts.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    private function withTotals(array $row, array $actor): array
    {
        $hours = BigDecimal::zero();
        $costs = BigDecimal::zero();
        foreach (array_filter(explode('|', (string) ($row['duration_values'] ?? ''))) as $duration) {
            $hours = $hours->plus($duration);
        }
        foreach (array_filter(explode('|', (string) ($row['cost_values'] ?? ''))) as $cost) {
            $costs = $costs->plus($cost);
        }
        $row['total_hours'] = (string) $hours->toScale(Decimal::TIME_SCALE, RoundingMode::HalfUp);
        if ($this->canReadCosts($actor)) {
            $row['total_labor_cost'] = Decimal::money($costs);
        }
        unset($row['basis_json'], $row['duration_values'], $row['cost_values']);
        $row['canEdit'] = $this->canEdit($row, $actor);
        $row['status_label'] = self::STATUS[$row['status'] ?? 'draft'] ?? '';
        return $row;
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function detail(string $id,array $actor):array
    {
        $report=$this->reports->find($id);if($report===null)throw new DailyReportValidationException(['Tagesbericht wurde nicht gefunden.']);$this->assertReportAccess($report,$actor);
        $entries=$this->reports->entries($id);foreach($entries as &$entry)unset($entry['target_kind'],$entry['target_basis_type'],$entry['target_basis_id'],$entry['target_node_id']);unset($entry);if(!$this->canReadCosts($actor))foreach($entries as &$entry)unset($entry['hourly_cost_rate_snapshot'],$entry['labor_cost']);unset($entry);
        unset($report['basis_json']);$report['entries']=$entries;$report['attachments']=array_map($this->mapAttachment(...),$this->reports->attachments($id));$report['canEdit']=$this->canEdit($report,$actor);
        $report['labor_counts_for_order']=(bool)$report['labor_counts_for_order'];
        $office=$this->can($actor,'orders.write');$status=(string)$report['status'];
        $report['canAssignOrder']=$office&&in_array($status,['draft','reopened'],true);
        $report['orderEvents']=$office?$this->reports->orderEvents($id):[];
        // Lebenszyklus: Status, erlaubte Aktionen, Protokoll und archivierte Fassungen.
        $report['statusLabel']=self::STATUS[$status]??$status;
        $report['canSubmit']=$report['canEdit'];
        $report['canDelete']=$report['canEdit']&&$status==='draft'&&!(bool)$report['submitted_once'];
        $report['canApprove']=$office&&$status==='submitted';
        $report['canReturn']=$office&&$status==='submitted';
        $report['canReopen']=$office&&$status==='approved';
        $report['canCancel']=$office&&$status!=='cancelled'&&(bool)$report['submitted_once'];
        $report['events']=$this->reports->events($id);
        $report['statements']=array_map(fn(array $row):array=>['id'=>$row['id'],'revision'=>(int)$row['revision'],'current'=>$row['status']==='current','createdAt'=>$row['created_at'],
            'supersededAt'=>$row['superseded_at'],'url'=>'/api/daily-reports/'.rawurlencode($id).'/statements/'.rawurlencode((string)$row['id']).'/pdf'],$this->reports->statements($id));
        $current=array_values(array_filter($report['statements'],static fn(array $row):bool=>$row['current']));
        $report['currentStatementId']=$current[0]['id']??null;

        unset($report['order_project_id']);return$report;
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function create(array $input,array $actor):array
    {
        ['customer_id'=>$customerId,'project_id'=>$projectId,'order_id'=>$orderId,'cost_center_id'=>$costCenterId]=$this->resolveAssignment($input,$actor);
        $values=$this->validateReport($input,true);$id=Uuid::v4();$now=Clock::now();$entries=$this->validateEntries($input['entries']??[],$id,$orderId,$values['report_date'],$actor,$now,[]);
        $this->pdo->exec('BEGIN IMMEDIATE');try{$this->reports->insertReport(['id'=>$id,'customer_id'=>$customerId,'project_id'=>$projectId,'order_id'=>$orderId,'cost_center_id'=>$costCenterId,
            // Mit Auftrag angelegt: Stunden zählen wie bisher als Ist-Stunden. Ohne Auftrag: nie automatisch.
            'labor_counts_for_order'=>$orderId!==null?1:0,
            'report_date'=>$values['report_date'],'title'=>$values['title'],'activities'=>$values['activities'],'notes'=>$values['notes'],'created_by_user_id'=>$actor['id'],'created_at'=>$now,'updated_at'=>$now]);$this->reports->replaceEntries($id,$entries);(new ReportTimeBookings($this->pdo))->syncDailyReport($id,(string)$actor['id'],$now);$this->pdo->commit();}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
        return$this->detail($id,$actor);
    }

    /**
     * Zuordnung eines neuen Tagesberichts. Primär das optionale Kostenziel (Auftrag ODER
     * Kostenstelle); Kunde und Projekt sind optionale Kontextangaben.
     * - Mit Auftrag: Kunde und Projekt kommen aus dem Auftrag; abweichende Angaben sind ein Fehler.
     * - Sonst: Kunde optional (aktiv); ein Projekt nur zusammen mit seinem Kunden.
     *
     * @param array<string,mixed> $input orderId | costCenterId, customerId, projectId
     * @param array<string,mixed> $actor
     * @return array{customer_id:?string,project_id:?string,order_id:?string,cost_center_id:?string}
     */
    private function resolveAssignment(array $input,array $actor):array
    {
        $orderId=trim((string)($input['orderId']??''));$customerId=trim((string)($input['customerId']??''));$projectId=trim((string)($input['projectId']??''));
        // Kostenstelle: nur ohne Auftrag (gemeinsame Prüfung in CostTargets).
        $costCenterId=$this->costCenter($input['costCenterId']??'',$orderId!==''?$orderId:null,null);
        if($orderId!==''){
            $order=$this->orders->find($orderId);if($order===null)throw new DailyReportValidationException(['Auftrag wurde nicht gefunden.']);$this->assertOrderAccess($order,$actor);
            if($customerId!==''&&$customerId!==(string)$order['customer_id'])throw new DailyReportValidationException(['Der Auftrag gehört nicht zum ausgewählten Kunden.']);
            $orderProject=$order['project_id']!==null?(string)$order['project_id']:null;
            if($projectId!==''&&$projectId!==$orderProject)throw new DailyReportValidationException(['Das Projekt passt nicht zum ausgewählten Auftrag.']);
            return['customer_id'=>(string)$order['customer_id'],'project_id'=>$orderProject,'order_id'=>$orderId,'cost_center_id'=>null];
        }
        if($customerId!==''&&!$this->reports->customerIsActive($customerId))throw new DailyReportValidationException(['Bitte einen aktiven Kunden auswählen.']);
        if($projectId!==''){
            if($customerId==='')throw new DailyReportValidationException(['Ein Projekt kann nur zusammen mit seinem Kunden angegeben werden.']);
            $project=$this->reports->project($projectId);if($project===null||(string)$project['customer_id']!==$customerId||$project['status']==='archived')throw new DailyReportValidationException(['Das Projekt gehört nicht zum ausgewählten Kunden.']);
        }
        return['customer_id'=>$customerId!==''?$customerId:null,'project_id'=>$projectId!==''?$projectId:null,'order_id'=>null,'cost_center_id'=>$costCenterId];
    }

    /**
     * Kostenziel eines Berichts setzen, wechseln oder entfernen (Büro, orders.write): Auftrag
     * desselben Kunden und passenden Projekts ODER aktive Kostenstelle ODER keines. Die
     * zugehörigen Zeitbuchungen erhalten dasselbe Ziel (keine neuen Buchungen). Protokolliert.
     *
     * @param array<string,mixed> $input version, orderId | costCenterId (beide leer = ohne Zuordnung)
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function assignOrder(string $id,array $input,array $actor):array
    {
        if(!$this->can($actor,'orders.write'))throw new AccessDeniedException('orders.write');
        $report=$this->reports->find($id);if($report===null)throw new DailyReportValidationException(['Tagesbericht wurde nicht gefunden.']);
        if(!in_array($report['status'],['draft','reopened'],true))throw new DailyReportValidationException(['Die Zuordnung eines eingereichten, festgeschriebenen oder stornierten Berichts kann nicht geändert werden. Bitte den Bericht zuerst zur Korrektur öffnen.']);
        $orderId=trim((string)($input['orderId']??''));$order=null;
        $costCenterId=$this->costCenter($input['costCenterId']??'',$orderId!==''?$orderId:null,$report['cost_center_id']!==null?(string)$report['cost_center_id']:null);
        if($orderId!==''){
            $order=$this->orders->find($orderId);if($order===null)throw new DailyReportValidationException(['Auftrag wurde nicht gefunden.']);
            // Ohne Kunde übernimmt der Bericht den Auftragskunden; ein anderer Kunde wäre ein Widerspruch.
            if($report['customer_id']!==null&&(string)$order['customer_id']!==(string)$report['customer_id'])throw new DailyReportValidationException(['Der Auftrag gehört nicht zum Kunden des Berichts.']);
            $reportProject=$report['order_id']===null?$report['project_id']:null;
            if($reportProject!==null&&$order['project_id']!==null&&(string)$reportProject!==(string)$order['project_id'])throw new DailyReportValidationException(['Der Auftrag gehört zu einem anderen Projekt als der Bericht.']);
        }
        if(($order['id']??null)===$report['order_id']&&$costCenterId===$report['cost_center_id'])throw new DailyReportValidationException(['Der Bericht ist diesem Ziel bereits zugeordnet.']);
        $now=Clock::now();
        $this->pdo->exec('BEGIN IMMEDIATE');
        try{
            if(!$this->reports->setTarget($id,(int)($input['version']??0),$report['order_id']!==null?(string)$report['order_id']:null,$report['cost_center_id']!==null?(string)$report['cost_center_id']:null,$order!==null?(string)$order['id']:null,$costCenterId,$order!==null&&$order['project_id']!==null?(string)$order['project_id']:null,(string)$actor['id'],$now,$order!==null?(string)$order['customer_id']:null))throw new DailyReportValidationException(['Der Tagesbericht wurde zwischenzeitlich geändert. Bitte neu laden.']);
            (new ReportTimeBookings($this->pdo))->syncDailyReport($id,(string)$actor['id'],$now);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
        return$this->detail($id,$actor);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function update(string $id,array $input,array $actor):array
    {
        $current=$this->reports->find($id);if($current===null)throw new DailyReportValidationException(['Tagesbericht wurde nicht gefunden.']);$this->assertReportAccess($current,$actor);if(!$this->canEdit($current,$actor))throw new AccessDeniedException('reports.write.own');
        $version=(int)($input['version']??0);if($version<1)throw new DailyReportValidationException(['Versionsangabe fehlt.']);$values=$this->validateReport($input);$now=Clock::now();$existing=[];foreach($this->reports->entries($id) as $entry)$existing[(string)$entry['id']]=$entry;$entries=$this->validateEntries($input['entries']??[],$id,$current['order_id']!==null?(string)$current['order_id']:null,$values['report_date'],$actor,$now,$existing);
        $this->pdo->exec('BEGIN IMMEDIATE');try{if(!$this->reports->updateReport(['id'=>$id,'expected_version'=>$version,'report_date'=>$values['report_date'],'title'=>$values['title'],'activities'=>$values['activities'],'notes'=>$values['notes'],'updated_at'=>$now]))throw new DailyReportValidationException(['Der Tagesbericht wurde zwischenzeitlich geändert. Bitte neu laden.']);$this->reports->replaceEntries($id,$entries);(new ReportTimeBookings($this->pdo))->syncDailyReport($id,(string)$actor['id'],$now);$this->pdo->commit();}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
        return$this->detail($id,$actor);
    }

    /** @param array<string,mixed> $actor */
    public function delete(string $id,int $version,array $actor):void
    {
        $current=$this->reports->find($id);
        if($current===null)throw new DailyReportValidationException(['Tagesbericht wurde nicht gefunden.']);
        $this->assertReportAccess($current,$actor);
        if(!$this->canEdit($current,$actor))throw new AccessDeniedException('reports.write.own');
        if($current['status']!=='draft'||(bool)$current['submitted_once'])throw new DailyReportValidationException(['Eingereichte Tagesberichte bleiben erhalten und können nur vom Büro storniert werden.']);
        // Anhangszeilen werden per Fremdschlüssel mitgelöscht, Materialanforderungen bleiben
        // erhalten (Verweis wird geleert). Dateien erst nach erfolgreichem Löschen entfernen.
        $paths=$this->reports->attachmentPaths($id);
        if(!$this->reports->delete($id,$version))throw new DailyReportValidationException(['Der Tagesbericht wurde zwischenzeitlich geändert. Bitte neu laden.']);
        foreach($paths as $path)$this->removeFile((string)$path);
    }

    /**
     * Speichert einen Anhang (Foto, PDF, Text) zum Bericht.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function addAttachment(string $reportId, string $sourcePath, string $originalName, array $actor): array
    {
        $store = $this->store();
        $report = $this->editableReport($reportId, $actor);
        $size = is_file($sourcePath) ? (int) filesize($sourcePath) : 0;
        if ($size <= 0) {
            throw new DailyReportValidationException(['Die Datei ist leer oder konnte nicht gelesen werden.']);
        }
        if ($size > self::MAX_ATTACHMENT_BYTES) {
            throw new DailyReportValidationException(['Anhänge dürfen höchstens 15 MB groß sein.']);
        }
        if ($this->reports->attachmentCount($reportId) >= self::MAX_ATTACHMENTS_PER_REPORT) {
            throw new DailyReportValidationException(['Ein Bericht kann höchstens 30 Anhänge enthalten.']);
        }
        // Der Typ wird am Inhalt erkannt, nicht an Dateiname oder Browserangabe.
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($sourcePath);
        $extension = self::ATTACHMENT_TYPES[$mime] ?? null;
        if ($extension === null) {
            throw new DailyReportValidationException(['Dieser Dateityp wird nicht unterstützt. Erlaubt sind Fotos (JPG, PNG, WebP, GIF, HEIC), PDF und Textdateien.']);
        }
        $name = $this->safeFileName($originalName, $extension);
        $id = Uuid::v4();
        $hash = (string) hash_file('sha256', $sourcePath);
        $path = $store->store($sourcePath, 'daily-reports/' . $report['id'], $id, $extension);
        try {
            $this->reports->insertAttachment([
                'id' => $id, 'report_id' => $report['id'], 'original_name' => $name, 'mime_type' => $mime,
                'size_bytes' => $size, 'sha256' => $hash, 'storage_path' => $path,
                'uploaded_by_user_id' => (string) $actor['id'], 'created_at' => Clock::now(),
            ]);
        } catch (Throwable $exception) {
            $this->removeFile($path);
            throw $exception;
        }
        $row = array_values(array_filter($this->reports->attachments($reportId), static fn (array $item): bool => $item['id'] === $id))[0];
        return $this->mapAttachment($row);
    }

    /**
     * Liefert Dateipfad und Metadaten für den Download; prüft dieselben Leserechte wie der Bericht.
     *
     * @param array<string,mixed> $actor
     * @return array{path:string,mimeType:string,name:string,inline:bool}
     */
    public function attachmentFile(string $reportId, string $attachmentId, array $actor): array
    {
        $report = $this->reports->find($reportId);
        if ($report === null) {
            throw new DailyReportValidationException(['Tagesbericht wurde nicht gefunden.']);
        }
        $this->assertReportAccess($report, $actor);
        $attachment = $this->reports->attachment($reportId, $attachmentId);
        $path = $attachment !== null ? $this->store()->absolute((string) $attachment['storage_path']) : '';
        if ($attachment === null || !is_file($path)) {
            throw new DailyReportValidationException(['Anhang wurde nicht gefunden.']);
        }
        return [
            'path' => $path,
            'mimeType' => (string) $attachment['mime_type'],
            'name' => (string) $attachment['original_name'],
            'inline' => in_array($attachment['mime_type'], self::PREVIEW_TYPES, true),
        ];
    }

    /** @param array<string,mixed> $actor */
    public function deleteAttachment(string $reportId, string $attachmentId, array $actor): void
    {
        $this->editableReport($reportId, $actor);
        $attachment = $this->reports->attachment($reportId, $attachmentId);
        if ($attachment === null || !$this->reports->deleteAttachment($reportId, $attachmentId)) {
            throw new DailyReportValidationException(['Anhang wurde nicht gefunden.']);
        }
        $this->removeFile((string) $attachment['storage_path']);
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    private function editableReport(string $reportId, array $actor): array
    {
        $report = $this->reports->find($reportId);
        if ($report === null) {
            throw new DailyReportValidationException(['Tagesbericht wurde nicht gefunden.']);
        }
        $this->assertReportAccess($report, $actor);
        if (!$this->canEdit($report, $actor)) {
            throw new AccessDeniedException('reports.write.own');
        }
        return $report;
    }

    private function store(): AttachmentStore
    {
        return $this->attachmentStore ?? throw new \RuntimeException('Für Anhänge ist keine Dateiablage konfiguriert.');
    }

    private function removeFile(string $path): void
    {
        try {
            $this->store()->delete($path);
        } catch (Throwable $exception) {
            // Eine verwaiste Datei ist unkritisch; der Datensatz ist bereits entfernt.
            error_log('Anhang konnte nicht entfernt werden: ' . $exception->getMessage());
        }
    }

    /** Anzeigename ohne Pfadanteile und Steuerzeichen, mit zur Erkennung passender Endung. */
    private function safeFileName(string $name, string $extension): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F"]+/u', '', $name));
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = mb_substr($base !== '' ? $base : 'Anhang', 0, 120);
        return $base . '.' . $extension;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function mapAttachment(array $row): array
    {
        return [
            'id' => $row['id'],
            'name' => $row['original_name'],
            'mimeType' => $row['mime_type'],
            'size' => (int) $row['size_bytes'],
            'preview' => in_array($row['mime_type'], self::PREVIEW_TYPES, true),
            'uploadedByName' => $row['uploaded_by_name'] ?? '',
            'createdAt' => $row['created_at'],
            'url' => '/api/daily-reports/' . rawurlencode((string) $row['report_id']) . '/attachments/' . rawurlencode((string) $row['id']),
        ];
    }

    // -------------------------------------------------------------------------
    // Lebenszyklus
    // -------------------------------------------------------------------------

    /** Einreichen (Ersteller bzw. Büro); danach für den Mitarbeiter gesperrt. @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function submit(string $id, array $input, array $actor): array
    {
        $report = $this->editableReport($id, $actor);
        return $this->transition($report, $input, $actor, ['draft', 'reopened'], 'submitted', 'submit', false);
    }

    /** Zur Korrektur zurückgeben (Büro, Begründung). @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function returnReport(string $id, array $input, array $actor): array
    {
        return $this->transition($this->officeReport($id, $actor), $input, $actor, ['submitted'], 'reopened', 'return', true);
    }

    /** Festgeschriebenen Bericht zur Korrektur öffnen (Büro, Begründung); die Fassung gilt als ersetzt. @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function reopen(string $id, array $input, array $actor): array
    {
        return $this->transition($this->officeReport($id, $actor), $input, $actor, ['approved'], 'reopened', 'reopen', true);
    }

    /**
     * Stornieren (Büro, Begründung): Bericht bleibt erhalten, seine Zeitbuchungen entfallen.
     * Liegen sie auf eingereichten oder festgeschriebenen Zeiterfassungstagen, verhindert die
     * Tagessperre die Stornierung, bis diese Tage wieder geöffnet sind.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function cancel(string $id, array $input, array $actor): array
    {
        $report = $this->officeReport($id, $actor);
        if (!(bool) $report['submitted_once']) {
            throw new DailyReportValidationException(['Nie eingereichte Entwürfe werden gelöscht, nicht storniert.']);
        }
        return $this->transition($report, $input, $actor, ['submitted', 'approved', 'reopened'], 'cancelled', 'cancel', true);
    }

    /** Einzeln festschreiben (Büro); archiviert das PDF als neue Revision. @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function approve(string $id, array $input, array $actor): array
    {
        $report = $this->officeReport($id, $actor);
        $now = Clock::now();
        $stored = [];
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if (!$this->reports->setStatus($id, $this->version($input), ['submitted'], 'approved', $now)) {
                throw new DailyReportValidationException(['Nur eingereichte Berichte können festgeschrieben werden, oder der Bericht wurde zwischenzeitlich geändert.']);
            }
            $stored[] = $this->archive($id, $actor, $now);
            $this->event($id, 'approve', '', $actor, $now);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            foreach ($stored as $path) {
                $this->removeFile($path);
            }
            throw $exception;
        }
        return $this->detail($report['id'], $actor);
    }

    /**
     * Sammelfestschreibung im Zuge der Arbeitszeitfreigabe (läuft in deren Transaktion):
     * eingereichte Berichte mit Stunden des Mitarbeiters im Zeitraum, deren sämtliche
     * Mitarbeiter-Tage festgeschrieben sind. Es werden nie Zeiterfassungstage festgeschrieben.
     *
     * @param array<string,mixed> $actor
     * @return list<string> archivierte Dateien (für Aufräumen bei Fehlern)
     */
    public function approveCovered(string $employeeId, string $from, string $to, array $actor, string $now): array
    {
        $stored = [];
        foreach ($this->reports->submittedReportsCoveredBy($employeeId, $from, $to) as $id) {
            if ($this->reports->setStatus($id, null, ['submitted'], 'approved', $now)) {
                $stored[] = $this->archive($id, $actor, $now);
                $this->event($id, 'approve', 'Mit der Arbeitszeitfreigabe', $actor, $now);
            }
        }
        return $stored;
    }

    /** Archivierte Fassung (unverändert, Prüfsumme). @param array<string,mixed> $actor @return array{pdf:string,fileName:string} */
    public function statementPdf(string $reportId, string $statementId, array $actor): array
    {
        $report = $this->reports->find($reportId) ?? throw new DailyReportValidationException(['Tagesbericht wurde nicht gefunden.']);
        $this->assertReportAccess($report, $actor);
        $statement = $this->reports->statement($reportId, $statementId) ?? throw new DailyReportValidationException(['Die archivierte Fassung wurde nicht gefunden.']);
        try {
            $pdf = $this->store()->read((string) $statement['storage_path']);
        } catch (Throwable) {
            throw new DailyReportValidationException(['Die Datei des Tagesberichts ist nicht verfügbar.']);
        }
        if (!hash_equals((string) $statement['sha256'], hash('sha256', $pdf))) {
            throw new DailyReportValidationException(['Die Datei des Tagesberichts ist beschädigt (Prüfsumme).']);
        }
        return ['pdf' => $pdf, 'fileName' => (string) $statement['file_name']];
    }

    /**
     * Statuswechsel mit Version, Protokoll und Folgen (Revision ersetzen, Buchungen entfernen).
     *
     * @param array<string,mixed> $report
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @param list<string> $from
     * @return array<string,mixed>
     */
    private function transition(array $report, array $input, array $actor, array $from, string $to, string $action, bool $reasonRequired): array
    {
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reasonRequired && $reason === '') {
            throw new DailyReportValidationException(['Bitte eine Begründung angeben.']);
        }
        if (mb_strlen($reason) > 1000) {
            throw new DailyReportValidationException(['Die Begründung darf höchstens 1.000 Zeichen lang sein.']);
        }
        $now = Clock::now();
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if (!$this->reports->setStatus((string) $report['id'], $this->version($input), $from, $to, $now)) {
                throw new DailyReportValidationException(['Der Tagesbericht wurde zwischenzeitlich geändert oder hat einen anderen Status. Bitte neu laden.']);
            }
            if (in_array($action, ['reopen', 'cancel'], true)) {
                $this->reports->supersedeStatements((string) $report['id'], (string) $actor['id'], $now);
            }
            if ($action === 'cancel') {
                // Zeitbuchungen entfallen (Tagessperre der Zeiterfassung greift per Trigger).
                (new ReportTimeBookings($this->pdo))->syncDailyReport((string) $report['id'], (string) $actor['id'], $now);
            }
            $this->event((string) $report['id'], $action, $reason, $actor, $now);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return $this->detail((string) $report['id'], $actor);
    }

    /**
     * PDF mit festem Datenstand archivieren (läuft in der Transaktion des Aufrufers).
     *
     * @param array<string,mixed> $actor
     */
    private function archive(string $id, array $actor, string $now): string
    {
        $settings = $this->documentSettings ?? throw new \RuntimeException('Für die Archivierung sind keine Dokumenteinstellungen konfiguriert.');
        // Datenstand ohne Kostensätze und ohne Rechte-/Laufzeitangaben.
        $snapshot = $this->detail($id, ['id' => (string) $actor['id'], 'role' => 'office']);
        foreach ($snapshot['entries'] as &$entry) {
            unset($entry['hourly_cost_rate_snapshot'], $entry['labor_cost']);
        }
        unset($entry);
        $snapshot = array_diff_key($snapshot, array_flip(['canEdit', 'canAssignOrder', 'orderEvents', 'canSubmit', 'canDelete', 'canApprove', 'canReturn', 'canReopen', 'canCancel', 'statements', 'currentStatementId', 'events']));
        $revision = $this->reports->nextRevision($id);
        $document = DailyReportPdfService::render($snapshot, $settings->get(), function (string $attachmentId) use ($id): ?string {
            $attachment = $this->reports->attachment($id, $attachmentId);
            try {
                return $attachment !== null ? $this->store()->read((string) $attachment['storage_path']) : null;
            } catch (Throwable) {
                return null;
            }
        }, null, $revision);
        $statementId = Uuid::v4();
        $path = $this->store()->storeContents($document['pdf'], 'daily-report-statements/' . $id, $statementId, 'pdf');
        $this->reports->insertStatement([
            'id' => $statementId, 'report_id' => $id, 'revision' => $revision, 'status' => 'current',
            'snapshot_json' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'file_name' => $document['fileName'], 'storage_path' => $path,
            'sha256' => hash('sha256', $document['pdf']), 'size_bytes' => strlen($document['pdf']), 'created_by_user_id' => (string) $actor['id'], 'created_at' => $now,
        ]);
        return $path;
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    private function officeReport(string $id, array $actor): array
    {
        if (!$this->can($actor, 'orders.write')) {
            throw new AccessDeniedException('orders.write');
        }
        return $this->reports->find($id) ?? throw new DailyReportValidationException(['Tagesbericht wurde nicht gefunden.']);
    }

    /** @param array<string,mixed> $actor */
    private function event(string $reportId, string $action, string $reason, array $actor, string $now): void
    {
        $this->reports->insertEvent(['id' => Uuid::v4(), 'report_id' => $reportId, 'action' => $action, 'reason' => $reason, 'user_id' => (string) $actor['id'], 'created_at' => $now]);
    }

    /** @param array<string,mixed> $input */
    private function version(array $input): int
    {
        $version = (int) ($input['version'] ?? 0);
        if ($version < 1) {
            throw new DailyReportValidationException(['Versionsangabe fehlt.']);
        }
        return $version;
    }

    /** @return array{hours:string,costs:string} */
    public function orderTotals(string $orderId):array
    {
        $hours=BigDecimal::zero();$costs=BigDecimal::zero();foreach($this->reports->costRowsForOrder($orderId) as $row){$hours=$hours->plus($row['duration_hours']);$costs=$costs->plus($row['labor_cost']);}return['hours'=>(string)$hours->toScale(Decimal::TIME_SCALE,RoundingMode::HalfUp),'costs'=>Decimal::money($costs)];
    }

    /**
     * Stundenkennzahlen eines Auftrags aus der zentralen Stundenbasis – einzige Aggregation für
     * Übersicht und Ausführung der Auftragsdetailseite: gesamt, festgeschrieben, vorläufig, je
     * Herkunft (Rapport, Tagesbericht, manuell) und Ist-Lohnkosten (Dauer × Kostensatz-Snapshot;
     * costsComplete = false, wenn Buchungen ohne Kostensatz existieren – nie als 0 gewertet).
     *
     * @return array{hours:string,approvedHours:string,provisionalHours:string,bySource:array<string,string>,costs:string,costsComplete:bool}
     */
    public function orderHours(string $orderId): array
    {
        $minutes = ['total' => 0, 'approved' => 0];
        $sources = ['work_report' => 0, 'daily_report' => 0, 'manual' => 0];
        $costs = BigDecimal::zero();
        $complete = true;
        foreach ($this->reports->orderBookings($orderId) as $row) {
            $net = (int) $row['net_minutes'];
            $minutes['total'] += $net;
            $minutes['approved'] += (int) $row['approved'] === 1 ? $net : 0;
            $sources[(string) $row['source_kind']] = ($sources[(string) $row['source_kind']] ?? 0) + $net;
            if ($row['hourly_cost_rate_snapshot'] === null) {
                $complete = false;
                continue;
            }
            $costs = $costs->plus(BigDecimal::of((string) $row['hourly_cost_rate_snapshot'])->multipliedBy($net)->dividedBy(60, 6, RoundingMode::HalfUp));
        }
        $hours = static fn (int $value): string => (string) BigDecimal::of($value)->dividedBy(60, Decimal::TIME_SCALE, RoundingMode::HalfUp);
        return [
            'hours' => $hours($minutes['total']),
            'approvedHours' => $hours($minutes['approved']),
            'provisionalHours' => $hours($minutes['total'] - $minutes['approved']),
            'bySource' => array_map($hours, $sources),
            'costs' => Decimal::money($costs),
            'costsComplete' => $complete,
        ];
    }

    /** Leserecht für Auswertungen je Auftrag (Techniker: nur freigegebene Aufträge). @param array<string,mixed> $actor */
    public function assertOrderReadable(string $orderId,array $actor):void
    {
        $order=$this->orders->find($orderId);if($order===null)throw new DailyReportValidationException(['Auftrag wurde nicht gefunden.']);$this->assertOrderAccess($order,$actor);
    }

    /** @param array<string,mixed> $input @return array{report_date:string,title:string,activities:string,notes:string} */
    private function validateReport(array $input,bool $allowEmptyActivities=false):array
    {
        $date=(string)($input['reportDate']??'');$title=trim((string)($input['title']??''));$activities=trim((string)($input['activities']??''));$notes=trim((string)($input['notes']??''));$errors=[];
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)||!checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4)))$errors[]='Das Berichtsdatum ist ungültig.';if(mb_strlen($title)>200)$errors[]='Die Bezeichnung darf höchstens 200 Zeichen lang sein.';if((!$allowEmptyActivities&&$activities==='')||mb_strlen($activities)>10000)$errors[]='Die Tätigkeitsbeschreibung ist erforderlich und darf höchstens 10.000 Zeichen lang sein.';if(mb_strlen($notes)>10000)$errors[]='Bemerkungen dürfen höchstens 10.000 Zeichen lang sein.';if($errors!==[])throw new DailyReportValidationException($errors);return['report_date'=>$date,'title'=>$title,'activities'=>$activities,'notes'=>$notes];
    }

    /** @param mixed $raw @param array<string,mixed> $actor @return list<array<string,mixed>> */
    private function validateEntries(mixed $raw,string $reportId,?string $orderId,string $defaultDate,array $actor,string $now,array $existing):array
    {
        if(!is_array($raw))throw new DailyReportValidationException(['Zeiteinträge müssen als Liste übergeben werden.']);$result=[];$usedEntryIds=[];
        foreach(array_values($raw) as $index=>$entry){if(!is_array($entry))throw new DailyReportValidationException(['Zeiteintrag '.($index+1).' ist ungültig.']);$entryId=(string)($entry['id']??'');if($entryId!==''&&isset($usedEntryIds[$entryId]))throw new DailyReportValidationException(['Ein Zeiteintrag wurde mehrfach übermittelt.']);if($entryId!=='')$usedEntryIds[$entryId]=true;$old=$existing[$entryId]??null;$employee=$this->employees->find((string)($entry['employeeId']??''));$sameEmployee=$employee!==null&&$old!==null&&(string)$old['employee_id']===(string)$employee['id'];if($employee===null||((int)$employee['active']!==1&&!$sameEmployee))throw new DailyReportValidationException(['Zeiteintrag '.($index+1).': Mitarbeiter ist nicht aktiv oder unbekannt.']);if(($actor['role']??'')==='technician'&&(string)$employee['user_id']!==(string)$actor['id'])throw new AccessDeniedException('reports.write.own');
            $date=(string)($entry['workDate']??$defaultDate);if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)||!checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4)))throw new DailyReportValidationException(['Zeiteintrag '.($index+1).': Arbeitsdatum ist ungültig.']);try{$duration=Decimal::parse((string)($entry['durationHours']??''),Decimal::TIME_SCALE,'Dauer');}catch(\InvalidArgumentException $e){throw new DailyReportValidationException(['Zeiteintrag '.($index+1).': '.$e->getMessage()]);}if($duration->isLessThanOrEqualTo(0))throw new DailyReportValidationException(['Zeiteintrag '.($index+1).': Dauer muss größer als null sein.']);if(!ReportTimeBookings::wholeMinutes($duration))throw new DailyReportValidationException(['Zeiteintrag '.($index+1).': Die Dauer muss ganzen Minuten entsprechen (z. B. 1,5 oder 0,25 Stunden).']);$description=trim((string)($entry['description']??''));if(mb_strlen($description)>1000)throw new DailyReportValidationException(['Zeiteintrag '.($index+1).': Tätigkeit darf höchstens 1.000 Zeichen lang sein.']);$rate=BigDecimal::of((string)($sameEmployee?$old['hourly_cost_rate_snapshot']:$employee['hourly_cost_rate']));$cost=Decimal::money($duration->multipliedBy($rate));
            $result[]=['id'=>$old!==null?$entryId:Uuid::v4(),'report_id'=>$reportId,'employee_id'=>$employee['id'],'work_date'=>$date,'duration_hours'=>(string)$duration->toScale(Decimal::TIME_SCALE,RoundingMode::HalfUp),'description'=>$description,// Alle Stunden folgen dem Kostenziel des Berichts (keine Positionszuordnung je Zeile).
'target_kind'=>'order','target_basis_type'=>null,'target_basis_id'=>null,'target_node_id'=>null,'hourly_cost_rate_snapshot'=>(string)$rate->toScale(Decimal::COST_SCALE,RoundingMode::HalfUp),'labor_cost'=>$cost,'created_at'=>(string)($old['created_at']??$now),'updated_at'=>$now,'updated_by_user_id'=>(string)$actor['id']];
        }return$result;
    }

    /**
     * Techniker: eigene Berichte immer (auch nach nachträglicher Zuordnung zu einem noch offenen
     * Auftrag), fremde nur an freigegebenen Aufträgen – fremde Berichte ohne Auftrag nie.
     *
     * @param array<string,mixed> $report @param array<string,mixed> $actor
     */
    private function assertReportAccess(array $report,array $actor):void{if(($actor['role']??'')==='technician'&&!$this->technicianMaySee($report,$actor))throw new AccessDeniedException('reports.released.read');}
    /** @param array<string,mixed> $report @param array<string,mixed> $actor */
    private function technicianMaySee(array $report,array $actor):bool{return(string)$report['created_by_user_id']===(string)$actor['id']||($report['order_id']!==null&&$this->orders->visibleTo((string)$report['order_id'],$actor));}
    /** Kostenstelle prüfen (gemeinsame Regel in CostTargets). */
    private function costCenter(mixed $value,?string $orderId,?string $current):?string{return(new CostTargets($this->pdo))->costCenter($value,$orderId,$current,static fn(string $message):DailyReportValidationException=>new DailyReportValidationException([$message]));}
    /** @param array<string,mixed> $actor */ private function can(array $actor,string $permission):bool{return \App\Security\RolePermissions::allows((string)($actor['role']??''),$permission);}
    /** @param array<string,mixed> $resource @param array<string,mixed> $actor */
    private function assertOrderAccess(array $resource,array $actor):void{if(($actor['role']??'')==='technician'&&!$this->orders->visibleTo((string)($resource['id']??''),$actor))throw new AccessDeniedException('reports.released.read');}
    /** @param array<string,mixed> $report @param array<string,mixed> $actor */
    private function canEdit(array $report,array $actor):bool{return in_array($report['status']??'draft',['draft','reopened'],true)&&(($actor['role']??'')!=='technician'||(string)$report['created_by_user_id']===(string)$actor['id']);}
    /** @param list<array<string,mixed>> $employees @param array<string,mixed> $actor @return list<array<string,mixed>> */
    private function visibleEmployees(array $employees,array $actor):array{if($this->canReadCosts($actor))return$employees;foreach($employees as &$employee)unset($employee['hourly_cost_rate']);unset($employee);return$employees;}
    /** @param array<string,mixed> $actor */ private function canReadCosts(array $actor):bool{return \App\Security\RolePermissions::allows((string)($actor['role']??''),'costs.read');}
}
