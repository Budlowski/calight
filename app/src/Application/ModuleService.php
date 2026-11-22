<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Document\DocumentValidationException;
use App\Http\AccessDeniedException;
use App\Security\RolePermissions;
use App\Support\Clock;
use PDO;

/**
 * Zentrale Modulregistrierung (Einstellungen → Module).
 *
 * Calight bleibt eine Anwendung mit einem Schema: Module sind nur globale Schalter für
 * Oberfläche, Routen und Aktionen. Migrationen laufen immer vollständig, Daten werden bei einer
 * Deaktivierung nie gelöscht. Persistenz in operational_settings (`modules.<schlüssel>` = 1/0);
 * ohne gespeicherten Wert ist ein implementiertes Modul aktiv – bestehende Installationen
 * behalten damit nach dem Update ihren vollen Funktionsumfang.
 *
 * Das Basissystem ist immer aktiv. Noch nicht implementierte Module sind vorgesehen, aber weder
 * aktivierbar noch in Navigation oder Oberfläche sichtbar.
 */
final class ModuleService
{
    public const CORE = 'core';

    /** @var array<string,array{icon:string,label:string,description:string,implemented:bool,note?:string}> */
    public const DEFINITIONS = [
        'core' => [
            'icon' => 'layout-dashboard', 'label' => 'Basissystem', 'implemented' => true,
            'description' => 'Kunden, Projekte, Angebote, Aufträge, Aufmaße, Dokumente und Stammdaten.',
        ],
        'work_tracking' => [
            'icon' => 'clock-3', 'label' => 'Arbeitserfassung', 'implemented' => true,
            'description' => 'Zeiterfassung, Tagesberichte, Rapporte, Abwesenheiten und Reisekosten.',
        ],
        'procurement' => [
            'icon' => 'shopping-cart', 'label' => 'Beschaffung', 'implemented' => true,
            'description' => 'Bestellungen bei Lieferanten, Materialbedarf und Eingangsrechnungen.',
            'note' => 'Lieferanten bleiben als gemeinsame Stammdaten (Artikelstamm) immer verfügbar.',
        ],
        'billing' => [
            'icon' => 'badge-euro', 'label' => 'Abrechnung', 'implemented' => false,
            'description' => 'Abrechnungsaufstellungen und Referenzen auf externe Rechnungen.',
        ],
        'job_costing' => [
            'icon' => 'chart-no-axes-column-increasing', 'label' => 'Nachkalkulation', 'implemented' => false,
            'description' => 'Soll-/Ist-Vergleich von Aufträgen.',
            'note' => 'Nutzt vorhandene Daten aus Arbeitserfassung und Beschaffung; fehlende Quellen gelten nicht als null Kosten.',
        ],
        'contacts' => [
            'icon' => 'users', 'label' => 'Kontakte', 'implemented' => false,
            'description' => 'Erweitertes Adressbuch mit CardDAV.',
        ],
        'calendar' => [
            'icon' => 'calendar-days', 'label' => 'Kalender', 'implemented' => false,
            'description' => 'Terminverwaltung mit CalDAV.',
        ],
        'lexware' => [
            'icon' => 'refresh-cw', 'label' => 'Lexware-Anbindung', 'implemented' => false,
            'description' => 'Datenaustausch mit Lexware.',
        ],
    ];

    /**
     * Routen der optionalen Module (Präfixe von Seiten- und API-Pfaden). Lesende Aufrufe bleiben
     * bei deaktiviertem Modul erlaubt (historische Daten, Archiv-PDFs), schreibende nicht.
     *
     * @var array<string,list<string>>
     */
    public const ROUTES = [
        'work_tracking' => ['/time-tracking', '/api/time-tracking', '/daily-reports', '/api/daily-reports', '/work-reports', '/api/work-reports', '/mobile/times', '/mobile/daily-reports', '/mobile/rapports'],
        'procurement' => ['/purchase-orders', '/api/purchase-orders', '/material-requests', '/api/material-requests', '/mobile/material-requests'],
    ];

    /** Kostenstellen sind gemeinsame Grundlage dieser Module (Verwaltung sichtbar, sobald eines aktiv ist). */
    public const COST_CENTER_MODULES = ['work_tracking', 'procurement'];

    /** @var array<string,bool>|null */
    private ?array $state = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function isEnabled(string $module): bool
    {
        $definition = self::DEFINITIONS[$module] ?? null;
        if ($definition === null || !$definition['implemented']) {
            return false;
        }
        return $module === self::CORE || ($this->state()[$module] ?? true);
    }

