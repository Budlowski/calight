<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Document\DocumentValidationException;
use PDO;

/**
 * Analyse einer Datenbereinigung (Einstellungen → System → Datenbereinigung).
 *
 * Bestimmt je ausgewähltem Datenbereich die betroffenen Zeilen (temporäre Tabelle
 * temp.purge_set, je Tabelle über rowid) und prüft danach generisch über PRAGMA
 * foreign_key_list, dass keine nicht ausgewählte Zeile auf eine zu löschende verweist – weder
 * blockierend (RESTRICT/NO ACTION) noch über stille Kaskaden (CASCADE/SET NULL). Zusätzlich:
 * gesperrte Zeiterfassungstage, Einreichungen über Zeitraumgrenzen, Dokumente, die nur noch mit
 * gelöschten Vorgängen verknüpft wären, Dateien und optionale Nummernkreise.
 *
 * Bereichsauswahl erfolgt ausschließlich über die hier fest definierten Abfragen; Nutzereingaben
 * (Bereiche, Zeitraum) werden gegen Whitelists geprüft bzw. gebunden. Es gibt keinen allgemeinen
 * Tabellen- oder Datumsfilter. Tabellen- und Spaltennamen stammen ausschließlich aus Konstanten
 * bzw. aus PRAGMA-Abfragen des Schemas.
 */
final class SystemPurgePlanner
{
    /** Bereiche: Schlüssel => [Gruppe, Bezeichnung, Zeitraum unterstützt]. Reihenfolge = Auswahlreihenfolge. */
    public const AREAS = [
        'customers' => ['business', 'Kunden', false],
        'projects' => ['business', 'Projekte', false],
        'orders' => ['business', 'Aufträge einschließlich Nachträge', false],
        'offers' => ['business', 'Angebote einschließlich Revisionen', false],
        'measurements' => ['business', 'Aufmaße', false],
        'work_reports' => ['business', 'Rapporte einschließlich Korrekturen', true],
        'daily_reports' => ['business', 'Tagesberichte', true],
        'time' => ['business', 'Zeiterfassung einschließlich Abwesenheiten und Freigaben', true],
        'travel' => ['business', 'Reisekosten und Auslagen', true],
        'purchases' => ['business', 'Bestellungen einschließlich Belege, Lieferungen und Kostenverteilungen', false],
        'materials' => ['business', 'Materialanforderungen', false],
        'documents' => ['business', 'Sonstige Geschäftsdokumente', false],
        'employees' => ['master', 'Mitarbeiter', false],
        'suppliers' => ['master', 'Lieferanten', false],
        'cost_items' => ['master', 'Artikelstamm', false],
        'service_templates' => ['master', 'Positionsstamm', false],
        'categories_texts' => ['master', 'Kategorien und Schlepptexte', false],
        'cost_centers' => ['master', 'Kostenstellen', false],
    ];

    public const PERIOD_REASON = 'Ohne eindeutiges fachliches Datum bzw. mit zeitraumübergreifenden Abhängigkeiten (z. B. Kunden, Aufträge, Belege) nur vollständig bereinigbar.';

    /** Tabelle => Bereich (für verständliche Konfliktmeldungen). */
    private const TABLE_AREAS = [
        'customers' => 'customers', 'customer_contacts' => 'customers', 'customer_addresses' => 'customers',
        'projects' => 'projects',
        'offers' => 'offers',
        'orders' => 'orders', 'order_status_history' => 'orders', 'order_project_history' => 'orders', 'order_employees' => 'orders', 'order_addenda' => 'orders',
        'measurements' => 'measurements', 'measurement_items' => 'measurements', 'measurement_source_totals' => 'measurements',
        'work_reports' => 'work_reports', 'work_report_labor' => 'work_reports', 'work_report_materials' => 'work_reports', 'work_report_billing_items' => 'work_reports',
        'daily_reports' => 'daily_reports', 'daily_report_time_entries' => 'daily_reports', 'daily_report_events' => 'daily_reports',
        'daily_report_order_events' => 'daily_reports', 'daily_report_attachments' => 'daily_reports', 'daily_report_statements' => 'daily_reports',
        'time_entries' => 'time', 'time_absences' => 'time', 'time_day_locks' => 'time', 'time_submissions' => 'time',
        'time_lock_events' => 'time', 'time_statements' => 'time',
        'time_day_details' => 'travel', 'time_private_trips' => 'travel', 'time_expenses' => 'travel',
        'purchase_orders' => 'purchases', 'purchase_order_allocations' => 'purchases', 'purchase_order_confirmations' => 'purchases',
        'purchase_order_deliveries' => 'purchases', 'purchase_order_documents' => 'purchases', 'purchase_order_history' => 'purchases',
        'purchase_order_invoices' => 'purchases', 'purchase_order_items' => 'purchases', 'purchase_order_material_requests' => 'purchases',
        'purchase_order_payments' => 'purchases',
        'material_requests' => 'materials',
        'attachments' => 'documents', 'attachment_links' => 'documents',
        'employees' => 'employees', 'suppliers' => 'suppliers', 'cost_items' => 'cost_items',
        'service_templates' => 'service_templates', 'service_template_components' => 'service_templates',
        'master_categories' => 'categories_texts', 'trailing_texts' => 'categories_texts',
        'cost_centers' => 'cost_centers',
    ];

