<?php $title = 'Bestellung'; $contentClass = 'content-wide'; ?>
<div id="purchase-order-detail" class="record-detail-page purchase-detail" data-id="<?= $escape($purchaseOrderId) ?>" data-csrf="<?= $escape($csrfToken) ?>">
    <nav class="breadcrumb" aria-label="Brotkrümelnavigation">
        <ol>
            <li><a href="/purchase-orders">Bestellungen</a></li>
            <li aria-current="page" data-current-crumb>Bestellung</li>
        </ol>
    </nav>

    <!-- Kompakter Bestellkopf: Status und Beträge, keine Formulare. -->
    <section class="card order-header-card">
        <div class="page-heading order-detail-heading">
            <div class="order-heading-main">
                <span class="service-number" data-number>Bestellung</span>
                <h1 data-title>Bestellung wird geladen …</h1>
                <div class="document-status-line" data-status-line></div>
            </div>
            <div class="measurement-actions work-report-header-actions">
                <div class="order-detail-actions" data-actions></div>
            </div>
        </div>
        <dl class="project-facts purchase-facts" data-facts></dl>
    </section>

    <div class="notice" data-message role="status" hidden></div>

    <!-- 1. Bestellung: Ansicht, Bearbeiten über eigene Aktion -->
    <section class="card form-stack" data-order-section>
        <div class="step-section-heading">
            <span class="step-section-number" data-section-number>1</span>
            <div><h2>Bestellung</h2><small data-order-hint></small></div>
            <div class="step-section-actions" data-order-actions></div>
        </div>
        <div data-order-view></div>
        <div class="form-stack" data-order-edit hidden>
            <form class="form-stack" data-general novalidate>
                <div class="form-grid purchase-head-grid">
                    <label><span class="field-label">Lieferant <span aria-hidden="true">*</span></span><select name="supplierId" required></select></label>
                    <label><span class="field-label">Bezeichnung (optional)</span><input name="title" maxlength="200" autocomplete="off" placeholder="z. B. Material Baustelle Werk 2"></label>
                    <label><span class="field-label">Externe Bestellnummer (optional)</span><input name="externalReference" maxlength="100" autocomplete="off"></label>
                    <label><span class="field-label">Bestelldatum</span><input name="orderDate" type="date"></label>
                    <label><span class="field-label">Gewünschter Liefertermin (optional)</span><input name="expectedDeliveryDate" type="date"></label>
                    <label><span class="field-label">Auftrag (optional)</span><select name="orderId"></select></label>
                    <label data-expected-field hidden><span class="field-label">Bestellwert netto</span><input name="expectedNetTotal" inputmode="decimal" placeholder="0,00"></label>
                </div>
                <label><span class="field-label">Lieferanschrift (optional)</span><textarea name="deliveryAddress" rows="2" maxlength="1000" placeholder="Leer = Lieferung an die Firmenanschrift"></textarea></label>
                <label><span class="field-label">Bemerkung (optional)</span><textarea name="notes" rows="2" maxlength="5000"></textarea></label>
            </form>
            <div class="form-stack" data-items-editor>
                <div class="purchase-items-toolbar">
                    <h3 class="purchase-subheading">Positionen</h3>
                    <button class="button button-light button-small" type="button" data-add-item data-icon="plus">Position hinzufügen</button>
                </div>
                <div class="work-report-catalog" data-catalog-box>
                    <input type="search" data-catalog-search placeholder="Artikelstamm durchsuchen …" aria-label="Stammartikel suchen" autocomplete="off">
                    <button class="suggest-clear" type="button" data-catalog-clear aria-label="Suche leeren" title="Suche leeren" hidden>×</button>
                    <div class="work-report-catalog-results" data-catalog-results hidden></div>
                </div>
                <div data-items></div>
                <div class="work-report-billing-total" data-items-total-row hidden><span>Summe netto</span><strong data-items-total>–</strong></div>
            </div>
            <div class="form-actions">
                <span class="measurement-dirty-hint" data-dirty-hint hidden>Ungespeicherte Änderungen</span>
                <button class="button button-light" type="button" data-edit-cancel>Abbrechen</button>
                <button class="button" type="button" data-edit-save data-shortcut-save data-icon="save" title="Speichern (Strg+S)">Speichern</button>
            </div>
        </div>
    </section>

    <!-- 2.–5. Vorgänge: Listen mit aufgabenbezogenen Dialogen -->
    <section class="card form-stack" data-event-section="confirmations">
        <div class="step-section-heading">
            <span class="step-section-number" data-section-number>2</span>
            <div><h2>Auftragsbestätigungen</h2><small>Bestätigte Werte ändern weder den ursprünglichen Bestellwert noch die Ist-Kosten.</small></div>
            <div class="step-section-actions" data-event-actions="confirmations"></div>
        </div>
        <div data-event-list="confirmations"></div>
    </section>

    <section class="card form-stack" data-event-section="deliveries">
        <div class="step-section-heading">
            <span class="step-section-number" data-section-number>3</span>
            <div><h2>Lieferungen</h2><small>Der Lieferstatus ergibt sich aus der zuletzt erfassten Lieferung oder Korrektur.</small></div>
            <div class="step-section-actions" data-event-actions="deliveries"></div>
        </div>
        <div data-event-list="deliveries"></div>
    </section>

    <section class="card form-stack" data-event-section="invoices" hidden>
        <div class="step-section-heading">
            <span class="step-section-number" data-section-number>4</span>
            <div><h2>Rechnungen und Gutschriften</h2><small>Ist-Kosten entstehen aus den Belegen oder einem direkt erfassten Betrag – nie aus beidem.</small></div>
            <div class="step-section-actions" data-event-actions="invoices"></div>
        </div>
        <div data-event-list="invoices"></div>
    </section>

    <section class="card form-stack" data-event-section="payments">
        <div class="step-section-heading">
            <span class="step-section-number" data-section-number>5</span>
            <div><h2>Zahlungen</h2><small>Zahlungsvermerke verändern die Ist-Kosten nicht.</small></div>
            <div class="step-section-actions" data-event-actions="payments"></div>
        </div>
        <div data-event-list="payments"></div>
    </section>

    <!-- 6. Dokumente und Verlauf (einklappbar) -->
    <details class="card purchase-archive" data-archive-section>
        <summary class="step-section-heading">
            <span class="step-section-number" data-section-number>6</span>
            <div><h2>Dokumente und Verlauf</h2><small data-archive-hint></small></div>
        </summary>
        <div class="form-stack">
            <div class="purchase-items-toolbar">
                <h3 class="purchase-subheading">Dokumente</h3>
                <span data-document-actions></span>
            </div>
            <div data-documents></div>
            <h3 class="purchase-subheading">Verlauf</h3>
            <div data-history></div>
        </div>
    </details>
</div>
<script src="/assets/purchase-orders.js?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/purchase-orders.js')) ?>" defer></script>
<script src="/assets/purchase-order-detail.js?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/purchase-order-detail.js')) ?>" defer></script>
