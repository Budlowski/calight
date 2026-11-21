<?php

declare(strict_types=1);

namespace App\Application;

use App\Infrastructure\Pdf\PdfNumberFormatter;
use DateTimeImmutable;

/**
 * PDF-Stundennachweis (Woche oder Monat) auf dem gemeinsamen Briefbogen. Wird ausschließlich
 * aus dem gespeicherten Datenstand (snapshot_json) erzeugt und bei der Festschreibung einmalig
 * archiviert. Enthält keine Kostensätze.
 */
final readonly class TimeStatementPdf
{
    /** @param array<string,mixed> $documentSettings */
    public function __construct(private array $documentSettings)
    {
    }

    /** @param array<string,mixed> $snapshot */
    public function render(array $snapshot): string
    {
        $numbers = new PdfNumberFormatter();
        $period = $snapshot['period'];
        $employee = $snapshot['employee'];
        $pdf = new LetterheadPdf($this->documentSettings);
        $last = $snapshot['submissions'] !== [] ? $snapshot['submissions'][count($snapshot['submissions']) - 1] : [];
        $pdf->begin(
            'Stundennachweis',
            ($period['type'] === 'week' ? 'Woche ' : 'Monat ') . $period['label'] . ' · Revision ' . $snapshot['revision'],
            array_values(array_filter([(string) $employee['name'], $employee['personnelNumber'] ? 'Personalnummer ' . $employee['personnelNumber'] : ''])),
            [
                ['Zeitraum', self::date($period['from']) . ' – ' . self::date($period['to'])],
                ['Revision', (string) $snapshot['revision']],
                ['Eingereicht', isset($last['submittedAt']) ? self::dateTime((string) $last['submittedAt']) . ' · ' . $last['submittedBy'] : ''],
                ['Festgeschrieben', self::dateTime((string) $snapshot['createdAt']) . ($snapshot['createdBy'] !== '' ? ' · ' . $snapshot['createdBy'] : '')],
            ],
        );

        $rows = [];
        foreach ($snapshot['days'] as $day) {
            $label = self::weekday($day['date']) . ' ' . self::date($day['date']);
            if ($day['entries'] === []) {
                $rows[] = ['cells' => [$label, '–', '', $day['netMinutes'] > 0 ? self::hours($day['netMinutes']) : '', '', $day['holiday'] ?? ($day['netMinutes'] > 0 ? 'Fortsetzung vom Vortag' : 'Keine Arbeitszeit'), ''], 'muted' => true];
                continue;
            }
            foreach ($day['entries'] as $index => $entry) {
                $rows[] = ['cells' => [
                    $index === 0 ? $label : '',
                    $entry['start'] !== null ? $entry['start'] . '–' . $entry['end'] . ($entry['endsNextDay'] ? ' (+1)' : '') : 'ohne Uhrzeit',
                    $entry['breakMinutes'] > 0 ? $entry['breakMinutes'] . ' min' : '',
                    self::hours($entry['netMinutes']),
                    $entry['travelMinutes'] > 0 ? self::hours($entry['travelMinutes']) : '',
                    trim($entry['source'] . ' · ' . $entry['reference'] . ($entry['notes'] !== '' ? ' · ' . $entry['notes'] : ''), ' ·'),
                    (string) ($entry['target'] ?? '–'),
                ]];
            }
            $sum = array_sum(array_column($day['entries'], 'netMinutes'));
            if (count($day['entries']) > 1 || $sum !== $day['netMinutes']) {
                $rows[] = ['cells' => ['', 'Tag', '', self::hours($day['netMinutes']), $day['travelMinutes'] > 0 ? self::hours($day['travelMinutes']) : '', $sum !== $day['netMinutes'] ? 'anteilig nach Kalendertag' : '', ''], 'bold' => true];
            }
        }
        $pdf->section('Arbeitszeiten');
        $pdf->table([
            ['label' => 'Datum', 'width' => 62],
            ['label' => 'Zeit', 'width' => 68],
            ['label' => 'Pause', 'width' => 36],
            ['label' => 'Netto', 'width' => 40, 'align' => 'right'],
            ['label' => 'Reise', 'width' => 40, 'align' => 'right'],
            ['label' => 'Herkunft / Tätigkeit', 'width' => 150, 'wrap' => 34],
            ['label' => 'Auftrag / Kostenstelle', 'width' => 109],
        ], $rows);

        $totals = $snapshot['totals'];
        $pdf->section('Summen');
        $pdf->facts(array_values(array_filter([
            ['Arbeitszeit gesamt', self::hours((int) $totals['netMinutes'])],
            ['Davon Reisezeit', self::hours((int) $totals['travelMinutes'])],
            ['Übrige Arbeitszeit', self::hours((int) $totals['workMinutes'])],
            (int) $totals['untimedMinutes'] > 0 ? ['Davon ohne Uhrzeit', self::hours((int) $totals['untimedMinutes'])] : null,
            ($totals['mealAllowance'] ?? null) !== null && $totals['mealAllowance'] !== '0.00' ? ['Verpflegung (rechnerisch)', $numbers->money((string) $totals['mealAllowance'])] : null,
            ($totals['privateKm'] ?? '0.0') !== '0.0' ? ['Privat-Pkw', $numbers->quantity((string) $totals['privateKm']) . ' km' . (isset($totals['kmAmount']) ? ' · ' . $numbers->money((string) $totals['kmAmount']) . ' (rechnerisch)' : '')] : null,
            ['Auslagen', $numbers->money((string) $totals['expenseTotal'])],
        ])));

        $travel = [];
        foreach ($snapshot['days'] as $day) {
            $details = $day['details'];
            if ($details === null && $day['expenses'] === []) {
                continue;
            }
            $expenses = array_map(static fn (array $expense): string => $expense['purpose'] . ' ' . $numbers->money((string) $expense['amount']) . ($expense['target'] ? ' (' . $expense['target'] . ')' : '') . ($expense['hasReceipt'] ? ' · Beleg' : ''), $day['expenses']);
            $travel[] = ['cells' => [
                self::date($day['date']),
                (string) ($details['summary'] ?? ''),
                isset($details['allowance']) && $details['allowance'] !== null ? $numbers->money((string) $details['allowance']) : '',
                isset($details['privateKm']) && $details['privateKm'] !== null ? $numbers->quantity((string) $details['privateKm']) . ' km' : '',
                implode('; ', $expenses),
            ]];
        }
        if ($travel !== []) {
            $pdf->section('Reisekosten und Auslagen');
            $pdf->table([
                ['label' => 'Datum', 'width' => 55],
                ['label' => 'Auswärtstätigkeit / Fahrten', 'width' => 190, 'wrap' => 44],
                ['label' => 'Verpflegung', 'width' => 60, 'align' => 'right'],
                ['label' => 'Privat-Pkw', 'width' => 55, 'align' => 'right'],
                ['label' => 'Auslagen', 'width' => 145],
            ], $travel);
        }

        if ($snapshot['warnings'] !== []) {
            $pdf->section('Bestätigte Prüfhinweise');
            foreach ($snapshot['warnings'] as $warning) {
                $pdf->paragraph('• ' . $warning['message']);
            }
        }
        if ($snapshot['submissions'] !== []) {
            $pdf->section('Einreichung und Festschreibung');
            foreach ($snapshot['submissions'] as $submission) {
                $pdf->paragraph(sprintf('%s: eingereicht %s von %s, festgeschrieben %s von %s%s%s', $submission['periodLabel'], self::dateTime((string) $submission['submittedAt']), $submission['submittedBy'],
                    $submission['approvedAt'] ? self::dateTime((string) $submission['approvedAt']) : '–', $submission['approvedBy'] ?? '–',
                    $submission['warningsAcknowledged'] ? ', Hinweise bestätigt' : '', ($submission['comment'] ?? '') !== '' ? ' – ' . $submission['comment'] : ''));
            }
        }
        $pdf->paragraph('Dieser Nachweis dokumentiert die erfassten Arbeitszeiten, Reiseangaben und Auslagen. Er ist keine Lohn-, Steuer- oder Reisekostenabrechnung; '
            . 'Beträge sind rechnerische Referenzwerte nach den betrieblichen Einstellungen. Prüfhinweise ersetzen keine arbeitsrechtliche Prüfung.', null, false, '.35 .4 .45');
        return $pdf->output();
    }

    private static function hours(int $minutes): string
    {
        return sprintf('%d:%02d h', intdiv($minutes, 60), $minutes % 60);
    }

    private static function date(string $date): string
    {
        return (new DateTimeImmutable($date))->format('d.m.Y');
    }

    private static function weekday(string $date): string
    {
        return ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'][(int) (new DateTimeImmutable($date))->format('N') - 1];
    }

    private static function dateTime(string $value): string
    {
        return (new DateTimeImmutable($value))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i');
    }
}
