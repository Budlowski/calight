<?php $title = 'Module'; $contentClass = 'content-wide'; ?>
<div class="admin-shell">
<?php $adminArea = 'settings'; $adminActive = 'modules'; require __DIR__ . '/../partials/admin-nav.php'; ?>
<div class="admin-content">
<div id="module-settings-app" data-csrf="<?= $escape($csrfToken) ?>">
    <div class="page-heading"><div><h1>Module</h1><p class="muted">Funktionsumfang für den gesamten Betrieb festlegen. Benutzerrechte gelten zusätzlich.</p></div></div>
    <div class="notice" data-message role="status" hidden></div>
    <div class="module-list" data-modules><p class="muted">Module werden geladen …</p></div>
    <p class="muted operations-note">Deaktivieren löscht keine Daten: Vorhandenes bleibt lesbar, neue Vorgänge und Änderungen sind bis zur Wiederaktivierung gesperrt. Offene Vorgänge werden vorher zur Bestätigung angezeigt und bleiben unverändert.</p>
</div>
</div>
</div>
<script src="/assets/module-settings.js?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/module-settings.js')) ?>" defer></script>
