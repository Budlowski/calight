<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Support\Clock;
use App\Support\Uuid;
use PDO;

final readonly class ProjectRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM projects ORDER BY created_at DESC')->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM projects WHERE id = :id');
        $statement->execute(['id' => $id]);
        $project = $statement->fetch();

        return is_array($project) ? $project : null;
    }

    public function create(string $name, string $customerName, string $siteAddress): string
    {
        $id = Uuid::v4();
        $now = Clock::now();
        $statement = $this->pdo->prepare(
            'INSERT INTO projects (id, name, customer_name, site_address, created_at, updated_at)
             VALUES (:id, :name, :customer_name, :site_address, :created_at, :updated_at)'
        );
        $statement->execute([
            'id' => $id,
            'name' => $name,
            'customer_name' => $customerName,
            'site_address' => $siteAddress,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $id;
    }
}
