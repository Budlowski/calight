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
        $period = self::date((string) $report['workDate']) . ($report['workDateTo'] ? ' – ' . self::date((string) $report['workDateTo']) : '');
        $pdf->begin(
            'Rapport – Leistungsnachweis',
            $report['reportNumber'] ? 'Nr. ' . $report['reportNumber'] : 'Entwurf, noch nicht eingereicht',
            [(string) $report['customerName'], ...array_map('trim', explode(',', (string) ($report['customerAddress'] ?? '')))],
            [
                ['Rapportnummer', (string) ($report['reportNumber'] ?? '')],
                ['Leistungsdatum', $period],
                ['Kundennummer', (string) ($report['customerNumber'] ?? '')],
                ['Auftrag', $report['orderId'] ? trim($report['orderNumber'] . ' · ' . $report['orderTitle']) : ''],
                ['Projekt', $report['projectNumber'] ? trim($report['projectNumber'] . ' · ' . $report['projectName']) : ''],
                ['Ansprechpartner', (string) ($report['contactPerson'] ?? '')],
            ],
        );

        $pdf->section('Ausgeführte Arbeiten');
        $pdf->paragraph(trim((string) $report['description']) !== '' ? (string) $report['description'] : '–');

        if ($report['labor'] !== []) {
            $pdf->section('Arbeitsstunden');
            $total = BigDecimal::zero();
            $rows = [];
            foreach ($report['labor'] as $row) {
                $total = $total->plus((string) $row['hours']);
                $rows[] = ['cells' => [(string) $row['workerName'], (string) ($row['laborItemName'] ?? ''), (string) ($row['activity'] ?? ''), $numbers->quantity((string) $row['hours']) . ' h']];
            }
            $rows[] = ['cells' => ['Summe', '', '', $numbers->quantity((string) $total) . ' h'], 'bold' => true];
            $pdf->table([
                ['label' => 'Mitarbeiter', 'width' => 150, 'wrap' => 32],
                ['label' => 'Tätigkeitsart', 'width' => 110, 'wrap' => 24],
                ['label' => 'Tätigkeit', 'width' => 175, 'wrap' => 38],
                ['label' => 'Stunden', 'width' => 70, 'align' => 'right'],
            ], $rows);
        }

        if ($report['materials'] !== []) {
            $pdf->section('Material und sonstige Leistungen');
            $pdf->table([
                ['label' => 'Menge', 'width' => 70, 'align' => 'right'],
                ['label' => 'Einheit', 'width' => 60],
                ['label' => 'Bezeichnung', 'width' => 375, 'wrap' => 85],
            ], array_map(static fn (array $row): array => ['cells' => [$numbers->quantity((string) $row['quantity']), (string) $row['unit'], (string) $row['description']]], $report['materials']));
        }

        if (trim((string) $report['notes']) !== '') {
            $pdf->section('Bemerkungen');
            $pdf->paragraph((string) $report['notes']);
        }

        $statement = [
            'Mit der Unterschrift wird bestätigt, dass die oben beschriebenen Arbeiten am angegebenen Leistungsdatum ausgeführt'
            . ' und die aufgeführten Stunden sowie Materialien und sonstigen Leistungen erbracht wurden.',
            'Die Bestätigung bezieht sich ausschließlich auf diesen Leistungsnachweis' . ($report['reportNumber'] ? ' Nr. ' . $report['reportNumber'] : '')
            . '. Preise und Abrechnungsbeträge sind nicht Gegenstand dieser Bestätigung.',
        ];
        $confirmed = $report['confirmationStatus'] === 'confirmed';
        $note = '';
        if ($confirmed) {
            $note = (self::KIND_NOTES[(string) $report['confirmationKind']] ?? 'Bestätigt') . ' am ' . self::dateTime((string) $report['confirmedAt'])
                . ' durch ' . $report['confirmedByName'] . '.';
        }
        if ($report['submittedSha256']) {
            $note .= ($note !== '' ? ' ' : '') . 'Prüfsumme des eingereichten Leistungsnachweises (SHA-256): ' . $report['submittedSha256'];
        }
        $pdf->confirmationBlock($statement, [
            'signature' => $signature,
            'name' => $confirmed ? (string) $report['confirmedByName'] : '',
            'date' => $confirmed ? self::dateTime((string) $report['confirmedAt']) : '',
            'note' => $note,
        ]);
        return $pdf->output();
    }

    private static function date(string $value): string
    {
        return $value !== '' ? (new DateTimeImmutable($value))->format('d.m.Y') : '';
    }

    private static function dateTime(string $value): string
    {
        return $value !== '' ? (new DateTimeImmutable($value))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('d.m.Y H:i') . ' Uhr' : '';
    }
}
