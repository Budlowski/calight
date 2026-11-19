<?php $title = 'Bestellung'; $contentClass = 'content-wide'; ?>
<div id="purchase-order-detail" class="record-detail-page" data-id="<?= $escape($purchaseOrderId) ?>" data-csrf="<?= $escape($csrfToken) ?>">
    <nav class="breadcrumb" aria-label="Brotkrümelnavigation">
        <ol>
            <li><a href="/purchase-orders">Bestellungen</a></li>
            <li aria-current="page" data-current-crumb>Bestellung</li>
        </ol>
    </nav>

    <section class="card order-header-card">
        <div class="page-heading order-detail-heading">
            <div class="order-heading-main">
                <span class="service-number" data-number>Bestellung</span>
                <h1 data-title>Bestellung wird geladen …</h1>
                <div class="document-status-line" data-status-line></div>
            </div>
            <div class="measurement-actions work-report-header-actions">
                <div class="order-detail-actions" data-actions></div>
                <span class="measurement-dirty-hint" data-dirty-hint hidden>Ungespeicherte Änderungen</span>
            </div>
        </div>
        <dl class="project-facts" data-facts></dl>
    </section>

    <div class="notice" data-message role="status" hidden></div>

    <form class="card form-stack" data-general novalidate>
        <div class="step-section-heading">
            <span class="step-section-number">1</span>
            <div><h2>Allgemeine Angaben</h2><small data-general-hint>Lieferant, Bezug und Lieferanschrift</small></div>
        </div>
        <div class="form-grid purchase-head-grid">
            <label><span class="field-label">Lieferant <span aria-hidden="true">*</span></span><select name="supplierId" required></select></label>
            <label><span class="field-label">Bezeichnung (optional)</span><input name="title" maxlength="200" autocomplete="off" placeholder="z. B. Material Baustelle Werk 2"></label>
            <label><span class="field-label">Externe Bestellnummer (optional)</span><input name="externalReference" maxlength="100" autocomplete="off"></label>
            <label><span class="field-label">Bestelldatum</span><input name="orderDate" type="date"></label>
            <label><span class="field-label">Gewünschter Liefertermin (optional)</span><input name="expectedDeliveryDate" type="date"></label>
            <label><span class="field-label">Auftrag (optional)</span><select name="orderId"></select></label>
            <label data-expected-field hidden><span class="field-label">Erwarteter Bestellwert netto</span><input name="expectedNetTotal" inputmode="decimal" placeholder="0,00"></label>
        </div>
        <label><span class="field-label">Lieferanschrift (optional)</span><textarea name="deliveryAddress" rows="2" maxlength="1000" placeholder="Leer = Lieferung an die Firmenanschrift"></textarea></label>
        <label><span class="field-label">Bemerkung (optional)</span><textarea name="notes" rows="2" maxlength="5000"></textarea></label>
        <div class="purchase-request-links" data-request-links hidden></div>
    </form>

    <section class="card form-stack" data-items-section>
        <div class="step-section-heading">
            <span class="step-section-number">2</span>
            <div><h2>Positionen</h2><small data-items-hint></small></div>
            <div class="step-section-actions" data-items-tools>
                <button class="button button-light button-small" type="button" data-add-item data-icon="plus">Position hinzufügen</button>
            </div>
        </div>
        <div class="work-report-catalog" data-catalog-box>
            <input type="search" data-catalog-search placeholder="Artikelstamm durchsuchen …" aria-label="Stammartikel suchen" autocomplete="off">
            <button class="suggest-clear" type="button" data-catalog-clear aria-label="Suche leeren" title="Suche leeren" hidden>×</button>
            <div class="work-report-catalog-results" data-catalog-results hidden></div>
        </div>
        <div data-items></div>
        <div class="work-report-billing-total" data-items-total-row hidden><span>Summe netto</span><strong data-items-total>–</strong></div>
    </section>

    <section class="card form-stack" data-tracking-section>
        <div class="step-section-heading">
            <span class="step-section-number">3</span>
            <div><h2>Lieferung und Zahlung</h2><small>Getrennte Angaben – eine Bestellung kann z. B. teilgeliefert und bereits bezahlt sein.</small></div>
        </div>
        <div data-tracking></div>
    </section>

    <section class="card form-stack" data-costs-section hidden>
        <div class="step-section-heading">
            <span class="step-section-number">4</span>
            <div><h2>Rechnungen und Kosten</h2><small>Ist-Kosten stammen entweder aus Lieferantenrechnungen oder aus einem direkt erfassten Betrag – nie aus beidem.</small></div>
        </div>
        <div data-costs></div>
    </section>

    <section class="card form-stack" data-documents-section>
        <div class="step-section-heading">
            <span class="step-section-number" data-documents-number>5</span>
            <div><h2>Dokumente</h2><small>Eigene Bestell-PDFs werden je Fassung archiviert; Belege des Lieferanten als PDF oder Bild.</small></div>
        </div>
        <div data-documents></div>
    </section>

    <section class="card form-stack" data-history-section>
        <div class="step-section-heading">
            <span class="step-section-number" data-history-number>6</span>
            <div><h2>Statusverlauf</h2></div>
        </div>
        <div data-history></div>
    </section>
</div>
<script src="/assets/purchase-order-detail.js?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/purchase-order-detail.js')) ?>" defer></script>
