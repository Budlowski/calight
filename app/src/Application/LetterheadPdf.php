<?php

declare(strict_types=1);

namespace App\Application;

use App\Infrastructure\Pdf\SimplePdf;

/**
 * Gemeinsamer Briefbogen für Rapporte, Aufmaße und Bautagesberichte.
 *
 * Kopf (Firma, Logo), Fuß (Anschrift, Kontakt, Steuer- und Bankdaten),
 * Empfängerblock, Metadaten und Seitenumbrüche folgen den Dokumenteinstellungen
 * und der Gestaltung des Angebots-PDFs. Es werden keine Firmendaten fest
 * hinterlegt; alles stammt aus den Einstellungen.
 */
final class LetterheadPdf
{
    private SimplePdf $pdf;
    private string $accent;
    private float $left;
    private float $right;
    private float $top;
    private float $bottom;
    private float $font;
    private float $y = 0;
    /** @var (callable():void)|null Wiederholter Tabellenkopf nach Seitenumbruch */
    private $repeatHeader = null;

    /** @param array<string,mixed> $settings Dokumenteinstellungen */
    public function __construct(private readonly array $settings, ?string $watermark = null)
    {
        $this->pdf = new SimplePdf($watermark);
        $this->accent = self::color((string) ($settings['accentColor'] ?? '#155a8a'));
        $this->left = (float) ($settings['marginLeft'] ?? 45);
        $this->right = 595 - (float) ($settings['marginRight'] ?? 45);
        $this->top = 842 - (float) ($settings['marginTop'] ?? 42) - 25;
        $this->bottom = (float) ($settings['marginBottom'] ?? 82);
        $this->font = (float) ($settings['baseFontSize'] ?? 8);
    }

    /**
     * Erste Seite mit Empfänger, Metadaten und Dokumenttitel.
     *
     * @param list<string> $recipient Empfängerzeilen (erste Zeile fett)
     * @param list<array{0:string,1:string}> $meta Beschriftung und Wert rechts neben dem Empfänger
     */
    public function begin(string $documentLabel, string $subtitle, array $recipient, array $meta): void
    {
        $this->decoratePage(true);
        $addressY = ($this->settings['addressMode'] ?? 'standard') === 'window' ? 690 : 680;
        $sender = implode(' · ', array_filter([$this->company(), $this->street(), $this->city()]));
        if ($sender !== '') {
            $this->pdf->text($this->left, $addressY + 18, 6.5, $sender, false, '.35 .4 .45');
            $this->pdf->line($this->left, $addressY + 12, $this->left + 270, $addressY + 12);
        }
        $lineY = $addressY - 7;
        foreach (array_values(array_filter($recipient, static fn (string $line): bool => trim($line) !== '')) as $index => $line) {
            $this->pdf->text($this->left, $lineY, $index === 0 ? 10 : 9, $line, $index === 0);
            $lineY -= $index === 0 ? 15 : 14;
        }
        $metaY = $addressY - 7;
        foreach ($meta as [$label, $value]) {
            if (trim($value) === '') {
                continue;
            }
            $this->pdf->text($this->right - 200, $metaY, $this->font, $label, false, '.35 .4 .45');
            $this->pdf->text($this->right - 115, $metaY, $this->font, self::shorten($value, 34), true);
            $metaY -= 15;
        }
        $this->y = min($lineY, $metaY) - 30;
        $this->pdf->text($this->left, $this->y, 17, $documentLabel, true, $this->accent);
        $this->y -= 21;
        if ($subtitle !== '') {
            foreach (self::wrap($subtitle, 80) as $line) {
                $this->pdf->text($this->left, $this->y, 11, $line, true);
                $this->y -= 14;
            }
        }
        $this->y -= 12;
    }

    /** Abschnittsüberschrift; bleibt mit mindestens etwas Folgeinhalt auf derselben Seite. */
    public function section(string $title, float $keepWithNext = 40): void
    {
        $this->repeatHeader = null;
        $this->ensure(22 + $keepWithNext);
        $this->pdf->text($this->left, $this->y, $this->font + 3, $title, true, $this->accent);
        $this->y -= 6;
        $this->pdf->line($this->left, $this->y, $this->right, $this->y, '.3 .35 .4');
        $this->y -= 14;
    }

