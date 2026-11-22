<?php $title='Arbeitserfassung';$contentClass='content-wide'; ?>
<div class="admin-shell">
<?php $adminArea = 'settings'; $adminActive = 'work-tracking'; require __DIR__ . '/../partials/admin-nav.php'; ?>
<div class="admin-content">
<div id="operations-settings-app" data-csrf="<?= $escape($csrfToken) ?>">
    <div class="page-heading"><div><h1>Arbeitserfassung</h1><p class="muted">Zeiterfassung, Arbeitszeitgrenzwerte, Einreichungsrhythmus und Reisekosten für alle Benutzer. Änderungen verändern keine bestehenden Buchungen oder Berichte.</p></div></div>
    <div class="notice" data-message role="status" hidden></div>
    <div class="form-stack" data-settings><p class="muted">Einstellungen werden geladen …</p></div>
    <div class="operations-savebar" data-savebar>
        <span data-dirty-state>Keine ungespeicherten Änderungen.</span>
        <button class="button button-light" type="button" data-discard disabled>Änderungen verwerfen</button>
        <button class="button" type="button" data-save data-shortcut-save data-icon="save" disabled>Speichern</button>
    </div>
    <p class="muted operations-note">Die vorbelegten Werte orientieren sich am Arbeitszeitgesetz und an § 9 EStG. Sie bestimmen nur die Prüfungen und rechnerischen Referenzbeträge in Calight, ersetzen keine gesetzlichen Anforderungen und sind keine steuerliche Freigabe. Festgeschriebene Nachweise behalten die bei ihrer Festschreibung verwendeten Werte.</p>
</div>
</div>
</div>
<script src="/assets/operations-settings.js?v=<?= $escape((string)filemtime(dirname(__DIR__,2).'/public/assets/operations-settings.js')) ?>" defer></script>
