<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($title ?? 'Kalkulationssoftware') ?></title>
    <link rel="stylesheet" href="/assets/app.css?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/app.css')) ?>">
    <script src="/assets/modal.js?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/modal.js')) ?>" defer></script>
</head>
<body>
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
            <a href="/dashboard" data-icon="layout-dashboard">Übersicht</a>
            <a href="/customers" data-icon="users">Kunden</a>
            <a href="/projects" data-icon="folder-kanban">Projekte</a>
            <a href="/offers" data-icon="file-text">Angebote</a>
            <a href="/orders" data-icon="clipboard-list">Aufträge</a>

            <span class="sidebar-section-label">Ausführung &amp; Abrechnung</span>
            <a href="#" aria-disabled="true" tabindex="-1" data-icon="calendar-days">Bautagesberichte</a>
            <a href="#" aria-disabled="true" tabindex="-1" data-icon="ruler">Aufmaße</a>
            <a href="#" aria-disabled="true" tabindex="-1" data-icon="clipboard-check">Rapporte</a>
            <a href="#" aria-disabled="true" tabindex="-1" data-icon="package">Bestellungen</a>
            <a href="#" aria-disabled="true" tabindex="-1" data-icon="chart-no-axes-column-increasing">Nachkalkulation</a>

            <div class="sidebar-management">
                <span class="sidebar-section-label">Verwaltung</span>
                <a href="/master-data" data-icon="database">Stammdaten</a>
                <a href="/settings" data-icon="settings">Einstellungen</a>
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