    /** Mehrzeiliger Text mit Umbruch; Seitenumbrüche zeilenweise. */
    public function paragraph(string $text, ?float $size = null, bool $bold = false, string $color = '0 0 0', int $width = 105): void
    {
        $size ??= $this->font + 1;
        foreach (self::wrap($text, $width) as $line) {
            $this->ensure(13);
            $this->pdf->text($this->left, $this->y, $size, $line, $bold, $color);
            $this->y -= $size + 4;
        }
        $this->y -= 4;
    }

    /** @param list<array{0:string,1:string}> $pairs */
    public function facts(array $pairs): void
    {
        foreach ($pairs as [$label, $value]) {
            if (trim($value) === '') {
                continue;
            }
            $lines = self::wrap($value, 80);
            $this->ensure(13 * count($lines));
            $this->pdf->text($this->left, $this->y, $this->font, $label, false, '.35 .4 .45');
            foreach ($lines as $line) {
                $this->pdf->text($this->left + 120, $this->y, $this->font, $line, true);
                $this->y -= 12;
            }
        }
        $this->y -= 6;
    }

    /**
     * Tabelle mit Spaltenkopf, der nach jedem Seitenumbruch wiederholt wird.
     * Die erste Textspalte mit „wrap“ bricht um; andere Spalten bleiben einzeilig.
     *
     * @param list<array{label:string,width:float,align?:string,wrap?:int}> $columns Breiten in Punkt, Summe ≤ Satzspiegel
     * @param list<array{cells:list<string>,bold?:bool,muted?:bool,indent?:int}> $rows
     */
    public function table(array $columns, array $rows): void
    {
        $header = function () use ($columns): void {
            $x = $this->left;
            foreach ($columns as $column) {
                $this->cell($x, $column, $column['label'], true, '.35 .4 .45');
                $x += $column['width'];
            }
            $this->y -= 7;
            $this->pdf->line($this->left, $this->y, $this->right, $this->y, '.3 .35 .4');
            $this->y -= 12;
        };
        $this->ensure(40);
        $header();
        $this->repeatHeader = $header;
        foreach ($rows as $row) {
            $wrapped = [];
            foreach ($columns as $index => $column) {
                $value = (string) ($row['cells'][$index] ?? '');
                $wrapped[$index] = isset($column['wrap']) ? (self::wrap($value, $column['wrap']) ?: ['']) : [$value];
            }
            $height = max(array_map('count', $wrapped)) * 10 + 6;
            $this->ensure($height);
            $color = ($row['muted'] ?? false) ? '.4 .44 .48' : '0 0 0';
            $x = $this->left;
            foreach ($columns as $index => $column) {
                $lineY = $this->y;
                // Einrückung der Hierarchie nur in der Bezeichnungsspalte (zweite Spalte).
                $offset = $index === 1 ? min((int) ($row['indent'] ?? 0), 4) * 7 : 0;
                foreach ($wrapped[$index] as $line) {
                    $this->cell($x + $offset, ['width' => $column['width'] - $offset] + $column, $line, (bool) ($row['bold'] ?? false), $color, $lineY);
                    $lineY -= 10;
                }
                $x += $column['width'];
            }
            $this->y -= $height - 2;
            $this->pdf->line($this->left, $this->y + 6, $this->right, $this->y + 6, '.86 .88 .9');
            $this->y -= 4;
        }
        $this->repeatHeader = null;
        $this->y -= 6;
    }

    /** JPEG-Bild proportional eingepasst (Originaldaten bleiben unverändert). */
    public function image(string $jpeg, float $maxWidth, float $maxHeight, string $caption = ''): bool
    {
        $this->ensure($maxHeight + ($caption !== '' ? 16 : 6));
        $placed = $this->pdf->jpegContained($jpeg, $this->left, $this->y - $maxHeight, $maxWidth, $maxHeight);
        if ($placed === null) {
            return false;
        }
        $this->y -= $maxHeight + 4;
        if ($caption !== '') {
            $this->pdf->text($this->left, $this->y - 6, $this->font - 1, self::shorten($caption, 90), false, '.35 .4 .45');
            $this->y -= 14;
        }
        $this->y -= 6;
        return true;
    }

