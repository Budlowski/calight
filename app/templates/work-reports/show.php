<?php $title = 'Rapport'; $contentClass = 'content-wide'; ?>
<div id="work-report-detail" class="record-detail-page" data-id="<?= $escape($reportId) ?>" data-csrf="<?= $escape($csrfToken) ?>">
    <nav class="breadcrumb" aria-label="Brotkrümelnavigation">
        <ol>
            <li data-orders-crumb><a href="/orders">Aufträge</a></li>
            <li data-order-crumb></li>
            <li><a href="/work-reports">Rapporte</a></li>
            <li aria-current="page" data-current-crumb>Rapport</li>
        </ol>
    </nav>

    <section class="card order-header-card">
        <div class="page-heading order-detail-heading">
            <div class="order-heading-main">
                <span class="service-number" data-number>Rapport</span>
                <h1 data-title>Rapport wird geladen …</h1>
                <div class="document-status-line" data-status-line></div>
            </div>
            <div class="measurement-actions work-report-header-actions">
                <div class="order-detail-actions" data-actions></div>
                <span class="measurement-dirty-hint" data-dirty-hint hidden>Ungespeicherte Änderungen</span>
            </div>
        </div>
        <dl class="project-facts">
            <div><dt>Auftrag</dt><dd data-order></dd></div>
            <div><dt>Kunde</dt><dd data-customer></dd></div>
            <div><dt>Kundenbestätigung</dt><dd data-confirmation>–</dd></div>
        </dl>
    </section>

    <div class="notice" data-message role="status" hidden></div>

    <form class="card form-stack work-report-proof" data-proof novalidate>
        <div class="step-section-heading">
            <span class="step-section-number">1</span>
            <div><h2>Leistungsnachweis</h2><small data-proof-hint>Ausgeführte Arbeiten, Stunden und Material</small></div>
        </div>
        <div class="form-grid work-report-head-grid">
            <label><span class="field-label">Leistungsdatum <span aria-hidden="true">*</span></span><input name="workDate" type="date" required></label>
            <label><span class="field-label">Bis (optional, mehrtägig)</span><input name="workDateTo" type="date"></label>
            <label><span class="field-label">Ansprechpartner vor Ort (optional)</span><input name="contactPerson" maxlength="200" autocomplete="off"></label>
            <label class="work-report-location"><span class="field-label">Einsatzort / Objekt (optional)</span><input name="workLocation" maxlength="200" autocomplete="off" placeholder="z. B. Werk 2, Produktionshalle 4"></label>
        </div>
        <label><span class="field-label">Ausgeführte Arbeiten <span aria-hidden="true">*</span></span><textarea name="description" rows="4" maxlength="5000"></textarea></label>

        <section class="report-form-section">
            <div class="step-section-heading"><div><h3>Arbeitsstunden</h3><small data-hours-summary></small></div>
                <div class="step-section-actions"><button class="button button-light button-small" type="button" data-add-labor data-icon="plus">Stunden hinzufügen</button></div></div>
            <div data-labor></div>
        </section>

        <section class="report-form-section">
            <div class="step-section-heading"><div><h3>Material und sonstige Leistungen</h3><small>z. B. 5 m Steuerleitung, 1 Stk. Temperaturfühler, Anfahrt</small></div>
                <div class="step-section-actions"><button class="button button-light button-small" type="button" data-add-material data-icon="plus">Material hinzufügen</button></div></div>
            <div data-materials></div>
        </section>

        <label><span class="field-label">Bemerkungen (optional)</span><textarea name="notes" rows="2" maxlength="5000"></textarea></label>
    </form>

    <section class="card form-stack work-report-billing" data-billing-section hidden>
        <div class="step-section-heading">
            <span class="step-section-number">2</span>
            <div><h2>Kaufmännische Bearbeitung</h2><small>Abrechnungspositionen mit Verkaufspreisen – nur für das Büro sichtbar. Der Leistungsnachweis bleibt unverändert.</small></div>
            <div class="step-section-actions" data-billing-tools>
                <button class="button button-light button-small" type="button" data-billing-prefill data-icon="copy">Aus Leistungsnachweis übernehmen</button>
                <button class="button button-light button-small" type="button" data-billing-add data-icon="plus">Freie Position</button>
            </div>
        </div>
        <div class="notice" data-billing-message role="status" hidden></div>
        <div class="work-report-billing-bar">
            <span class="measurement-dirty-hint" data-billing-dirty-hint hidden>Ungespeicherte Positionen</span>
            <div class="order-detail-actions" data-billing-actions></div>
        </div>
        <div class="work-report-catalog" data-catalog-box>
            <input type="search" data-catalog-search placeholder="Artikelstamm durchsuchen …" aria-label="Stammartikel suchen" autocomplete="off">
            <button class="suggest-clear" type="button" data-catalog-clear aria-label="Suche leeren" title="Suche leeren" hidden>×</button>
            <div class="work-report-catalog-results" data-catalog-results role="listbox" hidden></div>
        </div>
        <div data-billing></div>
        <div class="work-report-billing-total"><span>Nettobetrag</span><strong data-billing-total>0,00 €</strong></div>
    </section>
</div>
<script src="/assets/work-report-detail.js?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/work-report-detail.js')) ?>" defer></script>
