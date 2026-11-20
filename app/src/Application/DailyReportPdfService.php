<?php

declare(strict_types=1);

namespace App\Application;

use App\Infrastructure\Pdf\PdfNumberFormatter;
use App\Infrastructure\Persistence\DocumentSettingsRepository;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Throwable;

/**
 * PDF eines Bautagesberichts zur internen Dokumentation.
 *
 * Enthält Tätigkeiten, Arbeitszeiten (ohne Kostensätze und Lohnkosten),
 * Bemerkungen und JPEG-Fotos in verkleinerter Darstellung; die Originaldateien
 * bleiben unverändert. Materialanforderungen sind interne Vorgänge und nicht
 * Bestandteil des Dokuments. Bautagesberichte sind veränderlich und werden
 * daher bei jedem Abruf neu erzeugt, nicht archiviert.
 */
final readonly class DailyReportPdfService
{
    public function __construct(private DailyReportService $reports, private DocumentSettingsRepository $settings)
    {
    }

    /** @param array<string,mixed> $actor @return array{pdf:string,fileName:string} */
    public function pdf(string $id, array $actor): array
    {
        $report = $this->reports->detail($id, $actor);
        $numbers = new PdfNumberFormatter();
        $date = (new DateTimeImmutable((string) $report['report_date']))->format('d.m.Y');
        $pdf = new LetterheadPdf($this->settings->get());
        $pdf->begin(
            'Bautagesbericht',
            trim((string) $report['title']) !== '' ? (string) $report['title'] : 'Interne Dokumentation vom ' . $date,
            [(string) $report['customer_name']],
            // Projekt und Auftrag nur bei Angabe – ohne Auftrag keine leere oder künstliche Auftragsnummer.
            array_values(array_filter([
                ['Berichtsdatum', $date],
                $report['project_id'] !== null ? ['Projekt', trim(($report['project_number'] ?? '') . ' · ' . ($report['project_name'] ?? ''), ' ·')] : null,
                $report['order_id'] !== null ? ['Auftrag', trim($report['order_number'] . ' · ' . $report['order_title'])] : null,
                // Allgemeine Kostenstelle statt Auftrag – nie eine künstliche Auftragsnummer.
                ($report['cost_center_id'] ?? null) !== null ? ['Kostenstelle', trim($report['cost_center_number'] . ' · ' . $report['cost_center_name'])] : null,
                ['Kundennummer', (string) ($report['customer_number'] ?? '')],
                ['Erstellt von', (string) ($report['creator_name'] ?? '')],
            ])),
        );

        $pdf->section('Tätigkeiten');
        $pdf->paragraph(trim((string) $report['activities']) !== '' ? (string) $report['activities'] : '–');

        if ($report['entries'] !== []) {
            $targets = array_column($report['targets'], 'label', 'key');
            $total = BigDecimal::zero();
            $rows = [];
            foreach ($report['entries'] as $entry) {
                $total = $total->plus((string) $entry['duration_hours']);
                $target = $entry['targetKey'] === 'order' ? '' : (string) ($targets[$entry['targetKey']] ?? '');
                $rows[] = ['cells' => [
                    (string) $entry['employee_name'],
                    (new DateTimeImmutable((string) $entry['work_date']))->format('d.m.Y'),
                    trim(implode(' – ', array_filter([(string) ($entry['description'] ?? ''), $target]))),
                    $numbers->quantity((string) $entry['duration_hours']) . ' h',
                ]];
            }
            $rows[] = ['cells' => ['Summe', '', '', $numbers->quantity((string) $total) . ' h'], 'bold' => true];
            $pdf->section('Arbeitszeiten');
            $pdf->table([
                ['label' => 'Mitarbeiter', 'width' => 140, 'wrap' => 30],
                ['label' => 'Datum', 'width' => 65],
                ['label' => 'Tätigkeit / Zuordnung', 'width' => 230, 'wrap' => 50],
                ['label' => 'Stunden', 'width' => 70, 'align' => 'right'],
            ], $rows);
        }

        if (trim((string) $report['notes']) !== '') {
            $pdf->section('Bemerkungen');
            $pdf->paragraph((string) $report['notes']);
        }

        $others = [];
        $photoSection = false;
        foreach ($report['attachments'] as $attachment) {
            $jpeg = $attachment['mimeType'] === 'image/jpeg' ? $this->contents((string) $report['id'], (string) $attachment['id'], $actor) : null;
            if ($jpeg === null) {
                $others[] = (string) $attachment['name'];
                continue;
            }
            if (!$photoSection) {
                $pdf->section('Fotos', 230);
                $photoSection = true;
            }
            if (!$pdf->image($jpeg, 330, 220, (string) $attachment['name'])) {
                $others[] = (string) $attachment['name'];
            }
        }
        if ($others !== []) {
            $pdf->section('Weitere Anhänge');
            $pdf->paragraph(implode("\n", $others) . "\n\nDiese Anhänge sind in Calight beim Bericht hinterlegt und nicht im PDF eingebettet.", null, false, '.35 .4 .45');
        }

        $fileName = 'Bautagesbericht_' . str_replace('-', '', (string) $report['report_date']) . '_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $report['order_number']) . '.pdf';
        return ['pdf' => $pdf->output(), 'fileName' => $fileName];
    }

    /** @param array<string,mixed> $actor */
    private function contents(string $reportId, string $attachmentId, array $actor): ?string
    {
        try {
            $file = $this->reports->attachmentFile($reportId, $attachmentId, $actor);
            $contents = file_get_contents($file['path']);
            return $contents === false ? null : $contents;
        } catch (Throwable) {
            return null;
        }
    }
}
