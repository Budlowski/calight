<?php $title = 'Zeiterfassung'; $contentClass = 'content-wide'; ?>
<div id="time-tracking-app" class="overview-page time-tracking" data-csrf="<?= $escape($csrfToken) ?>">
    <div class="page-heading">
        <div>
            <h1>Zeiterfassung</h1>
            <p class="muted">Arbeitszeiten aus Rapporten, Tagesberichten und eigenen Zeitbuchungen.</p>
        </div>
    </div>
    <div class="notice" data-message role="status" hidden></div>
    <div class="time-toolbar">
        <div class="view-switch time-view-switch" role="tablist" aria-label="Ansicht">
            <button class="button button-light button-small" type="button" role="tab" data-view="day">Tag</button>
            <button class="button button-light button-small" type="button" role="tab" data-view="week">Woche</button>
            <button class="button button-light button-small" type="button" role="tab" data-view="month">Monat</button>
            <button class="button button-light button-small" type="button" role="tab" data-view="report" hidden>Auswertung</button>
            <button class="button button-light button-small" type="button" role="tab" data-view="approvals" hidden>Freigaben</button>
        </div>
        <div class="time-period">
            <button class="icon-button" type="button" data-step="-1" title="Zurück" aria-label="Zurück">‹</button>
            <strong data-period-label aria-live="polite"></strong>
            <button class="icon-button" type="button" data-step="1" title="Weiter" aria-label="Weiter">›</button>
            <button class="button button-light button-small" type="button" data-today>Heute</button>
        </div>
        <label class="time-employee" data-employee-field hidden><span class="visually-hidden">Mitarbeiter</span><select data-employee></select></label>
    </div>
    <div data-content aria-live="polite"><p class="empty-state">Wird geladen …</p></div>
</div>
<script src="/assets/time-tracking.js?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/time-tracking.js')) ?>" defer></script>
