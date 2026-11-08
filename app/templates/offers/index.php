<?php $title='Angebote'; $contentClass='content-wide'; ?>
<div id="offers-overview" data-csrf="<?= $escape($csrfToken) ?>">
    <div class="page-heading"><div><h1>Angebote</h1><p class="muted">Angebotsentwürfe projektbezogen anlegen und bearbeiten.</p></div></div>
    <div id="offers-message" class="notice" hidden></div>
    <div class="grid-two offer-overview-grid">
        <section class="card"><h2>Neuer Angebotsentwurf</h2><form id="new-offer-form" class="form-stack"><label>Projekt<select name="projectId" required><option value="">Projekt auswählen …</option><?php foreach($projects as $project): ?><option value="<?= $escape($project['id']) ?>"><?= $escape(($project['project_number']?:'Ohne Nr.').' · '.$project['name']) ?></option><?php endforeach; ?></select></label><label>Bezeichnung<input name="title" maxlength="200" required></label><button class="button" type="submit">Entwurf anlegen</button><button class="button button-light" id="import-new-offer" type="button">JSON als neuen Entwurf importieren</button><input id="import-new-file" type="file" accept="application/json,.json" hidden></form><p class="muted">Beim Anlegen oder Importieren wird noch keine Angebotsnummer vergeben.</p></section>
        <section><div class="master-toolbar offer-toolbar"><input id="offer-search" type="search" placeholder="Angebot oder Projekt durchsuchen"><select id="offer-status"><option value="">Alle Status</option><option value="draft">Entwurf</option><option value="submitted">Abgegeben</option><option value="commissioned">Beauftragt</option><option value="rejected">Abgelehnt</option></select></div><div id="offer-list" class="service-list"></div></section>
    </div>
</div>
<script src="/assets/offers-overview.js?v=<?= $escape((string)filemtime(dirname(__DIR__,2).'/public/assets/offers-overview.js')) ?>" defer></script>