    /**
     * Bestätigungsbereich: entweder gespeicherte Unterschrift mit Angaben oder
     * leere Felder für eine handschriftliche Unterschrift.
     *
     * @param list<string> $statement Bestätigungstext
     * @param array{signature?:?string,name?:string,date?:string,note?:string,checksum?:string} $confirmation
     */
    public function confirmationBlock(array $statement, array $confirmation): void
    {
        $this->repeatHeader = null;
        $this->ensure(220);
        $this->section('Bestätigung durch den Kunden', 180);
        foreach ($statement as $text) {
            $this->paragraph($text, $this->font, false, '0 0 0');
        }
        // Unterschriftsfeld: Bild proportional eingepasst, mit Abstand zur Linie darunter.
        $this->y -= 6;
        $boxTop = $this->y;
        $lineY = $boxTop - 82;
        $width = ($this->right - $this->left - 24) / 2;
        $nameX = $this->left + $width + 24;
        $signature = $confirmation['signature'] ?? null;
        if (is_string($signature) && $signature !== '') {
            $this->pdf->jpegContained($signature, $this->left + 6, $lineY + 8, $width - 12, 66);
        }
        $this->pdf->line($this->left, $lineY, $this->left + $width, $lineY, '.3 .35 .4');
        $this->pdf->line($nameX, $lineY, $this->right, $lineY, '.3 .35 .4');
        $this->pdf->text($this->left, $lineY - 11, $this->font - 1, 'Unterschrift Kunde', false, '.35 .4 .45');
        $this->pdf->text($nameX, $lineY - 11, $this->font - 1, 'Name in Druckbuchstaben, Datum', false, '.35 .4 .45');
        if (($confirmation['name'] ?? '') !== '') {
            $this->pdf->text($nameX, $lineY + 22, $this->font + 1.5, self::shorten((string) $confirmation['name'], 40), true);
            if (($confirmation['date'] ?? '') !== '') {
                $this->pdf->text($nameX, $lineY + 8, $this->font, (string) $confirmation['date']);
            }
        }
        $this->y = $lineY - 28;
        if (($confirmation['note'] ?? '') !== '') {
            $this->paragraph((string) $confirmation['note'], $this->font - .5, false, '.35 .4 .45');
        }
        // Prüfsumme vollständig, aber dezent und ohne eigene Seite.
        $checksum = (string) ($confirmation['checksum'] ?? '');
        if ($checksum !== '') {
            $this->y -= 4;
            $this->ensure(18);
            $this->pdf->text($this->left, $this->y, 6, 'Prüfsumme des eingereichten Leistungsnachweises (SHA-256):', false, '.5 .55 .6');
            $this->pdf->text($this->left, $this->y - 8, 6, $checksum, false, '.5 .55 .6');
            $this->y -= 16;
        }
    }

    public function output(): string
    {
        return $this->pdf->output();
    }

    /** Sorgt für ausreichend Platz; sonst neue Seite mit Kopf, Fuß und ggf. wiederholtem Tabellenkopf. */
    public function ensure(float $height): void
    {
        if ($this->y - $height >= $this->bottom) {
            return;
        }
        $this->pdf->page();
        $this->decoratePage(false);
        $this->y = $this->top;
        if ($this->repeatHeader !== null) {
            ($this->repeatHeader)();
        }
    }

    /** @param array{width:float,align?:string} $column */
    private function cell(float $x, array $column, string $text, bool $bold, string $color, ?float $y = null): void
    {
        $text = isset($column['wrap']) ? $text : self::shorten($text, max(4, (int) ($column['width'] / ($this->font * .5))));
        if (($column['align'] ?? 'left') === 'right') {
            $this->pdf->textRight($x + $column['width'] - ($column['pad'] ?? 4), $y ?? $this->y, $this->font, $text, $bold, $color);
            return;
        }
        $this->pdf->text($x, $y ?? $this->y, $this->font, $text, $bold, $color);
    }

