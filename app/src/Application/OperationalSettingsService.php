<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Document\DocumentValidationException;
use App\Http\AccessDeniedException;
use App\Security\RolePermissions;
use App\Support\Clock;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use PDO;

/**
 * Globale Betriebseinstellungen (Einstellungen → Betrieb). Bewusst klein: feste, typisierte
 * Definitionen mit Standardwert; gespeichert in operational_settings (Text). Verwalten nur mit
 * operations.manage (Administratoren); lesen über values() durch die Fachdienste.
 *
 * Typen: bool, choice (options), number (Dezimalzahl mit scale, min, max, unit).
 * Die vorbelegten Arbeitszeitgrenzwerte orientieren sich am Arbeitszeitgesetz (ArbZG §§ 3–5),
 * die Reisekostenwerte an § 9 EStG. Sie bestimmen nur die Prüfungen und Referenzbeträge in
 * Calight und ersetzen weder gesetzliche Anforderungen noch eine steuerliche Prüfung.
 * Festgeschriebene Nachweise speichern die bei der Festschreibung verwendeten Werte.
 */
final readonly class OperationalSettingsService
{
    public const GROUPS = [
        'time' => 'Zeiterfassung',
        'limits' => 'Arbeitszeitgrenzwerte',
        'travel' => 'Reisekosten',
    ];

    /** @var array<string,array<string,mixed>> */
    public const DEFINITIONS = [
        'time_tracking.times_optional' => [
            'group' => 'time', 'type' => 'bool', 'default' => true,
            'label' => 'Stundenerfassung ohne Uhrzeiten erlauben',
            'description' => 'Aktiviert: Buchungen ohne Beginn und Ende gelten als vollständig. Deaktiviert: Stunden aus Berichten bleiben vollständig erhalten und zählen, sind aber bis zur Ergänzung der Uhrzeiten als „Uhrzeiten fehlen“ gekennzeichnet; manuelle Zeitblöcke benötigen Uhrzeiten, und fehlende Uhrzeiten verhindern die Einreichung.',
        ],
        'time_tracking.submission_rhythm' => [
            'group' => 'time', 'type' => 'choice', 'default' => 'both',
            'options' => ['week' => 'Wöchentlich', 'month' => 'Monatlich', 'both' => 'Wöchentlich oder monatlich'],
            'label' => 'Einreichungsrhythmus',
            'description' => 'Zeiträume, für die Mitarbeiter ihre Arbeitszeiten einreichen können (ISO-Kalenderwoche bzw. Kalendermonat).',
        ],
        'time_tracking.warnings_enabled' => [
            'group' => 'time', 'type' => 'bool', 'default' => true,
            'label' => 'Arbeitszeitwarnungen',
            'description' => 'Hinweise zu Tageshöchstarbeitszeit, Ruhepausen, Ruhezeit sowie Sonn- und Feiertagsarbeit bei Einreichung und Festschreibung. Warnungen verhindern die Einreichung nicht.',
        ],
        'time_limits.regular_daily_hours' => ['group' => 'limits', 'type' => 'number', 'default' => '8', 'scale' => 2, 'min' => '1', 'max' => '24', 'unit' => 'h',
            'label' => 'Reguläre tägliche Arbeitszeit', 'description' => 'Hinweis bei Überschreitung (Standard 8 h, § 3 ArbZG).'],
        'time_limits.max_daily_hours' => ['group' => 'limits', 'type' => 'number', 'default' => '10', 'scale' => 2, 'min' => '1', 'max' => '24', 'unit' => 'h',
            'label' => 'Tägliche Höchstarbeitszeit', 'description' => 'Warnung bei Überschreitung (Standard 10 h).'],
        'time_limits.break_threshold_1_hours' => ['group' => 'limits', 'type' => 'number', 'default' => '6', 'scale' => 2, 'min' => '0', 'max' => '24', 'unit' => 'h',
            'label' => 'Arbeitszeitgrenze erste Pausenstufe', 'description' => 'Ab mehr als dieser Arbeitszeit gilt die erste Mindestpause (Standard 6 h, § 4 ArbZG).'],
        'time_limits.break_minimum_1_minutes' => ['group' => 'limits', 'type' => 'number', 'default' => '30', 'scale' => 0, 'min' => '0', 'max' => '240', 'unit' => 'min',
            'label' => 'Mindestpause erste Stufe', 'description' => 'Standard 30 Minuten.'],
        'time_limits.break_threshold_2_hours' => ['group' => 'limits', 'type' => 'number', 'default' => '9', 'scale' => 2, 'min' => '0', 'max' => '24', 'unit' => 'h',
            'label' => 'Arbeitszeitgrenze zweite Pausenstufe', 'description' => 'Ab mehr als dieser Arbeitszeit gilt die zweite Mindestpause (Standard 9 h).'],
        'time_limits.break_minimum_2_minutes' => ['group' => 'limits', 'type' => 'number', 'default' => '45', 'scale' => 0, 'min' => '0', 'max' => '240', 'unit' => 'min',
            'label' => 'Mindestpause zweite Stufe', 'description' => 'Standard 45 Minuten.'],
        'time_limits.max_continuous_hours' => ['group' => 'limits', 'type' => 'number', 'default' => '6', 'scale' => 2, 'min' => '1', 'max' => '24', 'unit' => 'h',
            'label' => 'Maximale ununterbrochene Arbeitszeit', 'description' => 'Länger ohne Ruhepause wird als Hinweis gemeldet (Standard 6 h).'],
        'time_limits.min_break_part_minutes' => ['group' => 'limits', 'type' => 'number', 'default' => '15', 'scale' => 0, 'min' => '1', 'max' => '120', 'unit' => 'min',
            'label' => 'Mindestdauer einzelner Ruhepausen', 'description' => 'Kürzere Unterbrechungen zählen nicht als Ruhepause (Standard 15 Minuten).'],
        'time_limits.min_rest_hours' => ['group' => 'limits', 'type' => 'number', 'default' => '11', 'scale' => 2, 'min' => '0', 'max' => '24', 'unit' => 'h',
            'label' => 'Tägliche Mindestruhezeit', 'description' => 'Zwischen Arbeitsende und Arbeitsbeginn des Folgetags (Standard 11 h, § 5 ArbZG).'],
        'travel.meal_allowances_enabled' => ['group' => 'travel', 'type' => 'bool', 'default' => true,
            'label' => 'Verpflegungspauschalen erfassen', 'description' => 'Zeigt je Tag eine rechnerische Verpflegungspauschale als Referenzbetrag (keine steuerliche Freigabe oder Erstattungszusage).'],
        'travel.single_day_threshold_hours' => ['group' => 'travel', 'type' => 'number', 'default' => '8', 'scale' => 2, 'min' => '0', 'max' => '24', 'unit' => 'h',
            'label' => 'Abwesenheitsgrenze eintägige Auswärtstätigkeit', 'description' => 'Pauschale bei mehr als dieser Abwesenheit (Standard: mehr als 8 h, § 9 Abs. 4a EStG).'],
        'travel.allowance_single' => ['group' => 'travel', 'type' => 'number', 'default' => '14', 'scale' => 2, 'min' => '0', 'max' => '1000', 'unit' => '€',
            'label' => 'Pauschale eintägige Auswärtstätigkeit', 'description' => 'Standard 14 €.'],
        'travel.allowance_arrival' => ['group' => 'travel', 'type' => 'number', 'default' => '14', 'scale' => 2, 'min' => '0', 'max' => '1000', 'unit' => '€',
            'label' => 'Pauschale Anreisetag', 'description' => 'Mit auswärtiger Übernachtung, ohne Mindestabwesenheit (Standard 14 €).'],
        'travel.allowance_full' => ['group' => 'travel', 'type' => 'number', 'default' => '28', 'scale' => 2, 'min' => '0', 'max' => '1000', 'unit' => '€',
            'label' => 'Pauschale voller Tag', 'description' => '24 Stunden Abwesenheit (Standard 28 €). Grundlage der Mahlzeitenkürzungen.'],
        'travel.allowance_departure' => ['group' => 'travel', 'type' => 'number', 'default' => '14', 'scale' => 2, 'min' => '0', 'max' => '1000', 'unit' => '€',
            'label' => 'Pauschale Abreisetag', 'description' => 'Nach auswärtiger Übernachtung, ohne Mindestabwesenheit (Standard 14 €).'],
        'travel.reduction_breakfast_percent' => ['group' => 'travel', 'type' => 'number', 'default' => '20', 'scale' => 2, 'min' => '0', 'max' => '100', 'unit' => '%',
            'label' => 'Kürzung gestelltes Frühstück', 'description' => 'Bezogen auf die Pauschale für einen vollen Tag (Standard 20 %).'],
        'travel.reduction_lunch_percent' => ['group' => 'travel', 'type' => 'number', 'default' => '40', 'scale' => 2, 'min' => '0', 'max' => '100', 'unit' => '%',
            'label' => 'Kürzung gestelltes Mittagessen', 'description' => 'Bezogen auf die Pauschale für einen vollen Tag (Standard 40 %).'],
        'travel.reduction_dinner_percent' => ['group' => 'travel', 'type' => 'number', 'default' => '40', 'scale' => 2, 'min' => '0', 'max' => '100', 'unit' => '%',
            'label' => 'Kürzung gestelltes Abendessen', 'description' => 'Bezogen auf die Pauschale für einen vollen Tag (Standard 40 %).'],
        'travel.km_rate' => ['group' => 'travel', 'type' => 'number', 'default' => '0.30', 'scale' => 2, 'min' => '0', 'max' => '5', 'unit' => '€/km',
            'label' => 'Privat-Pkw-Kilometersatz', 'description' => 'Rechnerischer Betrag je dienstlich gefahrenem Kilometer (Standard 0,30 €/km).'],
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function all(array $actor): array
    {
        $this->require($actor);
        $stored = $this->pdo->query('SELECT s.setting_key, s.setting_value, s.updated_at, u.display_name AS updated_by_name
            FROM operational_settings s LEFT JOIN users u ON u.id = s.updated_by_user_id')->fetchAll(PDO::FETCH_UNIQUE);
        $values = $this->values();
        $result = [];
        foreach (self::DEFINITIONS as $key => $definition) {
            $row = $stored[$key] ?? null;
            $result[] = [
                'key' => $key, 'group' => $definition['group'], 'type' => $definition['type'], 'label' => $definition['label'], 'description' => $definition['description'],
                'value' => $values[$key], 'default' => $definition['default'],
                'options' => $definition['options'] ?? null, 'unit' => $definition['unit'] ?? null, 'scale' => $definition['scale'] ?? null,
                'min' => $definition['min'] ?? null, 'max' => $definition['max'] ?? null,
                'updatedAt' => $row['updated_at'] ?? null, 'updatedByName' => $row['updated_by_name'] ?? null,
            ];
        }
        return ['groups' => self::GROUPS, 'settings' => $result];
    }

    /**
     * Aktuelle Werte aller Einstellungen (Standardwert, wenn nicht gespeichert), typisiert:
     * bool, string (choice) bzw. normalisierte Dezimalzahl als String. Ohne Rechteprüfung –
     * nur für die Fachdienste.
     *
     * @return array<string,bool|string>
     */
    public function values(): array
    {
        return self::resolve($this->pdo->query('SELECT setting_key, setting_value FROM operational_settings')->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /**
     * Gespeicherte Rohwerte (key => Text) mit Standardwerten zu typisierten Werten ergänzen.
     *
     * @param array<string,string> $stored
     * @return array<string,bool|string>
     */
    public static function resolve(array $stored): array
    {
        $values = [];
        foreach (self::DEFINITIONS as $key => $definition) {
            $raw = $stored[$key] ?? null;
            $values[$key] = match ($definition['type']) {
                'bool' => $raw === null ? $definition['default'] : $raw === '1',
                'choice' => $raw !== null && isset($definition['options'][$raw]) ? $raw : $definition['default'],
                default => $raw !== null && is_numeric($raw) ? $raw : $definition['default'],
            };
        }
        return $values;
    }

    /**
     * Speichert einzelne Einstellungen. Eine Änderung verändert keine bestehenden Buchungen,
     * Berichte oder festgeschriebenen Nachweise; offene Zeiträume werden danach nach der
     * aktuellen Regel geprüft.
     *
     * @param array<string,mixed> $values key => Wert
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function save(array $values, array $actor): array
    {
        $this->require($actor);
        $now = Clock::now();
        $normalized = [];
        foreach ($values as $key => $value) {
            $definition = self::DEFINITIONS[$key] ?? throw new DocumentValidationException(['Unbekannte Einstellung: ' . $key]);
            $normalized[$key] = $this->normalize($definition, $value);
        }
        $merged = array_merge($this->values(), $normalized);
        if (BigDecimal::of((string) $merged['time_limits.break_threshold_2_hours'])->isLessThan((string) $merged['time_limits.break_threshold_1_hours'])) {
            throw new DocumentValidationException(['Die zweite Pausenstufe muss bei mindestens derselben Arbeitszeit wie die erste beginnen.']);
        }
        foreach ($normalized as $key => $value) {
            $this->pdo->prepare('INSERT INTO operational_settings (setting_key, setting_value, updated_by_user_id, updated_at) VALUES (:key, :value, :user, :now)
                ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_by_user_id = excluded.updated_by_user_id, updated_at = excluded.updated_at')
                ->execute(['key' => $key, 'value' => $value, 'user' => (string) $actor['id'], 'now' => $now]);
        }
        return $this->all($actor);
    }

    /** @param array<string,mixed> $definition */
    private function normalize(array $definition, mixed $value): string
    {
        if ($definition['type'] === 'bool') {
            if (!is_bool($value)) {
                throw new DocumentValidationException([$definition['label'] . ': bitte Ja oder Nein angeben.']);
            }
            return $value ? '1' : '0';
        }
        if ($definition['type'] === 'choice') {
            if (!is_string($value) || !isset($definition['options'][$value])) {
                throw new DocumentValidationException([$definition['label'] . ': ungültige Auswahl.']);
            }
            return $value;
        }
        $text = str_replace(',', '.', trim((string) (is_scalar($value) ? $value : '')));
        try {
            $number = BigDecimal::of($text);
        } catch (MathException) {
            throw new DocumentValidationException([$definition['label'] . ': bitte eine Zahl angeben.']);
        }
        if ($number->getScale() > $definition['scale'] && !$number->isEqualTo($number->toScale($definition['scale'], \Brick\Math\RoundingMode::Down))) {
            throw new DocumentValidationException([$definition['label'] . ': höchstens ' . $definition['scale'] . ' Nachkommastellen.']);
        }
        if ($number->isLessThan($definition['min']) || $number->isGreaterThan($definition['max'])) {
            throw new DocumentValidationException([sprintf('%s: bitte einen Wert zwischen %s und %s %s angeben.', $definition['label'], $definition['min'], $definition['max'], $definition['unit'])]);
        }
        return (string) $number->toScale($definition['scale'], \Brick\Math\RoundingMode::Down)->stripTrailingZeros();
    }

    /** @param array<string,mixed> $actor */
    private function require(array $actor): void
    {
        if (!RolePermissions::allows((string) ($actor['role'] ?? ''), 'operations.manage')) {
            throw new AccessDeniedException('operations.manage');
        }
    }
}
