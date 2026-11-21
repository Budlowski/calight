<?php
require __DIR__ . '/partials/helpers.php';
$today = $home['today'];
$features = $home['features'];
$firstName = trim(explode(' ', trim((string) ($currentUser['displayName'] ?? '')))[0] ?? '');
?>
<div class="mobile-page">
    <h1 class="mobile-title">Hallo<?= $firstName !== '' ? ', ' . $escape($firstName) : '' ?></h1>
    <p class="mobile-subtitle"><?= $escape($mobileWeekday(new DateTimeImmutable('today')) . ', ' . (new DateTimeImmutable('today'))->format('d.m.Y')) ?></p>

    <?php if ($today !== null): ?>
    <section class="mobile-card" aria-labelledby="mobile-today-heading">
        <div class="mobile-card-heading">
            <h2 id="mobile-today-heading">Arbeitszeit heute</h2>
            <?php if ($today['available']): ?><span class="status-badge mobile-lock-<?= $escape($today['lockStatus']) ?>"><?= $escape($today['lockLabel']) ?></span><?php endif; ?>
        </div>
        <?php if ($today['available']): ?>
            <a class="mobile-today" href="/mobile/times">
                <strong class="mobile-today-value"><?= $escape($mobileHours($today['netMinutes'])) ?></strong>
                <span class="muted">
                    <?= $escape($today['entryCount'] === 1 ? '1 Buchung' : $today['entryCount'] . ' Buchungen') ?><?= $today['travelMinutes'] > 0 ? ' · davon Reisezeit ' . $escape($mobileHours($today['travelMinutes'])) : '' ?><?= $today['absences'] !== [] ? ' · ' . $escape(implode(', ', $today['absences'])) : '' ?>
                </span>
            </a>
        <?php else: ?>
            <p class="muted mobile-card-text"><?= $escape($today['message']) ?></p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($home['quickActions'] !== []): ?>
    <section aria-labelledby="mobile-quick-heading">
        <h2 class="mobile-section-title" id="mobile-quick-heading">Schnellzugriff</h2>
        <div class="mobile-quick-grid">
            <?php foreach ($home['quickActions'] as $action): ?>
                <a class="mobile-quick-action" href="<?= $escape($action['url']) ?>">
                    <span class="ui-icon icon-<?= $escape($action['icon']) ?>" aria-hidden="true"></span>
                    <strong><?= $escape($action['label']) ?></strong>
                    <small><?= $escape($action['hint']) ?></small>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($home['hints'] !== []): ?>
    <section class="mobile-card" aria-labelledby="mobile-hints-heading">
        <div class="mobile-card-heading"><h2 id="mobile-hints-heading">Prüfhinweise dieser Woche</h2><span class="status-badge"><?= $escape((string) count($home['hints'])) ?></span></div>
        <ul class="mobile-hint-list">
            <?php foreach (array_slice($home['hints'], 0, 5) as $hint): ?>
                <li class="mobile-hint mobile-hint-<?= $escape($hint['level']) ?>"><span aria-hidden="true"><?= $hint['level'] === 'error' ? '✕' : '⚠' ?></span><span><span class="visually-hidden"><?= $hint['level'] === 'error' ? 'Sperrfehler: ' : 'Hinweis: ' ?></span><?= $escape($hint['message']) ?></span></li>
            <?php endforeach; ?>
        </ul>
        <?php if (count($home['hints']) > 5): ?><p class="mobile-card-text muted">Weitere <?= $escape((string) (count($home['hints']) - 5)) ?> Hinweise in der Wochenansicht.</p><?php endif; ?>
        <a class="mobile-card-link" href="/mobile/times?view=week">Woche prüfen</a>
    </section>
    <?php endif; ?>

    <section class="mobile-card" aria-labelledby="mobile-open-heading">
        <div class="mobile-card-heading"><h2 id="mobile-open-heading">Offene eigene Vorgänge</h2><?php if ($home['openItems'] !== []): ?><span class="status-badge"><?= $escape((string) count($home['openItems'])) ?></span><?php endif; ?></div>
        <?php if ($home['openItems'] === []): ?>
            <p class="muted mobile-card-text">Keine offenen Entwürfe oder Anforderungen.</p>
        <?php else: ?>
            <ul class="mobile-list">
                <?php foreach (array_slice($home['openItems'], 0, 8) as $item): ?>
                    <li><a class="mobile-list-item" href="<?= $escape($item['url']) ?>">
                        <span class="mobile-list-main"><small class="mobile-list-kicker"><?= $escape($item['type']) ?></small><strong><?= $escape($item['label']) ?></strong><?php if ($item['detail'] !== ''): ?><small class="muted"><?= $escape($item['detail']) ?></small><?php endif; ?></span>
                        <span class="status-badge"><?= $escape($item['status']) ?></span>
                    </a></li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($home['openItems']) > 8): ?><p class="mobile-card-text muted">und <?= $escape((string) (count($home['openItems']) - 8)) ?> weitere</p><?php endif; ?>
        <?php endif; ?>
    </section>

    <?php if ($features['orders']): ?>
    <section class="mobile-card" aria-labelledby="mobile-orders-heading">
        <div class="mobile-card-heading"><h2 id="mobile-orders-heading">Aktuelle Aufträge</h2></div>
        <?php if ($home['orders'] === []): ?>
            <p class="muted mobile-card-text">Keine aktiven Aufträge.</p>
        <?php else: ?>
            <ul class="mobile-list">
                <?php foreach ($home['orders'] as $order): ?>
                    <li><a class="mobile-list-item" href="/mobile/orders/<?= $escape(rawurlencode((string) $order['id'])) ?>">
                        <span class="mobile-list-main"><small class="mobile-list-kicker"><?= $escape($order['number']) ?></small><strong><?= $escape($order['title']) ?></strong><small class="muted"><?= $escape($order['customerName']) ?><?= $order['site'] !== '' ? ' · ' . $escape($order['site']) : '' ?></small></span>
                        <span class="<?= $escape($mobileStatusClass($order['status'])) ?>"><?= $escape($order['statusLabel']) ?></span>
                    </a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <a class="mobile-card-link" href="/mobile/orders">Alle Aufträge</a>
    </section>
    <?php endif; ?>
</div>
