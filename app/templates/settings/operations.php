<?php $title='Betrieb';$contentClass='content-wide'; ?>
<div id="operations-settings-app" data-csrf="<?= $escape($csrfToken) ?>">
    <nav class="breadcrumb" aria-label="Brotkrümelnavigation"><ol><li><a href="/settings">Einstellungen</a></li><li aria-current="page">Betrieb</li></ol></nav>
    <div class="page-heading"><div><h1>Betrieb</h1><p class="muted">Betriebliche Vorgaben für alle Benutzer. Änderungen verändern keine bestehenden Buchungen oder Berichte.</p></div></div>
    <div class="notice" data-message role="status" hidden></div>
    <div class="form-stack" data-settings><p class="muted">Einstellungen werden geladen …</p></div>
    <p class="muted operations-note">Die vorbelegten Werte orientieren sich am Arbeitszeitgesetz und an § 9 EStG. Sie bestimmen nur die Prüfungen und rechnerischen Referenzbeträge in Calight, ersetzen keine gesetzlichen Anforderungen und sind keine steuerliche Freigabe. Festgeschriebene Nachweise behalten die bei ihrer Festschreibung verwendeten Werte.</p>
</div>
<script src="/assets/operations-settings.js?v=<?= $escape((string)filemtime(dirname(__DIR__,2).'/public/assets/operations-settings.js')) ?>" defer></script>
