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
    /** @return list<array<string,mixed>> */ public function customers():array{return$this->pdo->query("SELECT id,customer_number,CASE WHEN customer_type='company' THEN company_name ELSE trim(first_name||' '||last_name) END name FROM customers WHERE active=1 ORDER BY name")->fetchAll();}

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
        $customerId=null;
        if(trim($customerName)!==''){
            $customerId=Uuid::v4();$customer=$this->pdo->prepare("INSERT INTO customers(id,customer_type,company_name,first_name,last_name,country,created_at,updated_at) VALUES(:id,'company',:name,'','','Deutschland',:created,:updated)");$customer->execute(['id'=>$customerId,'name'=>$customerName,'created'=>$now,'updated'=>$now]);
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO projects (id,name,customer_id,customer_name,site_address,created_at,updated_at)
             VALUES (:id,:name,:customer_id,:customer_name,:site_address,:created_at,:updated_at)'
        );
        $statement->execute([
            'id' => $id,
            'name' => $name,
            'customer_name' => $customerName,
            'customer_id'=>$customerId,
            'site_address' => $siteAddress,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $id;
    }
}
