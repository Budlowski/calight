<?php
$title=$project['name'];
$contentClass='content-wide';
$statusLabels=['draft'=>'Entwurf','active'=>'Aktiv','completed'=>'Abgeschlossen','archived'=>'Archiviert'];
$offerStatusLabels=['draft'=>'Entwurf','submitted'=>'Abgegeben','commissioned'=>'Angenommen','rejected'=>'Abgelehnt'];
$customerName=$project['linked_customer_name'] ?: $project['customer_name'];
$siteParts=array_filter([
    trim($project['site_street'].' '.$project['site_house_number']),
    trim($project['site_postal_code'].' '.$project['site_city']),
    $project['site_country'],
]);
$siteAddress=$siteParts!==[]?implode(', ',$siteParts):$project['site_address'];
?>
<nav class="breadcrumb" aria-label="Brotkrümelnavigation">
    <ol>
        <li><a href="/projects">Projekte</a></li>
        <li aria-current="page"><?= $escape($project['name']) ?></li>
    </ol>
</nav>
<header class="project-detail-head card">
    <div><span class="service-number"><?= $escape($project['project_number'] ?: 'Ohne Projektnummer') ?></span><h1><?= $escape($project['name']) ?></h1><p class="muted"><?= $escape($project['description'] ?: 'Keine Projektbeschreibung') ?></p></div>
    <span class="status-badge status-<?= $escape($project['status']) ?>"><?= $escape($statusLabels[$project['status']] ?? $project['status']) ?></span>
    <dl class="project-facts"><div><dt>Kunde</dt><dd><?= $escape($customerName ?: '–') ?></dd></div><div><dt>Baustelle</dt><dd><?= $escape($siteAddress ?: '–') ?></dd></div><div><dt>Ansprechpartner</dt><dd><?= $escape($project['contact_name'] ?: '–') ?><?php if($project['contact_function']): ?><span class="muted"> · <?= $escape($project['contact_function']) ?></span><?php endif; ?><?php if($project['contact_email'] || $project['contact_phone']): ?><small><?= $escape(implode(' · ',array_filter([$project['contact_email'],$project['contact_phone']]))) ?></small><?php endif; ?></dd></div></dl>
</header>
<div class="project-offer-layout">
    <section class="card project-offer-create"><h2>Neuer Angebotsentwurf</h2><form action="/projects/<?= $escape(rawurlencode($project['id'])) ?>/offers" method="post" class="compact-action-form"><input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>"><label>Bezeichnung<input name="title" maxlength="200" required></label><button class="button" type="submit">Entwurf anlegen</button></form><span class="muted">Noch ohne Angebotsnummer</span></section>
    <section class="project-offers"><div class="section-heading"><h2>Angebote</h2><span class="muted"><?= count($offers) ?> Einträge</span></div>
        <?php if($offers===[]): ?><p class="card muted">Noch keine Angebote vorhanden.</p><?php else: ?><div class="offer-detail-list"><?php foreach($offers as $item): $displayDate=$item['status']==='draft'||empty($item['submittedAt'])?$item['updatedAt']:$item['submittedAt']; ?><div class="offer-detail-row"><a class="offer-detail-main" href="/offers/<?= $escape(rawurlencode($item['id'])) ?>"><strong><?= $escape($item['title']) ?></strong><small><?= $escape($item['offerNumber'] ?: 'Entwurf ohne Angebotsnummer') ?></small></a><span class="status-badge status-<?= $escape($item['status']) ?>"><?= $escape($offerStatusLabels[$item['status']] ?? $item['status']) ?></span><span><?= $item['status']==='draft'?'Entwurf':'Schreibgeschützt' ?></span><time datetime="<?= $escape($displayDate) ?>"><?= $escape((new DateTimeImmutable($displayDate))->format('d.m.Y H:i')) ?></time><?php if($item['status']==='draft'): ?><form action="/offers/<?= $escape(rawurlencode($item['id'])) ?>/delete" method="post"><input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>"><button class="table-action danger-text" type="submit" data-icon="trash" data-confirm-message="Angebotsentwurf endgültig löschen?">Löschen</button></form><?php endif; ?></div><?php endforeach; ?></div><?php endif; ?>
    </section>
</div>
