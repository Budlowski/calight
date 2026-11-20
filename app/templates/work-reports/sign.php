<?php
$title = 'Leistungsnachweis bestätigen';
// Exakte Anzeige der Dezimalstrings (z. B. "3.500" → "3,5"), ohne Gleitkommazahlen.
$hours = static fn (string $value): string => str_replace('.', ',', str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value);
$date = static fn (?string $value): string => $value ? (new DateTimeImmutable($value))->format('d.m.Y') : '';
?>
<div id="work-report-sign" class="sign-page" data-id="<?= $escape($report['id']) ?>" data-csrf="<?= $escape($csrfToken) ?>"
     data-version="<?= $escape((string) $report['version']) ?>" data-hash="<?= $escape((string) $report['submittedSha256']) ?>">
    <header class="sign-header">
        <span class="sign-company"><?= $escape($companyName !== '' ? $companyName : 'Leistungsnachweis') ?></span>
        <h1>Leistungsnachweis <?= $escape((string) $report['reportNumber']) ?></h1>
        <p class="muted"><?= $escape((string) $report['customerName']) ?> · <?= $escape($date($report['workDate'])) ?><?= $report['workDateTo'] ? ' – ' . $escape($date($report['workDateTo'])) : '' ?></p>
        <?php if (trim((string) ($report['workLocation'] ?? '')) !== ''): ?>
            <p class="sign-location"><span>Einsatzort / Objekt:</span> <strong><?= $escape((string) $report['workLocation']) ?></strong></p>
        <?php endif; ?>
    </header>

    <section class="sign-section">
        <h2>Ausgeführte Arbeiten</h2>
        <p class="sign-text"><?= nl2br($escape((string) $report['description'])) ?></p>
    </section>

    <?php if ($report['labor'] !== []): ?>
    <section class="sign-section">
        <h2>Arbeitsstunden</h2>
        <ul class="sign-list">
            <?php // Stabil nach Leistungsdatum; innerhalb eines Tages in erfasster Reihenfolge.
            $laborRows = $report['labor']; usort($laborRows, static fn (array $a, array $b): int => strcmp((string) ($a['workDate'] ?? ''), (string) ($b['workDate'] ?? ''))); ?>
            <?php foreach ($laborRows as $row): ?>
                <li><span><?php if (($row['workDate'] ?? null) !== null): ?><small><?= $escape($date($row['workDate'])) ?></small><?php endif; ?><?= $escape($row['workerName']) ?><?= $row['activity'] !== '' ? '<small>' . $escape($row['activity']) . '</small>' : '' ?></span><strong><?= $escape($hours((string) $row['hours'])) ?> h</strong></li>
            <?php endforeach; ?>
            <li class="sign-total"><span>Summe</span><strong><?= $escape($hours((string) $report['totalHours'])) ?> h</strong></li>
        </ul>
    </section>
    <?php endif; ?>

    <?php if ($report['materials'] !== []): ?>
    <section class="sign-section">
        <h2>Material und sonstige Leistungen</h2>
        <ul class="sign-list">
            <?php foreach ($report['materials'] as $row): ?>
                <li><span><?= $escape($row['description']) ?></span><strong><?= $escape($hours((string) $row['quantity'])) ?> <?= $escape($row['unit']) ?></strong></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if (trim((string) $report['notes']) !== ''): ?>
    <section class="sign-section">
        <h2>Bemerkungen</h2>
        <p class="sign-text"><?= nl2br($escape((string) $report['notes'])) ?></p>
    </section>
    <?php endif; ?>

    <form class="sign-section sign-form" data-sign-form novalidate>
        <h2>Bestätigung</h2>
        <p class="sign-statement"><?= $escape(implode(' ', \App\Application\WorkReportPdfService::confirmationStatement($report['reportNumber'] ? (string) $report['reportNumber'] : null))) ?></p>
        <label><span class="field-label">Name der unterschreibenden Person <span aria-hidden="true">*</span></span>
            <input name="signerName" maxlength="200" required autocomplete="name" value="<?= $escape((string) ($report['contactPerson'] ?? '')) ?>"></label>
        <div class="field-label">Unterschrift <span aria-hidden="true">*</span></div>
        <div class="signature-pad">
            <span class="signature-baseline" aria-hidden="true"></span>
            <canvas data-signature-canvas aria-label="Unterschriftenfeld: mit Finger, Stift oder Maus unterschreiben" role="img"></canvas>
            <span class="signature-hint" data-signature-hint>Hier unterschreiben</span>
        </div>
        <p class="field-error" data-error hidden></p>
        <div class="sign-actions">
            <button class="button button-light" type="button" data-clear data-icon="refresh-cw">Unterschrift löschen</button>
            <button class="button" type="submit" data-submit data-icon="clipboard-check">Bestätigen und unterschreiben</button>
        </div>
        <a class="sign-cancel" href="/work-reports/<?= $escape(rawurlencode((string) $report['id'])) ?>">Abbrechen</a>
    </form>
</div>
<script src="/assets/work-report-sign.js?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/work-report-sign.js')) ?>" defer></script>
