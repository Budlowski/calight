<?php $title=$offer['title']; $contentClass='content-wide'; ?>
<div id="offer-editor" data-offer-id="<?= $escape($offer['id']) ?>" data-etag="<?= $escape($etag) ?>" data-csrf="<?= $escape($csrfToken) ?>">
    <section class="card offer-document-head">
        <div class="offer-document-topline">
            <div><p class="breadcrumb"><a href="/projects/<?= $escape(rawurlencode($offer['projectId'])) ?>">← Projekt</a> · <a href="/offers">Angebotsübersicht</a></p><div class="offer-document-title"><h1>Angebotsentwurf</h1><span class="status-badge">Entwurf</span></div></div>
            <div class="offer-save-area"><span id="save-status" role="status">Entwurf wird geladen …</span><button class="button" id="save-offer" type="button" data-icon="save" aria-keyshortcuts="Control+S Meta+S" title="Entwurf speichern (Strg+S)" disabled>Entwurf speichern</button></div>
        </div>
        <div class="offer-document-controls"><label>Bezeichnung<input id="offer-title" value="<?= $escape($offer['title']) ?>" maxlength="200" required></label><div class="offer-document-meta"><span>Schema <?= $escape($offer['schemaVersion']) ?> · Version <?= $escape($metadata['draft_version']) ?></span><span>Eine Angebotsnummer wird erst bei verbindlicher Abgabe vergeben.</span></div></div>
    </section>
    <div class="offer-tree-toolbar"><button class="button" type="button" data-icon="plus" data-add-root-title>Titel hinzufügen</button><button class="button button-light" type="button" data-icon="plus" data-add-root-position>Position hinzufügen</button><button class="button button-light" type="button" data-icon="chevrons-down" data-expand-all>Alle aufklappen</button><button class="button button-light" type="button" data-icon="chevrons-up" data-collapse-all>Alle einklappen</button><span class="toolbar-spacer"></span><a class="button button-light" href="/api/offers/<?= $escape(rawurlencode($offer['id'])) ?>/export" download>JSON exportieren</a><button class="button button-light" type="button" data-import-json>JSON importieren</button><input type="file" data-import-file accept="application/json,.json" hidden><button class="button button-light" type="button" data-pdf-preview>PDF-Vorschau</button><button class="button button-light" type="button" data-pdf-download>PDF herunterladen</button></div>
    <div id="offer-tree" class="offer-tree" aria-live="polite"></div>
</div>
<script src="/assets/offer-editor.js?v=<?= $escape((string)filemtime(dirname(__DIR__,2).'/public/assets/offer-editor.js')) ?>" defer></script>
