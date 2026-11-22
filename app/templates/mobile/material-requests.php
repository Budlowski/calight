<?php
// Mobiler Materialbedarf: Liste serverseitig gefiltert (Sichtbarkeit wie im Dienst), Darstellung
// und Aktionen (Bearbeiten, Löschen, Status) über die gemeinsamen Bausteine CalightMaterialRequests.
require __DIR__ . '/partials/helpers.php';
$listUrl = static function (array $changes) use ($list): string {
    $query = array_filter(array_merge(['q' => $list['q'], 'status' => $list['status'], 'order' => $list['order'], 'page' => (string) $list['page']], $changes),
        static fn (string $value, string $key): bool => $value !== '' && !($key === 'status' && $value === 'open') && !($key === 'page' && $value === '1'), ARRAY_FILTER_USE_BOTH);
    return '/mobile/material-requests' . ($query === [] ? '' : '?' . http_build_query($query));
};
$asset = static fn (string $file): string => '/assets/' . $file . '?v=' . (string) filemtime(dirname(__DIR__, 2) . '/public/assets/' . $file);
$json = static fn (mixed $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
?>
<div class="mobile-page" id="mobile-reports" data-kind="material" data-create-for="<?= $escape($createFor) ?>" data-csrf="<?= $escape($csrfToken) ?>">
    <div class="mobile-title-row">
        <h1 class="mobile-title">Materialbedarf</h1>
        <?php if ($canCreate): ?><button class="button" type="button" data-mobile-create data-icon="plus">Anfordern</button><?php endif; ?>
    </div>
    <div class="notice" data-message role="status" hidden></div>

    <form class="mobile-search" action="/mobile/material-requests" method="get" role="search">
        <label class="visually-hidden" for="mobile-material-search">Materialbedarf durchsuchen</label>
        <input id="mobile-material-search" type="search" name="q" value="<?= $escape($list['q']) ?>" placeholder="Material, Auftrag, Kunde" enterkeyhint="search" autocomplete="off">
        <?php if ($list['status'] !== 'open'): ?><input type="hidden" name="status" value="<?= $escape($list['status']) ?>"><?php endif; ?>
        <?php if ($list['order'] !== ''): ?><input type="hidden" name="order" value="<?= $escape($list['order']) ?>"><?php endif; ?>
        <button class="button" type="submit">Suchen</button>
    </form>

    <nav class="mobile-chips" aria-label="Status">
        <?php foreach ($filters as $key => $label): ?>
            <a class="mobile-chip" href="<?= $escape($listUrl(['status' => $key, 'page' => '1'])) ?>"<?= $list['status'] === $key ? ' aria-current="true"' : '' ?>><?= $escape($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <p class="mobile-result-count muted" role="status"><?= $escape($list['total'] === 1 ? '1 Anforderung' : $list['total'] . ' Anforderungen') ?>
        <?php if ($list['order'] !== ''): ?> · nur ein Auftrag (<a href="<?= $escape($listUrl(['order' => '', 'page' => '1'])) ?>">alle anzeigen</a>)<?php endif; ?></p>

    <?php if ($list['items'] === []): ?>
        <p class="mobile-card empty-state"><?= $list['q'] !== '' ? 'Keine passenden Anforderungen gefunden.' : 'Kein Materialbedarf in dieser Auswahl.' ?></p>
    <?php else: ?>
        <ul class="mobile-list mobile-order-list" data-material-list>
            <?php foreach ($list['items'] as $request): ?>
                <li class="mobile-card mobile-material">
                    <small class="mobile-list-kicker"><?= $escape(implode(' · ', array_filter([$request['orderNumber'] ?? $request['costCenterNumber'] ?? 'Ohne Auftrag', $request['customerName'] ?? '']))) ?></small>
                    <?php if ($request['orderTitle']): ?><strong><?= $escape($request['orderTitle']) ?></strong><?php endif; ?>
                    <div data-material-item="<?= $escape($request['id']) ?>"></div>
                    <?php if ($request['reportUrl']): ?><a class="mobile-card-link" href="<?= $escape($request['reportUrl']) ?>">Aus Tagesbericht vom <?= $escape($mobileDate($request['reportDate'])) ?></a><?php endif; ?>
                </li>
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
<script type="application/json" data-material-json><?= $json($list['items']) ?></script>
<?php if ($orderOptions !== []): ?><script type="application/json" data-order-options><?= $json($orderOptions) ?></script><?php endif; ?>
<script src="<?= $escape($asset('material-requests.js')) ?>" defer></script>
<script src="<?= $escape($asset('mobile-reports.js')) ?>" defer></script>
