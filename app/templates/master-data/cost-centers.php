<?php $title = 'Kostenstellen'; $contentClass = 'content-wide'; ?>
<div id="cost-centers-app" class="overview-page" data-csrf="<?= $escape($csrfToken) ?>">
    <nav class="breadcrumb" aria-label="Brotkrümelnavigation"><ol><li><a href="/master-data">Stammdaten</a></li><li aria-current="page">Kostenstellen</li></ol></nav>
    <div class="page-heading">
        <div>
            <h1>Kostenstellen</h1>
            <p class="muted">Allgemeine interne Zuordnungsziele neben Kundenaufträgen – z. B. Verwaltung, Lager oder IT. Keine Buchhaltung.</p>
        </div>
        <div class="order-detail-actions" data-actions></div>
    </div>
    <div class="notice" data-message role="status" hidden></div>
    <div class="overview-toolbar">
        <label class="cost-center-year"><span class="field-label">Auswertungsjahr</span><select data-year aria-label="Auswertungsjahr"></select></label>
        <label class="check-inline"><input type="checkbox" data-show-inactive> Deaktivierte anzeigen</label>
    </div>
    <div class="overview-results" data-list aria-live="polite"><p class="empty-state">Kostenstellen werden geladen …</p></div>
    <p class="muted cost-center-note">Stunden aus der zentralen Zeiterfassung nach Buchungstag; Bestellungen nach Bestelldatum mit bekannten Ist-Kosten (bei Kostenverteilung nur der zugeordnete Betrag); Auslagen nach Datum. Keine Umlagen oder Zuschläge.</p>
</div>
<script src="/assets/cost-centers.js?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/cost-centers.js')) ?>" defer></script>
