<?php $title='Tagesbericht';$contentClass='content-wide'; ?>
<div id="daily-report-detail" class="detail-page" data-id="<?= $escape($reportId) ?>" data-csrf="<?= $escape($csrfToken) ?>">
    <nav class="breadcrumb" aria-label="Brotkrümelnavigation"><ol><li><a href="/daily-reports">Tagesberichte</a></li><li data-order-crumb hidden></li><li aria-current="page" data-current-crumb>Tagesbericht</li></ol></nav>
    <div class="page-heading"><div><h1 data-title>Tagesbericht</h1><p class="muted" data-meta>Bericht wird geladen …</p></div><div class="order-detail-actions"><a class="button button-light" href="/api/daily-reports/<?= $escape(rawurlencode($reportId)) ?>/pdf" data-icon="file-down" title="Gespeicherter Stand als PDF zur internen Dokumentation">PDF</a></div></div>
    <div class="notice" data-message role="status" hidden></div>
    <form id="daily-report-form" class="card form-stack detail-form-card" novalidate>
        <section class="report-form-section">
            <div class="step-section-heading"><span class="step-section-number">1</span><div><h2>Zuordnung und Grunddaten</h2><small>Kunde, Projekt, Auftrag und Berichtsdatum</small></div></div>
            <div class="report-assignment-box" data-assignment></div>
            <div class="form-grid report-head-grid">
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
            <div class="step-section-heading"><span class="step-section-number">4</span><div><h2>Arbeitszeiten</h2><small data-time-summary>0,000 Stunden erfasst</small></div><div class="step-section-actions"><button class="button button-light button-small" type="button" data-add-entry data-icon="plus">Stunden hinzufügen</button></div></div>
            <div data-entries></div>
        </section>
        <p class="field-error" data-error hidden></p>
        <div class="form-actions"><button class="button" type="submit" data-save data-icon="save">Speichern</button><a class="button button-light" href="/daily-reports">Abbrechen</a><button class="button button-danger-light" type="button" data-delete data-icon="trash">Bericht löschen</button></div>
    </form>

    <section class="card report-extra-section" aria-labelledby="report-attachments-heading">
        <div class="step-section-heading">
            <span class="step-section-number">5</span>
            <div><h2 id="report-attachments-heading">Fotos und Anhänge</h2><small data-attachment-summary>Fotos, PDF- oder Textdateien bis 15 MB</small></div>
            <div class="step-section-actions" data-attachment-actions hidden>
                <label class="button button-light button-small file-button" data-icon="plus">Foto aufnehmen<input type="file" accept="image/*" capture="environment" data-attachment-input></label>
                <label class="button button-light button-small file-button" data-icon="file-up">Datei hinzufügen<input type="file" accept="image/*,application/pdf,text/plain,.heic,.heif" multiple data-attachment-input></label>
            </div>
        </div>
        <p class="field-error" data-attachment-error hidden></p>
        <div data-attachments aria-live="polite"></div>
    </section>

    <section class="card report-extra-section" aria-labelledby="report-material-heading">
        <div class="step-section-heading">
            <span class="step-section-number">6</span>
            <div><h2 id="report-material-heading">Materialbedarf</h2><small>Benötigtes Material an das Büro melden; wird sofort und unabhängig vom Bericht gespeichert.</small></div>
            <div class="step-section-actions"><button class="button button-light button-small" type="button" data-add-material data-icon="plus">Material anfordern</button></div>
        </div>
        <div data-material-requests></div>
    </section>
</div>
<script src="/assets/material-requests.js?v=<?= $escape((string)filemtime(dirname(__DIR__,2).'/public/assets/material-requests.js')) ?>" defer></script>
<script src="/assets/daily-report-detail.js?v=<?= $escape((string)filemtime(dirname(__DIR__,2).'/public/assets/daily-report-detail.js')) ?>" defer></script>
