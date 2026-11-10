<?php
$title='Auftrag '.$order['orderNumber'];
$contentClass='content-wide';
$labels=['open'=>'Offen','in_progress'=>'In Bearbeitung','completed'=>'Abgeschlossen','invoiced'=>'Abgerechnet'];
$offerLabels=['draft'=>'Entwurf','submitted'=>'Abgegeben','commissioned'=>'Angenommen','rejected'=>'Abgelehnt'];
$next=['open'=>'in_progress','in_progress'=>'completed','completed'=>'invoiced'];
$money=static fn(?string $value):string=>$value===null?'–':number_format((float)$value,2,',','.').' €';
?>
<div id="order-detail" data-id="<?= $escape($order['id']) ?>" data-status="<?= $escape($order['status']) ?>" data-csrf="<?= $escape($csrfToken) ?>">
    <nav class="breadcrumb" aria-label="Brotkrümelnavigation"><ol><li><a href="/orders">Aufträge</a></li><?php if($order['projectId']!==null): ?><li><a href="/projects/<?= $escape(rawurlencode((string)$order['projectId'])) ?>"><?= $escape((string)$order['projectName']) ?></a></li><?php endif; ?><li aria-current="page"><?= $escape($order['orderNumber']) ?></li></ol></nav>
    <section class="card">
        <div class="page-heading"><div><h1><?= $escape($order['orderNumber'].' · '.$order['title']) ?></h1><p><span class="status-badge status-<?= $escape($order['status']) ?>"><?= $escape($labels[$order['status']]) ?></span> · <?= $escape($order['orderType']==='offer'?'Angebotsauftrag':'Regieauftrag') ?></p></div><?php if(isset($next[$order['status']])): ?><button class="button" data-next-status="<?= $escape($next[$order['status']]) ?>" data-icon="refresh-cw">Status: <?= $escape($labels[$next[$order['status']]]) ?></button><?php endif; ?></div>
        <div class="project-facts"><div><span>Kunde</span><strong><?= $escape($order['customerName']) ?></strong></div><div><span>Projekt</span><strong><?= $escape($order['projectName']??'Ohne Projekt') ?></strong></div><div><span>Auftragsdatum</span><strong><?= $escape((new DateTimeImmutable($order['orderDate']))->format('d.m.Y')) ?></strong></div></div>
        <?php if($order['description']!==''): ?><p><?= nl2br($escape($order['description'])) ?></p><?php endif; ?>
    </section>

    <section class="order-value-summary" aria-label="Auftragssummen">
        <div><span>Ursprüngliche Auftragssumme netto</span><strong><?= $escape($order['originalNetTotal']===null?'Keine vereinbarte Summe':$money($order['originalNetTotal'])) ?></strong></div>
        <div><span>Angenommene Nachträge netto</span><strong><?= $escape($money($order['acceptedAddendaNetTotal'])) ?></strong></div>
        <div class="total"><span>Aktuelle Gesamtauftragssumme netto</span><strong><?= $escape($order['currentContractNetTotal']===null?'Keine vereinbarte Summe':$money($order['currentContractNetTotal'])) ?></strong></div>
    </section>

    <?php if($order['orderType']==='offer'): ?><section class="card"><h2>Unveränderliche Auftragsgrundlage</h2><p>Angebot <?= $escape($order['sourceOfferNumber']) ?><?= $order['sourceRevision']>0?' · Revision '.$escape((string)$order['sourceRevision']):'' ?></p><a class="button button-light" data-icon="eye" href="/offers/<?= $escape(rawurlencode($order['sourceOfferId'])) ?>">Angebotsstand anzeigen</a><p class="muted"><?= $escape((string)count($order['basis']['nodes']??[])) ?> Hauptelemente wurden als Auftragsgrundlage archiviert.</p></section><?php else: ?><section class="notice">Direkter Regieauftrag ohne ursprüngliche Auftragssumme. Spätere Rapporte und Ist-Erfassungen erzeugen hier keinen fiktiven Vertragsumsatz.</section><?php endif; ?>

    <section class="card order-change-orders">
        <div class="section-heading"><div><h2>Nachträge</h2><span class="muted">Zusätzliche Leistungen werden erst nach Annahme Bestandteil des Auftrags.</span></div><button class="button" type="button" data-create-change-order data-icon="plus">Nachtrag erstellen</button></div>
        <?php if($order['changeOrders']===[]): ?><p class="muted">Noch keine Nachtragsangebote vorhanden.</p><?php else: ?><div class="offer-detail-list"><?php foreach($order['changeOrders'] as $changeOrder): ?><div class="offer-detail-row order-change-order-row"><a class="offer-detail-main" href="/offers/<?= $escape(rawurlencode((string)$changeOrder['id'])) ?>"><strong><?= $escape($changeOrder['title']) ?></strong><small><?= $escape($changeOrder['offerNumber']?:'Nachtragsentwurf ohne Nummer') ?><?= $changeOrder['revision']>0?' · Revision '.$escape((string)$changeOrder['revision']):'' ?></small></a><span class="status-badge status-<?= $escape($changeOrder['status']) ?>"><?= $escape($offerLabels[$changeOrder['status']]??$changeOrder['status']) ?></span><span class="management-card-total"><?= $escape($money($changeOrder['netTotal'])) ?></span><span class="muted"><?php if($changeOrder['accepted']): ?>Übernommen<?= $changeOrder['acceptedRevision']>0?' aus Revision '.$escape((string)$changeOrder['acceptedRevision']):'' ?><?php else: ?>Nicht im Auftragsumfang<?php endif; ?></span><a class="mini-button" data-icon="eye" href="/offers/<?= $escape(rawurlencode((string)$changeOrder['id'])) ?>">Öffnen</a></div><?php endforeach; ?></div><?php endif; ?>
    </section>

    <section class="card"><h2>Statusverlauf</h2><div class="service-list"><?php foreach($order['history'] as $history): ?><div class="service-list-item"><strong><?= $escape($labels[$history['new_status']]??$history['new_status']) ?></strong><span class="muted"><?= $escape((new DateTimeImmutable($history['changed_at']))->format('d.m.Y H:i')) ?></span></div><?php endforeach; ?></div></section>
</div>
<script src="/assets/order-detail.js?v=<?= $escape((string)filemtime(dirname(__DIR__,2).'/public/assets/order-detail.js')) ?>" defer></script>
