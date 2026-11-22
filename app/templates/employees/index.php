<?php $title='Mitarbeiter';$contentClass='content-wide'; ?>
<div class="admin-shell">
<?php $adminArea = 'master-data'; $adminActive = 'employees'; require __DIR__ . '/../partials/admin-nav.php'; ?>
<div class="admin-content">
<div id="employees-app" data-csrf="<?= $escape($csrfToken) ?>" data-can-create-user="<?= \App\Http\Session::can('users.manage') ? '1' : '0' ?>" data-can-read-costs="<?= \App\Http\Session::can('costs.read') ? '1' : '0' ?>">
    <div class="page-heading"><div><h1>Mitarbeiter</h1><p class="muted">Mitarbeiter, interne Stundenkostensätze und die Zuordnung zu Benutzerkonten verwalten. Mitarbeiter und Benutzerkonten sind getrennte Einträge.</p></div><button class="button" type="button" data-new data-icon="plus">Mitarbeiter anlegen</button></div>
    <div class="notice" data-message role="status" hidden></div>
    <div class="management-toolbar employee-toolbar"><input type="search" data-search placeholder="Name, Personalnummer oder Benutzername durchsuchen" aria-label="Mitarbeiter suchen"><label class="check-inline"><input type="checkbox" data-show-inactive> Inaktive anzeigen</label><span class="muted" data-count></span></div>
    <div class="service-list management-list employee-list" data-list></div>
</div>
</div>
</div>
<script src="/assets/employees.js?v=<?= $escape((string)filemtime(dirname(__DIR__,2).'/public/assets/employees.js')) ?>" defer></script>
