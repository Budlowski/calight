<?php

declare(strict_types=1);

namespace App\Application;

use App\Infrastructure\Pdf\PdfNumberFormatter;
use App\Infrastructure\Persistence\DocumentSettingsRepository;
use App\Infrastructure\Persistence\MeasurementRepository;
use Brick\Math\BigDecimal;
use DateTimeImmutable;

/**
 * PDF eines Aufmaßes mit Titel-/Positionshierarchie und Mengenfortschritt.
 *
 * Verwendet ausschließlich die vorhandenen Werte aus MeasurementService::detail()
 * (Snapshots, festgeschriebene Summen); es wird nichts neu berechnet.
 * Abgeschlossene Aufmaße werden einmal erzeugt und archiviert; Entwürfe nur als
 * Vorschau mit Wasserzeichen.
 */
final readonly class MeasurementPdfService
{
    public function __construct(
        private MeasurementService $measurements,
        private MeasurementRepository $repository,
        private DocumentSettingsRepository $settings,
        private DocumentArchive $archive,
    ) {
    }

    /** @param array<string,mixed> $actor @return array{pdf:string,fileName:string} */
    public function pdf(string $id, array $actor): array
    {
        $measurement = $this->measurements->detail($id, $actor);
        if ($measurement['status'] !== 'completed') {
            return ['pdf' => $this->render($measurement, 'ENTWURF'), 'fileName' => 'Aufmass_Entwurf.pdf'];
        }
        // Abgeschlossene Aufmaße sind unveränderlich: ein Stand je Aufmaß.
        $state = hash('sha256', $measurement['id'] . '|' . $measurement['measurementNumber'] . '|' . $measurement['completedAt'] . '|' . $measurement['netTotal']);
        $fileName = 'Aufmass_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $measurement['measurementNumber']) . '.pdf';
        $document = $this->archive->fetchOrCreate('measurement', $id, $state, $fileName, fn (): string => $this->render($measurement, null), (string) $actor['id']);
        return ['pdf' => $document['pdf'], 'fileName' => $document['fileName']];
    }

    /** @param array<string,mixed> $measurement */
    public function render(array $measurement, ?string $watermark): string
    {
        $numbers = new PdfNumberFormatter();
        $context = $this->repository->documentContext((string) $measurement['id']);
        $pdf = new LetterheadPdf($this->settings->get(), $watermark);
        $pdf->begin(
            $measurement['correctionOfId'] ? 'Korrekturaufmaß' : 'Aufmaß',
            (string) $measurement['title'],
            [
                (string) $measurement['customerName'],
                trim(($context['street'] ?? '') . ' ' . ($context['house_number'] ?? '')),
                trim(($context['postal_code'] ?? '') . ' ' . ($context['city'] ?? '')),
            ],
            [
                ['Aufmaßnummer', (string) ($measurement['measurementNumber'] ?? 'Entwurf')],
                ['Aufmaßdatum', (new DateTimeImmutable((string) $measurement['measurementDate']))->format('d.m.Y')],
                ['Kundennummer', (string) ($context['customer_number'] ?? '')],
                ['Auftrag', trim($measurement['orderNumber'] . ' · ' . $measurement['orderTitle'])],
                ['Projekt', ($context['project_number'] ?? null) ? trim($context['project_number'] . ' · ' . $context['project_name']) : ''],
                ['Korrektur zu', (string) ($measurement['correctedNumber'] ?? '')],
            ],
        );
        $pdf->paragraph('Aufgeführt sind die in diesem Aufmaß erfassten Mengen. „Bisher“ umfasst die vor diesem Aufmaß abgeschlossenen Aufmaße, „Gesamt“ den Stand einschließlich dieses Aufmaßes.', null, false, '.35 .4 .45');
        if (trim((string) ($measurement['notes'] ?? '')) !== '') {
            $pdf->paragraph((string) $measurement['notes']);
        }

        $columns = [
            ['label' => 'Pos.', 'width' => 45],
            ['label' => 'Leistung', 'width' => 185, 'wrap' => 40],
            ['label' => 'Einheit', 'width' => 40],
            ['label' => 'Vereinbart', 'width' => 60, 'align' => 'right'],
            ['label' => 'Bisher', 'width' => 55, 'align' => 'right'],
            ['label' => 'Dieses Aufmaß', 'width' => 60, 'align' => 'right'],
            ['label' => 'Gesamt', 'width' => 60, 'align' => 'right'],
        ];
        foreach ($measurement['sources'] as $source) {
            $rows = $this->rows($source['nodes'], 0, $numbers);
            if ($rows === []) {
                continue;
            }
            $pdf->section((string) $source['label']);
            $pdf->table($columns, $rows);
        }

        if ($measurement['totals'] !== []) {
            $pdf->section('Summen', 60);
            $rows = array_map(static fn (array $total): array => ['cells' => [
                (string) $total['label'], $numbers->money((string) $total['itemsTotal']),
                !BigDecimal::of((string) $total['adjustmentAmount'])->isZero() ? $numbers->money((string) $total['adjustmentAmount']) : '–', $numbers->money((string) $total['netTotal']),
            ]], $measurement['totals']);
            if ($measurement['netTotal'] !== null) {
                $rows[] = ['cells' => ['Gesamt netto', '', '', $numbers->money((string) $measurement['netTotal'])], 'bold' => true];
            }
            $pdf->table([
                ['label' => 'Grundlage', 'width' => 235, 'wrap' => 50],
                ['label' => 'Positionen', 'width' => 90, 'align' => 'right'],
                ['label' => 'Nachlass/Aufschlag', 'width' => 90, 'align' => 'right'],
                ['label' => 'Netto', 'width' => 90, 'align' => 'right'],
            ], $rows);
            $pdf->paragraph('Bewertung zu den verbindlichen Einzelpreisen der Auftragsgrundlage bzw. der angenommenen Nachträge; ein Nachlass bzw. Aufschlag des jeweiligen Angebots wird anteilig auf Normalpositionen angewendet.', null, false, '.35 .4 .45');
        }
        return $pdf->output();
    }

    /**
     * Nur Positionen mit Menge in diesem Aufmaß; Titel nur, wenn sie solche Positionen enthalten.
     *
     * @param list<array<string,mixed>> $nodes
     * @return list<array{cells:list<string>,bold?:bool,muted?:bool,indent?:int}>
     */
    private function rows(array $nodes, int $depth, PdfNumberFormatter $numbers): array
    {
        $rows = [];
        foreach ($nodes as $node) {
            if ($node['kind'] === 'title') {
                $children = $this->rows($node['children'], $depth + 1, $numbers);
                if ($children !== []) {
                    $rows[] = ['cells' => [(string) $node['number'], (string) $node['name'], '', '', '', '', ''], 'bold' => true, 'indent' => $depth];
                    array_push($rows, ...$children);
                }
                continue;
            }
            if (($node['quantity'] ?? '') === '') {
                continue;
            }
            $text = (string) $node['shortText'];
            if ($node['effectiveType'] !== 'normal') {
                $text = '[' . ($node['effectiveType'] === 'bedarf' ? 'Bedarf' : 'Alternative') . '] ' . $text;
            }
            if (str_starts_with((string) $node['openQuantity'], '-')) {
                $text .= "\nMehrmenge " . $numbers->quantity(ltrim((string) $node['openQuantity'], '-')) . ' ' . $node['unit'] . ($node['overrunConfirmed'] ? ' (bestätigt)' : '');
            }
            $rows[] = ['cells' => [
                (string) $node['number'], $text, (string) $node['unit'], $numbers->quantity((string) $node['agreedQuantity']),
                $numbers->quantity((string) $node['previousQuantity']), $numbers->quantity((string) $node['quantity']), $numbers->quantity((string) $node['totalQuantity']),
            ], 'indent' => $depth];
        }
        return $rows;
    }
}
