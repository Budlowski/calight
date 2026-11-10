<?php
$title='Auftrag '.$order['orderNumber'];
$contentClass='content-wide';
$labels=['open'=>'Offen','in_progress'=>'In Bearbeitung','completed'=>'Abgeschlossen','invoiced'=>'Abgerechnet'];
$offerLabels=['draft'=>'Entwurf','submitted'=>'Abgegeben','commissioned'=>'Angenommen','rejected'=>'Abgelehnt'];
$next=['open'=>'in_progress','in_progress'=>'completed','completed'=>'invoiced'];
$money=static function(?string $value):string{
    if($value===null)return '–';
    $negative=str_starts_with($value,'-');$unsigned=ltrim($value,'+-');[$whole,$fraction]=array_pad(explode('.',$unsigned,2),2,'');
    $whole=preg_replace('/(?<=\d)(?=(?:\d{3})+$)/','.',$whole)??$whole;
    return($negative?'-':'').$whole.','.str_pad(substr($fraction,0,2),2,'0').' €';
};
?>
<div id="order-detail" class="order-detail-page" data-id="<?= $escape($order['id']) ?>" data-status="<?= $escape($order['status']) ?>" data-updated-at="<?= $escape($order['updatedAt']) ?>" data-csrf="<?= $escape($csrfToken) ?>">
    <nav class="breadcrumb" aria-label="Brotkrümelnavigation"><ol><li><a href="/orders">Aufträge</a></li><?php if($order['projectId']!==null): ?><li><a href="/projects/<?= $escape(rawurlencode((string)$order['projectId'])) ?>"><?= $escape((string)$order['projectName']) ?></a></li><?php endif; ?><li aria-current="page"><?= $escape($order['orderNumber']) ?></li></ol></nav>

    <section class="card order-header-card">
        <div class="page-heading order-detail-heading">
            <div class="order-heading-main">
                <h1><span class="order-heading-number"><?= $escape($order['orderNumber']) ?></span><span aria-hidden="true"> · </span><span data-order-title><?= $escape($order['title']) ?></span></h1>
                <div class="document-status-line"><span class="status-badge status-<?= $escape($order['status']) ?>"><?= $escape($labels[$order['status']]) ?></span><span><?= $escape($order['orderType']==='offer'?'Angebotsauftrag':'Regieauftrag') ?></span></div>
            </div>
            <div class="order-detail-actions">
                <button class="button button-light" type="button" data-edit-order data-icon="file-text">Bearbeiten</button>
                <?php if(isset($next[$order['status']])): ?><button class="button" type="button" data-next-status="<?= $escape($next[$order['status']]) ?>" data-next-status-label="<?= $escape($labels[$next[$order['status']]]) ?>" data-icon="refresh-cw"><?= $escape($labels[$next[$order['status']]]) ?> setzen</button><?php endif; ?>
            </div>
        </div>
        <dl class="project-facts">
            <div><dt>Kunde</dt><dd><?= $escape($order['customerName']) ?></dd></div>
            <div><dt>Projekt</dt><dd><?= $escape($order['projectName']??'Ohne Projekt') ?></dd></div>
            <div><dt>Auftragsdatum</dt><dd><?= $escape((new DateTimeImmutable($order['orderDate']))->format('d.m.Y')) ?></dd></div>
        </dl>
        <p class="order-description" data-order-description<?= $order['description']===''?' hidden':'' ?>><?= $escape($order['description']) ?></p>
        <form class="order-edit-form" data-order-edit hidden novalidate>
            <div class="form-grid order-edit-fields">
                <label>Auftragsbezeichnung<input name="title" maxlength="200" required value="<?= $escape($order['title']) ?>"></label>
                <label>Auftragsbeschreibung (optional)<textarea name="description" maxlength="5000" rows="3"><?= $escape($order['description']) ?></textarea></label>
            </div>
            <p class="field-error" data-order-edit-error hidden></p>
            <div class="form-actions"><button class="button" type="submit" data-icon="save">Änderungen speichern</button><button class="button button-light" type="button" data-cancel-order-edit>Abbrechen</button></div>
        </form>
    </section>

    <section class="order-value-summary" aria-label="Auftragssummen">
        <div><span>Ursprüngliche Auftragssumme netto</span><strong><?= $escape($order['originalNetTotal']===null?'Keine vereinbarte Summe':$money($order['originalNetTotal'])) ?></strong></div>
        <div><span>Angenommene Nachträge netto</span><strong><?= $escape($money($order['acceptedAddendaNetTotal'])) ?></strong></div>
        <div class="total"><span>Aktuelle Gesamtauftragssumme netto</span><strong><?= $escape($order['currentContractNetTotal']===null?'Keine vereinbarte Summe':$money($order['currentContractNetTotal'])) ?></strong></div>
    </section>

    <?php if($order['orderType']==='offer'): ?>
    <section class="card order-basis-card">
        <div class="section-heading"><div><h2>Auftragsgrundlage</h2><span class="muted">Der bei Auftragserstellung archivierte Angebotsstand bleibt unverändert.</span></div></div>
        <p>Angebot <?= $escape($order['sourceOfferNumber']) ?><?= $order['sourceRevision']>0?' · Revision '.$escape((string)$order['sourceRevision']):'' ?></p>
        <a class="button button-light" data-icon="eye" href="/offers/<?= $escape(rawurlencode($order['sourceOfferId'])) ?>">Angebotsstand anzeigen</a>
        <p class="muted order-basis-note"><?= $escape((string)count($order['basis']['nodes']??[])) ?> Hauptelemente wurden als Auftragsgrundlage archiviert.</p>
    </section>
    <?php else: ?><section class="notice">Direkter Regieauftrag ohne ursprüngliche Auftragssumme. Spätere Rapporte und Ist-Erfassungen erzeugen hier keinen fiktiven Vertragsumsatz.</section><?php endif; ?>

    <section class="card order-change-orders">
        <div class="section-heading"><div><h2>Nachträge</h2><span class="muted">Zusätzliche Leistungen werden erst nach Annahme Bestandteil des Auftrags.</span></div><button class="button" type="button" data-create-change-order data-icon="plus">Nachtrag erstellen</button></div>
        <?php if($order['changeOrders']===[]): ?><p class="empty-state">Noch keine Nachträge vorhanden.</p><?php else: ?><div class="offer-detail-list"><?php foreach($order['changeOrders'] as $changeOrder): ?><div class="offer-detail-row order-change-order-row"><a class="offer-detail-main" href="/offers/<?= $escape(rawurlencode((string)$changeOrder['id'])) ?>"><strong><?= $escape($changeOrder['title']) ?></strong><small><?= $escape($changeOrder['offerNumber']?:'Nachtragsentwurf ohne Nummer') ?><?= $changeOrder['revision']>0?' · Revision '.$escape((string)$changeOrder['revision']):'' ?></small></a><span class="status-badge status-<?= $escape($changeOrder['status']) ?>"><?= $escape($offerLabels[$changeOrder['status']]??$changeOrder['status']) ?></span><strong class="order-change-order-total"><?= $escape($money($changeOrder['netTotal'])) ?></strong><span class="order-change-order-scope"><?php if($changeOrder['accepted']): ?>Im Auftragsumfang<?= $changeOrder['acceptedRevision']>0?' · Revision '.$escape((string)$changeOrder['acceptedRevision']):'' ?><?php elseif($changeOrder['status']==='submitted'): ?>Annahme ausstehend<?php elseif($changeOrder['status']==='rejected'): ?>Nicht übernommen<?php else: ?>Noch nicht abgegeben<?php endif; ?></span><a class="table-action" data-icon="eye" href="/offers/<?= $escape(rawurlencode((string)$changeOrder['id'])) ?>">Ansehen</a></div><?php endforeach; ?></div><?php endif; ?>
    </section>

    <section class="card order-daily-reports">
        <div class="section-heading"><div><h2>Bautagesberichte</h2><span class="muted"><?= $escape((string)count($dailyReports??[])) ?> Berichte · <?= $escape(number_format((float)($actualLabor['hours']??0),3,',','.')) ?> Ist-Stunden<?php if(isset($actualLabor['costs'])): ?> · <?= $escape($money($actualLabor['costs'])) ?> Ist-Lohnkosten<?php endif; ?></span></div><a class="button" data-icon="plus" href="/daily-reports?order=<?= $escape(rawurlencode((string)$order['id'])) ?>">Bericht erstellen</a></div>
        <?php if(($dailyReports??[])===[]): ?><p class="empty-state">Noch keine Bautagesberichte vorhanden.</p><?php else: ?><div class="offer-detail-list"><?php foreach($dailyReports as $report): ?><div class="offer-detail-row order-report-row"><a class="offer-detail-main" href="/daily-reports/<?= $escape(rawurlencode((string)$report['id'])) ?>"><strong><?= $escape($report['title']?:'Bautagesbericht vom '.(new DateTimeImmutable($report['report_date']))->format('d.m.Y')) ?></strong><small><?= $escape($report['employee_names']?:'Keine Arbeitszeiten') ?></small></a><span><?= $escape(number_format((float)$report['total_hours'],3,',','.')) ?> h</span><time datetime="<?= $escape($report['report_date']) ?>"><?= $escape((new DateTimeImmutable($report['report_date']))->format('d.m.Y')) ?></time><a class="table-action" data-icon="eye" href="/daily-reports/<?= $escape(rawurlencode((string)$report['id'])) ?>">Ansehen</a></div><?php endforeach; ?></div><?php endif; ?>
    </section>

    <section class="card order-history-card"><div class="section-heading"><div><h2>Statusverlauf</h2><span class="muted">Neuester Eintrag zuerst</span></div></div><div class="order-status-history"><?php foreach($order['history'] as $history): ?><div class="order-status-entry"><span class="status-badge status-<?= $escape($history['new_status']) ?>"><?= $escape($labels[$history['new_status']]??$history['new_status']) ?></span><span><?= $history['previous_status']===null?'Auftrag angelegt':$escape(($labels[$history['previous_status']]??$history['previous_status']).' → '.($labels[$history['new_status']]??$history['new_status'])) ?></span><time datetime="<?= $escape($history['changed_at']) ?>"><?= $escape((new DateTimeImmutable($history['changed_at']))->format('d.m.Y H:i')) ?></time></div><?php endforeach; ?></div></section>
</div>
<script src="/assets/order-detail.js?v=<?= $escape((string)filemtime(dirname(__DIR__,2).'/public/assets/order-detail.js')) ?>" defer></script>