    /** Kostenstellenverwaltung und -auswahl stehen bereit, sobald ein nutzendes Modul aktiv ist. */
    public function costCentersEnabled(): bool
    {
        foreach (self::COST_CENTER_MODULES as $module) {
            if ($this->isEnabled($module)) {
                return true;
            }
        }
        return false;
    }

    /** Modul, dem ein Pfad gehört (null: Basissystem bzw. modulübergreifend). */
    public static function moduleForPath(string $path): ?string
    {
        foreach (self::ROUTES as $module => $prefixes) {
            foreach ($prefixes as $prefix) {
                if ($path === $prefix || str_starts_with($path, $prefix . '/') || str_starts_with($path, $prefix . '?')) {
                    return $module;
                }
            }
        }
        return null;
    }

    /**
     * Zentrale Routenprüfung: Fehlermeldung, wenn die Anfrage ein deaktiviertes Modul verändern
     * würde (neue Vorgänge und Bearbeitung); sonst null. Kostenstellen sind nur änderbar, solange
     * ein nutzendes Modul aktiv ist.
     */
    public function blockedMessage(string $method, string $path): ?string
    {
        if (in_array($method, ['GET', 'HEAD'], true)) {
            return null;
        }
        if (str_starts_with($path, '/api/cost-centers') && !$this->costCentersEnabled()) {
            return 'Kostenstellen werden nur mit aktiver Arbeitserfassung oder Beschaffung gepflegt.';
        }
        $module = self::moduleForPath($path);
        if ($module === null || $this->isEnabled($module)) {
            return null;
        }
        return self::disabledMessage($module);
    }

    public static function disabledMessage(string $module): string
    {
        return sprintf('Das Modul „%s“ ist deaktiviert. Vorhandene Daten bleiben lesbar; neue Vorgänge und Änderungen sind erst nach der Aktivierung unter Einstellungen → Module möglich.', self::DEFINITIONS[$module]['label'] ?? $module);
    }

    /**
     * Übersicht für Einstellungen → Module (nur Administratoren).
     *
     * @param array<string,mixed> $actor
     * @return list<array<string,mixed>>
     */
    public function overview(array $actor): array
    {
        $this->require($actor);
        $result = [];
        foreach (self::DEFINITIONS as $key => $definition) {
            $isEnabled = $this->isEnabled($key);
            $result[] = [
                'key' => $key, 'icon' => $definition['icon'], 'label' => $definition['label'], 'description' => $definition['description'],
                'note' => $definition['note'] ?? null, 'implemented' => $definition['implemented'],
                'core' => $key === self::CORE, 'enabled' => $isEnabled,
            ];
        }
        return $result;
    }

