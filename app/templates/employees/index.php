<?php $title='Mitarbeiter';$contentClass='content-wide'; ?>
<div class="admin-shell">
<?php $adminArea = 'master-data'; $adminActive = 'employees'; require __DIR__ . '/../partials/admin-nav.php'; ?>
<div class="admin-content">
<div id="employees-app" data-csrf="<?= $escape($csrfToken) ?>" data-can-create-user="<?= \App\Http\Session::can('users.manage') ? '1' : '0' ?>">
    <div class="page-heading"><div><h1>Mitarbeiter</h1><p class="muted">Mitarbeiter, interne Stundenkostensätze und die Zuordnung zu Benutzerkonten verwalten. Mitarbeiter und Benutzerkonten sind getrennte Einträge.</p></div><button class="button" type="button" data-new data-icon="plus">Mitarbeiter anlegen</button></div>
    <div class="notice" data-message role="status" hidden></div>
    <div class="management-toolbar employee-toolbar"><input type="search" data-search placeholder="Name, Personalnummer oder Benutzername durchsuchen" aria-label="Mitarbeiter suchen"><label class="check-inline"><input type="checkbox" data-show-inactive> Inaktive anzeigen</label><span class="muted" data-count></span></div>
    <div class="master-split management-split">
        <div class="service-list management-list" data-list></div>
        <form id="employee-form" class="card form-stack master-editor management-editor" data-form hidden novalidate>
            <div class="editor-heading"><div><h2 data-form-title>Mitarbeiter</h2><p class="muted">Der Kostensatz wird bei jeder Zeitbuchung als unveränderlicher Snapshot gespeichert; Änderungen wirken nur auf künftige Buchungen.</p></div></div>
            <div class="form-grid employee-form-grid">
                <label><span class="field-label">Anzeigename <span aria-hidden="true">*</span></span><input name="displayName" maxlength="150" required></label>
                <label><span class="field-label">Personalnummer (optional)</span><input name="personnelNumber" maxlength="50"></label>
                <label><span class="field-label">Interner Stundenkostensatz € <span aria-hidden="true">*</span></span><input name="hourlyCostRate" inputmode="decimal" required></label>
            </div>
            <label class="check-inline"><input name="active" type="checkbox" checked> Mitarbeiter aktiv</label>
            <fieldset class="employee-account">
                <legend>Benutzerkonto</legend>
                <label class="check-inline"><input type="radio" name="accountMode" value="none" checked> Kein Benutzerkonto</label>
                <label class="check-inline"><input type="radio" name="accountMode" value="existing"> Vorhandenes Benutzerkonto verknüpfen</label>
                <label class="check-inline" data-new-account-option><input type="radio" name="accountMode" value="new"> Neues Benutzerkonto anlegen und verknüpfen</label>
                <div data-account-existing hidden><label><span class="field-label">Benutzerkonto</span><select name="userId"></select></label><small class="muted">Nur Konten ohne Mitarbeiter sind wählbar.</small></div>
                <div class="form-grid" data-account-new hidden>
                    <label><span class="field-label">Benutzername <span aria-hidden="true">*</span></span><input name="newUsername" maxlength="64" autocomplete="off" autocapitalize="none" spellcheck="false"></label>
                    <label><span class="field-label">Rolle</span><select name="newRole"></select></label>
                    <label><span class="field-label">Passwort <span aria-hidden="true">*</span></span><input name="newPassword" type="password" minlength="8" autocomplete="new-password"></label>
                    <label><span class="field-label">Passwort wiederholen <span aria-hidden="true">*</span></span><input name="newPasswordRepeat" type="password" minlength="8" autocomplete="new-password"></label>
                </div>
                <p class="muted" data-account-note></p>
            </fieldset>
            <p class="field-error" data-error hidden></p>
            <div class="form-actions"><button class="button" type="submit" data-save data-icon="save">Speichern</button><button class="button button-light" type="button" data-cancel>Abbrechen</button></div>
        </form>
    </div>
</div>
</div>
</div>
<script src="/assets/employees.js?v=<?= $escape((string)filemtime(dirname(__DIR__,2).'/public/assets/employees.js')) ?>" defer></script>
