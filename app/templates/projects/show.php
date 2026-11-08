<?php $title = $project['name']; ?>
<p><a href="/projects">← Projekte</a></p>
<h1><?= $escape($project['name']) ?></h1>
<dl class="details">
    <dt>Kunde</dt><dd><?= $escape($project['customer_name'] ?: '–') ?></dd>
    <dt>Baustelle</dt><dd><?= nl2br($escape($project['site_address'] ?: '–')) ?></dd>
</dl>
<div class="grid-two">
    <section class="card">
        <h2>Neuer Angebotsentwurf</h2>
        <form action="/projects/<?= $escape(rawurlencode($project['id'])) ?>/offers" method="post" class="form-stack">
            <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
            <label>Bezeichnung
                <input name="title" maxlength="200" required>
            </label>
            <button class="button" type="submit">Entwurf anlegen</button>
        </form>
    </section>
    <section class="card">
        <h2>Angebote</h2>
        <?php if ($offers === []): ?>
            <p>Noch keine Angebote vorhanden.</p>
        <?php else: ?>
            <ul class="link-list">
                <?php foreach ($offers as $item): ?>
                    <li><a href="/offers/<?= $escape(rawurlencode($item['id'])) ?>">Entwurf <?= $escape(substr($item['id'], 0, 8)) ?></a> <span class="muted">Version <?= $escape($item['draft_version']) ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
