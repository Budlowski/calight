<?php $title = 'Module'; $contentClass = 'content-wide'; ?>
<div id="module-settings-app" data-csrf="<?= $escape($csrfToken) ?>">
    <nav class="breadcrumb" aria-label="Brotkrümelnavigation"><ol><li><a href="/settings">Einstellungen</a></li><li aria-current="page">Module</li></ol></nav>
    <div class="page-heading"><div><h1>Module</h1><p class="muted">Funktionsumfang für den gesamten Betrieb festlegen. Berechtigungen der Benutzer gelten unabhängig davon weiter.</p></div></div>
    <div class="notice" data-message role="status" hidden></div>
    <div class="module-list" data-modules><p class="muted">Module werden geladen …</p></div>
    <p class="muted operations-note">Eine Deaktivierung löscht keine Daten: Datensätze, Nachweise und PDFs bleiben erhalten und lesbar, neue Vorgänge und Änderungen sind bis zur Wiederaktivierung gesperrt. Einstellungen eines Moduls bleiben gespeichert. Offene Vorgänge werden vor der Deaktivierung zur Bestätigung aufgeführt und bleiben unverändert; nach der Wiederaktivierung lassen sie sich regulär weiterbearbeiten.</p>
</div>
<script src="/assets/module-settings.js?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/module-settings.js')) ?>" defer></script>
