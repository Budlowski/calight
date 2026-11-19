<?php $title = 'Bestellungen'; $contentClass = 'content-wide'; ?>
<div id="purchase-orders-app" class="overview-page" data-csrf="<?= $escape($csrfToken) ?>" data-can-costs="<?= $canCosts ? '1' : '0' ?>" data-can-write="<?= $canWrite ? '1' : '0' ?>">
    <div class="page-heading">
        <div>
            <h1>Bestellungen</h1>
            <p class="muted">Beschaffung bei Lieferanten – in Calight ausgelöst oder extern bereits bestellt.</p>
        </div>
        <?php if ($canWrite): ?><button class="button" type="button" data-new data-icon="plus">Bestellung anlegen</button><?php endif; ?>
    </div>
    <div class="notice" data-message role="status" hidden></div>
    <div class="overview-toolbar">
        <input type="search" data-search placeholder="Nummer, Referenz, Lieferant oder Auftrag durchsuchen">
        <select data-supplier-filter aria-label="Lieferant"><option value="">Alle Lieferanten</option></select>
        <select data-order-filter aria-label="Auftrag"><option value="">Alle Aufträge</option></select>
        <select data-origin-filter aria-label="Herkunft">
            <option value="">Intern und extern</option>
            <option value="internal">Intern (Calight-Bestellung)</option>
            <option value="external">Extern erfasst</option>
        </select>
        <select data-status-filter aria-label="Bestellstatus">
            <option value="">Alle Bestellstatus</option>
            <option value="draft">Entwurf</option>
            <option value="ordered">Bestellt</option>
            <option value="cancelled">Storniert</option>
        </select>
        <select data-delivery-filter aria-label="Lieferstatus">
            <option value="">Alle Lieferstatus</option>
            <option value="pending">Ausstehend</option>
            <option value="partial">Teilgeliefert</option>
            <option value="delivered">Geliefert</option>
        </select>
        <select data-payment-filter aria-label="Zahlungsstatus">
            <option value="">Alle Zahlungsstatus</option>
            <option value="open">Offen</option>
            <option value="partial">Teilbezahlt</option>
            <option value="paid">Bezahlt</option>
        </select>
        <select data-sort aria-label="Sortierung"></select>
        <span class="management-result-count" data-count></span>
        <button class="button button-light" type="button" data-reset data-icon="refresh-cw">Zurücksetzen</button>
        <div class="view-switch" role="group" aria-label="Ansicht">
            <button class="icon-button" type="button" data-list-view="table" data-icon="list" title="Tabelle" aria-label="Tabellenansicht"></button>
            <button class="icon-button" type="button" data-list-view="cards" data-icon="layout-grid" title="Karten" aria-label="Kartenansicht"></button>
        </div>
    </div>
    <div class="overview-results" data-list aria-live="polite"></div>
</div>
<script src="/assets/purchase-orders.js?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/purchase-orders.js')) ?>" defer></script>
