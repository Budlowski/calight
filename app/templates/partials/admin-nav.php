<?php
/**
 * Gemeinsame vertikale Unternavigation für Stammdaten und Einstellungen.
 *
 * Erwartet $adminArea ('master-data' | 'settings') und $adminActive (Schlüssel des aktiven
 * Bereichs). Einträge erscheinen nur mit Recht und aktivem Modul; Gruppen nur mit Einträgen.
 * Bereiche derselben Seite (Reiter über URL-Hash, z. B. /master-data#services) tragen data-tab
 * bzw. data-settings-tab, damit die Seitenskripte ohne Neuladen umschalten können.
 * Auf kleinen Bildschirmen erscheint dieselbe Liste in einem einklappbaren Menü.
 */
use App\Http\Session;

$can = static function (string ...$permissions): bool {
    foreach ($permissions as $permission) {
        if (!Session::can($permission)) {
            return false;
        }
    }
    return true;
};
$costCenters = $modules === null || $modules->costCentersEnabled();
$catalog = $can('master_data.read', 'costs.read');

$groups = $adminArea === 'master-data'
    ? [
        'Kalkulation' => [
            ['key' => 'cost-items', 'label' => 'Artikelstamm', 'href' => '/master-data#cost-items', 'tab' => 'cost-items', 'visible' => $catalog],
            ['key' => 'services', 'label' => 'Positionsstamm', 'href' => '/master-data#services', 'tab' => 'services', 'visible' => $catalog],
            ['key' => 'trailing-texts', 'label' => 'Schlepptexte', 'href' => '/master-data#trailing-texts', 'tab' => 'trailing-texts', 'visible' => $catalog],
            ['key' => 'categories', 'label' => 'Kategorien', 'href' => '/master-data#categories', 'tab' => 'categories', 'visible' => $catalog],
        ],
        'Organisation' => [
            ['key' => 'employees', 'label' => 'Mitarbeiter', 'href' => '/master-data/employees', 'visible' => $can('employees.manage', 'costs.read')],
            ['key' => 'cost-centers', 'label' => 'Kostenstellen', 'href' => '/master-data/cost-centers', 'visible' => $costCenters && $can('master_data.read')],
        ],
        'Geschäftspartner' => [
            ['key' => 'suppliers', 'label' => 'Lieferanten', 'href' => '/master-data#suppliers', 'tab' => 'suppliers', 'visible' => $catalog],
        ],
    ]
    : [
        'Allgemein' => [
            ['key' => 'company', 'label' => 'Unternehmen', 'href' => '/settings#settings-company', 'settingsTab' => 'company', 'visible' => $can('settings.manage')],
            ['key' => 'finance', 'label' => 'Steuern & Bank', 'href' => '/settings#settings-finance', 'settingsTab' => 'finance', 'visible' => $can('settings.manage')],
            ['key' => 'modules', 'label' => 'Module', 'href' => '/settings/modules', 'visible' => $can('operations.manage')],
        ],
        'Benutzerverwaltung' => [
            ['key' => 'users', 'label' => 'Benutzerkonten', 'href' => '/settings/users', 'visible' => $can('users.manage')],
            ['key' => 'permissions', 'label' => 'Rollen & Rechte', 'href' => '/settings/permissions', 'visible' => $can('users.manage')],
        ],
        'Dokumente' => [
            ['key' => 'document', 'label' => 'Dokumentlayout', 'href' => '/settings#settings-document', 'settingsTab' => 'document', 'visible' => $can('settings.manage')],
            ['key' => 'terms', 'label' => 'Angebotsbedingungen', 'href' => '/settings#settings-terms', 'settingsTab' => 'terms', 'visible' => $can('settings.manage')],
        ],
        'Fachmodule' => [
            ['key' => 'work-tracking', 'label' => 'Arbeitserfassung', 'href' => '/settings/work-tracking', 'visible' => $can('operations.manage') && $moduleEnabled('work_tracking')],
        ],
    ];
$groups = array_filter(array_map(static fn (array $items): array => array_values(array_filter($items, static fn (array $item): bool => $item['visible'])), $groups));
$activeLabel = '';
foreach ($groups as $items) {
    foreach ($items as $item) {
        if ($item['key'] === $adminActive) {
            $activeLabel = $item['label'];
        }
    }
}
$renderLinks = static function () use ($groups, $adminActive, $escape): string {
    $html = '';
    foreach ($groups as $label => $items) {
        $html .= '<div class="admin-nav-group"><span class="admin-nav-label">' . $escape($label) . '</span>';
        foreach ($items as $item) {
            $attributes = isset($item['tab']) ? ' data-tab="' . $escape($item['tab']) . '"' : '';
            $attributes .= isset($item['settingsTab']) ? ' data-settings-tab="' . $escape($item['settingsTab']) . '"' : '';
            $attributes .= $item['key'] === $adminActive ? ' aria-current="page"' : '';
            $html .= '<a class="admin-nav-link" href="' . $escape($item['href']) . '"' . $attributes . '>' . $escape($item['label']) . '</a>';
        }
        $html .= '</div>';
    }
    return $html;
};
$areaLabel = $adminArea === 'master-data' ? 'Stammdaten' : 'Einstellungen';
?>
<nav class="admin-nav" aria-label="<?= $escape($areaLabel) ?>"><?= $renderLinks() ?></nav>
<details class="admin-nav-compact">
    <summary><span class="muted"><?= $escape($areaLabel) ?>:</span> <strong data-admin-current><?= $escape($activeLabel ?: 'Bereich wählen') ?></strong></summary>
    <nav aria-label="<?= $escape($areaLabel) ?> (kompakt)"><?= $renderLinks() ?></nav>
</details>
