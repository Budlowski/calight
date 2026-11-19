<?php
$title = 'Stammdaten';
$contentClass = 'content-wide';
// Werkzeugleiste je Bereich: Suche, Filter, Anzahl, Weitere Aktionen (JSON), Ansicht, Anlegen.
$toolbar = static function (string $scope, string $placeholder, string $newLabel, string $filters = '') use ($escape): string {
    return '<div class="overview-toolbar">'
        . '<input type="search" data-search placeholder="' . $escape($placeholder) . '" aria-label="' . $escape($placeholder) . '">'
        . $filters
        . '<span class="management-result-count" data-count></span>'
        . '<details class="context-menu"><summary class="button button-light" data-icon="menu">Weitere Aktionen</summary><div class="context-menu-panel">'
        . '<button type="button" data-icon="file-down" data-master-export="' . $escape($scope) . '">JSON exportieren</button>'
        . '<button type="button" data-icon="file-up" data-master-import="' . $escape($scope) . '">JSON importieren</button></div></details>'
        . '<div class="view-switch" role="group" aria-label="Ansicht">'
        . '<button class="icon-button" type="button" data-list-view="table" data-icon="list" title="Tabelle" aria-label="Tabellenansicht"></button>'
        . '<button class="icon-button" type="button" data-list-view="cards" data-icon="layout-grid" title="Karten" aria-label="Kartenansicht"></button></div>'
        . '<button class="button" type="button" data-new data-icon="plus">' . $escape($newLabel) . '</button>'
        . '</div>';
};
?>
<div id="master-data-app" class="overview-page" data-csrf="<?= $escape($csrfToken) ?>">
    <div class="page-heading">
        <div>
            <h1>Stammdaten</h1>
            <p class="muted">Artikelstamm, Positionsstamm, Schlepptexte, Kategorien und Lieferanten zentral verwalten.</p>
        </div>
        <label class="check-inline"><input type="checkbox" id="show-archived"> Archivierte anzeigen</label>
    </div>

    <div id="master-message" class="notice" role="status" hidden></div>

    <nav class="tabs" aria-label="Stammdatenbereiche">
        <button class="tab-button" type="button" data-tab="cost-items">Artikelstamm</button>
        <button class="tab-button" type="button" data-tab="services">Positionsstamm</button>
        <button class="tab-button" type="button" data-tab="trailing-texts">Schlepptexte</button>
        <button class="tab-button" type="button" data-tab="categories">Kategorien</button>
        <button class="tab-button" type="button" data-tab="suppliers">Lieferanten</button>
    </nav>

    <section class="tab-panel" data-panel="cost-items" hidden>
        <?= $toolbar('cost-items', 'Artikelnummer, Bezeichnung oder Beschreibung durchsuchen', 'Artikel anlegen',
            '<select data-filter="category" aria-label="Kategorie"><option value="">Alle Kategorien</option></select>'
            . '<select data-filter="type" aria-label="Kalkulationstyp"><option value="">Alle Typen</option><option value="material">Material</option><option value="labor">Lohn</option><option value="external">Fremdleistung</option><option value="other">Sonstige Kosten</option></select>') ?>
        <div class="overview-results" data-list aria-live="polite"></div>
    </section>

    <section class="tab-panel" data-panel="services" hidden>
        <?= $toolbar('services', 'Positionsnummer oder Bezeichnung durchsuchen', 'Position anlegen') ?>
        <div class="overview-results" data-list aria-live="polite"></div>
    </section>

    <section class="tab-panel" data-panel="trailing-texts" hidden>
        <?= $toolbar('trailing-texts', 'Kürzel oder Text durchsuchen', 'Schlepptext anlegen') ?>
        <div class="overview-results" data-list aria-live="polite"></div>
    </section>

    <section class="tab-panel" data-panel="categories" hidden>
        <?= $toolbar('categories', 'Kategorien durchsuchen', 'Kategorie anlegen') ?>
        <div class="overview-results" data-list aria-live="polite"></div>
    </section>

    <section class="tab-panel" data-panel="suppliers" hidden>
        <?= $toolbar('suppliers', 'Nummer, Name, Ort oder Kundennummer durchsuchen', 'Lieferant anlegen') ?>
        <div class="overview-results" data-list aria-live="polite"></div>
    </section>
</div>
<input id="master-import-file" type="file" accept="application/json,.json" hidden>
<script src="/assets/master-data.js?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/master-data.js')) ?>" defer></script>
