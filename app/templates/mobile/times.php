<?php
require __DIR__ . '/partials/helpers.php';
$day = new DateTimeImmutable($times['date']);
$previous = $day->modify('-1 day')->format('Y-m-d');
$next = $day->modify('+1 day')->format('Y-m-d');
$dayUrl = static fn (string $date): string => '/mobile/times' . ($date === $today ? '' : '?date=' . rawurlencode($date));
$editUrl = '/time-tracking?view=day&date=' . rawurlencode($times['date']);
?>
<div class="mobile-page">
    <h1 class="mobile-title">Zeiten</h1>

    <nav class="mobile-day-nav" aria-label="Tag wählen">
        <a class="button button-light" href="<?= $escape($dayUrl($previous)) ?>" aria-label="Vorheriger Tag">‹</a>
        <span class="mobile-day-label"><strong><?= $escape($mobileWeekday($day)) ?></strong><span><?= $escape($day->format('d.m.Y')) ?></span></span>
        <a class="button button-light" href="<?= $escape($dayUrl($next)) ?>" aria-label="Nächster Tag">›</a>
    </nav>
    <?php if ($times['date'] !== $today): ?><p class="mobile-center"><a href="/mobile/times">Zu heute</a></p><?php endif; ?>

    <?php if (!$times['available']): ?>
        <p class="mobile-card muted"><?= $escape($times['message']) ?></p>
    <?php else: ?>
        <section class="mobile-card" aria-labelledby="mobile-day-total">
            <div class="mobile-card-heading"><h2 id="mobile-day-total">Arbeitszeit</h2><span class="status-badge mobile-lock-<?= $escape($times['lockStatus']) ?>"><?= $escape($times['lockLabel']) ?></span></div>
            <p class="mobile-today"><strong class="mobile-today-value"><?= $escape($mobileHours($times['netMinutes'])) ?></strong><?php if ($times['travelMinutes'] > 0): ?><span class="muted">davon Reisezeit <?= $escape($mobileHours($times['travelMinutes'])) ?></span><?php endif; ?></p>
        </section>

        <?php if ($times['absences'] !== []): ?>
        <section class="mobile-card" aria-labelledby="mobile-day-absences">
            <h2 id="mobile-day-absences">Abwesenheit</h2>
            <ul class="mobile-list">
                <?php foreach ($times['absences'] as $absence): ?>
                    <li class="mobile-list-item"><strong><?= $escape($absence['label']) ?></strong><span class="muted"><?= $absence['minutes'] === null ? 'ganztägig' : $escape($mobileHours($absence['minutes'])) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>

        <section class="mobile-card" aria-labelledby="mobile-day-entries">
            <h2 id="mobile-day-entries">Buchungen</h2>
            <?php if ($times['entries'] === []): ?>
                <p class="muted mobile-card-text">Keine Arbeitszeiten an diesem Tag.</p>
            <?php else: ?>
                <ul class="mobile-list">
                    <?php foreach ($times['entries'] as $entry): ?>
                        <li class="mobile-list-item">
                            <span class="mobile-list-main">
                                <small class="mobile-list-kicker"><?= $escape($entry['time']) ?><?= $entry['source'] !== '' ? ' · ' . $escape($entry['source']) : '' ?></small>
                                <strong><?= $escape($entry['target']) ?></strong>
                                <?php if ($entry['notes'] !== ''): ?><small class="muted"><?= $escape($entry['notes']) ?></small><?php endif; ?>
                            </span>
                            <span class="mobile-entry-hours"><?= $escape($mobileHours($entry['netMinutes'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <a class="button mobile-wide-button" href="<?= $escape($editUrl) ?>"><?= $times['canEdit'] ? 'In der Zeiterfassung bearbeiten' : 'In der Zeiterfassung ansehen' ?></a>
        <p class="mobile-card-text muted mobile-center">Erfassung, Reisekosten und Einreichung erfolgen vorerst in der Zeiterfassung.</p>
    <?php endif; ?>
</div>