    private function decoratePage(bool $first): void
    {
        $this->footer();
        if (!($this->settings['showHeader'] ?? true)) {
            return;
        }
        $hasLogo = is_string($this->settings['logoData'] ?? null);
        $companyX = $first && ($this->settings['logoPosition'] ?? 'right') === 'left' && $hasLogo
            ? $this->left + (float) ($this->settings['logoWidth'] ?? 180) + 12
            : $this->left;
        $this->pdf->text($companyX, 800, 14, $this->company() ?: 'Calight', true, $this->accent);
        if ($first) {
            $this->logo();
        }
    }

    private function footer(): void
    {
        if (!($this->settings['showFooter'] ?? true)) {
            return;
        }
        $s = $this->settings;
        $this->pdf->line($this->left, 72, $this->right, 72);
        $columns = [
            [$this->company(), $this->street(), $this->city()],
            [($s['phone'] ?? '') !== '' ? 'Telefon ' . $s['phone'] : '', (string) ($s['email'] ?? ''), (string) ($s['website'] ?? '')],
            [($s['taxNumber'] ?? '') !== '' ? 'Steuernummer ' . $s['taxNumber'] : '', ($s['vatId'] ?? '') !== '' ? 'USt-ID ' . $s['vatId'] : ''],
            [(string) ($s['bankName'] ?? ''), ($s['iban'] ?? '') !== '' ? 'IBAN ' . $s['iban'] : '', ($s['bic'] ?? '') !== '' ? 'BIC ' . $s['bic'] : ''],
        ];
        $width = ($this->right - $this->left) / 4;
        foreach ($columns as $index => $lines) {
            $y = 58;
            foreach (array_filter($lines, static fn ($value): bool => trim((string) $value) !== '') as $line) {
                $this->pdf->text($this->left + $index * $width, $y, 6.5, self::shorten((string) $line, 32), $y === 58);
                $y -= 10;
            }
        }
        if (trim((string) ($s['footerText'] ?? '')) !== '') {
            $this->pdf->text($this->left, 18, 6.5, (string) $s['footerText']);
        }
    }

    private function logo(): void
    {
        $logo = $this->settings['logoData'] ?? null;
        if (!is_string($logo) || !str_starts_with($logo, 'data:image/jpeg;base64,')) {
            return;
        }
        $data = base64_decode(substr($logo, 23), true);
        $width = (float) ($this->settings['logoWidth'] ?? 180);
        $height = (float) ($this->settings['logoHeight'] ?? 100);
        $x = ($this->settings['logoPosition'] ?? 'right') === 'left' ? $this->left : $this->right - $width;
        if ($data !== false) {
            $this->pdf->jpegContained($data, $x, 814.0 - $height, $width, $height);
        }
    }

    private function company(): string
    {
        return trim((string) ($this->settings['companyName'] ?? '') . ' ' . (string) ($this->settings['legalForm'] ?? ''));
    }

    private function street(): string
    {
        return trim((string) ($this->settings['street'] ?? '') . ' ' . (string) ($this->settings['houseNumber'] ?? ''));
    }

    private function city(): string
    {
        return trim((string) ($this->settings['postalCode'] ?? '') . ' ' . (string) ($this->settings['city'] ?? ''));
    }

    /** @return list<string> */
    public static function wrap(string $text, int $width): array
    {
        $text = trim(str_replace("\r\n", "\n", $text));
        if ($text === '') {
            return [];
        }
        $lines = [];
        foreach (explode("\n", $text) as $paragraph) {
            if (trim($paragraph) === '') {
                $lines[] = '';
                continue;
            }
            foreach (explode("\n", wordwrap($paragraph, $width, "\n", true)) as $line) {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    private static function shorten(string $text, int $length): string
    {
        return mb_strlen($text) > $length ? mb_substr($text, 0, max(1, $length - 1)) . '…' : $text;
    }

    private static function color(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            return '0.08 0.35 0.54';
        }
        return implode(' ', array_map(static fn (string $part): string => (string) round(hexdec($part) / 255, 3), str_split($hex, 2)));
    }
}