    /** Verständliche Tabellenbezeichnungen für die Vorschau. */
    public const TABLE_LABELS = [
        'customers' => 'Kunden', 'customer_contacts' => 'Ansprechpartner', 'customer_addresses' => 'Kundenadressen',
        'projects' => 'Projekte', 'offers' => 'Angebote/Nachträge (alle Revisionen)', 'orders' => 'Aufträge',
        'order_status_history' => 'Auftragsstatusverlauf', 'order_project_history' => 'Auftragsprojektverlauf', 'order_employees' => 'Mitarbeiterzuordnungen zu Aufträgen', 'order_addenda' => 'Angenommene Nachträge',
        'measurements' => 'Aufmaße', 'measurement_items' => 'Aufmaßpositionen', 'measurement_source_totals' => 'Aufmaßsummen',
        'work_reports' => 'Rapporte', 'work_report_labor' => 'Rapport-Stundenpositionen', 'work_report_materials' => 'Rapport-Materialpositionen',
        'work_report_billing_items' => 'Rapport-Abrechnungspositionen',
        'daily_reports' => 'Tagesberichte', 'daily_report_time_entries' => 'Tagesbericht-Stundenpositionen', 'daily_report_events' => 'Tagesbericht-Protokoll',
        'daily_report_order_events' => 'Tagesbericht-Zuordnungsprotokoll', 'daily_report_attachments' => 'Tagesbericht-Anhänge', 'daily_report_statements' => 'Festgeschriebene Tagesberichtsfassungen',
        'time_entries' => 'Zeitbuchungen', 'time_absences' => 'Abwesenheiten', 'time_day_locks' => 'Tagessperren der Zeiterfassung',
        'time_submissions' => 'Einreichungen/Freigaben', 'time_lock_events' => 'Freigabeprotokoll', 'time_statements' => 'Stundennachweise',
        'time_day_details' => 'Auswärtstätigkeit/Verpflegung je Tag', 'time_private_trips' => 'Privat-Pkw-Fahrten', 'time_expenses' => 'Auslagen',
        'purchase_orders' => 'Bestellungen', 'purchase_order_allocations' => 'Kostenverteilungen', 'purchase_order_confirmations' => 'Bestellbestätigungen',
        'purchase_order_deliveries' => 'Lieferungen', 'purchase_order_documents' => 'Bestellbelege', 'purchase_order_history' => 'Bestellprotokoll',
        'purchase_order_invoices' => 'Rechnungen und Gutschriften', 'purchase_order_items' => 'Bestellpositionen',
        'purchase_order_material_requests' => 'Verknüpfungen Bestellung–Materialanforderung', 'purchase_order_payments' => 'Zahlungen',
        'material_requests' => 'Materialanforderungen', 'attachments' => 'Dokumente', 'attachment_links' => 'Dokumentverknüpfungen',
        'generated_documents' => 'Archivierte PDF-Dokumente', 'document_locks' => 'Bearbeitungssperren', 'assigned_numbers' => 'Vergebene Nummern',
        'employees' => 'Mitarbeiter', 'suppliers' => 'Lieferanten', 'cost_items' => 'Artikel', 'service_templates' => 'Stammpositionen',
        'service_template_components' => 'Bestandteile von Stammpositionen', 'master_categories' => 'Kategorien', 'trailing_texts' => 'Schlepptexte',
        'cost_centers' => 'Kostenstellen',
    ];

    /** Spalten mit Dateiverweisen relativ zu APP_DOCUMENTS_PATH. */
    private const DOCUMENT_FILE_COLUMNS = [
        ['attachments', 'storage_path'], ['daily_report_attachments', 'storage_path'], ['daily_report_statements', 'storage_path'],
        ['time_statements', 'storage_path'], ['time_expenses', 'receipt_path'], ['purchase_order_documents', 'storage_path'],
        ['generated_documents', 'storage_path'], ['work_reports', 'confirmation_evidence_path'],
    ];

    /** Vergabetyp in assigned_numbers => Tabelle des Datensatzes. */
    private const NUMBER_ENTITIES = [
        'customer' => 'customers', 'project' => 'projects', 'offer' => 'offers', 'change_order' => 'offers', 'order' => 'orders',
        'measurement' => 'measurements', 'work_report' => 'work_reports', 'purchase_order' => 'purchase_orders',
        'service_template' => 'service_templates', 'supplier' => 'suppliers', 'cost_item' => 'cost_items',
    ];

