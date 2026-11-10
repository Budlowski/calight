<?php $title='Angebote'; $contentClass='content-wide'; ?>
<div id="offers-overview" data-csrf="<?= $escape($csrfToken) ?>">
    <div class="page-heading"><div><h1>Angebote</h1><p class="muted">Angebote und auftragsbezogene Nachträge verwalten, abschließen und einsehen.</p></div></div>
    <div id="offers-message" class="notice" hidden></div>
    <div class="master-toolbar management-toolbar offer-toolbar"><input id="offer-search" type="search" placeholder="Nummer, Bezeichnung, Kunde, Projekt oder Auftrag durchsuchen"><select id="offer-document-type"><option value="">Alle Dokumentarten</option><option value="offer">Angebote</option><option value="change_order">Nachträge</option></select><select id="offer-status"><option value="">Alle Status</option><option value="draft">Entwurf</option><option value="submitted">Abgegeben</option><option value="commissioned">Angenommen</option><option value="rejected">Abgelehnt</option></select><select id="offer-sort"><option value="updated">Zuletzt geändert</option><option value="title">Bezeichnung A–Z</option><option value="number">Dokumentnummer</option><option value="net-desc">Nettosumme absteigend</option></select><span class="management-result-count" id="offer-result-count"></span><button class="button button-light" id="offer-reset" type="button" data-icon="refresh-cw">Zurücksetzen</button><button class="button" id="new-offer" type="button" data-icon="plus">Neues Angebot</button></div>
    <div class="master-split management-split offer-management-split">
        <div id="offer-list" class="service-list management-list"></div>
        <form id="new-offer-form" class="card form-stack master-editor management-editor" hidden>
            <div class="editor-heading"><div><h2>Angebotsentwurf</h2><p class="muted">Kunde ist erforderlich, ein Projekt bleibt optional.</p></div></div>
            <label>Kunde<select name="customerId" required><option value="">Kunde auswählen …</option><?php foreach($customers as $customer): ?><option value="<?= $escape($customer['id']) ?>"><?= $escape(($customer['customer_number']?:'Ohne Nr.').' · '.$customer['name']) ?></option><?php endforeach; ?></select></label>
            <label>Projekt (optional)<select name="projectId"><option value="">Ohne Projekt</option><?php foreach($projects as $project): ?><option value="<?= $escape($project['id']) ?>" data-customer-id="<?= $escape((string)$project['customer_id']) ?>"><?= $escape(($project['project_number']?:'Ohne Nr.').' · '.$project['name']) ?></option><?php endforeach; ?></select></label>
            <label>Bezeichnung<input name="title" maxlength="200" required></label>
            <p class="notice">Beim Anlegen oder Importieren wird noch keine Angebotsnummer vergeben.</p>
            <div class="form-actions"><button class="button" type="submit" data-icon="save">Entwurf anlegen</button><button class="button button-light" id="import-new-offer" type="button" data-icon="file-up">JSON importieren</button><button class="button button-light" type="button" data-cancel>Abbrechen</button></div>
            <input id="import-new-file" type="file" accept="application/json,.json" hidden>
        </form>
    </div>
</div>
<script src="/assets/offers-overview.js?v=<?= $escape((string)filemtime(dirname(__DIR__,2).'/public/assets/offers-overview.js')) ?>" defer></script>
