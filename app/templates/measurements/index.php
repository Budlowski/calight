<?php $title = 'Aufmaße'; $contentClass = 'content-wide'; ?>
<div id="measurements-app" class="overview-page" data-csrf="<?= $escape($csrfToken) ?>" data-initial-order="<?= $escape($initialOrderId) ?>">
    <div class="page-heading">
        <div>
            <h1>Aufmaße</h1>
            <p class="muted">Ausgeführte Mengen vereinbarter Auftragsleistungen erfassen und verbindlich abschließen.</p>
        </div>
        <?php if ($canWrite): ?><button class="button" type="button" data-new data-icon="plus">Aufmaß anlegen</button><?php endif; ?>
    </div>
    <div class="notice" data-message role="status" hidden></div>
    <div class="overview-toolbar">
        <input type="search" data-search placeholder="Nummer, Bezeichnung, Auftrag oder Kunde durchsuchen">
        <select data-status-filter aria-label="Status">
            <option value="">Alle Status</option>
            <option value="draft">Entwurf</option>
            <option value="completed">Abgeschlossen</option>
        </select>
        <select data-order-filter aria-label="Auftrag"><option value="">Alle Aufträge</option></select>
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
<script src="/assets/measurements.js?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/measurements.js')) ?>" defer></script>
