<?php $title='Mitarbeiter';$contentClass='content-wide'; ?>
<div id="employees-app" data-csrf="<?= $escape($csrfToken) ?>">
    <nav class="breadcrumb" aria-label="Brotkrümelnavigation"><ol><li><a href="/settings">Einstellungen</a></li><li aria-current="page">Mitarbeiter</li></ol></nav>
    <div class="page-heading"><div><h1>Mitarbeiter</h1><p class="muted">Mitarbeiter, optionale Benutzerkonten und interne Stundenkostensätze verwalten.</p></div><button class="button" type="button" data-new data-icon="plus">Mitarbeiter anlegen</button></div>
    <div class="management-toolbar employee-toolbar"><input type="search" data-search placeholder="Name oder Personalnummer durchsuchen"><label class="check-inline"><input type="checkbox" data-show-inactive> Inaktive anzeigen</label><span class="muted" data-count></span></div>
    <div class="master-split management-split"><div class="service-list management-list" data-list></div><form id="employee-form" class="card form-stack master-editor management-editor" data-form hidden novalidate>
        <div class="editor-heading"><div><h2 data-form-title>Mitarbeiter</h2><p class="muted">Der Kostensatz wird bei jeder Zeitbuchung als unveränderlicher Snapshot gespeichert.</p></div></div>
        <div class="form-grid employee-form-grid"><label>Anzeigename<input name="displayName" maxlength="150" required></label><label>Personalnummer (optional)<input name="personnelNumber" maxlength="50"></label><label>Benutzerkonto (optional)<select name="userId"><option value="">Kein Benutzerkonto</option></select></label><label>Interner Stundenkostensatz €<input name="hourlyCostRate" inputmode="decimal" required></label></div>
        <label class="check-inline"><input name="active" type="checkbox" checked> Mitarbeiter aktiv</label><p class="field-error" data-error hidden></p>
        <div class="form-actions"><button class="button" type="submit" data-icon="save">Speichern</button><button class="button button-light" type="button" data-cancel>Abbrechen</button></div>
    </form></div>
</div>
<script src="/assets/employees.js?v=<?= $escape((string)filemtime(dirname(__DIR__,2).'/public/assets/employees.js')) ?>" defer></script>
