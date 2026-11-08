<?php $title = $offer['title']; ?>
<p><a href="/projects/<?= $escape(rawurlencode($offer['projectId'])) ?>">← Projekt</a></p>
<h1>Angebotsentwurf</h1>
<div class="notice">Eine Geschäftsnummer wird erst bei der verbindlichen Abgabe in Phase 1 vergeben.</div>
<form id="offer-form" class="card form-stack" data-offer-id="<?= $escape($offer['id']) ?>" data-etag="<?= $escape($etag) ?>" data-csrf="<?= $escape($csrfToken) ?>">
    <label>Bezeichnung
        <input id="offer-title" value="<?= $escape($offer['title']) ?>" maxlength="200" required>
    </label>
    <p class="muted">Schema-Version <?= $escape($offer['schemaVersion']) ?> · Entwurfsversion <?= $escape($metadata['draft_version']) ?></p>
    <button class="button" type="submit" disabled>Entwurf speichern</button>
    <span id="save-status" role="status">Entwurf wird geladen …</span>
</form>
<script src="/assets/offer-editor.js" defer></script>