    /**
     * Modul aktivieren oder deaktivieren. Offene Vorgänge verhindern die Deaktivierung nicht, müssen
     * aber ausdrücklich bestätigt werden (confirmOpen). Es werden keine Daten verändert, gelöscht,
     * abgeschlossen, freigegeben oder storniert; offene Vorgänge bleiben in ihrem Zustand und sind
     * nach der Wiederaktivierung regulär weiter bearbeitbar.
     *
     * Eine technische Deaktivierungssperre ist nicht nötig: Der Schalter ist ein einzelner
     * atomarer Schreibvorgang; jede Fachänderung läuft in ihrer eigenen Transaktion mit den
     * bestehenden Sperren (Trigger, Versionen). Eine Anfrage, die die Modulprüfung vor dem
     * Umschalten passiert hat, wird regulär abgeschlossen; danach werden Änderungen abgelehnt.
     *
     * @param array<string,mixed> $actor
     * @return array{saved:bool,openItems:list<string>,modules:list<array<string,mixed>>}
     */
    public function setEnabled(string $module, bool $enabled, bool $confirmOpen, array $actor): array
    {
        $this->require($actor);
        $definition = self::DEFINITIONS[$module] ?? throw new DocumentValidationException(['Unbekanntes Modul.']);
        if ($module === self::CORE) {
            throw new DocumentValidationException(['Das Basissystem ist immer aktiv.']);
        }
        if (!$definition['implemented']) {
            throw new DocumentValidationException([$definition['label'] . ' ist noch nicht verfügbar.']);
        }
        if (!$enabled && $this->isEnabled($module)) {
            $open = $this->openItems($module);
            if ($open !== [] && !$confirmOpen) {
                return ['saved' => false, 'openItems' => $open, 'modules' => $this->overview($actor)];
            }
        }
        $this->pdo->prepare('INSERT INTO operational_settings (setting_key, setting_value, updated_by_user_id, updated_at) VALUES (:key, :value, :user, :now)
            ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_by_user_id = excluded.updated_by_user_id, updated_at = excluded.updated_at')
            ->execute(['key' => 'modules.' . $module, 'value' => $enabled ? '1' : '0', 'user' => (string) $actor['id'], 'now' => Clock::now()]);
        $this->state = null;
        return ['saved' => true, 'openItems' => [], 'modules' => $this->overview($actor)];
    }

    /**
     * Offene Vorgänge eines Moduls für den Bestätigungsdialog (bleiben unverändert erhalten).
     *
     * @return list<string>
     */
    public function openItems(string $module): array
    {
        $count = fn (string $sql): int => (int) $this->pdo->query($sql)->fetchColumn();
        $items = [];
        $add = static function (int $number, string $singular, string $plural) use (&$items): void {
            if ($number > 0) {
                $items[] = $number . ' ' . ($number === 1 ? $singular : $plural);
            }
        };
        if ($module === 'work_tracking') {
            $add($count("SELECT COUNT(*) FROM daily_reports WHERE status = 'draft'"), 'Tagesbericht im Entwurf', 'Tagesberichte im Entwurf');
            $add($count("SELECT COUNT(*) FROM daily_reports WHERE status IN ('submitted', 'reopened')"),
                'Tagesbericht wartet auf Festschreibung oder Korrektur', 'Tagesberichte warten auf Festschreibung oder Korrektur');
            $add($count("SELECT COUNT(*) FROM (SELECT DISTINCT employee_id, work_date FROM time_day_locks WHERE status IN ('submitted', 'reopened'))"),
                'Zeiterfassungstag ist eingereicht oder zur Korrektur geöffnet (Freigabe offen)', 'Zeiterfassungstage sind eingereicht oder zur Korrektur geöffnet (Freigaben offen)');
            $add($count("SELECT COUNT(*) FROM (SELECT DISTINCT e.employee_id, e.work_date FROM time_entries e
                WHERE NOT EXISTS (SELECT 1 FROM time_day_locks l WHERE l.employee_id = e.employee_id AND l.work_date = e.work_date AND l.status IN ('submitted', 'approved')))"),
                'Tag mit noch nicht eingereichten Arbeitszeiten', 'Tage mit noch nicht eingereichten Arbeitszeiten');
            $add($count("SELECT COUNT(*) FROM work_reports WHERE status = 'draft'"), 'Rapport im Entwurf', 'Rapporte im Entwurf');
            $add($count("SELECT COUNT(*) FROM work_reports WHERE status = 'submitted' AND superseded_by_id IS NULL"),
                'eingereichter Rapport ist noch nicht bearbeitet', 'eingereichte Rapporte sind noch nicht bearbeitet');
        }
        if ($module === 'procurement') {
            $add($count("SELECT COUNT(*) FROM purchase_orders WHERE status = 'draft'"), 'Bestellentwurf', 'Bestellentwürfe');
            $add($count("SELECT COUNT(*) FROM purchase_orders WHERE status = 'ordered' AND (delivery_status <> 'delivered' OR payment_status <> 'paid')"),
                'Bestellung ist noch nicht vollständig geliefert oder bezahlt', 'Bestellungen sind noch nicht vollständig geliefert oder bezahlt');
            $add($count("SELECT COUNT(*) FROM material_requests WHERE status = 'open'"), 'offene Materialanforderung', 'offene Materialanforderungen');
        }
        return $items;
    }

    /** @return array<string,bool> */
    private function state(): array
    {
        if ($this->state === null) {
            $rows = $this->pdo->query("SELECT setting_key, setting_value FROM operational_settings WHERE setting_key LIKE 'modules.%'")->fetchAll(PDO::FETCH_KEY_PAIR);
            $this->state = [];
            foreach ($rows as $key => $value) {
                $this->state[substr((string) $key, 8)] = $value !== '0';
            }
        }
        return $this->state;
    }

    /** @param array<string,mixed> $actor */
    private function require(array $actor): void
    {
        if (!RolePermissions::allows((string) ($actor['role'] ?? ''), 'operations.manage')) {
            throw new AccessDeniedException('operations.manage');
        }
    }
}
