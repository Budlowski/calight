<?php
$title = $project['name'];
$contentClass = 'content-wide';
$canWriteProject = \App\Http\Session::can('projects.write');
$canWriteOffers = \App\Http\Session::can('offers.write');
$statusLabels = ['draft' => 'Entwurf', 'active' => 'Aktiv', 'completed' => 'Abgeschlossen', 'archived' => 'Archiviert'];
$offerStatusLabels = ['draft' => 'Entwurf', 'submitted' => 'Abgegeben', 'commissioned' => 'Angenommen', 'rejected' => 'Abgelehnt'];
$customerName = $project['linked_customer_name'] ?: $project['customer_name'];
$siteParts = array_filter([
    trim($project['site_street'] . ' ' . $project['site_house_number']),
    trim($project['site_postal_code'] . ' ' . $project['site_city']),
    $project['site_country'],
]);
$siteAddress = $siteParts !== [] ? implode(', ', $siteParts) : $project['site_address'];
$projectUrl = '/projects/' . rawurlencode($project['id']);
?>
<div id="project-detail" class="record-detail-page" data-project-id="<?= $escape($project['id']) ?>" data-csrf="<?= $escape($csrfToken) ?>">
    <nav class="breadcrumb" aria-label="Brotkrümelnavigation">
        <ol>
            <li><a href="/projects">Projekte</a></li>
            <li aria-current="page"><?= $escape($project['name']) ?></li>
        </ol>
    </nav>

    <section class="card order-header-card">
        <div class="page-heading order-detail-heading">
            <div class="order-heading-main">
                <span class="service-number"><?= $escape($project['project_number'] ?: 'Ohne Projektnummer') ?></span>
                <h1><?= $escape($project['name']) ?></h1>
                <div class="document-status-line"><span class="status-badge status-<?= $escape($project['status']) ?>"><?= $escape($statusLabels[$project['status']] ?? $project['status']) ?></span></div>
            </div>
            <?php if ($canWriteProject): ?>
                <div class="order-detail-actions"><a class="button button-light" data-icon="square-pen" href="<?= $escape($projectUrl) ?>/edit">Bearbeiten</a></div>
            <?php endif; ?>
        </div>
        <dl class="project-facts">
            <div><dt>Kunde</dt><dd><?= $escape($customerName ?: '–') ?></dd></div>
            <div><dt>Baustelle</dt><dd><?= $escape($siteAddress ?: '–') ?></dd></div>
            <div><dt>Ansprechpartner</dt><dd><?= $escape($project['contact_name'] ?: '–') ?><?php if ($project['contact_function']): ?><span class="muted"> · <?= $escape($project['contact_function']) ?></span><?php endif; ?><?php if ($project['contact_email'] || $project['contact_phone']): ?><small><?= $escape(implode(' · ', array_filter([$project['contact_email'], $project['contact_phone']]))) ?></small><?php endif; ?></dd></div>
        </dl>
        <?php if ($project['description']): ?><p class="order-description"><?= $escape($project['description']) ?></p><?php endif; ?>
    </section>

    <div class="notice" data-message role="status" hidden></div>

    <section class="card">
        <div class="section-heading">
            <div><h2>Angebote</h2><span class="muted"><?= count($offers) ?> Einträge</span></div>
            <?php if ($canWriteOffers && $project['customer_id']): ?><button class="button" type="button" data-new-offer data-icon="plus">Angebot anlegen</button><?php endif; ?>
        </div>
        <?php if ($canWriteOffers && !$project['customer_id']): ?>
            <p class="notice">Für Angebote muss dem Projekt zuerst ein Kunde zugeordnet werden.<?php if ($canWriteProject): ?> <a href="<?= $escape($projectUrl) ?>/edit">Projekt bearbeiten</a><?php endif; ?></p>
        <?php endif; ?>
        <?php if ($offers === []): ?>
            <p class="empty-state">Noch keine Angebote vorhanden.</p>
        <?php else: ?>
            <div class="offer-detail-list">
                <?php foreach ($offers as $item):
                    $displayDate = $item['status'] === 'draft' || empty($item['submittedAt']) ? $item['updatedAt'] : $item['submittedAt']; ?>
                    <div class="offer-detail-row">
                        <a class="offer-detail-main" href="/offers/<?= $escape(rawurlencode($item['id'])) ?>"><strong><?= $escape($item['title']) ?></strong><small><?= $escape($item['offerNumber'] ?: 'Entwurf ohne Angebotsnummer') ?><?= ($item['revision'] ?? 0) > 0 ? ' · Revision ' . $escape((string) $item['revision']) : '' ?></small></a>
                        <span class="status-badge status-<?= $escape($item['status']) ?>"><?= $escape($offerStatusLabels[$item['status']] ?? $item['status']) ?></span>
                        <span><?= $item['status'] === 'draft' ? 'Bearbeitbar' : 'Schreibgeschützt' ?></span>
                        <time datetime="<?= $escape($displayDate) ?>"><?= $escape((new DateTimeImmutable($displayDate))->format('d.m.Y H:i')) ?></time>
                        <?php if ($canWriteOffers && $item['status'] === 'draft'): ?>
                            <form action="/offers/<?= $escape(rawurlencode($item['id'])) ?>/delete" method="post"><input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>"><button class="icon-button icon-button-danger" type="submit" data-icon="trash" data-delete-offer data-confirm-message="Angebotsentwurf <?= $escape($item['title']) ?> endgültig löschen?" title="Löschen" aria-label="Löschen"></button></form>
                        <?php else: ?><span aria-hidden="true"></span><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($canWriteOffers && $project['customer_id']): ?>
        <template data-offer-template>
            <form class="form-stack" novalidate>
                <input type="hidden" name="customerId" value="<?= $escape((string) $project['customer_id']) ?>">
                <p class="muted">Kunde: <?= $escape((string) $customerName) ?> · Die Angebotsnummer wird erst bei der Abgabe vergeben.</p>
                <label>Bezeichnung <span aria-hidden="true">*</span><input name="title" maxlength="200" required></label>
                <p class="field-error" data-dialog-error hidden></p>
            </form>
        </template>
    <?php endif; ?>
</div>
<script src="/assets/project-detail.js?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/project-detail.js')) ?>" defer></script>
