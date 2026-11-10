<?php $title='Bautagesbericht';$contentClass='content-wide'; ?>
<div id="daily-report-detail" class="detail-page" data-id="<?= $escape($reportId) ?>" data-csrf="<?= $escape($csrfToken) ?>">
    <nav class="breadcrumb" aria-label="Brotkrümelnavigation"><ol><li><a href="/orders" data-order-list>Aufträge</a></li><li data-order-crumb></li><li><a href="/daily-reports">Bautagesberichte</a></li><li aria-current="page" data-current-crumb>Bautagesbericht</li></ol></nav>
    <div class="page-heading"><div><h1 data-title>Bautagesbericht</h1><p class="muted" data-meta>Bericht wird geladen …</p></div></div>
    <div class="notice" data-message role="status" hidden></div>
    <form id="daily-report-form" class="card form-stack detail-form-card" novalidate>
        <section class="report-form-section">
            <div class="step-section-heading"><span class="step-section-number">1</span><div><h2>Auftragsdaten</h2><small>Auftrag und Berichtsgrunddaten</small></div></div>
            <div class="form-grid report-head-grid">
                <label><span class="field-label">Auftrag</span><select name="orderId" disabled></select></label>
                <label><span class="field-label">Berichtsdatum <span aria-hidden="true">*</span></span><input name="reportDate" type="date" required></label>
                <label><span class="field-label">Bezeichnung (optional)</span><input name="title" maxlength="200"></label>
            </div>
        </section>
        <section class="report-form-section">
            <div class="step-section-heading"><span class="step-section-number">2</span><div><h2>Tätigkeitsbeschreibung</h2><small>Ausgeführte Arbeiten dokumentieren</small></div></div>
            <label><span class="field-label">Tätigkeitsbeschreibung <span aria-hidden="true">*</span></span><textarea name="activities" rows="4" maxlength="10000" required></textarea></label>
        </section>
        <section class="report-form-section">
            <div class="step-section-heading"><span class="step-section-number">3</span><div><h2>Bemerkungen</h2><small>Optionale ergänzende Hinweise</small></div></div>
            <label><span class="field-label">Bemerkungen (optional)</span><textarea name="notes" rows="3" maxlength="10000"></textarea></label>
        </section>
        <section class="report-form-section report-time-section">
            <div class="step-section-heading"><span class="step-section-number">4</span><div><h2>Arbeitszeiten</h2><small data-time-summary>0,000 Stunden erfasst</small></div><div class="step-section-actions"><button class="button button-light button-small" type="button" data-add-entry data-icon="plus">Zeit hinzufügen</button></div></div>
            <div data-entries></div>
        </section>
        <p class="field-error" data-error hidden></p>
        <div class="form-actions"><button class="button" type="submit" data-save data-icon="save">Bericht speichern</button><a class="button button-light" href="/daily-reports">Abbrechen</a><button class="button button-danger-light" type="button" data-delete data-icon="trash">Bericht löschen</button></div>
    </form>
</div>
<script src="/assets/daily-report-detail.js?v=<?= $escape((string)filemtime(dirname(__DIR__,2).'/public/assets/daily-report-detail.js')) ?>" defer></script>
