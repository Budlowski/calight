<?php $title = 'Datenbereinigung'; $contentClass = 'content-wide'; $asset = static fn (string $file): string => '/assets/' . $file . '?v=' . (string) filemtime(dirname(__DIR__, 2) . '/public/assets/' . $file); ?>
<div class="admin-shell">
<?php $adminArea = 'settings'; $adminActive = 'system-purge'; require __DIR__ . '/../partials/admin-nav.php'; ?>
<div class="admin-content">
<div id="system-purge-app" class="system-purge" data-csrf="<?= $escape($csrfToken) ?>">
    <div class="page-heading"><div><h1>Datenbereinigung</h1><p class="muted">Test- und Geschäftsdaten vor der Produktivnahme gezielt entfernen. Benutzerkonten, Einstellungen und nicht ausgewählte Daten bleiben erhalten.</p></div></div>
    <div class="notice notice-error" role="note"><strong>Unwiderrufliche Löschung.</strong> Ausgewählte Daten und zugehörige Dateien werden endgültig entfernt. Vorher wird verpflichtend eine Sicherung erstellt; eine Wiederherstellung erfolgt nur manuell aus dieser Sicherung. Echte Geschäftsunterlagen können gesetzlichen Aufbewahrungspflichten unterliegen.</div>
    <div class="notice" data-message role="status" hidden></div>
    <div data-content><p class="muted">Wird geladen …</p></div>
</div>
</div>
</div>
<script src="<?= $escape($asset('system-purge.js')) ?>" defer></script>
