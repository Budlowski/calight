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
        $statement = $this->pdo->prepare("SELECT p.*,
            CASE WHEN c.customer_type='company' THEN c.company_name ELSE trim(c.first_name || ' ' || c.last_name) END AS linked_customer_name,
            trim(co.first_name || ' ' || co.last_name) AS contact_name, co.function AS contact_function,
            co.email AS contact_email, co.phone AS contact_phone
            FROM projects p
            LEFT JOIN customers c ON c.id=p.customer_id
            LEFT JOIN customer_contacts co ON co.id=p.contact_id
            WHERE p.id = :id");
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