    /** Bleibt immer erhalten (Anzeige in der Vorschau). */
    public const PRESERVED = [
        'Benutzerkonten, Rollen und Berechtigungen',
        'Firmen-, Dokument- und Betriebseinstellungen sowie Modulkonfiguration',
        'Nummernkreiskonfiguration (Zählerstände nur mit ausdrücklicher Option)',
        'Migrationen, Benutzer- und Sicherheitsprotokolle sowie Bereinigungsprotokolle',
        'Sicherungen',
    ];

    public function __construct(private PDO $pdo, private string $documentsPath, private string $offersPath)
    {
    }

    /**
     * Prüft und normalisiert die Auswahl.
     *
     * @param array<string,mixed> $input
     * @return array{areas:list<string>,mode:string,from:?string,to:?string,resetNumbers:bool}
     */
    public static function selection(array $input): array
    {
        $raw = $input['areas'] ?? [];
        if (!is_array($raw)) {
            throw new DocumentValidationException(['Bitte die Datenbereiche als Liste angeben.']);
        }
        $areas = [];
        foreach ($raw as $area) {
            if (!is_string($area) || !isset(self::AREAS[$area])) {
                throw new DocumentValidationException(['Unbekannter Datenbereich.']);
            }
            $areas[$area] = true;
        }
        // Feste Reihenfolge, damit gleiche Auswahl denselben Fingerabdruck ergibt.
        $areas = array_values(array_filter(array_keys(self::AREAS), static fn (string $key): bool => isset($areas[$key])));
        $resetNumbers = ($input['resetNumbers'] ?? false) === true;
        if ($areas === [] && !$resetNumbers) {
            throw new DocumentValidationException(['Bitte mindestens einen Datenbereich auswählen.']);
        }
        $mode = (string) ($input['mode'] ?? 'all');
        if (!in_array($mode, ['all', 'period'], true)) {
            throw new DocumentValidationException(['Ungültiger Modus.']);
        }
        $from = $to = null;
        if ($mode === 'period') {
            $from = self::date($input['from'] ?? null, 'Von');
            $to = self::date($input['to'] ?? null, 'Bis');
            if ($from > $to) {
                throw new DocumentValidationException(['„Von“ darf nicht nach „Bis“ liegen.']);
            }
            $unsupported = array_filter($areas, static fn (string $key): bool => !self::AREAS[$key][2]);
            if ($unsupported !== []) {
                throw new DocumentValidationException(['Ein Zeitraum ist nur für Rapporte, Tagesberichte, Zeiterfassung sowie Reisekosten und Auslagen möglich. Nicht unterstützt: '
                    . implode(', ', array_map(static fn (string $key): string => self::AREAS[$key][1], $unsupported)) . '. ' . self::PERIOD_REASON]);
            }
            if ($resetNumbers) {
                throw new DocumentValidationException(['Nummernkreise können nur bei vollständiger Bereinigung zurückgesetzt werden.']);
            }
        }
        return ['areas' => $areas, 'mode' => $mode, 'from' => $from, 'to' => $to, 'resetNumbers' => $resetNumbers];
    }

