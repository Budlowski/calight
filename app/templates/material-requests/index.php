<?php $title = 'Materialbedarf'; $contentClass = 'content-wide'; ?>
<div id="material-requests-app" class="overview-page" data-csrf="<?= $escape($csrfToken) ?>">
    <div class="page-heading">
        <div>
            <h1>Materialbedarf</h1>
            <p class="muted"><?= $canManage ? 'Materialanforderungen aus Tagesberichten und Aufträgen auftragsübergreifend bearbeiten.' : 'Gemeldeten Materialbedarf Ihrer freigegebenen Aufträge einsehen.' ?></p>
        </div>
    </div>
    <?php $procurementTab = 'materials'; require dirname(__DIR__) . '/partials/procurement-tabs.php'; ?>
    <div class="notice" data-message role="status" hidden></div>
    <div class="overview-toolbar">
        <input type="search" data-search placeholder="Material, Auftrag, Kunde oder Ersteller durchsuchen">
        <select data-status-filter aria-label="Status">
            <option value="">Offen</option>
            <option value="done">Erledigt</option>
            <option value="all">Alle</option>
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
<script src="/assets/material-requests.js?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/material-requests.js')) ?>" defer></script>
