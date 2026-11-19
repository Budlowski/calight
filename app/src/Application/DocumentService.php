<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Document\DocumentValidationException;
use App\Http\AccessDeniedException;
use App\Infrastructure\Persistence\AttachmentRepository;
use App\Infrastructure\Persistence\AttachmentStore;
use App\Infrastructure\Persistence\DocumentRepository;
use App\Security\RolePermissions;
use App\Support\Clock;
use App\Support\ListQuery;
use App\Support\Uuid;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Zentrale Dokumentenübersicht und geschäftsbezogene Anhänge (Phase 3.4.2). Kein DMS.
 *
 * - Fachdokumente (Angebote, Bestellungen, Rapporte, Aufmaße, Bautagesberichte) bleiben in
 *   ihren Modulen; hier entsteht nur eine Leseprojektion mit Links auf deren Download-Routen.
 *   Je Quelle gelten die Leserechte des Fachmoduls (Lieferantenrechnungen zusätzlich costs.read).
 * - Allgemeine Anhänge werden einmal gespeichert und direkt Kunden, Ansprechpartnern,
 *   Projekten, Angeboten oder Aufträgen zugeordnet.
 * - Rechteregel für Anhänge: Lesen nur, wenn der Benutzer JEDES zugeordnete Objekt lesen
 *   darf (die strengste Zuordnung gilt, keine Rechteausweitung durch Mehrfachzuordnung).
 *   Hochladen und Zuordnen erfordern Schreibrecht auf jedes Zielobjekt; eine Zuordnung
 *   entfernen erfordert Schreibrecht auf dieses Objekt. Mit der letzten Zuordnung wird der
 *   Anhang samt Datei gelöscht.
 * - Die Übersicht und die Dokumentbereiche der Geschäftsobjekte setzen documents.read voraus
 *   (Büro/Admin). Techniker sehen ihre Dokumente weiterhin nur in den Fachmodulen, deren
 *   zeilenweise Einschränkungen hier nicht nachgebaut werden.
 */
