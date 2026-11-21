<?php
// Persönliche mobile Zeiterfassung. Inhalte, Formulare und Aktionen baut mobile-times.js über die
// bestehende API auf; der Server prüft Rechte, Sperren und Validierung wie in der Büroansicht.
$pageScripts = ['mobile-times.js'];
?>
<div class="mobile-page mobile-times" id="mobile-times"
     data-csrf="<?= $escape($csrfToken) ?>"
     data-today="<?= $escape($today) ?>"
     data-date="<?= $escape($initial['date']) ?>"
     data-view="<?= $escape($initial['view']) ?>"
     data-form="<?= $escape($initial['form']) ?>"
     data-order="<?= $escape($initial['order']) ?>">
    <h1 class="mobile-title">Zeiten</h1>

    <nav class="mobile-segmented" aria-label="Ansicht">
        <button type="button" data-view-switch="day">Tag</button>
        <button type="button" data-view-switch="week">Woche</button>
        <button type="button" data-view-switch="month">Monat</button>
    </nav>

    <nav class="mobile-day-nav" aria-label="Zeitraum wählen">
        <button class="button button-light" type="button" data-step="-1" aria-label="Zurück">‹</button>
        <span class="mobile-day-label" data-period-label aria-live="polite"></span>
        <button class="button button-light" type="button" data-step="1" aria-label="Weiter">›</button>
    </nav>
    <p class="mobile-center"><button class="link-button" type="button" data-go-today hidden>Zu heute</button></p>

    <div class="notice" data-message role="status" hidden></div>
    <div data-content><p class="mobile-card empty-state">Zeiten werden geladen …</p></div>
    <noscript><p class="mobile-card">Die Zeiterfassung benötigt JavaScript.</p></noscript>
</div>

<section class="mobile-sheet" data-sheet role="dialog" aria-modal="true" aria-labelledby="mobile-sheet-title" hidden>
    <header class="mobile-sheet-header">
        <button class="mobile-sheet-close" type="button" data-sheet-cancel aria-label="Schließen">‹</button>
        <h2 id="mobile-sheet-title" data-sheet-title></h2>
    </header>
    <form class="mobile-sheet-form" data-sheet-form novalidate>
        <div class="mobile-sheet-body" data-sheet-body></div>
        <div class="notice notice-error mobile-sheet-error" data-sheet-error role="alert" hidden></div>
        <footer class="mobile-sheet-actions" data-sheet-actions></footer>
    </form>
</section>