    /**
     * Analysiert die Auswahl. Ergebnis enthält Zählungen, Konflikte, Dateien, Nummernkreise und den
     * Fingerabdruck über genau die betroffenen Datensätze. Die Zeilen bleiben in temp.purge_set
     * (für die anschließende Ausführung in derselben Transaktion).
     *
     * @param array{areas:list<string>,mode:string,from:?string,to:?string,resetNumbers:bool} $selection
     * @return array<string,mixed>
     */
    public function analyze(array $selection): array
    {
        $this->pdo->exec('CREATE TEMP TABLE IF NOT EXISTS purge_set (tbl TEXT NOT NULL, rid INTEGER NOT NULL, PRIMARY KEY (tbl, rid))');
        $this->pdo->exec('DELETE FROM temp.purge_set');
        $period = $selection['mode'] === 'period' ? ['from' => $selection['from'], 'to' => $selection['to']] : null;
        $selected = array_fill_keys($selection['areas'], true);
        $blockers = [];
        foreach (array_keys(self::AREAS) as $area) {
            if (isset($selected[$area])) {
                $this->selectArea($area, $period, $blockers);
            }
        }
        $this->selectDependentLinks();
        $numbers = $this->numberRanges($selection['resetNumbers']);
        $blockers = array_merge($blockers, $this->foreignKeyConflicts($selected, $period !== null), $this->lockConflicts(), $this->orphanedDocuments());
        $files = $this->files();
        $blockers = array_merge($blockers, $files['blockers']);

        $counts = $this->counts();
        $areas = [];
        foreach ($selection['areas'] as $area) {
            $areas[$area] = ['key' => $area, 'label' => self::AREAS[$area][1], 'tables' => [], 'total' => 0];
        }
        $additional = ['key' => 'additional', 'label' => 'Zusätzlich abhängige Datensätze', 'tables' => [], 'total' => 0];
        foreach ($counts as $table => $count) {
            $owner = self::TABLE_AREAS[$table] ?? null;
            $entry = ['table' => $table, 'label' => self::TABLE_LABELS[$table] ?? $table, 'count' => $count];
            if ($owner !== null && isset($areas[$owner])) {
                $areas[$owner]['tables'][] = $entry;
                $areas[$owner]['total'] += $count;
            } else {
                $additional['tables'][] = $entry;
                $additional['total'] += $count;
            }
        }
        $areaList = array_values($areas);
        if ($additional['tables'] !== []) {
            $areaList[] = $additional;
        }
        return [
            'selection' => $selection,
            'areas' => $areaList,
            'total' => array_sum($counts),
            'counts' => $counts,
            'files' => ['delete' => $files['delete'], 'count' => count($files['delete']), 'bytes' => $files['bytes'], 'missing' => $files['missing'],
                'kept' => $files['kept'], 'offerDirectories' => $files['offerDirectories']],
            'numberRanges' => $numbers,
            'blockers' => array_values(array_unique($blockers)),
            'preserved' => self::PRESERVED,
            'fingerprint' => $this->fingerprint($selection, $counts, $blockers, $numbers),
        ];
    }

