<?php
// Mobile Übersicht für Tagesberichte, Rapporte bzw. Aufmaße (gemeinsames Template,
// $kind = daily|work|measurement). Liste serverseitig (Suche, Statusfilter, Seiten); Anlegen über
// die gemeinsamen Anlegewege der Fachmodule (CalightDailyReports.create,
// CalightWorkReports.openCreateDialog, CalightMeasurements.createForOrder).
require __DIR__ . '/partials/helpers.php';
$base = ['daily' => '/mobile/daily-reports', 'work' => '/mobile/rapports', 'measurement' => '/mobile/measurements'][$kind];
$moduleScript = ['daily' => 'daily-reports.js', 'work' => 'work-reports.js', 'measurement' => 'measurements.js'][$kind];
$orderOptions ??= [];
$listUrl = static function (array $changes) use ($list, $base): string {
    $query = array_filter(array_merge(['q' => $list['q'], 'status' => $list['status'], 'order' => $list['order'], 'page' => (string) $list['page']], $changes),
        static fn (string $value, string $key): bool => $value !== '' && !($key === 'status' && $value === 'all') && !($key === 'page' && $value === '1'), ARRAY_FILTER_USE_BOTH);
    return $base . ($query === [] ? '' : '?' . http_build_query($query));
};
$asset = static fn (string $file): string => '/assets/' . $file . '?v=' . (string) filemtime(dirname(__DIR__, 2) . '/public/assets/' . $file);
?>
<div class="mobile-page" id="mobile-reports" data-kind="<?= $escape($kind) ?>" data-create-for="<?= $escape($createFor) ?>" data-csrf="<?= $escape($csrfToken) ?>">
    <div class="mobile-title-row">
        <h1 class="mobile-title"><?= $escape($heading) ?></h1>
        <?php if ($canCreate): ?><button class="button" type="button" data-mobile-create data-icon="plus">Neu</button><?php endif; ?>
    </div>
    <div class="notice notice-error" data-message role="alert" hidden></div>

    <form class="mobile-search" action="<?= $escape($base) ?>" method="get" role="search">
        <label class="visually-hidden" for="mobile-report-search"><?= $escape($heading) ?> durchsuchen</label>
        <input id="mobile-report-search" type="search" name="q" value="<?= $escape($list['q']) ?>" placeholder="Bezeichnung, Kunde, Auftrag, Nummer" enterkeyhint="search" autocomplete="off">
        <?php if ($list['status'] !== 'all'): ?><input type="hidden" name="status" value="<?= $escape($list['status']) ?>"><?php endif; ?>
        <?php if ($list['order'] !== ''): ?><input type="hidden" name="order" value="<?= $escape($list['order']) ?>"><?php endif; ?>
        <button class="button" type="submit">Suchen</button>
    </form>

    <nav class="mobile-chips" aria-label="Status">
        <?php foreach ($filters as $key => $label): ?>
            <a class="mobile-chip" href="<?= $escape($listUrl(['status' => $key, 'page' => '1'])) ?>"<?= $list['status'] === $key ? ' aria-current="true"' : '' ?>><?= $escape($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <p class="mobile-result-count muted" role="status">
        <?= $escape($list['total'] === 1 ? '1 Dokument' : $list['total'] . ' Dokumente') ?><?= $list['q'] !== '' ? ' für „' . $escape($list['q']) . '“' : '' ?>
        <?php if ($list['order'] !== ''): ?> · nur ein Auftrag (<a href="<?= $escape($listUrl(['order' => '', 'page' => '1'])) ?>">alle anzeigen</a>)<?php endif; ?>
    </p>

    <?php if ($list['items'] === []): ?>
        <p class="mobile-card empty-state"><?= $list['q'] !== '' || $list['status'] !== 'all' ? 'Keine passenden Dokumente gefunden.' : 'Noch keine Dokumente vorhanden.' ?></p>
    <?php else: ?>
        <ul class="mobile-list mobile-order-list">
            <?php foreach ($list['items'] as $item): ?>
                <li><a class="mobile-list-item mobile-card" href="<?= $escape($item['url']) ?>">
                    <span class="mobile-list-main">
                        <small class="mobile-list-kicker"><?= $escape($mobileDate($item['date'])) ?> · <?= $escape($item['target'] !== '' ? $item['target'] : $item['targetLabel']) ?></small>
                        <strong><?= $escape($item['title']) ?></strong>
                        <?php if ($item['customerName'] !== ''): ?><small><?= $escape($item['customerName']) ?></small><?php endif; ?>
                        <?php if ($item['detail'] !== ''): ?><small class="muted"><?= $escape($item['detail']) ?></small><?php endif; ?>
                    </span>
                    <span class="mobile-list-side">
                        <span class="status-badge mobile-doc-<?= $escape($item['status']) ?>"><?= $escape($item['statusLabel']) ?></span>
                        <?php if (isset($item['hours'])): ?><small class="mobile-entry-hours"><?= $escape(str_replace('.', ',', rtrim(rtrim($item['hours'], '0'), '.') ?: '0')) ?> h</small><?php endif; ?>
                    </span>
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
<?php if ($orderOptions !== []): ?><script type="application/json" data-order-options><?= json_encode($orderOptions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script><?php endif; ?>
<script src="<?= $escape($asset($moduleScript)) ?>" defer></script>
<script src="<?= $escape($asset('mobile-reports.js')) ?>" defer></script>