final readonly class DocumentService
{
    public const TYPE_LABELS = [
        'attachment' => 'Anhang',
        'offer' => 'Angebot',
        'change_order' => 'Nachtrag',
        'purchase_order' => 'Bestellung',
        'supplier_document' => 'Lieferantenbeleg',
        'work_report' => 'Rapport',
        'work_report_evidence' => 'Rapport-Nachweis',
        'measurement' => 'Aufmaß',
        'daily_report' => 'Bautagesbericht',
        'daily_report_attachment' => 'Berichtsanhang',
    ];
    public const LIST_SORTS = ['date' => 'desc', 'title' => 'asc', 'type' => 'asc'];
    public const LIST_FILTERS = ['type', 'customer', 'contact', 'project', 'offer', 'order', 'period', 'format'];
    /** Zeitraum-Filter: Tage zurück ab heute bzw. laufendes Jahr. */
    public const PERIODS = ['30' => 'Letzte 30 Tage', '90' => 'Letzte 90 Tage', '365' => 'Letzte 12 Monate', 'year' => 'Dieses Jahr'];
    public const FORMATS = ['pdf' => 'PDF', 'image' => 'Bild'];
    /** Erlaubte Dateitypen (am Inhalt geprüft) – wie bei den übrigen Belegen, keine ausführbaren Dateien. */
    public const ALLOWED_TYPES = [
        'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
        'image/heic' => 'heic', 'image/heif' => 'heif',
    ];
    public const MAX_BYTES = 15 * 1024 * 1024;
    private const READ = ['customer' => 'customers.read', 'contact' => 'customers.read', 'project' => 'projects.read', 'offer' => 'offers.read', 'order' => 'orders.read'];
    private const WRITE = ['customer' => 'customers.write', 'contact' => 'customers.write', 'project' => 'projects.write', 'offer' => 'offers.write', 'order' => 'orders.write'];
    private const MAX_LINKS = 10;

    public function __construct(
        private PDO $pdo,
        private AttachmentRepository $attachments,
        private DocumentRepository $documents,
        private AttachmentStore $store,
    ) {
    }

    // -------------------------------------------------------------------------
    // Leseprojektion
    // -------------------------------------------------------------------------

    /** @param array<string,mixed> $parameters @param array<string,mixed> $actor @return array<string,mixed> */
    public function page(array $parameters, array $actor): array
    {
        $this->require($actor, 'documents.read');
        $query = ListQuery::fromArray($parameters, self::LIST_SORTS, 'date', self::LIST_FILTERS);
        $rows = $this->visibleDocuments($actor);
        $type = $query->filter('type');
        if ($type !== null && isset(self::TYPE_LABELS[$type])) {
            $rows = array_filter($rows, static fn (array $row): bool => $row['type'] === $type);
        }
        // Kontextfilter (direkt oder über das zugehörige Geschäftsobjekt).
        foreach (['customer', 'contact', 'project', 'offer', 'order'] as $context) {
            $value = $query->filter($context);
            if ($value !== null) {
                $rows = array_filter($rows, static fn (array $row): bool => in_array($value, $row['context'][$context] ?? [], true));
            }
        }
        $period = $query->filter('period');
        if ($period !== null && isset(self::PERIODS[$period])) {
            $from = $period === 'year' ? (new DateTimeImmutable('first day of january this year'))->format('Y-m-d') : (new DateTimeImmutable('-' . (int) $period . ' days'))->format('Y-m-d');
            $rows = array_filter($rows, static fn (array $row): bool => $row['date'] !== null && substr((string) $row['date'], 0, 10) >= $from);
        }
        $format = $query->filter('format');
        if ($format === 'pdf') {
            $rows = array_filter($rows, static fn (array $row): bool => $row['mimeType'] === 'application/pdf');
        } elseif ($format === 'image') {
            $rows = array_filter($rows, static fn (array $row): bool => str_starts_with((string) $row['mimeType'], 'image/'));
        }
        foreach ($query->searchTerms() as $term) {
            $needle = mb_strtolower($term);
            $rows = array_filter($rows, static fn (array $row): bool => str_contains(mb_strtolower($row['title'] . ' ' . $row['fileName'] . ' ' . $row['typeLabel'] . ' '
                . implode(' ', array_column($row['objects'], 'label')) . ' ' . $row['description']), $needle));
        }
        $rows = array_values($rows);
        $direction = $query->direction === 'asc' ? 1 : -1;
        usort($rows, static function (array $a, array $b) use ($query, $direction): int {
            $result = match ($query->sort) {
                'title' => strcasecmp($a['title'], $b['title']),
                'type' => strcmp($a['typeLabel'], $b['typeLabel']),
                default => strcmp((string) $a['date'], (string) $b['date']),
            };
            return ($result * $direction) ?: strcmp($a['id'], $b['id']);
        });
        $total = count($rows);
        $pageCount = max(1, (int) ceil($total / $query->perPage));
        $page = min($query->page, $pageCount);
        $items = array_map(static function (array $row): array {
            unset($row['context']);
            return $row;
        }, array_slice($rows, ($page - 1) * $query->perPage, $query->perPage));
        return ['items' => $items, 'total' => $total, 'page' => $page, 'perPage' => $query->perPage, 'pageCount' => $pageCount, 'sort' => $query->sort, 'dir' => $query->direction];
    }

    /**
     * Auswahllisten der Übersicht. Kunden, Projekte und Aufträge nur mit dem jeweiligen Leserecht.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function referenceData(array $actor): array
    {
        $this->require($actor, 'documents.read');
        return [
            'types' => self::TYPE_LABELS,
            'periods' => self::PERIODS,
            'formats' => self::FORMATS,
            'customers' => $this->can($actor, 'customers.read') ? $this->documents->customers() : [],
            'projects' => $this->can($actor, 'projects.read') ? $this->documents->projects() : [],
            'orders' => $this->can($actor, 'orders.read') ? $this->documents->orders() : [],
        ];
    }

    /**
     * Alle für den Benutzer sichtbaren Dokumente. Quellen ohne Leserecht entfallen vollständig
     * (keine Titel, Dateinamen oder Metadaten).
     *
     * @param array<string,mixed> $actor
     * @return list<array<string,mixed>>
     */
    private function visibleDocuments(array $actor): array
    {
        $rows = [];
        $context = static fn (?string $customer = null, ?string $project = null, ?string $order = null, ?string $offer = null): array => array_filter([
            'customer' => $customer !== null ? [$customer] : [], 'project' => $project !== null ? [$project] : [],
            'order' => $order !== null ? [$order] : [], 'offer' => $offer !== null ? [$offer] : [],
        ]);
        $row = static fn (string $id, string $type, string $title, string $fileName, string $mime, ?string $date, array $objects, string $url, array $ctx, string $description = '', bool $removable = false): array => [
            'id' => $id, 'type' => $type, 'typeLabel' => self::TYPE_LABELS[$type], 'title' => $title, 'fileName' => $fileName,
            'mimeType' => $mime, 'date' => $date, 'objects' => $objects, 'url' => $url, 'context' => $ctx, 'description' => $description, 'removable' => $removable,
        ];

        if ($this->can($actor, 'offers.read')) {
            foreach ($this->documents->offerPdfs() as $offer) {
                $changeOrder = $offer['document_type'] === 'change_order';
                $number = (string) ($offer['offer_number'] ?? '');
                $revision = (int) ($offer['revision_number'] ?? 0);
                $title = ($changeOrder ? 'Nachtrag ' : 'Angebot ') . $number . ($revision > 0 ? ' · Revision ' . $revision : '');
                $rows[] = $row('offer:' . $offer['id'], $changeOrder ? 'change_order' : 'offer', $title, $title . '.pdf', 'application/pdf', $offer['submitted_at'],
                    [['label' => trim(($changeOrder ? 'Nachtrag ' : 'Angebot ') . $number . ' ' . (string) $offer['list_title']), 'href' => '/offers/' . rawurlencode((string) $offer['id'])],
                     ...($offer['customer_id'] !== null ? [['label' => 'Kunde ' . (string) $offer['customer_name'], 'href' => '/customers/' . rawurlencode((string) $offer['customer_id'])]] : [])],
                    '/api/offers/' . rawurlencode((string) $offer['id']) . '/submitted-pdf', $context($offer['customer_id'], $offer['project_id'], $offer['order_id'], (string) $offer['id']));
            }
        }
        if ($this->can($actor, 'purchases.read')) {
            $costs = $this->can($actor, 'costs.read');
            foreach ($this->documents->purchaseDocuments() as $document) {
                // Rechnungsbelege und eigene Bestell-PDFs (enthalten Preise) nur mit Kostenrecht.
                if (in_array($document['kind'], ['invoice', 'own_order'], true) && !$costs) {
                    continue;
                }
                $own = $document['kind'] === 'own_order';
                $title = $own ? 'Bestellung ' . $document['order_number'] . ($document['revision'] > 1 ? ' · Fassung ' . $document['revision'] : '') : (string) $document['original_name'];
                $rows[] = $row('purchase:' . $document['id'], $own ? 'purchase_order' : 'supplier_document', $title, (string) $document['original_name'], (string) $document['mime_type'], $document['created_at'],
                    [['label' => 'Bestellung ' . $document['order_number'] . ' · ' . $document['supplier_name'], 'href' => '/purchase-orders/' . rawurlencode((string) $document['purchase_order_id'])]],
                    '/api/purchase-orders/' . rawurlencode((string) $document['purchase_order_id']) . '/documents/' . rawurlencode((string) $document['id']),
                    $context($document['customer_id'], $document['project_id'], $document['order_id']));
            }
        }
        if ($this->can($actor, 'workreports.read')) {
            foreach ($this->documents->workReports() as $report) {
                $objects = [['label' => 'Rapport ' . $report['report_number'], 'href' => '/work-reports/' . rawurlencode((string) $report['id'])],
                    ['label' => 'Kunde ' . $report['customer_name'], 'href' => '/customers/' . rawurlencode((string) $report['customer_id'])]];
                $ctx = $context($report['customer_id'], $report['project_id'], $report['order_id']);
                $rows[] = $row('work-report:' . $report['id'], 'work_report', 'Rapport ' . $report['report_number'], 'Rapport_' . $report['report_number'] . '.pdf', 'application/pdf',
                    $report['submitted_at'], $objects, '/api/work-reports/' . rawurlencode((string) $report['id']) . '/pdf', $ctx);
                if ($report['confirmation_evidence_path'] !== null) {
                    $rows[] = $row('work-report-evidence:' . $report['id'], 'work_report_evidence', 'Bestätigung Rapport ' . $report['report_number'],
                        (string) $report['confirmation_evidence_name'], (string) $report['confirmation_evidence_mime'], $report['confirmed_at'], $objects,
                        '/api/work-reports/' . rawurlencode((string) $report['id']) . '/confirmation-evidence', $ctx);
                }
            }
        }
        if ($this->can($actor, 'measurements.read')) {
            foreach ($this->documents->measurements() as $measurement) {
                $rows[] = $row('measurement:' . $measurement['id'], 'measurement', 'Aufmaß ' . $measurement['measurement_number'], 'Aufmass_' . $measurement['measurement_number'] . '.pdf',
                    'application/pdf', $measurement['completed_at'],
                    [['label' => 'Aufmaß ' . $measurement['measurement_number'] . ($measurement['title'] !== '' ? ' · ' . $measurement['title'] : ''), 'href' => '/measurements/' . rawurlencode((string) $measurement['id'])],
                     ['label' => 'Auftrag ' . $measurement['order_number'], 'href' => '/orders/' . rawurlencode((string) $measurement['order_id'])]],
                    '/api/measurements/' . rawurlencode((string) $measurement['id']) . '/pdf', $context($measurement['customer_id'], $measurement['project_id'], $measurement['order_id']));
            }
        }
        if ($this->can($actor, 'reports.read')) {
            foreach ($this->documents->dailyReports() as $report) {
                $date = (new DateTimeImmutable((string) $report['report_date']))->format('d.m.Y');
                $rows[] = $row('daily-report:' . $report['id'], 'daily_report', 'Bautagesbericht ' . $date . ($report['title'] !== '' ? ' · ' . $report['title'] : ''),
                    'Bautagesbericht_' . $report['report_date'] . '.pdf', 'application/pdf', $report['report_date'],
                    [['label' => 'Auftrag ' . $report['order_number'], 'href' => '/orders/' . rawurlencode((string) $report['order_id'])]],
                    '/api/daily-reports/' . rawurlencode((string) $report['id']) . '/pdf', $context($report['customer_id'], $report['project_id'], $report['order_id']));
            }
            foreach ($this->documents->dailyReportAttachments() as $attachment) {
                $rows[] = $row('daily-report-attachment:' . $attachment['id'], 'daily_report_attachment', (string) $attachment['original_name'], (string) $attachment['original_name'],
                    (string) $attachment['mime_type'], $attachment['created_at'],
                    [['label' => 'Bautagesbericht ' . (new DateTimeImmutable((string) $attachment['report_date']))->format('d.m.Y'), 'href' => '/daily-reports/' . rawurlencode((string) $attachment['report_id'])],
                     ['label' => 'Auftrag ' . $attachment['order_number'], 'href' => '/orders/' . rawurlencode((string) $attachment['order_id'])]],
                    '/api/daily-reports/' . rawurlencode((string) $attachment['report_id']) . '/attachments/' . rawurlencode((string) $attachment['id']),
                    $context($attachment['customer_id'], $attachment['project_id'], $attachment['order_id']));
            }
        }
        foreach ($this->attachments->allWithLinks() as $attachment) {
            $resolved = $this->readableTargets($attachment, $actor);
            if ($resolved === null) {
                continue;
            }
            $ctx = ['customer' => [], 'contact' => [], 'project' => [], 'offer' => [], 'order' => []];
            foreach ($resolved as $target) {
                $ctx[$target['type']][] = $target['id'];
                // Indirekter Kontext: Objekt gehört zu Kunde/Projekt/Auftrag.
                foreach (['customer' => 'customer_id', 'project' => 'project_id', 'order' => 'order_id'] as $key => $column) {
                    if ($target[$column] !== null) {
                        $ctx[$key][] = $target[$column];
                    }
                }
            }
            $rows[] = $row('attachment:' . $attachment['id'], 'attachment', $attachment['title'] !== '' ? (string) $attachment['title'] : (string) $attachment['original_name'],
                (string) $attachment['original_name'], (string) $attachment['mime_type'], $attachment['created_at'],
                array_map(static fn (array $target): array => ['label' => $target['label'], 'href' => $target['href'], 'type' => $target['type'], 'id' => $target['id'],
                    'canUnlink' => $target['canWrite']], $resolved),
                '/api/attachments/' . rawurlencode((string) $attachment['id']), array_map(static fn (array $ids): array => array_values(array_unique($ids)), $ctx),
                (string) $attachment['description'], true);
        }
        return $rows;
    }

    /**
     * Vorhandene Zuordnungen eines Anhangs, sofern der Benutzer ALLE lesen darf; sonst null.
     * Zuordnungen auf nicht mehr vorhandene Objekte zählen nicht; ohne verbleibende Zuordnung null.
     *
     * @param array<string,mixed> $attachment
     * @param array<string,mixed> $actor
     * @return list<array<string,mixed>>|null
     */
    private function readableTargets(array $attachment, array $actor): ?array
    {
        $links = array_filter(explode('|', (string) ($attachment['link_list'] ?? '')), static fn (string $value): bool => $value !== '');
        $result = [];
        foreach ($links as $link) {
            [$type, $id] = explode(':', $link, 2) + [1 => ''];
            $target = $this->attachments->target($type, $id);
            if ($target === null) {
                continue;
            }
            if (!$this->canReadTarget($actor, $type, $target)) {
                return null;
            }
            $result[] = $target + ['type' => $type, 'id' => $id, 'canWrite' => $this->can($actor, self::WRITE[$type])];
        }
        return $result === [] ? null : $result;
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $target */
    private function canReadTarget(array $actor, string $type, array $target): bool
    {
        if (!$this->can($actor, self::READ[$type] ?? '')) {
            return false;
        }
        // Wie in den Fachmodulen: Techniker sehen nur freigegebene Aufträge.
        return !($type === 'order' && ($actor['role'] ?? '') === 'technician' && $target['status'] === 'open');
    }

    // -------------------------------------------------------------------------
    // Allgemeine Anhänge
    // -------------------------------------------------------------------------

    /**
     * Lädt einen Anhang hoch und ordnet ihn den angegebenen Objekten zu (mindestens eines).
     *
     * @param list<array{type:string,id:string}> $targets
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function upload(string $sourcePath, string $originalName, string $title, string $description, array $targets, array $actor): array
    {
        $this->require($actor, 'documents.read');
        $targets = $this->validTargets($targets, $actor);
        $title = $this->text($title, 200, 'Titel');
        $description = $this->text($description, 1000, 'Beschreibung');
        $size = is_file($sourcePath) ? (int) filesize($sourcePath) : 0;
        if ($size <= 0) {
            throw new DocumentValidationException(['Die Datei ist leer oder fehlt.']);
        }
        if ($size > self::MAX_BYTES) {
            throw new DocumentValidationException(['Dokumente dürfen höchstens 15 MB groß sein.']);
        }
        // Typ am Inhalt erkennen, nicht am Dateinamen oder an der Browserangabe.
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($sourcePath);
        $extension = self::ALLOWED_TYPES[$mime] ?? null;
        if ($extension === null) {
            throw new DocumentValidationException(['Dieser Dateityp wird nicht unterstützt. Erlaubt sind PDF und Bilder (JPG, PNG, WebP, GIF, HEIC).']);
        }
        $id = Uuid::v4();
        $now = Clock::now();
        $sha256 = (string) hash_file('sha256', $sourcePath);
        $path = $this->store->store($sourcePath, 'attachments/' . substr($now, 0, 4) . '/' . substr($now, 5, 2), $id, $extension);
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $this->attachments->insert([
                'id' => $id, 'original_name' => self::fileName($originalName, $extension), 'title' => $title, 'description' => $description,
                'mime_type' => $mime, 'size_bytes' => $size, 'sha256' => $sha256, 'storage_path' => $path,
                'uploaded_by_user_id' => (string) $actor['id'], 'created_at' => $now,
            ]);
            foreach ($targets as $target) {
                $this->attachments->link($id, $target['type'], $target['id'], (string) $actor['id'], $now);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->discard($path);
            throw $exception;
        }
        return ['id' => $id];
    }

    /** @param array<string,mixed> $actor @return array{contents:string,name:string,mimeType:string,inline:bool} */
    public function file(string $id, array $actor): array
    {
        $this->require($actor, 'documents.read');
        $attachment = $this->readable($id, $actor);
        try {
            $contents = $this->store->read((string) $attachment['storage_path']);
        } catch (Throwable) {
            throw new DocumentValidationException(['Die Datei ist nicht mehr verfügbar.']);
        }
        return [
            'contents' => $contents, 'name' => (string) $attachment['original_name'], 'mimeType' => (string) $attachment['mime_type'],
            'inline' => in_array($attachment['mime_type'], ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'], true),
        ];
    }

    /**
     * Entfernt eine Zuordnung. Ist es die letzte, wird der Anhang samt Datei gelöscht –
     * andere Zuordnungen und Fachdokumente bleiben unberührt.
     *
     * @param array<string,mixed> $actor
     * @return array{deleted:bool}
     */
    public function unlink(string $id, string $type, string $targetId, array $actor): array
    {
        $this->require($actor, 'documents.read');
        $attachment = $this->readable($id, $actor);
        if (!isset(self::WRITE[$type]) || !$this->can($actor, self::WRITE[$type])) {
            throw new AccessDeniedException(self::WRITE[$type] ?? 'documents.read');
        }
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if (!$this->attachments->unlink($id, $type, $targetId)) {
                throw new DocumentValidationException(['Diese Zuordnung besteht nicht.']);
            }
            $remaining = $this->attachments->links($id);
            if ($remaining === []) {
                $this->attachments->delete($id);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        if ($remaining === []) {
            $this->discard((string) $attachment['storage_path']);
        }
        return ['deleted' => $remaining === []];
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    private function readable(string $id, array $actor): array
    {
        $attachment = $this->attachments->find($id) ?? throw new DocumentValidationException(['Das Dokument wurde nicht gefunden.']);
        $links = array_map(static fn (array $link): string => $link['target_type'] . ':' . $link['target_id'], $this->attachments->links($id));
        if ($this->readableTargets(['link_list' => implode('|', $links)], $actor) === null) {
            throw new AccessDeniedException('documents.read');
        }
        return $attachment;
    }

    /**
     * Ziele prüfen: bekannter Typ, Objekt existiert, Schreibrecht, keine Dubletten.
     *
     * @param array<mixed> $targets
     * @param array<string,mixed> $actor
     * @return list<array{type:string,id:string}>
     */
    private function validTargets(array $targets, array $actor): array
    {
        $result = [];
        foreach ($targets as $target) {
            $type = (string) (is_array($target) ? ($target['type'] ?? '') : '');
            $id = trim((string) (is_array($target) ? ($target['id'] ?? '') : ''));
            if (!isset(self::WRITE[$type]) || $id === '') {
                throw new DocumentValidationException(['Ungültige Zuordnung.']);
            }
            if (!$this->can($actor, self::WRITE[$type])) {
                throw new AccessDeniedException(self::WRITE[$type]);
            }
            $object = $this->attachments->target($type, $id) ?? throw new DocumentValidationException(['Das zugeordnete Objekt wurde nicht gefunden.']);
            if (!$this->canReadTarget($actor, $type, $object)) {
                throw new AccessDeniedException(self::READ[$type]);
            }
            $result[$type . ':' . $id] = ['type' => $type, 'id' => $id];
        }
        if ($result === []) {
            throw new DocumentValidationException(['Ein Dokument braucht mindestens eine Zuordnung.']);
        }
        if (count($result) > self::MAX_LINKS) {
            throw new DocumentValidationException(['Höchstens ' . self::MAX_LINKS . ' Zuordnungen je Dokument.']);
        }
        return array_values($result);
    }

    private function text(string $value, int $max, string $label): string
    {
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            throw new DocumentValidationException([$label . ' darf höchstens ' . $max . ' Zeichen lang sein.']);
        }
        return $value;
    }

    /** Sicherer Anzeigename ohne Pfadanteile; die Endung folgt dem erkannten Typ. */
    public static function fileName(string $name, string $extension): string
    {
        $base = trim((string) preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', pathinfo(basename(str_replace('\\', '/', $name)), PATHINFO_FILENAME)));
        $base = ltrim($base, '.');
        return mb_substr($base !== '' ? $base : 'Dokument', 0, 120) . '.' . $extension;
    }

    private function discard(string $path): void
    {
        try {
            $this->store->delete($path);
        } catch (Throwable) {
            // Verwaiste Datei ist unkritisch.
        }
    }

    /** @param array<string,mixed> $actor */
    private function require(array $actor, string $permission): void
    {
        if (!$this->can($actor, $permission)) {
            throw new AccessDeniedException($permission);
        }
    }

    /** @param array<string,mixed> $actor */
    private function can(array $actor, string $permission): bool
    {
        return $permission !== '' && RolePermissions::allows((string) ($actor['role'] ?? ''), $permission);
    }
}
