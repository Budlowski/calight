<?php
require __DIR__ . '/partials/helpers.php';
$filters = \App\Application\MobileWorkspace::ORDER_FILTERS;
// Links der Liste behalten Suche und Statusfilter; Standardwerte stehen nicht in der URL.
$listUrl = static function (array $changes) use ($list): string {
    $query = array_filter(array_merge(['q' => $list['q'], 'status' => $list['status'], 'page' => (string) $list['page']], $changes),
        static fn (string $value, string $key): bool => $value !== '' && !($key === 'status' && $value === 'active') && !($key === 'page' && $value === '1'), ARRAY_FILTER_USE_BOTH);
    return '/mobile/orders' . ($query === [] ? '' : '?' . http_build_query($query));
};
?>
<div class="mobile-page">
    <h1 class="mobile-title">Aufträge</h1>

    <form class="mobile-search" action="/mobile/orders" method="get" role="search">
        <label class="visually-hidden" for="mobile-order-search">Aufträge durchsuchen</label>
        <input id="mobile-order-search" type="search" name="q" value="<?= $escape($list['q']) ?>" placeholder="Nummer, Bezeichnung, Kunde, Projekt" enterkeyhint="search" autocomplete="off">
        <?php if ($list['status'] !== 'active'): ?><input type="hidden" name="status" value="<?= $escape($list['status']) ?>"><?php endif; ?>
        <button class="button" type="submit">Suchen</button>
    </form>

    <nav class="mobile-chips" aria-label="Status">
        <?php foreach ($filters as $key => $label): ?>
            <a class="mobile-chip" href="<?= $escape($listUrl(['status' => $key, 'page' => '1'])) ?>"<?= $list['status'] === $key ? ' aria-current="true"' : '' ?>><?= $escape($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <p class="mobile-result-count muted" role="status"><?= $escape($list['total'] === 1 ? '1 Auftrag' : $list['total'] . ' Aufträge') ?><?= $list['q'] !== '' ? ' für „' . $escape($list['q']) . '“' : '' ?></p>

    <?php if ($list['items'] === []): ?>
        <p class="mobile-card empty-state"><?= $list['q'] !== '' ? 'Keine passenden Aufträge gefunden.' : 'Keine Aufträge in dieser Auswahl.' ?></p>
    <?php else: ?>
        <ul class="mobile-list mobile-order-list">
            <?php foreach ($list['items'] as $order): ?>
                <li><a class="mobile-list-item mobile-card" href="/mobile/orders/<?= $escape(rawurlencode((string) $order['id'])) ?>">
                    <span class="mobile-list-main">
                        <small class="mobile-list-kicker"><?= $escape($order['number']) ?></small>
                        <strong><?= $escape($order['title']) ?></strong>
                        <small><?= $escape($order['customerName']) ?><?= $order['projectName'] ? ' · ' . $escape($order['projectName']) : '' ?></small>
                        <?php if ($order['site'] !== ''): ?><small class="muted"><?= $escape($order['site']) ?></small><?php endif; ?>
                    </span>
                    <span class="<?= $escape($mobileStatusClass($order['status'])) ?>"><?= $escape($order['statusLabel']) ?></span>
                </a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($list['pageCount'] > 1): ?>
    <nav class="mobile-pagination" aria-label="Seiten">
        <?php if ($list['page'] > 1): ?><a class="button button-light" href="<?= $escape($listUrl(['page' => (string) ($list['page'] - 1)])) ?>">Zurück</a><?php else: ?><span></span><?php endif; ?>
        <span class="muted">Seite <?= $escape((string) $list['page']) ?> von <?= $escape((string) $list['pageCount']) ?></span>
        <?php if ($list['page'] < $list['pageCount']): ?><a class="button button-light" href="<?= $escape($listUrl(['page' => (string) ($list['page'] + 1)])) ?>">Weiter</a><?php else: ?><span></span><?php endif; ?>
    </nav>
    <?php endif; ?>
</div>
