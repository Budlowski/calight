<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Document\DocumentValidationException;
use App\Http\AccessDeniedException;
use App\Security\RolePermissions;
use App\Support\Clock;
use PDO;

/**
 * Globale Betriebseinstellungen (Einstellungen → Betrieb). Bewusst klein: feste, typisierte
 * Definitionen mit Standardwert; gespeichert in operational_settings. Nur mit operations.manage
 * (Administratoren). Neue Einstellungen werden in DEFINITIONS ergänzt.
 */
final readonly class OperationalSettingsService
{
    /** @var array<string,array{type:string,default:bool,label:string,description:string}> */
    public const DEFINITIONS = [
        'time_tracking.times_optional' => [
            'type' => 'bool', 'default' => true,
            'label' => 'Stundenerfassung ohne Uhrzeiten erlauben',
            'description' => 'Aktiviert: Buchungen ohne Beginn und Ende gelten als vollständig. Deaktiviert: Stunden aus Berichten bleiben vollständig erhalten und zählen, sind aber bis zur Ergänzung der Uhrzeiten als „Uhrzeiten fehlen“ gekennzeichnet; manuelle Zeitblöcke benötigen Uhrzeiten.',
        ],
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /** @param array<string,mixed> $actor @return list<array<string,mixed>> */
    public function all(array $actor): array
    {
        $this->require($actor);
        $stored = $this->pdo->query('SELECT s.setting_key, s.setting_value, s.updated_at, u.display_name AS updated_by_name
            FROM operational_settings s LEFT JOIN users u ON u.id = s.updated_by_user_id')->fetchAll(PDO::FETCH_UNIQUE);
        $result = [];
        foreach (self::DEFINITIONS as $key => $definition) {
            $row = $stored[$key] ?? null;
            $result[] = [
                'key' => $key, 'type' => $definition['type'], 'label' => $definition['label'], 'description' => $definition['description'],
                'value' => $row !== null ? $row['setting_value'] === '1' : $definition['default'], 'default' => $definition['default'],
                'updatedAt' => $row['updated_at'] ?? null, 'updatedByName' => $row['updated_by_name'] ?? null,
            ];
        }
        return $result;
    }

    /**
     * Speichert einzelne Einstellungen. Eine Änderung verändert keine bestehenden Buchungen oder
     * Berichte; diese werden danach nach der aktuellen Regel bewertet.
     *
     * @param array<string,mixed> $values key => Wert
     * @param array<string,mixed> $actor
     * @return list<array<string,mixed>>
     */
    public function save(array $values, array $actor): array
    {
        $this->require($actor);
        $now = Clock::now();
        foreach ($values as $key => $value) {
            $definition = self::DEFINITIONS[$key] ?? throw new DocumentValidationException(['Unbekannte Einstellung: ' . $key]);
            if ($definition['type'] === 'bool' && !is_bool($value)) {
                throw new DocumentValidationException([$definition['label'] . ': bitte Ja oder Nein angeben.']);
            }
            $this->pdo->prepare('INSERT INTO operational_settings (setting_key, setting_value, updated_by_user_id, updated_at) VALUES (:key, :value, :user, :now)
                ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_by_user_id = excluded.updated_by_user_id, updated_at = excluded.updated_at')
                ->execute(['key' => $key, 'value' => $value ? '1' : '0', 'user' => (string) $actor['id'], 'now' => $now]);
        }
        return $this->all($actor);
    }

    /** @param array<string,mixed> $actor */
    private function require(array $actor): void
    {
        if (!RolePermissions::allows((string) ($actor['role'] ?? ''), 'operations.manage')) {
            throw new AccessDeniedException('operations.manage');
        }
    }
}
