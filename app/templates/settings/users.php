<?php $title = 'Benutzerkonten'; $contentClass = 'content-wide'; ?>
<div class="admin-shell">
<?php $adminArea = 'settings'; $adminActive = 'users'; require __DIR__ . '/../partials/admin-nav.php'; ?>
<div class="admin-content">
<div id="users-app" class="overview-page" data-csrf="<?= $escape($csrfToken) ?>">
    <div class="page-heading">
        <div><h1>Benutzerkonten</h1><p class="muted">Anmeldungen, Rollen und Zuordnung zu Mitarbeitern. Konten werden nicht gelöscht, sondern deaktiviert.</p></div>
        <button class="button" type="button" data-new data-icon="plus">Benutzer anlegen</button>
    </div>
    <div class="notice" data-message role="status" hidden></div>
    <div class="overview-toolbar">
        <input type="search" data-search placeholder="Benutzername, Name oder Mitarbeiter suchen" aria-label="Benutzer suchen">
        <label class="check-inline"><input type="checkbox" data-show-inactive> Deaktivierte anzeigen</label>
        <span class="management-result-count" data-count></span>
    </div>
    <div class="overview-results" data-list aria-live="polite"><p class="empty-state">Benutzerkonten werden geladen …</p></div>
    <p class="muted operations-note">Passwörter werden nur als Hash gespeichert und nie angezeigt. Ein Zurücksetzen des Passworts oder eine Deaktivierung beendet bestehende Sitzungen des Kontos sofort. Rechte der Rollen siehe <a href="/settings/permissions">Rollen &amp; Rechte</a>.</p>
</div>
</div>
</div>
<script src="/assets/users.js?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/users.js')) ?>" defer></script>
