<?php $title='Auftragsabwicklung';$contentClass='content-wide'; ?>
<div class="admin-shell">
<?php $adminArea = 'settings'; $adminActive = 'orders'; require __DIR__ . '/../partials/admin-nav.php'; ?>
<div class="admin-content">
<div id="operations-settings-app" data-csrf="<?= $escape($csrfToken) ?>" data-groups="orders">
    <div class="page-heading settings-heading"><div><h1>Auftragsabwicklung</h1><p class="muted">Welche Aufträge Monteure sehen. Büro und Administratoren sehen immer alle Aufträge.</p></div><button class="button" type="button" data-save data-shortcut-save data-icon="save">Speichern</button></div>
    <div class="notice" data-message role="status" hidden></div>
    <p class="muted operations-dirty" data-dirty-state aria-live="polite"></p>
    <div class="form-stack" data-settings><p class="muted">Einstellungen werden geladen …</p></div>
    <p class="muted operations-note">Die Zuordnung erfolgt auf der Auftragsdetailseite unter „Zugeordnete Mitarbeiter“. Entfernte Zuordnungen löschen keine Zeiten, Berichte oder Entwürfe; eigene Vorgänge bleiben für Monteure erreichbar.</p>
</div>
</div>
</div>
<script src="/assets/operations-settings.js?v=<?= $escape((string)filemtime(dirname(__DIR__,2).'/public/assets/operations-settings.js')) ?>" defer></script>
