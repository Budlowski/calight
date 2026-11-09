<?php $title='Projekte'; $contentClass='content-wide'; ?>
<div id="customer-project-app" data-page="projects" data-csrf="<?= $escape($csrfToken) ?>">
    <div class="page-heading"><div><h1>Projekte</h1><p class="muted">Projekte, Kundenbezug und Baustellenanschriften verwalten.</p></div></div>
    <div id="admin-message" class="notice" hidden></div>
    <div class="master-toolbar management-toolbar project-toolbar"><input id="project-search" type="search" placeholder="Nummer, Projekt, Kunde, Ansprechpartner oder Ort durchsuchen"><select id="project-status-filter"><option value="">Alle Status</option><option value="draft">Entwurf</option><option value="active">Aktiv</option><option value="completed">Abgeschlossen</option><option value="archived">Archiviert</option></select><select id="project-sort"><option value="updated">Zuletzt geändert</option><option value="name">Projektname A–Z</option><option value="number">Projektnummer</option></select><span class="management-result-count" id="project-result-count"></span><button class="button button-light" id="project-reset" type="button" data-icon="refresh-cw">Zurücksetzen</button><button class="button" id="new-project" type="button">Neues Projekt</button></div>
    <div class="master-split management-split customer-project-split">
        <div id="project-list" class="service-list management-list"></div>
        <form id="project-form" class="card form-stack master-editor management-editor" hidden>
            <input name="id" type="hidden"><div class="editor-heading"><h2>Projekt</h2></div>
            <div class="form-grid project-head-grid">
                <div class="service-number-field"><div class="service-number-heading"><label for="project-number">Projektnummer</label><label class="check-inline auto-number-option"><input name="autoProjectNumber" type="checkbox" checked> Automatisch</label></div><input id="project-number" name="projectNumber" maxlength="100"></div>
                <label>Projektname<input name="name" maxlength="200" required></label><label>Status<select name="status"><option value="draft">Entwurf</option><option value="active">Aktiv</option><option value="completed">Abgeschlossen</option><option value="archived">Archiviert</option></select></label>
            </div>
            <label>Projektbeschreibung<textarea name="description" rows="3" maxlength="10000"></textarea></label>
            <div class="form-grid"><label>Kunde<select name="customerId"><option value="">Kein Kunde zugeordnet</option></select></label><label>Ansprechpartner<select name="contactId"><option value="">Kein Ansprechpartner</option></select></label></div>
            <label data-legacy-customer>Freier Kundenname für Altprojekt<input name="customerName" maxlength="200"></label>
            <h3>Baustellenadresse</h3><div class="address-grid"><label>Straße<input name="siteStreet" maxlength="200"></label><label>Hausnummer<input name="siteHouseNumber" maxlength="30"></label><label>PLZ<input name="sitePostalCode" maxlength="20"></label><label>Ort<input name="siteCity" maxlength="120"></label><label>Land<input name="siteCountry" value="Deutschland" maxlength="100"></label></div>
            <p id="legacy-site-address" class="notice" hidden></p>
            <div class="form-actions"><button class="button" type="submit">Projekt speichern</button><button class="button button-light" type="button" data-cancel>Abbrechen</button><a id="project-offers-link" class="button button-light" data-icon="folder-kanban" href="#" hidden>Angebote öffnen</a></div>
        </form>
    </div>
</div>
<script src="/assets/customer-projects.js?v=<?= $escape((string) filemtime(dirname(__DIR__,2).'/public/assets/customer-projects.js')) ?>" defer></script>
