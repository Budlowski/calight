<div class="mobile-page">
    <h1 class="mobile-title"><?= $escape($title ?? 'Fehler') ?></h1>
    <p class="mobile-card mobile-card-text"><?= $escape($message ?? 'Die Seite konnte nicht geladen werden.') ?></p>
    <a class="button button-light mobile-wide-button" href="/mobile/orders">Zur Auftragsübersicht</a>
</div>
