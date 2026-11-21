<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($title ?? 'Kalkulationssoftware') ?></title>
    <link rel="stylesheet" href="/assets/app.css?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/app.css')) ?>">
    <script src="/assets/calight.js?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/calight.js')) ?>" defer></script>
    <script src="/assets/modal.js?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/modal.js')) ?>" defer></script>
    <script src="/assets/list-view.js?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/list-view.js')) ?>" defer></script>
</head>
<?php
// Seite eines deaktivierten Moduls: dynamische Oberflächen blenden Bearbeitungsaktionen aus (Calight.moduleReadOnly).
$pageModule = \App\Application\ModuleService::moduleForPath((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'));
$pageReadOnly = $pageModule !== null && !$moduleEnabled($pageModule);
?>
<body<?= $currentUser !== null ? ' data-user-id="'.$escape((string)$currentUser['id']).'"' : '' ?><?= $pageReadOnly ? ' data-module-readonly="1"' : '' ?>>
<?php
// Kundenansichten (z. B. Unterschrift) zeigen bewusst keine Calight-Navigation.
$showShell = $currentUser !== null && !($bareLayout ?? false);
if ($showShell): ?>
    <header class="topbar">
        <div class="topbar-start">
            <button class="nav-toggle" type="button" data-nav-toggle aria-controls="main-navigation" aria-expanded="false" aria-label="Navigation öffnen" title="Navigation">
                <span class="ui-icon icon-menu" aria-hidden="true"></span>
            </button>
            <a class="brand" href="/dashboard">Calight</a>
        </div>
        <div class="user-area">
            <span class="user-name"><?= $escape($currentUser['displayName']) ?></span>
            <?php if (\App\Application\UserInterface::canSwitch($currentUser)): ?>
            <form action="/interface" method="post">
                <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                <input type="hidden" name="interface" value="mobile">
                <button class="button button-secondary" type="submit" title="Zur mobilen Oberfläche wechseln (wird als bevorzugte Oberfläche gespeichert)">Mobile Ansicht</button>
            </form>
            <?php endif; ?>
            <form action="/logout" method="post">
                <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                <button class="button button-secondary" type="submit">Abmelden</button>
            </form>
        </div>
    </header>
    <div class="shell">
        <div class="nav-backdrop" data-nav-backdrop hidden></div>
        <nav class="sidebar" id="main-navigation" aria-label="Hauptnavigation">
<?php
            // Menüpunkte nur mit Leserecht anzeigen; die Zugriffskontrolle selbst bleibt serverseitig.
            $currentPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
            $navLink = static function (string $href, string $icon, string $label, array $permissions) use ($escape, $currentPath): string {
                foreach ($permissions as $permission) {
                    if (!\App\Http\Session::can($permission)) {
                        return '';
                    }
                }
                $active = $currentPath === $href || str_starts_with($currentPath, $href . '/');
                return '<a href="' . $escape($href) . '" data-icon="' . $escape($icon) . '"' . ($active ? ' aria-current="page"' : '') . '>' . $escape($label) . '</a>';
            };
?>
            <?php
            // Gruppen erscheinen nur mit mindestens einem sichtbaren Eintrag. Einträge erscheinen nur
            // für implementierte, aktivierte Module (ModuleService) und mit Leserecht. Noch nicht
            // implementierte Bereiche (Kontakte, Kalender, Abrechnung, Nachkalkulation) werden erst
            // mit ihrer Umsetzung aufgenommen – keine Platzhalter.
            $navGroup = static function (string $label, string $links) use ($escape): string {
                return $links === '' ? '' : '<span class="sidebar-section-label">' . $escape($label) . '</span>' . $links;
            };
            $workTracking = $moduleEnabled('work_tracking');
            $procurement = $moduleEnabled('procurement');
            // Beschaffung: Bestellungen mit Materialbedarf als Reiter; ohne Bestellrecht (z. B. Monteure)
            // führt der Eintrag direkt zum Materialbedarf.
            $procurementLink = '';
            if ($procurement) {
                $procurementLink = \App\Http\Session::can('purchases.read')
                    ? $navLink('/purchase-orders', 'shopping-cart', 'Bestellungen', ['purchases.read'])
                    : $navLink('/material-requests', 'package', 'Materialbedarf', ['reports.read']);
                if (\App\Http\Session::can('purchases.read') && str_starts_with($currentPath, '/material-requests')) {
                    $procurementLink = str_replace('<a ', '<a aria-current="page" ', $procurementLink);
                }
            }
            ?>
            <?= $navGroup('Allgemein', $navLink('/dashboard', 'layout-dashboard', 'Übersicht', ['dashboard.read'])
                . $navLink('/customers', 'users', 'Kunden', ['customers.read'])
                . $navLink('/projects', 'folder-kanban', 'Projekte', ['projects.read'])
                . $navLink('/documents', 'files', 'Dokumente', ['documents.read'])) ?>
            <?= $navGroup('Auftragsabwicklung', $navLink('/offers', 'file-text', 'Angebote', ['offers.read'])
                . $navLink('/orders', 'clipboard-list', 'Aufträge', ['orders.read'])
                . $navLink('/measurements', 'ruler', 'Aufmaße', ['measurements.read'])) ?>
            <?= $navGroup('Arbeitserfassung', $workTracking ? $navLink('/time-tracking', 'clock-3', 'Zeiterfassung', ['timesheet.own'])
                . $navLink('/daily-reports', 'calendar-days', 'Tagesberichte', ['reports.read'])
                . $navLink('/work-reports', 'clipboard-check', 'Rapporte', ['workreports.read']) : '') ?>
            <?= $navGroup('Beschaffung', $procurementLink) ?>

            <?php $managementLinks = $navLink('/master-data', 'database', 'Stammdaten', ['master_data.read', 'costs.read']) . $navLink('/settings', 'settings', 'Einstellungen', ['settings.manage']); ?>
            <div class="sidebar-management"<?= $managementLinks === '' ? ' hidden' : '' ?>>
                <span class="sidebar-section-label">Verwaltung</span>
                <?= $managementLinks ?>
            </div>
        </nav>
        <main class="content <?= $escape($contentClass ?? '') ?>">
<?php else: ?>
        <main class="content <?= ($bareLayout ?? false) ? 'content-bare' : 'content-narrow' ?>">
<?php endif; ?>
            <?php
            // Seiten eines deaktivierten Moduls bleiben für historische Daten lesbar (Direktlinks).
            if ($pageReadOnly): ?>
                <div class="notice notice-warning module-notice" role="status"><?= $escape(\App\Application\ModuleService::disabledMessage($pageModule)) ?></div>
            <?php endif; ?>
            <?php foreach ($flashMessages as $flash): ?>
                <div class="notice notice-<?= $escape($flash['type']) ?>" role="status"><?= $escape($flash['message']) ?></div>
            <?php endforeach; ?>
            <?= $content ?>
        </main>
<?php if ($showShell): ?>
    </div>
<?php endif; ?>
<script src="/assets/ui.js?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/ui.js')) ?>" defer></script>
</body>
</html>