    /** Tabellen der aktuellen Auswahl in sicherer Löschreihenfolge (siehe SystemPurgeService). @return list<string> */
    public function selectedTables(): array
    {
        return array_map('strval', $this->pdo->query('SELECT DISTINCT tbl FROM temp.purge_set ORDER BY tbl')->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Eingehende Fremdschlüssel aller Tabellen: Elterntabelle => Liste [Kindtabelle, Kindspalte,
     * Elternspalte, ON DELETE].
     *
     * @return array<string,list<array{0:string,1:string,2:string,3:string}>>
     */
    public function incomingForeignKeys(): array
    {
        $incoming = [];
        foreach ($this->tables() as $child) {
            foreach ($this->pdo->query('PRAGMA foreign_key_list(' . self::quote($child) . ')')->fetchAll() as $key) {
                $parent = (string) $key['table'];
                $to = $key['to'] !== null && $key['to'] !== '' ? (string) $key['to'] : $this->primaryKey($parent);
                $incoming[$parent][] = [$child, (string) $key['from'], $to, strtoupper((string) $key['on_delete'])];
            }
        }
        return $incoming;
    }

    /** @return list<string> */
    public function tables(): array
    {
        return array_map('strval', $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /** Teilabfrage: Werte einer Spalte aller ausgewählten Zeilen einer Tabelle. */
    public static function selectedValues(string $table, string $column): string
    {
        return sprintf('(SELECT p.%s FROM %s p JOIN temp.purge_set s ON s.tbl = %s AND s.rid = p.rowid)', self::quote($column), self::quote($table), self::literal($table));
    }

    private static function literal(string $table): string
    {
        return "'" . str_replace("'", "''", $table) . "'";
    }

    // -------------------------------------------------------------------------
    // Bereichsauswahl
    // -------------------------------------------------------------------------

    /** @param array{from:?string,to:?string}|null $period @param list<string> $blockers */
    private function selectArea(string $area, ?array $period, array &$blockers): void
    {
        $in = static fn (string $table, string $column = 'id'): string => self::selectedValues($table, $column);
        switch ($area) {
            case 'customers':
                $this->add('customers');
                $this->add('customer_contacts');
                $this->add('customer_addresses');
                break;
            case 'projects':
                $this->add('projects');
                break;
            case 'orders':
                $this->add('orders');
                $this->add('order_status_history');
                $this->add('order_project_history');
                $this->add('order_employees');
                $this->add('order_addenda');
                // Nachträge sind Angebotsdokumente eines Auftrags.
                $this->add('offers', "document_type = 'change_order'");
                break;
            case 'offers':
                $this->add('offers', "document_type = 'offer'");
                break;
            case 'measurements':
                $this->add('measurements');
                $this->add('measurement_items');
                $this->add('measurement_source_totals');
                $this->add('generated_documents', "document_type = 'measurement' AND entity_id IN " . $in('measurements'));
                break;
            case 'work_reports':
                if ($period === null) {
                    $this->add('work_reports');
                } else {
                    // Vollständig im Zeitraum: Leistungszeitraum und jedes Positionsdatum.
                    $this->add('work_reports', 'work_date >= :from AND COALESCE(work_date_to, work_date) <= :to AND NOT EXISTS (SELECT 1 FROM work_report_labor l
                        WHERE l.report_id = work_reports.id AND l.work_date IS NOT NULL AND (l.work_date < :from OR l.work_date > :to))', $period);
                }
                $this->add('work_report_labor', 'report_id IN ' . $in('work_reports'));
                $this->add('work_report_materials', 'report_id IN ' . $in('work_reports'));
                $this->add('work_report_billing_items', 'report_id IN ' . $in('work_reports'));
                $this->add('time_entries', 'work_report_labor_id IN ' . $in('work_report_labor'));
                $this->add('generated_documents', "document_type = 'work_report' AND entity_id IN " . $in('work_reports'));
                break;
            case 'daily_reports':
                $period === null ? $this->add('daily_reports') : $this->add('daily_reports', 'report_date >= :from AND report_date <= :to', $period);
                foreach (['daily_report_time_entries', 'daily_report_events', 'daily_report_order_events', 'daily_report_attachments', 'daily_report_statements'] as $table) {
                    $this->add($table, 'report_id IN ' . $in('daily_reports'));
                }
                $this->add('time_entries', 'daily_report_entry_id IN ' . $in('daily_report_time_entries'));
                break;
            case 'time':
                if ($period === null) {
                    $this->add('time_entries', "source_kind = 'manual'");
                    foreach (['time_absences', 'time_submissions', 'time_day_locks', 'time_lock_events', 'time_statements'] as $table) {
                        $this->add($table);
                    }
                    break;
                }
                $this->add('time_entries', "source_kind = 'manual' AND work_date >= :from AND work_date <= :to", $period);
                $this->add('time_absences', 'work_date >= :from AND work_date <= :to', $period);
                $this->add('time_submissions', 'from_date >= :from AND to_date <= :to', $period);
                $this->add('time_day_locks', 'submission_id IN ' . $in('time_submissions') . ' OR (submission_id IS NULL AND work_date >= :from AND work_date <= :to)', $period);
                $this->add('time_lock_events', 'submission_id IN ' . $in('time_submissions') . ' OR (submission_id IS NULL AND from_date >= :from AND to_date <= :to)', $period);
                $this->add('time_statements', 'submission_id IN ' . $in('time_submissions'));
                // Einreichungen, die nur teilweise im Zeitraum liegen, würden unvollständig.
                $statement = $this->pdo->prepare('SELECT period_key FROM time_submissions WHERE from_date <= :to AND to_date >= :from AND NOT (from_date >= :from AND to_date <= :to) ORDER BY from_date');
                $statement->execute($period);
                foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $key) {
                    $blockers[] = sprintf('Zeiterfassung: Die Einreichung %s reicht über die Zeitraumgrenze hinaus. Bitte den Zeitraum so wählen, dass er ganze Einreichungszeiträume umfasst.', (string) $key);
                }
                break;
            case 'travel':
                foreach (['time_day_details', 'time_private_trips', 'time_expenses'] as $table) {
                    $period === null ? $this->add($table) : $this->add($table, 'work_date >= :from AND work_date <= :to', $period);
                }
                break;
            case 'purchases':
                foreach (['purchase_orders', 'purchase_order_allocations', 'purchase_order_confirmations', 'purchase_order_deliveries', 'purchase_order_documents',
                    'purchase_order_history', 'purchase_order_invoices', 'purchase_order_items', 'purchase_order_material_requests', 'purchase_order_payments'] as $table) {
                    $this->add($table);
                }
                break;
            case 'materials':
                $this->add('material_requests');
                break;
            case 'documents':
                $this->add('attachments');
                $this->add('attachment_links');
                break;
            case 'employees':
                $this->add('employees');
                break;
            case 'suppliers':
                $this->add('suppliers');
                break;
            case 'cost_items':
                $this->add('cost_items');
                break;
            case 'service_templates':
                $this->add('service_templates');
                $this->add('service_template_components');
                break;
            case 'categories_texts':
                $this->add('master_categories');
                $this->add('trailing_texts');
                break;
            case 'cost_centers':
                $this->add('cost_centers');
                break;
        }
    }

    /**
     * Verknüpfungen ohne Fremdschlüssel, die zu gelöschten Datensätzen gehören: Dokument-
     * verknüpfungen (Ziel Kunde/Ansprechpartner/Projekt/Angebot/Auftrag) und Bearbeitungssperren.
     */
    private function selectDependentLinks(): void
    {
        foreach (['customer' => 'customers', 'contact' => 'customer_contacts', 'project' => 'projects', 'offer' => 'offers', 'order' => 'orders'] as $type => $table) {
            $this->add('attachment_links', 'target_type = ' . self::literal($type) . ' AND target_id IN ' . self::selectedValues($table, 'id'));
        }
        $this->add('document_locks', 'document_id IN ' . self::selectedValues('offers', 'id'));
    }

    /** @param array<string,string|null> $parameters */
    private function add(string $table, string $where = '1 = 1', array $parameters = []): void
    {
        $sql = sprintf('INSERT OR IGNORE INTO temp.purge_set (tbl, rid) SELECT %s, rowid FROM %s WHERE %s', self::literal($table), self::quote($table), $where);
        $used = array_filter($parameters, static fn (string $key): bool => str_contains($where, ':' . $key), ARRAY_FILTER_USE_KEY);
        $this->pdo->prepare($sql)->execute($used);
    }

    // -------------------------------------------------------------------------
    // Prüfungen
    // -------------------------------------------------------------------------

    /**
     * Jede nicht ausgewählte Zeile, die auf eine zu löschende verweist, blockiert – auch bei
     * CASCADE (stille Mitlöschung) und SET NULL (stille Änderung).
     *
     * @param array<string,bool> $selected
     * @return list<string>
     */
    private function foreignKeyConflicts(array $selected, bool $period): array
    {
        $conflicts = [];
        $tables = array_fill_keys($this->selectedTables(), true);
        foreach ($this->incomingForeignKeys() as $parent => $keys) {
            if (!isset($tables[$parent])) {
                continue;
            }
            foreach ($keys as [$child, $column, $parentColumn, $action]) {
                $sql = sprintf('SELECT COUNT(*) FROM %s c WHERE c.%s IN %s AND c.rowid NOT IN (SELECT rid FROM temp.purge_set WHERE tbl = %s)',
                    self::quote($child), self::quote($column), self::selectedValues($parent, $parentColumn), self::literal($child));
                $count = (int) $this->pdo->query($sql)->fetchColumn();
                if ($count === 0) {
                    continue;
                }
                $childLabel = self::TABLE_LABELS[$child] ?? $child;
                $parentLabel = self::TABLE_LABELS[$parent] ?? $parent;
                $owner = self::TABLE_AREAS[$child] ?? null;
                $effect = match ($action) {
                    'CASCADE' => 'würden unbemerkt mitgelöscht',
                    'SET NULL' => 'würden unbemerkt verändert',
                    default => 'verhindern die Löschung',
                };
                if ($owner === null) {
                    $conflicts[] = sprintf('%d × %s verweisen auf zu löschende %s und %s. Dieser Bereich ist nicht bereinigbar.', $count, $childLabel, $parentLabel, $effect);
                } elseif (!isset($selected[$owner])) {
                    $conflicts[] = sprintf('%d × %s verweisen auf zu löschende %s und %s. Bitte zusätzlich „%s“ auswählen.', $count, $childLabel, $parentLabel, $effect, self::AREAS[$owner][1]);
                } else {
                    $conflicts[] = sprintf('%d × %s verweisen auf zu löschende %s, gehören aber nicht zur Auswahl%s und %s.', $count, $childLabel, $parentLabel,
                        $period ? ' (außerhalb des Zeitraums bzw. zeitraumübergreifender Vorgang)' : '', $effect);
                }
            }
        }
        return $conflicts;
    }

    /**
     * Daten auf festgeschriebenen/eingereichten Zeiterfassungstagen nur zusammen mit der Sperre
     * (Bereich Zeiterfassung) entfernen, sonst entstünden Nachweise ohne Grundlage.
     *
     * @return list<string>
     */
    private function lockConflicts(): array
    {
        $conflicts = [];
        foreach (['time_entries' => true, 'time_day_details' => false, 'time_private_trips' => false, 'time_expenses' => false, 'time_absences' => false] as $table => $overnight) {
            $date = $overnight ? "(l.work_date = x.work_date OR (x.ends_next_day = 1 AND l.work_date = date(x.work_date, '+1 day')))" : 'l.work_date = x.work_date';
            $sql = sprintf("SELECT COUNT(*) FROM %s x JOIN temp.purge_set s ON s.tbl = %s AND s.rid = x.rowid WHERE EXISTS (SELECT 1 FROM time_day_locks l
                WHERE l.status <> 'reopened' AND l.employee_id = x.employee_id AND %s AND l.rowid NOT IN (SELECT rid FROM temp.purge_set WHERE tbl = 'time_day_locks'))",
                self::quote($table), self::literal($table), $date);
            $count = (int) $this->pdo->query($sql)->fetchColumn();
            if ($count > 0) {
                $conflicts[] = sprintf('%d × %s liegen auf eingereichten oder festgeschriebenen Zeiterfassungstagen. Bitte zusätzlich „%s“ (für denselben Zeitraum) auswählen.',
                    $count, self::TABLE_LABELS[$table], self::AREAS['time'][1]);
            }
        }
        return $conflicts;
    }

    /**
     * Dokumente, deren sämtliche Verknüpfungen entfernt würden, blieben ohne Bezug zurück.
     *
     * @return list<string>
     */
    private function orphanedDocuments(): array
    {
        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM attachments a
            WHERE a.rowid NOT IN (SELECT rid FROM temp.purge_set WHERE tbl = 'attachments')
              AND EXISTS (SELECT 1 FROM attachment_links l WHERE l.attachment_id = a.id)
              AND NOT EXISTS (SELECT 1 FROM attachment_links l WHERE l.attachment_id = a.id AND l.rowid NOT IN (SELECT rid FROM temp.purge_set WHERE tbl = 'attachment_links'))")->fetchColumn();
        return $count === 0 ? [] : [sprintf('%d × Dokumente sind nur mit zu löschenden Vorgängen verknüpft und blieben ohne Bezug zurück. Bitte zusätzlich „%s“ auswählen.',
            $count, self::AREAS['documents'][1])];
    }

    // -------------------------------------------------------------------------
    // Dateien, Nummernkreise, Zählungen, Fingerabdruck
    // -------------------------------------------------------------------------

    /**
     * Zu löschende Dateien. Pfade, auf die eine verbleibende Zeile verweist, bleiben erhalten.
     *
     * @return array{delete:list<array{path:string,size:int}>,bytes:int,missing:int,kept:int,offerDirectories:list<string>,blockers:list<string>}
     */
    private function files(): array
    {
        $documents = rtrim($this->documentsPath, '/');
        $candidates = [];
        $remaining = [];
        $blockers = [];
        foreach (self::DOCUMENT_FILE_COLUMNS as [$table, $column]) {
            $sql = sprintf('SELECT x.%1$s AS path, (x.rowid IN (SELECT rid FROM temp.purge_set WHERE tbl = %2$s)) AS selected FROM %3$s x WHERE x.%1$s IS NOT NULL AND x.%1$s <> \'\'',
                self::quote($column), self::literal($table), self::quote($table));
            foreach ($this->pdo->query($sql)->fetchAll() as $row) {
                $relative = (string) $row['path'];
                if ((int) $row['selected'] === 1) {
                    if (!self::safeRelative($relative)) {
                        $blockers[] = sprintf('Unerwarteter Dateipfad in %s. Die Bereinigung wird aus Sicherheitsgründen nicht ausgeführt.', self::TABLE_LABELS[$table] ?? $table);
                        continue;
                    }
                    $candidates[$documents . '/' . $relative] = true;
                } else {
                    $remaining[$documents . '/' . $relative] = true;
                }
            }
        }
        // Angebote: JSON-Entwürfe, Snapshots und PDFs unter APP_OFFERS_PATH.
        $offers = rtrim($this->offersPath, '/');
        $directories = [];
        foreach ($this->pdo->query("SELECT o.storage_key, o.id, o.draft_relative_path, o.snapshot_relative_path, o.submitted_pdf_relative_path,
                (o.rowid IN (SELECT rid FROM temp.purge_set WHERE tbl = 'offers')) AS selected FROM offers o")->fetchAll() as $row) {
            foreach (['draft_relative_path', 'snapshot_relative_path', 'submitted_pdf_relative_path'] as $column) {
                $relative = (string) ($row[$column] ?? '');
                if ($relative === '') {
                    continue;
                }
                if ((int) $row['selected'] === 1) {
                    if (!self::safeRelative($relative)) {
                        $blockers[] = 'Unerwarteter Dateipfad bei einem Angebot. Die Bereinigung wird aus Sicherheitsgründen nicht ausgeführt.';
                        continue;
                    }
                    $candidates[$offers . '/' . $relative] = true;
                } else {
                    $remaining[$offers . '/' . $relative] = true;
                }
            }
            if ((int) $row['selected'] === 1 && self::safeRelative((string) $row['storage_key'] . '/' . (string) $row['id'])) {
                $directories[] = $offers . '/' . $row['storage_key'] . '/' . $row['id'];
            }
        }
        $delete = [];
        $bytes = 0;
        $missing = 0;
        $kept = 0;
        foreach (array_keys($candidates) as $path) {
            if (isset($remaining[$path])) {
                $kept++;
                continue;
            }
            if (!is_file($path)) {
                $missing++;
                continue;
            }
            $size = (int) filesize($path);
            $delete[] = ['path' => $path, 'size' => $size];
            $bytes += $size;
        }
        usort($delete, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
        sort($directories);
        return ['delete' => $delete, 'bytes' => $bytes, 'missing' => $missing, 'kept' => $kept, 'offerDirectories' => array_values(array_unique($directories)), 'blockers' => $blockers];
    }

    private static function safeRelative(string $relative): bool
    {
        return $relative !== '' && !str_starts_with($relative, '/') && !str_contains($relative, '..') && !str_contains($relative, "\0") && !str_contains($relative, '\\');
    }

    /**
     * Nummernkreise: Rücksetzen nur, wenn kein vergebener Wert mehr zu einem verbleibenden
     * Datensatz gehört. Ohne Option bleiben alle Reservierungen und Zählerstände unverändert.
     *
     * @return list<array{code:string,label:string,next:int,resetTo:?int,reason:string}>
     */
    private function numberRanges(bool $reset): array
    {
        if (!$reset) {
            return [];
        }
        $result = [];
        foreach ($this->pdo->query('SELECT id, code, label, next_counter FROM number_ranges ORDER BY code')->fetchAll() as $range) {
            $assignments = $this->pdo->prepare('SELECT entity_type, entity_id, counter_value, assigned_manually FROM assigned_numbers WHERE number_range_id = :id');
            $assignments->execute(['id' => $range['id']]);
            $rows = $assignments->fetchAll();
            if ($rows === []) {
                continue;
            }
            $blocking = null;
            $minimum = null;
            foreach ($rows as $row) {
                $table = self::NUMBER_ENTITIES[(string) $row['entity_type']] ?? null;
                if ($table === null) {
                    $blocking = 'unbekannte Zuordnung';
                    break;
                }
                $exists = $this->pdo->prepare(sprintf('SELECT COUNT(*) FROM %s WHERE id = :id AND rowid NOT IN (SELECT rid FROM temp.purge_set WHERE tbl = %s)', self::quote($table), self::literal($table)));
                $exists->execute(['id' => $row['entity_id']]);
                if ((int) $exists->fetchColumn() > 0) {
                    $blocking = 'vergebene Nummern gehören zu verbleibenden Datensätzen';
                    break;
                }
                if ((int) $row['assigned_manually'] === 0 && $row['counter_value'] !== null) {
                    $minimum = $minimum === null ? (int) $row['counter_value'] : min($minimum, (int) $row['counter_value']);
                }
            }
            $next = (int) $range['next_counter'];
            if ($blocking === null) {
                // Alle Reservierungen entfallen; der Zähler beginnt wieder beim ersten automatisch vergebenen Wert.
                $this->add('assigned_numbers', 'number_range_id = :range', ['range' => (string) $range['id']]);
            }
            $resetTo = $blocking === null && $minimum !== null && $minimum < $next ? $minimum : null;
            $result[] = ['code' => (string) $range['code'], 'label' => (string) $range['label'], 'next' => $next, 'resetTo' => $resetTo,
                'reason' => $blocking ?? ($resetTo === null ? 'Reservierungen werden entfernt, Zählerstand bleibt' : 'wird zurückgesetzt')];
        }
        return $result;
    }

    /** @return array<string,int> */
    private function counts(): array
    {
        $counts = [];
        foreach ($this->pdo->query('SELECT tbl, COUNT(*) AS count FROM temp.purge_set GROUP BY tbl ORDER BY tbl')->fetchAll() as $row) {
            $counts[(string) $row['tbl']] = (int) $row['count'];
        }
        return $counts;
    }

    /**
     * Fingerabdruck über Auswahl, Konflikte, Nummernkreise und den vollständigen Inhalt jeder
     * betroffenen Zeile. Jede Änderung an betroffenen Daten macht eine Vorschau ungültig.
     *
     * @param array<string,mixed> $selection @param array<string,int> $counts @param list<string> $blockers @param list<array<string,mixed>> $numbers
     */
    private function fingerprint(array $selection, array $counts, array $blockers, array $numbers): string
    {
        $hash = hash_init('sha256');
        hash_update($hash, json_encode([$selection, $counts, array_values(array_unique($blockers)), $numbers], JSON_THROW_ON_ERROR));
        foreach (array_keys($counts) as $table) {
            $rows = $this->pdo->query(sprintf('SELECT x.* FROM %s x JOIN temp.purge_set s ON s.tbl = %s AND s.rid = x.rowid ORDER BY x.rowid', self::quote($table), self::literal($table)));
            while (($row = $rows->fetch(PDO::FETCH_NUM)) !== false) {
                hash_update($hash, $table . "\x1f" . json_encode($row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\x1e");
            }
        }
        return hash_final($hash);
    }

    private function primaryKey(string $table): string
    {
        foreach ($this->pdo->query('PRAGMA table_info(' . self::quote($table) . ')')->fetchAll() as $column) {
            if ((int) $column['pk'] === 1) {
                return (string) $column['name'];
            }
        }
        return 'rowid';
    }

    private static function date(mixed $value, string $label): string
    {
        $text = trim((string) $value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if ($date === false || $date->format('Y-m-d') !== $text) {
            throw new DocumentValidationException([$label . ': Bitte ein gültiges Datum angeben.']);
        }
        return $text;
    }
}
