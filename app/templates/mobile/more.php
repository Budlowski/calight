<?php
// „Mehr“: weitere berechtigte Bereiche (mobil: Zeiten, Tagesberichte, Rapporte; übrige führen
// noch zu den responsiven Seiten der Fachmodule). Nur aktive Module mit Leserecht.
$links = array_values(array_filter([
    ['visible' => $features['dailyReports'], 'href' => '/mobile/daily-reports', 'icon' => 'calendar-days', 'label' => 'Tagesberichte'],
    ['visible' => $features['workReports'], 'href' => '/mobile/rapports', 'icon' => 'clipboard-check', 'label' => 'Rapporte'],
    ['visible' => $features['materialRequests'], 'href' => '/material-requests', 'icon' => 'package', 'label' => 'Materialbedarf'],
    ['visible' => $features['measurements'], 'href' => '/measurements', 'icon' => 'ruler', 'label' => 'Aufmaße'],
    ['visible' => $features['times'], 'href' => '/mobile/times?view=week', 'icon' => 'clock-3', 'label' => 'Zeiten, Abwesenheiten, Reisekosten und Einreichung'],
    ['visible' => $features['documents'], 'href' => '/documents', 'icon' => 'files', 'label' => 'Dokumente'],
], static fn (array $link): bool => $link['visible']));
?>
<div class="mobile-page">
    <h1 class="mobile-title">Mehr</h1>

    <?php if ($links !== []): ?>
    <section class="mobile-card" aria-labelledby="mobile-more-areas">
        <h2 id="mobile-more-areas">Bereiche</h2>
        <ul class="mobile-list">
            <?php foreach ($links as $link): ?>
                <li><a class="mobile-list-item" href="<?= $escape($link['href']) ?>"><span class="mobile-list-main mobile-list-icon"><span class="ui-icon icon-<?= $escape($link['icon']) ?>" aria-hidden="true"></span><strong><?= $escape($link['label']) ?></strong></span><span aria-hidden="true">›</span></a></li>
            <?php endforeach; ?>
        </ul>
        <p class="mobile-card-text muted">Materialbedarf, Aufmaße und Dokumente öffnen vorerst die Standardansicht von Calight.</p>
    </section>
    <?php endif; ?>

    <section class="mobile-card" aria-labelledby="mobile-more-account">
        <h2 id="mobile-more-account">Konto</h2>
        <p class="mobile-card-text"><strong><?= $escape($currentUser['displayName']) ?></strong> <span class="muted">(<?= $escape($currentUser['username']) ?>)</span></p>
        <div class="mobile-stack">
            <?php if ($features['switchInterface']): ?>
                <form action="/interface" method="post">
                    <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="interface" value="office">
                    <button class="button button-light mobile-wide-button" type="submit" data-icon="layout-dashboard">Zur Büroansicht wechseln</button>
                </form>
            <?php endif; ?>
            <form action="/logout" method="post">
                <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                <button class="button button-light mobile-wide-button" type="submit">Abmelden</button>
            </form>
        </div>
    </section>
</div>
