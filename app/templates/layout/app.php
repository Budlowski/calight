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
<body<?= $currentUser !== null ? ' data-user-id="'.$escape((string)$currentUser['id']).'"' : '' ?>>
<?php if ($currentUser !== null): ?>
    <header class="topbar">
        <a class="brand" href="/dashboard">Calight</a>
        <div class="user-area">
            <span><?= $escape($currentUser['displayName']) ?></span>
            <form action="/logout" method="post">
                <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                <button class="button button-secondary" type="submit">Abmelden</button>
            </form>
        </div>
    </header>
    <div class="shell">
        <nav class="sidebar" aria-label="Hauptnavigation">
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
            <?= $navLink('/dashboard', 'layout-dashboard', 'Übersicht', ['dashboard.read']) ?>
            <?= $navLink('/customers', 'users', 'Kunden', ['customers.read']) ?>
            <?= $navLink('/projects', 'folder-kanban', 'Projekte', ['projects.read']) ?>
            <?= $navLink('/offers', 'file-text', 'Angebote', ['offers.read']) ?>
            <?= $navLink('/orders', 'clipboard-list', 'Aufträge', ['orders.read']) ?>

            <span class="sidebar-section-label">Ausführung &amp; Abrechnung</span>
            <?= $navLink('/daily-reports', 'calendar-days', 'Bautagesberichte', ['reports.read']) ?>
            <a href="#" aria-disabled="true" tabindex="-1" data-icon="ruler">Aufmaße</a>
            <a href="#" aria-disabled="true" tabindex="-1" data-icon="clipboard-check">Rapporte</a>
            <a href="#" aria-disabled="true" tabindex="-1" data-icon="package">Bestellungen</a>
            <a href="#" aria-disabled="true" tabindex="-1" data-icon="chart-no-axes-column-increasing">Nachkalkulation</a>

            <?php $managementLinks = $navLink('/master-data', 'database', 'Stammdaten', ['master_data.read', 'costs.read']) . $navLink('/settings', 'settings', 'Einstellungen', ['settings.manage']); ?>
            <div class="sidebar-management"<?= $managementLinks === '' ? ' hidden' : '' ?>>
                <span class="sidebar-section-label">Verwaltung</span>
                <?= $managementLinks ?>
            </div>
        </nav>
        <main class="content <?= $escape($contentClass ?? '') ?>">
<?php else: ?>
        <main class="content content-narrow">
<?php endif; ?>
            <?php foreach ($flashMessages as $flash): ?>
                <div class="notice notice-<?= $escape($flash['type']) ?>" role="status"><?= $escape($flash['message']) ?></div>
            <?php endforeach; ?>
            <?= $content ?>
        </main>
<?php if ($currentUser !== null): ?>
    </div>
<?php endif; ?>
<script src="/assets/ui.js?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/ui.js')) ?>" defer></script>
</body>
</html>
