<?php
// Gemeinsame Anzeigehilfen der mobilen Seiten (nur Formatierung, keine Fachlogik).

/** Minuten als „7:30 h“. */
$mobileHours = static function (int $minutes): string {
    $sign = $minutes < 0 ? '-' : '';
    $minutes = abs($minutes);
    return $sign . intdiv($minutes, 60) . ':' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT) . ' h';
};

/** ISO-Datum als „21.11.2026“ (leer: Strich). */
$mobileDate = static function (?string $date): string {
    if ($date === null || $date === '') {
        return '–';
    }
    return \App\Application\MobileWorkspace::germanDate($date);
};

/** Deutscher Wochentag (ohne intl-Erweiterung). */
$mobileWeekday = static fn (DateTimeInterface $date): string => ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'][(int) $date->format('w')];

/** Telefonlink: nur Ziffern und führendes Plus. */
$mobilePhoneHref = static function (string $phone): string {
    $digits = (string) preg_replace('/[^0-9+]/', '', $phone);
    return 'tel:' . (str_starts_with($digits, '+') ? '+' . str_replace('+', '', substr($digits, 1)) : str_replace('+', '', $digits));
};

/**
 * Kartenlink für die Navigation. Wird nur auf ausdrückliches Antippen geöffnet; Calight selbst
 * ruft keinen Kartendienst auf.
 */
$mobileMapHref = static fn (string $query): string => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($query);

$mobileStatusClass = static fn (string $status): string => 'status-badge status-' . preg_replace('/[^a-z_]/', '', $status);
