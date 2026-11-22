<?php

declare(strict_types=1);

namespace App\Security;

final class RolePermissions
{
    /** @var array<string,list<string>> */
    private const PERMISSIONS = [
        'admin' => ['*'],
        // Nur fuer bereits vor der Migration bestehende PHP-Sitzungen. In der
        // Datenbank wird die alte Rolle beim Migrieren zu admin uebernommen.
        'employee' => ['*'],
        'office' => [
            'dashboard.read', 'customers.read', 'customers.write', 'projects.read', 'projects.write',
            'offers.read', 'offers.write', 'orders.read', 'orders.write', 'master_data.read',
            'master_data.write', 'costs.read', 'settings.manage', 'employees.manage',
            'reports.read', 'reports.write', 'materials.manage',
            'measurements.read', 'measurements.write', 'measurements.complete',
            'workreports.read', 'workreports.write', 'workreports.process',
            // Bestellungen (Phase 3.4.1); Beträge zusätzlich nur mit costs.read.
            'purchases.read', 'purchases.write',
            // Zentrale Dokumentenübersicht und Anhänge (Phase 3.4.2); Rechte der Fachmodule gelten zusätzlich.
            'documents.read',
            // Zeiterfassung (Phase 3.4.3): eigene Zeiten sowie Auswertung/Korrektur aller Mitarbeiter.
            'timesheet.own', 'timesheet.manage',
            // Wechsel zwischen Büro- und mobiler Oberfläche (Benutzermenü); ändert keine weiteren Rechte.
            'interface.switch',
            // Verkaufsseitige Werte (Preise, Auftragssummen, Aufmaßbeträge); Monteure haben es nicht.
            'commercial.read',
        ],
        'technician' => ['dashboard.read', 'orders.read', 'reports.read', 'reports.write', 'measurements.read', 'measurements.write', 'workreports.read', 'workreports.write', 'timesheet.own'],
    ];

    /** Zuweisbare Rollen (Datenbankwerte) mit Bezeichnung. */
    public const ROLES = ['admin' => 'Administrator', 'office' => 'Büro', 'technician' => 'Monteur'];

    /**
     * Verständliche Darstellung der Rechte für Einstellungen → Berechtigungen (nur Anzeige, keine
     * zweite Rechtematrix). Gruppiert nach Bereichen; maßgeblich bleibt PERMISSIONS.
     *
     * @var array<string,array<string,string>>
     */
    public const DESCRIPTIONS = [
        'Allgemein' => [
            'dashboard.read' => 'Übersicht und mobile Startseite',
            'interface.switch' => 'Zwischen Büro- und mobiler Oberfläche wechseln',
            'documents.read' => 'Dokumentenübersicht und Anhänge',
        ],
        'Kunden und Aufträge' => [
            'customers.read' => 'Kunden ansehen', 'customers.write' => 'Kunden bearbeiten',
            'projects.read' => 'Projekte ansehen', 'projects.write' => 'Projekte bearbeiten',
            'offers.read' => 'Angebote ansehen', 'offers.write' => 'Angebote bearbeiten',
            'orders.read' => 'Aufträge ansehen (Monteure: nur freigegebene)', 'orders.write' => 'Aufträge bearbeiten, Berichte zuordnen',
            'commercial.read' => 'Verkaufspreise, Auftrags- und Aufmaßsummen sehen',
        ],
        'Ausführung' => [
            'reports.read' => 'Tagesberichte und Materialbedarf ansehen', 'reports.write' => 'Tagesberichte und Materialbedarf erfassen',
            'materials.manage' => 'Materialbedarf erledigen',
            'measurements.read' => 'Aufmaße ansehen', 'measurements.write' => 'Aufmaße erfassen', 'measurements.complete' => 'Aufmaße abschließen',
            'workreports.read' => 'Rapporte ansehen', 'workreports.write' => 'Rapporte erfassen', 'workreports.process' => 'Rapporte kaufmännisch bearbeiten',
            'timesheet.own' => 'Eigene Arbeitszeiten erfassen', 'timesheet.manage' => 'Zeiten aller Mitarbeiter auswerten, korrigieren, freigeben',
        ],
        'Beschaffung und Kosten' => [
            'purchases.read' => 'Bestellungen ansehen', 'purchases.write' => 'Bestellungen bearbeiten',
            'costs.read' => 'Interne Kosten und Kostensätze sehen',
        ],
        'Verwaltung' => [
            'master_data.read' => 'Stammdaten ansehen', 'master_data.write' => 'Stammdaten bearbeiten',
            'employees.manage' => 'Mitarbeiter verwalten', 'cost_centers.manage' => 'Kostenstellen verwalten',
            'settings.manage' => 'Firmen- und Dokumenteinstellungen', 'operations.manage' => 'Module und Arbeitserfassungseinstellungen',
            'users.manage' => 'Benutzerkonten verwalten',
            'system.purge' => 'Datenbereinigung vor der Produktivnahme (nur Administratoren)',
        ],
    ];

    public static function allows(string $role, string $permission): bool
    {
        $permissions = self::PERMISSIONS[$role] ?? [];
        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    /** @return list<string> */
    public static function forRole(string $role): array
    {
        return self::PERMISSIONS[$role] ?? [];
    }
}
