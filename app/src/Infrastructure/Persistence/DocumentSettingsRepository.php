<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Support\Clock;
use JsonException;
use PDO;
use RuntimeException;

final readonly class DocumentSettingsRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return array<string,mixed> */
    public function get(): array
    {
        $json = $this->pdo->query('SELECT settings_json FROM document_settings WHERE id=1')->fetchColumn();
        try { $data = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR); }
        catch (JsonException $exception) { throw new RuntimeException('Dokumenteinstellungen sind beschädigt.', 0, $exception); }
        if (!is_array($data)) throw new RuntimeException('Dokumenteinstellungen sind ungültig.');
        return $data;
    }

    /** @param array<string,mixed> $settings */
    public function save(array $settings): void
    {
        try { $json=json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
        catch(JsonException $exception){ throw new RuntimeException('Dokumenteinstellungen konnten nicht kodiert werden.',0,$exception); }
        $statement=$this->pdo->prepare('UPDATE document_settings SET settings_json=:json, updated_at=:updated WHERE id=1');
        $statement->execute(['json'=>$json,'updated'=>Clock::now()]);
    }
}
