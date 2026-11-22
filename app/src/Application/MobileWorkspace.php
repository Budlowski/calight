<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Document\DocumentValidationException;
use App\Domain\Order\OrderValidationException;
use App\Http\AccessDeniedException;
use App\Security\RolePermissions;
use DateTimeImmutable;

/**
 * Lesende Projektionen für das mobile Monteurs-Frontend (Phase 1).
 *
 * Keine eigene Fachlogik: Alle Daten stammen aus den bestehenden Diensten mit deren Rollen-,
 * Modul- und Datensatzrechten (z. B. Techniker nur freigegebene Aufträge). Die Projektionen
 * geben bewusst nur ausführungsrelevante Felder weiter – keine Preise, Summen, Kostensätze oder
 * Kalkulationsdaten, auch nicht für Büro und Administratoren, damit die mobile Oberfläche auf
 * jedem Gerät gleich zurückhaltend bleibt.
 */
final readonly class MobileWorkspace
{
    public const ORDER_STATUS = ['open' => 'Offen', 'in_progress' => 'In Bearbeitung', 'completed' => 'Abgeschlossen', 'invoiced' => 'Abgerechnet'];

    /** Statusfilter der mobilen Auftragsliste; „aktiv“ (offen oder in Bearbeitung) ist der Standard. */
    public const ORDER_FILTERS = ['active' => 'Aktiv', 'completed' => 'Abgeschlossen', 'invoiced' => 'Abgerechnet', 'all' => 'Alle'];

    private const WORK_REPORT_STATUS = ['draft' => 'Entwurf', 'submitted' => 'Eingereicht', 'processed' => 'Bearbeitet'];
    private const MEASUREMENT_STATUS = ['draft' => 'Entwurf', 'completed' => 'Abgeschlossen'];
    private const MATERIAL_STATUS = ['open' => 'Offen', 'done' => 'Erledigt'];

    /** Rein organisatorische Zeitraumhinweise, die auf der Startseite nicht als Prüfhinweis erscheinen. */
    private const IGNORED_PERIOD_CODES = ['rhythm', 'unfinished', 'nothing_open'];

    public function __construct(
        private ModuleService $modules,
        private OrderService $orders,
        private MeasurementService $measurements,
        private ?DailyReportService $dailyReports = null,
        private ?WorkReportService $workReports = null,
        private ?MaterialRequestService $materialRequests = null,
        private ?DocumentService $documents = null,
        private ?TimeTrackingService $timeTracking = null,
        private ?TimeApprovalService $timeApprovals = null,
    ) {
    }

    // -------------------------------------------------------------------------
    // Navigation und Schnellzugriffe
    // -------------------------------------------------------------------------

    /**
     * Sichtbare Bereiche: nur implementierte, aktive Module und vorhandene Leserechte.
     *
     * @param array<string,mixed> $actor
     * @return array<string,bool>
     */
    public function features(array $actor): array
    {
        $work = $this->modules->isEnabled('work_tracking');
        $procurement = $this->modules->isEnabled('procurement');
        return [
            'orders' => $this->can($actor, 'orders.read'),
            'times' => $work && $this->can($actor, 'timesheet.own'),
            // Persönliche Erfassung nur mit zugeordnetem, aktivem Mitarbeiter (employees.user_id).
            'timeEntry' => $work && $this->hasActiveEmployee($actor),
            'dailyReports' => $work && $this->can($actor, 'reports.read'),
            'workReports' => $work && $this->can($actor, 'workreports.read'),
            'materialRequests' => $procurement && $this->can($actor, 'reports.read'),
            'measurements' => $this->can($actor, 'measurements.read'),
            'documents' => $this->can($actor, 'documents.read'),
            'switchInterface' => UserInterface::canSwitch($actor),
        ];
    }

    /**
     * Schnellzugriffe der Startseite. Phase 1 nutzt die vorhandenen, responsiven Erfassungsseiten;
     * es erscheinen nur Einträge, deren Modul aktiv ist und die der Benutzer schreiben darf.
     *
     * @param array<string,mixed> $actor
     * @return list<array{label:string,icon:string,url:string,hint:string}>
     */
    public function quickActions(array $actor): array
    {
        $work = $this->modules->isEnabled('work_tracking');
        $procurement = $this->modules->isEnabled('procurement');
        $actions = [];
        $add = static function (bool $visible, string $label, string $icon, string $url, string $hint) use (&$actions): void {
            if ($visible) {
                $actions[] = ['label' => $label, 'icon' => $icon, 'url' => $url, 'hint' => $hint];
            }
        };
        $timeEntry = $work && $this->hasActiveEmployee($actor);
        $add($timeEntry, 'Arbeitszeit', 'clock-3', '/mobile/times?form=entry', 'Zeit erfassen');
        $add($work && $this->can($actor, 'reports.write'), 'Tagesbericht', 'calendar-days', '/mobile/daily-reports', 'Berichte erfassen');
        $add($work && $this->can($actor, 'workreports.write'), 'Rapport', 'clipboard-check', '/mobile/rapports', 'Regieleistungen');
        $add($procurement && $this->can($actor, 'reports.write'), 'Materialbedarf', 'package', '/mobile/material-requests', 'Material anfordern');
        $add($this->can($actor, 'measurements.write'), 'Aufmaß', 'ruler', '/mobile/measurements', 'Mengen erfassen');
        $add($timeEntry, 'Abwesenheit', 'calendar-days', '/mobile/times?form=absence', 'Urlaub, Krankheit …');
        $add($timeEntry, 'Reisekosten', 'badge-euro', '/mobile/times?form=travel', 'Auswärtstätigkeit, Fahrten, Auslagen');
        return $actions;
    }

    // -------------------------------------------------------------------------
    // Startseite
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function home(array $actor, string $today): array
    {
        $features = $this->features($actor);
        $orders = $features['orders']
            ? $this->orders(['status' => 'active', 'sort' => 'updated', 'per_page' => '10'], $actor)['items']
            : [];
        return [
            'today' => $features['times'] ? $this->todaySummary($actor, $today) : null,
            'hints' => $features['times'] ? $this->timeHints($actor, $today) : [],
            'openItems' => $this->openItems($actor, $features),
            'quickActions' => $this->quickActions($actor),
            'orders' => array_slice($orders, 0, 5),
            'features' => $features,
        ];
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    private function todaySummary(array $actor, string $today): array
    {
        if ($this->timeTracking === null) {
            return ['available' => false, 'message' => 'Die Zeiterfassung ist nicht verfügbar.'];
        }
        try {
            $day = $this->timeTracking->day(null, $today, $actor);
        } catch (DocumentValidationException $exception) {
            // Typisch: Benutzerkonto ohne zugeordneten Mitarbeiter.
            return ['available' => false, 'message' => implode(' ', $exception->errors)];
        }
        return [
            'available' => true,
            'date' => $day['date'],
            'netMinutes' => (int) ($day['totals']['netMinutes'] ?? 0),
            'travelMinutes' => (int) ($day['totals']['travelMinutes'] ?? 0),
            'entryCount' => count($day['entries']),
            'absences' => array_values(array_map(static fn (array $absence): string => (string) $absence['label'], $day['absences'])),
            'lockStatus' => $day['lockStatus'],
            'lockLabel' => $day['lockLabel'],
        ];
    }

    /**
     * Sperrfehler und Hinweise der laufenden Woche bis heute aus der zentralen Prüfung
     * (TimeApprovalService::check), ohne eigene Regeln.
     *
     * @param array<string,mixed> $actor
     * @return list<array{level:string,message:string}>
     */
    private function timeHints(array $actor, string $today): array
    {
        if ($this->timeApprovals === null) {
            return [];
        }
        try {
            $period = $this->timeApprovals->period(null, 'week', $today, $actor);
        } catch (DocumentValidationException) {
            return [];
        }
        $hints = [];
        foreach (['error' => $period['errors'], 'warning' => $period['warnings']] as $level => $issues) {
            foreach ($issues as $issue) {
                if (in_array($issue['code'] ?? '', self::IGNORED_PERIOD_CODES, true) || (($issue['date'] ?? null) !== null && $issue['date'] > $today)) {
                    continue;
                }
                $hints[] = ['level' => $level, 'message' => (string) $issue['message']];
            }
        }
        return $hints;
    }

    /**
     * Offene eigene Vorgänge: eigene Tagesberichte (Entwurf/Korrektur), Rapport- und
     * Aufmaßentwürfe sowie offene Materialanforderungen.
     *
     * @param array<string,mixed> $actor
     * @param array<string,bool> $features
     * @return list<array{type:string,label:string,detail:string,status:string,url:string}>
     */
    private function openItems(array $actor, array $features): array
    {
        $own = static fn (array $row, string $key): bool => (string) ($row[$key] ?? '') === (string) ($actor['id'] ?? '');
        $items = [];
        if ($features['dailyReports'] && $this->dailyReports !== null) {
            foreach ($this->dailyReports->page(['status' => 'open', 'per_page' => '100'], $actor)['items'] as $report) {
                if ($own($report, 'created_by_user_id')) {
                    $items[] = ['type' => 'Tagesbericht', 'label' => (string) ($report['title'] ?: 'Tagesbericht vom ' . self::germanDate((string) $report['report_date'])),
                        'detail' => self::join([$report['order_number'] ?? null, $report['customer_name'] ?? null]),
                        'status' => (string) $report['status_label'], 'url' => '/mobile/daily-reports/' . rawurlencode((string) $report['id'])];
                }
            }
        }
        if ($features['workReports'] && $this->workReports !== null) {
            foreach ($this->workReports->page(['status' => 'draft', 'per_page' => '100'], $actor)['items'] as $report) {
                if ($own($report, 'createdByUserId')) {
                    $items[] = ['type' => 'Rapport', 'label' => 'Rapportentwurf vom ' . self::germanDate((string) $report['workDate']),
                        'detail' => self::join([$report['orderNumber'] ?? null, $report['customerName'] ?? null]),
                        'status' => self::WORK_REPORT_STATUS['draft'], 'url' => '/mobile/rapports/' . rawurlencode((string) $report['id'])];
                }
            }
        }
        if ($features['measurements']) {
            foreach ($this->measurements->page(['status' => 'draft', 'per_page' => '100'], $actor)['items'] as $measurement) {
                if ($own($measurement, 'createdByUserId')) {
                    $items[] = ['type' => 'Aufmaß', 'label' => (string) $measurement['title'],
                        'detail' => self::join([$measurement['orderNumber'] ?? null, $measurement['customerName'] ?? null]),
                        'status' => self::MEASUREMENT_STATUS['draft'], 'url' => '/mobile/measurements/' . rawurlencode((string) $measurement['id'])];
                }
            }
        }
        if ($features['materialRequests'] && $this->materialRequests !== null) {
            foreach ($this->materialRequests->page(['status' => 'open', 'per_page' => '100'], $actor)['items'] as $request) {
                if ($own($request, 'createdByUserId')) {
                    $items[] = ['type' => 'Materialbedarf', 'label' => self::shorten((string) $request['description']),
                        'detail' => self::join([$request['orderNumber'] ?? $request['costCenterNumber'] ?? null, $request['customerName'] ?? null]),
                        'status' => self::MATERIAL_STATUS['open'], 'url' => '/mobile/material-requests' . ($request['orderId'] ? '?order=' . rawurlencode((string) $request['orderId']) : '')];
                }
            }
        }
        return $items;
    }

    // -------------------------------------------------------------------------
    // Aufträge
    // -------------------------------------------------------------------------

    /**
     * Durchsuchbare Auftragsliste ohne Summen. Techniker sehen wie in der Büroansicht nur
     * freigegebene Aufträge.
     *
     * @param array<string,mixed> $parameters q, status, page
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function orders(array $parameters, array $actor): array
    {
        $this->require($actor, 'orders.read');
        $filter = (string) ($parameters['status'] ?? 'active');
        if (!isset(self::ORDER_FILTERS[$filter])) {
            $filter = 'active';
        }
        $query = [
            'q' => mb_substr(trim((string) ($parameters['q'] ?? '')), 0, 100),
            'page' => (string) max(1, (int) ($parameters['page'] ?? 1)),
            'per_page' => in_array((string) ($parameters['per_page'] ?? ''), ['10', '25'], true) ? (string) $parameters['per_page'] : '25',
            'sort' => (string) ($parameters['sort'] ?? 'updated') === 'number' ? 'number' : 'updated',
        ];
        if ($filter !== 'all') {
            $query['status'] = $filter;
        }
        $page = $this->orders->page($query, $actor);
        return [
            'items' => array_map($this->orderSummary(...), $page['items']),
            'total' => (int) $page['total'],
            'page' => (int) $page['page'],
            'pageCount' => (int) $page['pageCount'],
            'q' => $query['q'],
            'status' => $filter,
        ];
    }

    /**
     * Ausführungsorientierte Auftragsansicht ohne kaufmännische Werte.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function order(string $id, array $actor): array
    {
        $this->require($actor, 'orders.read');
        $order = $this->orders->readableDetail($id, $actor);
        $features = $this->features($actor);
        $context = $this->orders->executionContext($id);
        $services = $this->services($id, $actor);
        $workEnabled = $this->modules->isEnabled('work_tracking');
        $procurementEnabled = $this->modules->isEnabled('procurement');

        // Historische Daten abgeschalteter Module bleiben mit Leserecht sichtbar (ohne Anlegeaktionen).
        $dailyReports = null;
        if ($this->dailyReports !== null && $this->can($actor, 'reports.read')) {
            $page = $this->dailyReports->page(['order' => $id, 'per_page' => '10'], $actor);
            $dailyReports = ['total' => (int) $page['total'], 'items' => array_map(static fn (array $report): array => [
                'label' => (string) ($report['title'] ?: 'Tagesbericht vom ' . self::germanDate((string) $report['report_date'])),
                'date' => (string) $report['report_date'],
                'detail' => (string) ($report['employee_names'] ?? ''),
                'status' => (string) $report['status_label'],
                'url' => '/mobile/daily-reports/' . rawurlencode((string) $report['id']),
            ], $page['items'])];
            if (!$workEnabled && $dailyReports['total'] === 0) {
                $dailyReports = null;
            }
        }
        $workReports = null;
        if ($this->workReports !== null && $this->can($actor, 'workreports.read')) {
            $rows = $this->workReports->forOrder($id, $actor);
            $workReports = array_map(static fn (array $report): array => [
                'label' => (string) ($report['reportNumber'] ?: 'Entwurf ohne Nummer'),
                'date' => (string) $report['workDate'],
                'detail' => (string) $report['creatorName'],
                'status' => self::WORK_REPORT_STATUS[$report['status']] ?? (string) $report['status'],
                'url' => '/mobile/rapports/' . rawurlencode((string) $report['id']),
            ], $rows);
            if (!$workEnabled && $workReports === []) {
                $workReports = null;
            }
        }
        $materialRequests = null;
        if ($this->materialRequests !== null && $this->can($actor, 'reports.read')) {
            $materialRequests = array_map(static fn (array $request): array => [
                'label' => self::shorten((string) $request['description']),
                'date' => (string) ($request['neededBy'] ?? ''),
                'detail' => self::join([$request['creatorName'] ?? null, ($request['neededBy'] ?? null) ? 'benötigt bis ' . self::germanDate((string) $request['neededBy']) : null]),
                'status' => self::MATERIAL_STATUS[$request['status']] ?? (string) $request['status'],
                'url' => '/mobile/material-requests?order=' . rawurlencode($id),
            ], $this->materialRequests->openForOrder($id, $actor));
            if (!$procurementEnabled && $materialRequests === []) {
                $materialRequests = null;
            }
        }
        $measurements = null;
        if ($features['measurements']) {
            $overview = $this->measurements->forOrder($id, $actor);
            $measurements = array_map(static fn (array $measurement): array => [
                'label' => (string) $measurement['title'],
                'date' => (string) $measurement['measurementDate'],
                'detail' => (string) ($measurement['measurementNumber'] ?: 'Entwurf ohne Nummer'),
                'status' => self::MEASUREMENT_STATUS[$measurement['status']] ?? (string) $measurement['status'],
                'url' => '/mobile/measurements/' . rawurlencode((string) $measurement['id']),
            ], $overview['measurements']);
        }
        $documents = null;
        if ($features['documents'] && $this->documents !== null) {
            $documents = array_map(static fn (array $document): array => [
                'label' => (string) $document['title'],
                'detail' => self::join([$document['typeLabel'] ?? null, $document['fileName'] ?? null]),
                'date' => substr((string) ($document['date'] ?? ''), 0, 10),
                'url' => (string) $document['url'],
            ], $this->documents->page(['order' => $id, 'per_page' => '25'], $actor)['items']);
        }

        return [
            'order' => [
                'id' => $order['id'],
                'number' => $order['orderNumber'],
                'title' => $order['title'],
                'description' => $order['description'],
                'status' => $order['status'],
                'statusLabel' => self::ORDER_STATUS[$order['status']] ?? $order['status'],
                'type' => $order['orderType'] === 'offer' ? 'Angebotsauftrag' : 'Regieauftrag',
                'orderDate' => $order['orderDate'],
                'customerName' => $order['customerName'],
                'customerNumber' => $order['customerNumber'],
                'projectName' => $order['projectName'],
                'projectNumber' => $order['projectNumber'],
            ],
            'site' => $this->site($context),
            'customerAddress' => $this->customerAddress($context),
            'contacts' => $this->contacts($context),
            'services' => $services,
            'dailyReports' => $dailyReports,
            'workReports' => $workReports,
            'materialRequests' => $materialRequests,
            'measurements' => $measurements,
            'documents' => $documents,
            'actions' => $this->orderActions($id, $actor, $services !== []),
        ];
    }

    /**
     * Erfassungseinstiege aus dem Auftrag; sie übernehmen die Auftragszuordnung über die
     * vorhandenen Einstiege der Fachmodule (create_for bzw. Auftragsreiter).
     *
     * @param array<string,mixed> $actor
     * @return list<array{label:string,icon:string,url:string}>
     */
    private function orderActions(string $id, array $actor, bool $measurable): array
    {
        $work = $this->modules->isEnabled('work_tracking');
        $procurement = $this->modules->isEnabled('procurement');
        $encoded = rawurlencode($id);
        $actions = [];
        if ($work && $this->hasActiveEmployee($actor)) {
            $actions[] = ['label' => 'Arbeitszeit erfassen', 'icon' => 'clock-3', 'url' => '/mobile/times?form=entry&order=' . $encoded];
        }
        if ($work && $this->can($actor, 'reports.write')) {
            $actions[] = ['label' => 'Tagesbericht anlegen', 'icon' => 'calendar-days', 'url' => '/mobile/daily-reports?create_for=' . $encoded];
        }
        if ($work && $this->can($actor, 'workreports.write')) {
            $actions[] = ['label' => 'Rapport anlegen', 'icon' => 'clipboard-check', 'url' => '/mobile/rapports?create_for=' . $encoded];
        }
        if ($procurement && $this->can($actor, 'reports.write')) {
            $actions[] = ['label' => 'Material anfordern', 'icon' => 'package', 'url' => '/mobile/material-requests?create_for=' . $encoded];
        }
        if ($measurable && $this->can($actor, 'measurements.write')) {
            $actions[] = ['label' => 'Aufmaß anlegen', 'icon' => 'ruler', 'url' => '/mobile/measurements?create_for=' . $encoded];
        }
        return $actions;
    }

    /**
     * Beauftragte Leistungen aus Auftragsgrundlage und angenommenen Nachträgen – nur Nummer,
     * Kurztext, Menge, Einheit und Art; Einzelpreise und Nachlässe werden entfernt.
     *
     * @param array<string,mixed> $actor
     * @return list<array{label:string,rows:list<array<string,mixed>>}>
     */
    private function services(string $orderId, array $actor): array
    {
        try {
            $sources = $this->measurements->basisPositions($orderId, $actor);
        } catch (OrderValidationException) {
            return [];
        }
        $result = [];
        foreach ($sources as $source) {
            $rows = [];
            $this->flatten($source['nodes'], 0, $rows);
            if ($rows !== []) {
                $result[] = ['label' => (string) $source['label'], 'rows' => $rows];
            }
        }
        return $result;
    }

    /** @param list<array<string,mixed>> $nodes @param list<array<string,mixed>> $rows */
    private function flatten(array $nodes, int $depth, array &$rows): void
    {
        foreach ($nodes as $node) {
            if ($node['kind'] === 'title') {
                $rows[] = ['kind' => 'title', 'depth' => $depth, 'number' => (string) $node['number'], 'text' => (string) $node['name'], 'type' => (string) $node['effectiveType']];
                $this->flatten($node['children'], $depth + 1, $rows);
                continue;
            }
            $rows[] = [
                'kind' => 'position', 'depth' => $depth, 'number' => (string) $node['number'], 'text' => (string) $node['shortText'],
                'quantity' => (string) $node['agreedQuantity'], 'unit' => (string) $node['unit'], 'type' => (string) $node['effectiveType'],
            ];
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function orderSummary(array $row): array
    {
        return [
            'id' => $row['id'],
            'number' => $row['orderNumber'],
            'title' => $row['title'],
            'customerName' => $row['customerName'],
            'projectName' => $row['projectName'],
            'projectNumber' => $row['projectNumber'],
            'site' => self::join([$row['siteStreet'] ?? null, $row['siteCity'] ?? null]) ?: (string) ($row['siteText'] ?? ''),
            'status' => $row['status'],
            'statusLabel' => self::ORDER_STATUS[$row['status']] ?? $row['status'],
        ];
    }

    /**
     * Einsatzort: Baustellenadresse des Projekts; ohne Projektadresse keine Annahme.
     *
     * @param array<string,mixed> $context
     * @return array{lines:list<string>,query:string}|null
     */
    private function site(array $context): ?array
    {
        $project = $context['project'] ?? null;
        if ($project === null) {
            return null;
        }
        $lines = array_values(array_filter([
            trim($project['street'] . ' ' . $project['houseNumber']),
            trim($project['postalCode'] . ' ' . $project['city']),
        ], static fn (string $line): bool => $line !== ''));
        if ($lines === [] && trim((string) $project['freeText']) !== '') {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $project['freeText']) ?: []), static fn (string $line): bool => $line !== ''));
        }
        return $lines === [] ? null : ['lines' => $lines, 'query' => implode(', ', $lines)];
    }

    /** @param array<string,mixed> $context @return array{lines:list<string>,query:string,phone:string,email:string}|null */
    private function customerAddress(array $context): ?array
    {
        $customer = $context['customer'] ?? null;
        if ($customer === null) {
            return null;
        }
        $lines = array_values(array_filter([
            trim($customer['street'] . ' ' . $customer['houseNumber']),
            trim($customer['postalCode'] . ' ' . $customer['city']),
        ], static fn (string $line): bool => $line !== ''));
        return ['lines' => $lines, 'query' => implode(', ', $lines), 'phone' => trim((string) $customer['phone']), 'email' => trim((string) $customer['email'])];
    }

    /** @param array<string,mixed> $context @return list<array<string,string>> */
    private function contacts(array $context): array
    {
        return array_map(static fn (array $contact): array => [
            'name' => trim(trim((string) $contact['first_name']) . ' ' . trim((string) $contact['last_name'])) ?: 'Ansprechpartner',
            'function' => trim((string) $contact['function']),
            'phone' => trim((string) $contact['phone']),
            'mobile' => trim((string) $contact['mobile']),
            'email' => trim((string) $contact['email']),
        ], $context['contacts'] ?? []);
    }

    // -------------------------------------------------------------------------
    // Tagesberichte und Rapporte (Übersichten; Bearbeitung über die bestehenden Editoren)
    // -------------------------------------------------------------------------

    /** Statusfilter der mobilen Tagesberichtsliste (Werte des bestehenden Listenfilters). */
    public const DAILY_REPORT_FILTERS = ['all' => 'Alle', 'open' => 'Offen', 'submitted' => 'Eingereicht', 'approved' => 'Festgeschrieben', 'cancelled' => 'Storniert'];
    /** Statusfilter der mobilen Rapportliste. */
    public const WORK_REPORT_FILTERS = ['all' => 'Alle', 'draft' => 'Entwurf', 'submitted' => 'Eingereicht', 'processed' => 'Bearbeitet'];

    /**
     * Tagesberichte mit den Sichtbarkeitsregeln des Dienstes (Techniker: eigene und fremde an
     * freigegebenen Aufträgen); ohne Kosten.
     *
     * @param array<string,mixed> $parameters q, status, page
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function dailyReports(array $parameters, array $actor): array
    {
        $this->require($actor, 'reports.read');
        $filter = $this->listFilter($parameters, self::DAILY_REPORT_FILTERS);
        $query = $this->listQuery($parameters, 'date');
        if ($filter !== 'all') {
            $query['status'] = $filter;
        }
        $page = $this->dailyReports?->page($query, $actor) ?? ['items' => [], 'total' => 0, 'page' => 1, 'pageCount' => 1];
        return $this->listResult($page, $query, $filter, static fn (array $report): array => [
            'id' => $report['id'],
            'url' => '/mobile/daily-reports/' . rawurlencode((string) $report['id']),
            'date' => (string) $report['report_date'],
            'title' => (string) ($report['title'] ?: ($report['order_title'] ?? '') ?: 'Tagesbericht'),
            'customerName' => (string) ($report['customer_name'] ?? ''),
            'target' => (string) ($report['order_number'] ?? $report['cost_center_number'] ?? ''),
            'targetLabel' => $report['order_number'] !== null ? 'Auftrag' : ($report['cost_center_number'] !== null ? 'Kostenstelle' : 'Ohne Zuordnung'),
            'detail' => (string) ($report['employee_names'] ?? ''),
            'hours' => (string) $report['total_hours'],
            'status' => (string) $report['status'],
            'statusLabel' => (string) $report['status_label'],
        ]);
    }

    /**
     * Rapporte mit den Sichtbarkeitsregeln des Dienstes; ohne Preise und Summen.
     *
     * @param array<string,mixed> $parameters q, status, page
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function workReports(array $parameters, array $actor): array
    {
        $this->require($actor, 'workreports.read');
        $filter = $this->listFilter($parameters, self::WORK_REPORT_FILTERS);
        $query = $this->listQuery($parameters, 'updated');
        if ($filter !== 'all') {
            $query['status'] = $filter;
        }
        $page = $this->workReports?->page($query, $actor) ?? ['items' => [], 'total' => 0, 'page' => 1, 'pageCount' => 1];
        return $this->listResult($page, $query, $filter, static fn (array $report): array => [
            'id' => $report['id'],
            'url' => '/mobile/rapports/' . rawurlencode((string) $report['id']),
            'date' => (string) $report['workDate'],
            'title' => (string) ($report['reportNumber'] ?: 'Entwurf ohne Nummer'),
            'customerName' => (string) $report['customerName'],
            'target' => (string) ($report['orderNumber'] ?? ''),
            'targetLabel' => $report['orderId'] !== null ? 'Auftrag' : 'Ohne Auftrag',
            'detail' => self::join([$report['creatorName'], $report['correctedNumber'] ? 'Korrektur zu ' . $report['correctedNumber'] : null,
                $report['supersededById'] ? 'ersetzt' : null, $report['confirmationStatus'] === 'confirmed' ? 'vom Kunden bestätigt' : null]),
            'hours' => (string) $report['totalHours'],
            'status' => (string) $report['status'],
            'statusLabel' => self::WORK_REPORT_STATUS[$report['status']] ?? (string) $report['status'],
        ]);
    }

    /** Statusfilter der mobilen Aufmaß- und Materialbedarfslisten (Werte der bestehenden Listenfilter). */
    public const MEASUREMENT_FILTERS = ['all' => 'Alle', 'draft' => 'Entwurf', 'completed' => 'Abgeschlossen'];
    public const MATERIAL_FILTERS = ['open' => 'Offen', 'done' => 'Erledigt', 'all' => 'Alle'];

    /**
     * Aufmaße mit den Sichtbarkeitsregeln des Dienstes (OrderVisibility, eigene immer); ohne Beträge.
     *
     * @param array<string,mixed> $parameters q, status, order, page
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function measurementList(array $parameters, array $actor): array
    {
        $this->require($actor, 'measurements.read');
        $filter = $this->listFilter($parameters, self::MEASUREMENT_FILTERS);
        $query = $this->listQuery($parameters, 'updated');
        if ($filter !== 'all') {
            $query['status'] = $filter;
        }
        return $this->listResult($this->measurements->page($query, $actor), $query, $filter, static fn (array $measurement): array => [
            'id' => $measurement['id'],
            'url' => '/mobile/measurements/' . rawurlencode((string) $measurement['id']),
            'date' => (string) $measurement['measurementDate'],
            'title' => (string) $measurement['title'],
            'customerName' => (string) $measurement['customerName'],
            'target' => (string) $measurement['orderNumber'],
            'targetLabel' => 'Auftrag',
            'detail' => self::join([$measurement['measurementNumber'] ?: 'Entwurf ohne Nummer', $measurement['itemCount'] . ' Positionen',
                $measurement['correctedNumber'] ? 'Korrektur zu ' . $measurement['correctedNumber'] : null]),
            'status' => (string) $measurement['status'],
            'statusLabel' => self::MEASUREMENT_STATUS[$measurement['status']] ?? (string) $measurement['status'],
        ]);
    }

    /**
     * Materialanforderungen (Sichtbarkeit wie im Dienst); Datensätze enthalten keine Preise.
     *
     * @param array<string,mixed> $parameters q, status, order, page
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function materialRequestList(array $parameters, array $actor): array
    {
        $this->require($actor, 'reports.read');
        $filter = (string) ($parameters['status'] ?? 'open');
        $filter = isset(self::MATERIAL_FILTERS[$filter]) ? $filter : 'open';
        $query = ['status' => $filter === 'open' ? '' : $filter] + $this->listQuery($parameters, 'created');
        $page = $this->materialRequests?->page($query, $actor) ?? ['items' => [], 'total' => 0, 'page' => 1, 'pageCount' => 1];
        return $this->listResult($page, $query, $filter, static fn (array $request): array => $request + [
            'reportUrl' => $request['dailyReportId'] ? '/mobile/daily-reports/' . rawurlencode((string) $request['dailyReportId']) : null,
        ]);
    }

    /**
     * Auswahl „Auftrag“ für mobiles Anlegen: aktive, für den Benutzer sichtbare Aufträge
     * (OrderVisibility über OrderService::page), nur Nummer und Bezeichnung.
     *
     * @param array<string,mixed> $actor
     * @return list<array{id:string,label:string}>
     */
    public function selectableOrders(array $actor): array
    {
        if (!$this->can($actor, 'orders.read')) {
            return [];
        }
        $page = $this->orders->page(['status' => 'active', 'sort' => 'number', 'per_page' => '100'], $actor);
        return array_map(static fn (array $order): array => ['id' => (string) $order['id'], 'label' => $order['orderNumber'] . ' · ' . $order['title'] . ' · ' . $order['customerName']], $page['items']);
    }

    /** @param array<string,mixed> $parameters @param array<string,string> $filters */
    private function listFilter(array $parameters, array $filters): string
    {
        $filter = (string) ($parameters['status'] ?? 'all');
        return isset($filters[$filter]) ? $filter : 'all';
    }

    /** @param array<string,mixed> $parameters @return array<string,string> */
    private function listQuery(array $parameters, string $sort): array
    {
        return [
            'q' => mb_substr(trim((string) ($parameters['q'] ?? '')), 0, 100),
            'page' => (string) max(1, (int) ($parameters['page'] ?? 1)),
            'per_page' => '25',
            'sort' => $sort,
        ] + (preg_match('/^[A-Za-z0-9-]{1,64}$/', (string) ($parameters['order'] ?? '')) === 1 ? ['order' => (string) $parameters['order']] : []);
    }

    /**
     * @param array<string,mixed> $page
     * @param array<string,string> $query
     * @param callable(array<string,mixed>):array<string,mixed> $map
     * @return array<string,mixed>
     */
    private function listResult(array $page, array $query, string $filter, callable $map): array
    {
        return [
            'items' => array_map($map, $page['items']),
            'total' => (int) $page['total'],
            'page' => (int) $page['page'],
            'pageCount' => (int) $page['pageCount'],
            'q' => $query['q'],
            'status' => $filter,
            'order' => $query['order'] ?? '',
        ];
    }

    // -------------------------------------------------------------------------
    // Hilfen
    // -------------------------------------------------------------------------

    /** @param array<string,mixed> $actor */
    private function hasActiveEmployee(array $actor): bool
    {
        $employee = $this->timeTracking?->ownEmployee($actor);
        return $employee !== null && $employee['active'];
    }

    /** @param array<string,mixed> $actor */
    private function can(array $actor, string $permission): bool
    {
        return RolePermissions::allows((string) ($actor['role'] ?? ''), $permission);
    }

    /** @param array<string,mixed> $actor */
    private function require(array $actor, string $permission): void
    {
        if (!$this->can($actor, $permission)) {
            throw new AccessDeniedException($permission);
        }
    }


    /** @param list<mixed> $parts */
    private static function join(array $parts): string
    {
        return implode(' · ', array_values(array_filter(array_map(static fn (mixed $part): string => trim((string) $part), $parts), static fn (string $part): bool => $part !== '')));
    }

    private static function shorten(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return mb_strlen($text) > 80 ? mb_substr($text, 0, 79) . '…' : $text;
    }

    public static function germanDate(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));
        return $parsed !== false ? $parsed->format('d.m.Y') : $date;
    }
}
