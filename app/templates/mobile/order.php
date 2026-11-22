<?php
require __DIR__ . '/partials/helpers.php';
$order = $detail['order'];
$typeLabels = ['bedarf' => 'Bedarf', 'alternative' => 'Alternative'];
$quantity = static function (string $value): string {
    // Mengen ohne nachgestellte Nullen, deutsches Dezimalkomma (Anzeige; Werte bleiben unverändert).
    $trimmed = str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    return str_replace('.', ',', $trimmed === '' || $trimmed === '-' ? '0' : $trimmed);
};
$relatedSections = [
    ['key' => 'dailyReports', 'title' => 'Tagesberichte', 'empty' => 'Noch keine Tagesberichte.',
        'items' => $detail['dailyReports']['items'] ?? null, 'total' => $detail['dailyReports']['total'] ?? 0,
        'more' => '/mobile/daily-reports?order=' . rawurlencode((string) $order['id'])],
    ['key' => 'workReports', 'title' => 'Rapporte', 'empty' => 'Noch keine Rapporte.', 'items' => $detail['workReports'], 'total' => count($detail['workReports'] ?? []), 'more' => null],
    ['key' => 'materialRequests', 'title' => 'Offener Materialbedarf', 'empty' => 'Kein offener Materialbedarf.', 'items' => $detail['materialRequests'], 'total' => count($detail['materialRequests'] ?? []),
        'more' => '/material-requests?order=' . rawurlencode((string) $order['id']) . '&status=all'],
    ['key' => 'measurements', 'title' => 'Aufmaße', 'empty' => 'Noch keine Aufmaße.', 'items' => $detail['measurements'], 'total' => count($detail['measurements'] ?? []), 'more' => null],
];
?>
<div class="mobile-page">
    <nav class="mobile-back" aria-label="Zurück"><a href="/mobile/orders">‹ Aufträge</a></nav>

    <header class="mobile-card mobile-order-head">
        <small class="mobile-list-kicker"><?= $escape($order['number']) ?> · <?= $escape($order['type']) ?></small>
        <h1 class="mobile-title"><?= $escape($order['title']) ?></h1>
        <span class="<?= $escape($mobileStatusClass($order['status'])) ?>"><?= $escape($order['statusLabel']) ?></span>
        <dl class="mobile-facts">
            <div><dt>Kunde</dt><dd><?= $escape($order['customerName']) ?><?= $order['customerNumber'] ? ' <span class="muted">(' . $escape($order['customerNumber']) . ')</span>' : '' ?></dd></div>
            <div><dt>Projekt</dt><dd><?= $order['projectName'] ? $escape($order['projectName']) . ($order['projectNumber'] ? ' <span class="muted">(' . $escape($order['projectNumber']) . ')</span>' : '') : '<span class="muted">Ohne Projekt</span>' ?></dd></div>
            <div><dt>Auftragsdatum</dt><dd><?= $escape($mobileDate($order['orderDate'])) ?></dd></div>
        </dl>
        <?php if (trim((string) $order['description']) !== ''): ?><p class="mobile-description"><?= nl2br($escape($order['description'])) ?></p><?php endif; ?>
    </header>

    <?php if ($detail['actions'] !== []): ?>
    <section aria-labelledby="mobile-order-actions-heading">
        <h2 class="mobile-section-title" id="mobile-order-actions-heading">Erfassen zu diesem Auftrag</h2>
        <div class="mobile-quick-grid">
            <?php foreach ($detail['actions'] as $action): ?>
                <a class="mobile-quick-action" href="<?= $escape($action['url']) ?>"><span class="ui-icon icon-<?= $escape($action['icon']) ?>" aria-hidden="true"></span><strong><?= $escape($action['label']) ?></strong></a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="mobile-card" aria-labelledby="mobile-site-heading">
        <h2 id="mobile-site-heading">Einsatzort</h2>
        <?php if ($detail['site'] !== null): ?>
            <address class="mobile-address"><?= implode('<br>', array_map($escape, $detail['site']['lines'])) ?></address>
            <a class="button button-light mobile-wide-button" href="<?= $escape($mobileMapHref($detail['site']['query'])) ?>" target="_blank" rel="noopener noreferrer">In Karte öffnen</a>
        <?php else: ?>
            <p class="muted mobile-card-text">Keine Baustellenadresse hinterlegt.</p>
        <?php endif; ?>
        <?php $customerAddress = $detail['customerAddress']; ?>
        <?php if ($customerAddress !== null && $customerAddress['lines'] !== []): ?>
            <h3>Kundenanschrift</h3>
            <address class="mobile-address"><?= $escape($order['customerName']) ?><br><?= implode('<br>', array_map($escape, $customerAddress['lines'])) ?></address>
            <?php if ($detail['site'] === null): ?><a class="button button-light mobile-wide-button" href="<?= $escape($mobileMapHref($customerAddress['query'])) ?>" target="_blank" rel="noopener noreferrer">Kundenanschrift in Karte öffnen</a><?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="mobile-card" aria-labelledby="mobile-contact-heading">
        <h2 id="mobile-contact-heading">Ansprechpartner</h2>
        <?php if ($detail['contacts'] === [] && ($customerAddress['phone'] ?? '') === '' && ($customerAddress['email'] ?? '') === ''): ?>
            <p class="muted mobile-card-text">Keine Kontaktdaten hinterlegt.</p>
        <?php endif; ?>
        <ul class="mobile-contact-list">
            <?php foreach ($detail['contacts'] as $contact): ?>
                <li>
                    <strong><?= $escape($contact['name']) ?></strong><?php if ($contact['function'] !== ''): ?> <span class="muted">· <?= $escape($contact['function']) ?></span><?php endif; ?>
                    <span class="mobile-contact-actions">
                        <?php if ($contact['mobile'] !== ''): ?><a class="button button-light" href="<?= $escape($mobilePhoneHref($contact['mobile'])) ?>">Mobil <?= $escape($contact['mobile']) ?></a><?php endif; ?>
                        <?php if ($contact['phone'] !== ''): ?><a class="button button-light" href="<?= $escape($mobilePhoneHref($contact['phone'])) ?>">Telefon <?= $escape($contact['phone']) ?></a><?php endif; ?>
                        <?php if ($contact['email'] !== ''): ?><a class="button button-light" href="mailto:<?= $escape($contact['email']) ?>"><?= $escape($contact['email']) ?></a><?php endif; ?>
                    </span>
                </li>
            <?php endforeach; ?>
            <?php if (($customerAddress['phone'] ?? '') !== '' || ($customerAddress['email'] ?? '') !== ''): ?>
                <li>
                    <strong><?= $escape($order['customerName']) ?></strong> <span class="muted">· Kunde allgemein</span>
                    <span class="mobile-contact-actions">
                        <?php if ($customerAddress['phone'] !== ''): ?><a class="button button-light" href="<?= $escape($mobilePhoneHref($customerAddress['phone'])) ?>">Telefon <?= $escape($customerAddress['phone']) ?></a><?php endif; ?>
                        <?php if ($customerAddress['email'] !== ''): ?><a class="button button-light" href="mailto:<?= $escape($customerAddress['email']) ?>"><?= $escape($customerAddress['email']) ?></a><?php endif; ?>
                    </span>
                </li>
            <?php endif; ?>
        </ul>
    </section>

    <section class="mobile-card" aria-labelledby="mobile-services-heading">
        <h2 id="mobile-services-heading">Beauftragte Leistungen</h2>
        <?php if ($detail['services'] === []): ?>
            <p class="muted mobile-card-text">Keine verbindlichen Leistungspositionen (Regieauftrag ohne angenommenen Nachtrag).</p>
        <?php else: ?>
            <?php foreach ($detail['services'] as $source): ?>
                <details class="mobile-disclosure"<?= count($detail['services']) === 1 ? ' open' : '' ?>>
                    <summary><?= $escape($source['label']) ?> <span class="muted">(<?= $escape((string) count(array_filter($source['rows'], static fn (array $row): bool => $row['kind'] === 'position'))) ?> Positionen)</span></summary>
                    <ul class="mobile-service-list">
                        <?php foreach ($source['rows'] as $row): ?>
                            <?php if ($row['kind'] === 'title'): ?>
                                <li class="mobile-service-title mobile-depth-<?= $escape((string) min(3, $row['depth'])) ?>"><span class="mobile-service-number"><?= $escape($row['number']) ?></span><strong><?= $escape($row['text']) ?></strong></li>
                            <?php else: ?>
                                <li class="mobile-service-position mobile-depth-<?= $escape((string) min(3, $row['depth'])) ?>">
                                    <span class="mobile-service-number"><?= $escape($row['number']) ?></span>
                                    <span class="mobile-service-text"><?= $escape($row['text']) ?><?php if (isset($typeLabels[$row['type']])): ?> <span class="status-badge"><?= $escape($typeLabels[$row['type']]) ?></span><?php endif; ?></span>
                                    <span class="mobile-service-quantity"><?= $escape($quantity($row['quantity'])) ?> <?= $escape($row['unit']) ?></span>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <?php foreach ($relatedSections as $section): if ($section['items'] === null) continue; ?>
    <section class="mobile-card" aria-labelledby="mobile-<?= $escape($section['key']) ?>-heading">
        <div class="mobile-card-heading"><h2 id="mobile-<?= $escape($section['key']) ?>-heading"><?= $escape($section['title']) ?></h2><span class="status-badge"><?= $escape((string) $section['total']) ?></span></div>
        <?php if ($section['items'] === []): ?>
            <p class="muted mobile-card-text"><?= $escape($section['empty']) ?></p>
        <?php else: ?>
            <ul class="mobile-list">
                <?php foreach ($section['items'] as $item): ?>
                    <li><a class="mobile-list-item" href="<?= $escape($item['url']) ?>">
                        <span class="mobile-list-main"><strong><?= $escape($item['label']) ?></strong><small class="muted"><?= $escape(implode(' · ', array_filter([$item['date'] !== '' ? $mobileDate($item['date']) : '', $item['detail']]))) ?></small></span>
                        <span class="status-badge"><?= $escape($item['status']) ?></span>
                    </a></li>
                <?php endforeach; ?>
            </ul>
            <?php if ($section['more'] !== null && $section['total'] > count($section['items'])): ?><a class="mobile-card-link" href="<?= $escape($section['more']) ?>">Alle <?= $escape((string) $section['total']) ?> anzeigen</a><?php endif; ?>
        <?php endif; ?>
    </section>
    <?php endforeach; ?>

    <?php if ($detail['documents'] !== null): ?>
    <section class="mobile-card" aria-labelledby="mobile-documents-heading">
        <div class="mobile-card-heading"><h2 id="mobile-documents-heading">Unterlagen</h2><span class="status-badge"><?= $escape((string) count($detail['documents'])) ?></span></div>
        <?php if ($detail['documents'] === []): ?>
            <p class="muted mobile-card-text">Keine Unterlagen zu diesem Auftrag.</p>
        <?php else: ?>
            <ul class="mobile-list">
                <?php foreach ($detail['documents'] as $document): ?>
                    <li><a class="mobile-list-item" href="<?= $escape($document['url']) ?>">
                        <span class="mobile-list-main"><strong><?= $escape($document['label']) ?></strong><small class="muted"><?= $escape(implode(' · ', array_filter([$document['date'] !== '' ? $mobileDate($document['date']) : '', $document['detail']]))) ?></small></span>
                        <span class="ui-icon icon-download" aria-hidden="true"></span>
                    </a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>
