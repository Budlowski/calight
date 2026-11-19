<?php

declare(strict_types=1);

namespace App\Application;

use App\Infrastructure\Pdf\PdfNumberFormatter;
use App\Infrastructure\Persistence\DocumentSettingsRepository;
use DateTimeImmutable;

/**
 * Bestell-PDF an den Lieferanten über den gemeinsamen Briefbogen.
 *
 * Grundlage ist ausschließlich der übergebene Bestellstand (inkl. Lieferanten-Snapshot);
 * ausgegebene Fassungen werden vom PurchaseOrderService unveränderlich archiviert.
 * Entwürfe erscheinen nur als Vorschau mit Wasserzeichen.
 */
final readonly class PurchaseOrderPdfService
{
    public function __construct(private DocumentSettingsRepository $settings)
    {
    }

    /**
     * @param array<string,mixed> $order Bestellkopf (Datenbankzeile, inkl. supplier_snapshot)
     * @param list<array<string,mixed>> $items
     */
    public function render(array $order, array $items, bool $preview): string
    {
        $numbers = new PdfNumberFormatter();
        $supplier = json_decode((string) ($order['supplier_snapshot'] ?? ''), true);
        if (!is_array($supplier)) {
            $supplier = ['name' => (string) ($order['supplier_name'] ?? ''), 'number' => (string) ($order['supplier_number'] ?? ''), 'contact' => ''];
        }
        $revision = (int) $order['revision'];
        $pdf = new LetterheadPdf($this->settings->get(), $preview ? 'ENTWURF' : null);
        $pdf->begin(
            'Bestellung',
            'Nr. ' . $order['order_number'] . ($revision > 1 ? ' · Fassung ' . $revision . ' (ersetzt frühere Fassungen)' : ''),
            array_values(array_filter([(string) $supplier['name'], ...preg_split('/\R/u', (string) ($supplier['contact'] ?? '')) ?: []], static fn (string $line): bool => trim($line) !== '')),
            [
                ['Bestellnummer', (string) $order['order_number']],
                ['Bestelldatum', self::date((string) ($order['order_date'] ?? ''))],
                ['Lieferantennummer', (string) ($supplier['number'] ?? '')],
                ['Gewünschte Lieferung', self::date((string) ($order['expected_delivery_date'] ?? ''))],
                ['Auftrag', (string) ($order['order_reference'] ?? '')],
            ],
        );

        if (trim((string) $order['title']) !== '') {
            $pdf->paragraph((string) $order['title'], null, true);
        }
        $pdf->paragraph('Wir bestellen zu den vereinbarten Bedingungen wie folgt:');

        $priced = array_filter($items, static fn (array $item): bool => $item['unit_price'] !== null && $item['unit_price'] !== '');
        $rows = [];
        foreach ($items as $item) {
            $description = (string) $item['description'] . (trim((string) $item['notes']) !== '' ? ' – ' . $item['notes'] : '');
            $cells = [
                (string) $item['position'],
                $item['quantity'] !== null ? $numbers->quantity((string) $item['quantity']) : '',
                (string) $item['unit'],
                (string) $item['article_number'],
                $description,
            ];
            if ($priced !== []) {
                $cells[] = $item['unit_price'] !== null ? $numbers->unitPrice((string) $item['unit_price']) : '';
                $cells[] = $item['line_total'] !== null ? $numbers->money((string) $item['line_total']) : '';
            }
            $rows[] = ['cells' => $cells];
        }
        $columns = $priced !== []
            ? [
                ['label' => 'Pos.', 'width' => 28],
                ['label' => 'Menge', 'width' => 52, 'align' => 'right', 'pad' => 10],
                ['label' => 'Einheit', 'width' => 40],
                ['label' => 'Artikel-Nr.', 'width' => 70, 'wrap' => 14],
                ['label' => 'Bezeichnung', 'width' => 175, 'wrap' => 38],
                ['label' => 'Einzelpreis', 'width' => 65, 'align' => 'right'],
                ['label' => 'Gesamt', 'width' => 75, 'align' => 'right'],
            ]
            : [
                ['label' => 'Pos.', 'width' => 28],
                ['label' => 'Menge', 'width' => 60, 'align' => 'right', 'pad' => 10],
                ['label' => 'Einheit', 'width' => 50],
                ['label' => 'Artikel-Nr.', 'width' => 90, 'wrap' => 18],
                ['label' => 'Bezeichnung', 'width' => 277, 'wrap' => 60],
            ];
        if ($priced !== [] && ($order['items_total'] ?? null) !== null) {
            $rows[] = ['cells' => ['', '', '', '', 'Summe netto', '', $numbers->money((string) $order['items_total'])], 'bold' => true];
        }
        $pdf->section('Positionen');
        $pdf->table($columns, $rows);

        if (trim((string) $order['delivery_address']) !== '') {
            $pdf->section('Lieferanschrift');
            $pdf->paragraph((string) $order['delivery_address']);
        }
        if (trim((string) $order['notes']) !== '') {
            $pdf->section('Hinweise');
            $pdf->paragraph((string) $order['notes']);
        }
        $pdf->paragraph('Bitte geben Sie bei Auftragsbestätigung, Lieferschein und Rechnung unsere Bestellnummer ' . $order['order_number'] . ' an.', null, false, '.35 .4 .45');
        return $pdf->output();
    }

    private static function date(string $value): string
    {
        return $value !== '' ? (new DateTimeImmutable($value))->format('d.m.Y') : '';
    }
}
