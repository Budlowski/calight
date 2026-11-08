<?php $title=$offer['title']; $contentClass='content-wide'; ?>
<div id="offer-editor" data-offer-id="<?= $escape($offer['id']) ?>" data-etag="<?= $escape($etag) ?>" data-csrf="<?= $escape($csrfToken) ?>">
    <div class="offer-editor-header"><div><p><a href="/projects/<?= $escape(rawurlencode($offer['projectId'])) ?>">← Projekt</a> · <a href="/offers">Angebotsübersicht</a></p><h1>Angebotsentwurf</h1></div><div class="offer-save-area"><span id="save-status" role="status">Entwurf wird geladen …</span><button class="button" id="save-offer" type="button" disabled>Entwurf speichern</button></div></div>
    <div class="notice">Eine Angebotsnummer wird erst bei der verbindlichen Abgabe vergeben.</div>
    <section class="card form-stack offer-document-head"><label>Bezeichnung<input id="offer-title" value="<?= $escape($offer['title']) ?>" maxlength="200" required></label><span class="muted">Schema-Version <?= $escape($offer['schemaVersion']) ?> · Entwurfsversion <?= $escape($metadata['draft_version']) ?></span></section>
    <div class="offer-tree-toolbar"><button class="button" type="button" data-add-root-title>Titel hinzufügen</button><button class="button button-light" type="button" data-add-root-position>Position hinzufügen</button><span class="muted">Elemente können per Ziehen oder mit den Pfeilen sortiert werden.</span></div>
    <div id="offer-tree" class="offer-tree" aria-live="polite"></div>
</div>
<script src="/assets/offer-editor.js?v=<?= $escape((string)filemtime(dirname(__DIR__,2).'/public/assets/offer-editor.js')) ?>" defer></script>
