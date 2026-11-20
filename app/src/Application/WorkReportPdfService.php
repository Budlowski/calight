<?php

declare(strict_types=1);

namespace App\Application;

use App\Infrastructure\Pdf\PdfNumberFormatter;
use App\Infrastructure\Persistence\DocumentSettingsRepository;
use Brick\Math\BigDecimal;
use DateTimeImmutable;

/**
 * Kundenfähiges PDF des eingereichten Leistungsnachweises eines Rapports.
 *
 * Enthält ausschließlich den Leistungsnachweis (Arbeiten, Stunden, Material,
 * Bemerkungen) und die Kundenbestätigung – niemals kaufmännische Positionen,
 * Verkaufspreise oder interne Kosten. Eingereichte Stände werden archiviert:
 * Für denselben Nachweis- und Bestätigungsstand wird stets dieselbe Datei
 * ausgeliefert. Entwürfe werden nur als Vorschau mit Wasserzeichen erzeugt.
 */
final readonly class WorkReportPdfService
{
    private const KIND_NOTES = [
        'signature' => 'Digital vor Ort unterschrieben',
        'returned_document' => 'Unterschriebenes Dokument zurückerhalten',
        'manual' => 'Bestätigung manuell dokumentiert (ohne gespeicherten Unterschriftsnachweis)',
    ];

    public function __construct(
        private WorkReportService $reports,
        private DocumentSettingsRepository $settings,
        private DocumentArchive $archive,
    ) {
    }

    /**
     * @param array<string,mixed> $actor
     * @return array{pdf:string,fileName:string}
     */
    public function pdf(string $id, array $actor): array
    {
        $report = $this->reports->detail($id, $actor);
        if ($report['status'] === 'draft') {
            return ['pdf' => $this->render($report, null, 'ENTWURF'), 'fileName' => 'Rapport_Entwurf.pdf'];
        }
        $signature = $report['confirmationKind'] === 'signature' ? $this->reports->confirmationEvidence($id, $actor)['contents'] : null;
        // Ausgegebener Stand = eingereichter Nachweis + Bestätigungsstand.
        $state = hash('sha256', json_encode([
            $report['submittedSha256'], $report['confirmationStatus'], $report['confirmationKind'],
            $report['confirmedByName'], $report['confirmedAt'], $signature !== null ? hash('sha256', $signature) : null,
        ], JSON_THROW_ON_ERROR));
        $fileName = 'Rapport_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $report['reportNumber']) . ($report['confirmationStatus'] === 'confirmed' ? '_bestaetigt' : '') . '.pdf';
        $document = $this->archive->fetchOrCreate('work_report', $id, $state, $fileName, fn (): string => $this->render($report, $signature, null), (string) $actor['id']);
        return ['pdf' => $document['pdf'], 'fileName' => $document['fileName']];
    }

    /** @param array<string,mixed> $report */
    public function render(array $report, ?string $signature, ?string $watermark): string
    {
        $numbers = new PdfNumberFormatter();
        $pdf = new LetterheadPdf($this->settings->get(), $watermark);
        // Kurze Einsatzorte stehen im Kopfblock; längere vollständig über den ausgeführten Arbeiten.
        $location = trim((string) ($report['workLocation'] ?? ''));
        $longLocation = mb_strlen($location) > 34;
        // Historische Rapporte (Positionen ohne eigenes Datum) behalten ihr Leistungsdatum im Kopf;
        // neue Rapporte zeigen das Einreichungsdatum und das Leistungsdatum je Stundenposition.
        $dated = $report['labor'] !== [] && array_filter($report['labor'], static fn (array $row): bool => ($row['workDate'] ?? null) === null) === [];
        $period = $dated ? '' : self::date((string) $report['workDate']) . ($report['workDateTo'] ? ' – ' . self::date((string) $report['workDateTo']) : '');
        $pdf->begin(
            'Rapport – Leistungsnachweis',
            $report['reportNumber'] ? 'Nr. ' . $report['reportNumber'] : 'Entwurf, noch nicht eingereicht',
            [(string) $report['customerName'], ...array_map('trim', explode(',', (string) ($report['customerAddress'] ?? '')))],
            [
                ['Rapportnummer', (string) ($report['reportNumber'] ?? '')],
                ['Eingereicht am', $report['submittedAt'] ? self::localDate((string) $report['submittedAt']) : ''],
                ['Leistungsdatum', $period],
                ['Kundennummer', (string) ($report['customerNumber'] ?? '')],
                ['Auftrag', $report['orderId'] ? trim($report['orderNumber'] . ' · ' . $report['orderTitle']) : ''],
                ['Projekt', $report['projectNumber'] ? trim($report['projectNumber'] . ' · ' . $report['projectName']) : ''],
                ['Einsatzort / Objekt', $longLocation ? '' : $location],
                ['Ansprechpartner', (string) ($report['contactPerson'] ?? '')],
            ],
        );

        if ($longLocation) {
            $pdf->section('Einsatzort / Objekt');
            $pdf->paragraph($location);
        }
        $pdf->section('Ausgeführte Arbeiten');
        $pdf->paragraph(trim((string) $report['description']) !== '' ? (string) $report['description'] : '–');

        if ($report['labor'] !== []) {
            $pdf->section('Arbeitsstunden');
            $total = BigDecimal::zero();
            $rows = [];
            // Die Spalte „Tätigkeit“ erscheint nur, wenn mindestens eine Zeile eine Tätigkeit enthält.
            $withActivity = array_filter($report['labor'], static fn (array $row): bool => trim((string) ($row['activity'] ?? '')) !== '') !== [];
            $text = static fn (mixed $value): string => trim((string) ($value ?? '')) !== '' ? (string) $value : '–';
            // Nach Leistungsdatum ordnen; innerhalb eines Tages bleibt die erfasste Reihenfolge (stabile Sortierung).
            $labor = $report['labor'];
            if ($dated) {
                usort($labor, static fn (array $a, array $b): int => strcmp((string) $a['workDate'], (string) $b['workDate']));
            }
            foreach ($labor as $row) {
                $total = $total->plus((string) $row['hours']);
                $cells = $dated ? [self::date((string) $row['workDate'])] : [];
                $cells = [...$cells, (string) $row['workerName'], $text($row['laborItemName'] ?? '')];
                if ($withActivity) {
                    $cells[] = $text($row['activity'] ?? '');
                }
                $cells[] = $numbers->quantity((string) $row['hours']) . ' h';
                $rows[] = ['cells' => $cells];
            }
            $columns = $withActivity ? [
                ['label' => 'Mitarbeiter', 'width' => 150, 'wrap' => 32],
                ['label' => 'Tätigkeitsart', 'width' => 110, 'wrap' => 24],
                ['label' => 'Tätigkeit', 'width' => 175, 'wrap' => 38],
                ['label' => 'Stunden', 'width' => 70, 'align' => 'right'],
            ] : [
                ['label' => 'Mitarbeiter', 'width' => 220, 'wrap' => 48],
                ['label' => 'Tätigkeitsart', 'width' => 215, 'wrap' => 47],
                ['label' => 'Stunden', 'width' => 70, 'align' => 'right'],
            ];
            if ($dated) {
                // Datumsspalte vorn; die Breite wird der Mitarbeiterspalte entnommen (Gesamtbreite unverändert).
                $columns[0]['width'] -= 65;
                $columns[0]['wrap'] = (int) round($columns[0]['wrap'] * $columns[0]['width'] / ($columns[0]['width'] + 65));
                array_unshift($columns, ['label' => 'Datum', 'width' => 65]);
            }
            $sum = array_fill(0, count($columns), '');
            $sum[0] = 'Summe';
            $sum[count($columns) - 1] = $numbers->quantity((string) $total) . ' h';
            $rows[] = ['cells' => $sum, 'bold' => true];
            $pdf->table($columns, $rows);
        }

        if ($report['materials'] !== []) {
            $pdf->section('Material und sonstige Leistungen');
            $pdf->table([
                ['label' => 'Menge', 'width' => 70, 'align' => 'right', 'pad' => 14],
                ['label' => 'Einheit', 'width' => 60],
                ['label' => 'Bezeichnung', 'width' => 375, 'wrap' => 85],
            ], array_map(static fn (array $row): array => ['cells' => [$numbers->quantity((string) $row['quantity']), (string) $row['unit'], (string) $row['description']]], $report['materials']));
        }

        if (trim((string) $report['notes']) !== '') {
            $pdf->section('Bemerkungen');
            $pdf->paragraph((string) $report['notes']);
        }

        $statement = self::confirmationStatement($report['reportNumber'] ? (string) $report['reportNumber'] : null);
        $confirmed = $report['confirmationStatus'] === 'confirmed';
        $note = '';
        if ($confirmed) {
            $note = (self::KIND_NOTES[(string) $report['confirmationKind']] ?? 'Bestätigt') . ' am ' . self::dateTime((string) $report['confirmedAt'])
                . ' durch ' . $report['confirmedByName'] . '.';
        }
        $pdf->confirmationBlock($statement, [
            'checksum' => (string) ($report['submittedSha256'] ?? ''),
            'signature' => $signature,
            'name' => $confirmed ? (string) $report['confirmedByName'] : '',
            'date' => $confirmed ? self::dateTime((string) $report['confirmedAt']) : '',
            'note' => $note,
        ]);
        return $pdf->output();
    }

    /**
     * Bestätigungstext – identisch auf der Unterschriftsseite und im PDF.
     *
     * @return list<string>
     */
    public static function confirmationStatement(?string $reportNumber): array
    {
        return [
            'Mit der Unterschrift wird bestätigt, dass die in diesem Leistungsnachweis beschriebenen Arbeiten ausgeführt'
            . ' und die aufgeführten Arbeitsstunden sowie Materialien und sonstigen Leistungen erbracht wurden.',
            'Die Bestätigung bezieht sich ausschließlich auf den eingereichten Leistungsnachweis' . ($reportNumber !== null ? ' Nr. ' . $reportNumber : '')
            . '. Verkaufspreise und Abrechnungsbeträge sind nicht Gegenstand dieser Bestätigung.',
        ];
    }

    private static function date(string $value): string
    {
        return $value !== '' ? (new DateTimeImmutable($value))->format('d.m.Y') : '';
    }

    /** Datum eines UTC-Zeitpunkts in lokaler Zeitzone. */
    private static function localDate(string $value): string
    {
        return (new DateTimeImmutable($value))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('d.m.Y');
    }

    private static function dateTime(string $value): string
    {
        return $value !== '' ? (new DateTimeImmutable($value))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('d.m.Y H:i') . ' Uhr' : '';
    }
}
