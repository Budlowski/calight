<?php $title = 'Dokumente'; $contentClass = 'content-wide'; ?>
<div id="documents-app" class="overview-page" data-csrf="<?= $escape($csrfToken) ?>">
    <div class="page-heading">
        <div>
            <h1>Dokumente</h1>
            <p class="muted">Alle Dokumente der Geschäftsvorgänge an einer Stelle – nur zum Finden und Öffnen. Hochladen und Verwalten erfolgt beim jeweiligen Kunden, Projekt, Angebot oder Auftrag.</p>
        </div>
    </div>
    <div class="notice" data-message role="status" hidden></div>
    <div class="overview-toolbar">
        <input type="search" data-search placeholder="Titel, Dateiname, Kunde, Auftrag durchsuchen">
        <select data-type-filter aria-label="Dokumentart"><option value="">Alle Dokumentarten</option></select>
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
<script src="/assets/documents.js?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/documents.js')) ?>" defer></script>
