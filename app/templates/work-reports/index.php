<?php $title = 'Rapporte'; $contentClass = 'content-wide'; ?>
<div id="work-reports-app" class="overview-page" data-csrf="<?= $escape($csrfToken) ?>" data-can-process="<?= $canProcess ? '1' : '0' ?>">
    <div class="page-heading">
        <div>
            <h1>Rapporte</h1>
            <p class="muted"><?= $canProcess ? 'Leistungsnachweise für Regiearbeiten prüfen und kaufmännisch bearbeiten.' : 'Ausgeführte Regiearbeiten mit Stunden und Material erfassen.' ?></p>
        </div>
        <?php if ($canWrite): ?><button class="button" type="button" data-new data-icon="plus">Rapport anlegen</button><?php endif; ?>
    </div>
    <div class="notice" data-message role="status" hidden></div>
    <div class="overview-toolbar">
        <input type="search" data-search placeholder="Nummer, Arbeiten, Kunde, Auftrag oder Ersteller durchsuchen">
        <select data-status-filter aria-label="Status">
            <option value="">Alle Status</option>
            <option value="draft">Entwurf</option>
            <option value="submitted">Eingereicht</option>
            <option value="processed">Bearbeitet</option>
        </select>
        <select data-confirmation-filter aria-label="Kundenbestätigung">
            <option value="">Alle Bestätigungen</option>
            <option value="confirmed">Vom Kunden bestätigt</option>
            <option value="none">Ohne Bestätigung</option>
        </select>
        <select data-customer-filter aria-label="Kunde"><option value="">Alle Kunden</option></select>
        <select data-assignment-filter aria-label="Auftragszuordnung">
            <option value="">Mit und ohne Auftrag</option>
            <option value="with_order">Mit Auftrag</option>
            <option value="without_order">Ohne Auftrag</option>
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
<script src="/assets/work-reports.js?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/work-reports.js')) ?>" defer></script>
