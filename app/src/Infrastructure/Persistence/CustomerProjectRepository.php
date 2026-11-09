<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;

final readonly class CustomerProjectRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function customers(): array
    {
        return $this->pdo->query('SELECT c.*,
            (SELECT COUNT(*) FROM projects p WHERE p.customer_id=c.id) AS project_count,
            (SELECT COUNT(*) FROM offers o JOIN projects p ON p.id=o.project_id WHERE p.customer_id=c.id) AS offer_count
            FROM customers c
            ORDER BY c.active DESC, c.customer_number COLLATE NOCASE, c.company_name COLLATE NOCASE, c.last_name COLLATE NOCASE')->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function customer(string $id): ?array
    {
        return $this->one('SELECT * FROM customers WHERE id = :id', ['id' => $id]);
    }

    /** @return list<array<string,mixed>> */
    public function contacts(string $customerId): array
    {
        return $this->all('SELECT * FROM customer_contacts WHERE customer_id = :id ORDER BY sort_order, last_name COLLATE NOCASE', ['id' => $customerId]);
    }

    /** @return list<array<string,mixed>> */
    public function addresses(string $customerId): array
    {
        return $this->all('SELECT * FROM customer_addresses WHERE customer_id = :id ORDER BY sort_order, label COLLATE NOCASE', ['id' => $customerId]);
    }

    /** @param array<string,mixed> $data */
    public function insertCustomer(array $data): void
    {
        $this->execute('INSERT INTO customers
            (id, customer_number, customer_type, company_name, first_name, last_name, street, house_number, postal_code, city,
             country, email, phone, website, vat_id, notes, copied_from_id, created_at, updated_at)
            VALUES (:id, :customer_number, :customer_type, :company_name, :first_name, :last_name, :street, :house_number, :postal_code, :city,
             :country, :email, :phone, :website, :vat_id, :notes, :copied_from_id, :created_at, :updated_at)', $data);
    }

    /** @param array<string,mixed> $data */
    public function updateCustomer(array $data): void
    {
        $this->execute('UPDATE customers SET customer_type=:customer_type, company_name=:company_name, first_name=:first_name,
            last_name=:last_name, street=:street, house_number=:house_number, postal_code=:postal_code, city=:city, country=:country,
            email=:email, phone=:phone, website=:website, vat_id=:vat_id, notes=:notes, updated_at=:updated_at WHERE id=:id', $data);
    }

    /** @param list<array<string,mixed>> $contacts @param list<array<string,mixed>> $addresses */
    public function replaceCustomerChildren(string $customerId, array $contacts, array $addresses): void
    {
        $this->deleteMissingChildren('customer_contacts', $customerId, array_column($contacts, 'id'));
        $this->deleteMissingChildren('customer_addresses', $customerId, array_column($addresses, 'id'));
        foreach ($contacts as $row) $this->execute('INSERT INTO customer_contacts
            (id, customer_id, salutation, first_name, last_name, function, email, phone, mobile, sort_order, created_at, updated_at)
            VALUES (:id,:customer_id,:salutation,:first_name,:last_name,:function,:email,:phone,:mobile,:sort_order,:created_at,:updated_at)
            ON CONFLICT(id) DO UPDATE SET salutation=excluded.salutation, first_name=excluded.first_name, last_name=excluded.last_name,
            function=excluded.function, email=excluded.email, phone=excluded.phone, mobile=excluded.mobile,
            sort_order=excluded.sort_order, updated_at=excluded.updated_at', $row);
        foreach ($addresses as $row) $this->execute('INSERT INTO customer_addresses
            (id, customer_id, address_type, label, street, house_number, postal_code, city, country, sort_order, created_at, updated_at)
            VALUES (:id,:customer_id,:address_type,:label,:street,:house_number,:postal_code,:city,:country,:sort_order,:created_at,:updated_at)
            ON CONFLICT(id) DO UPDATE SET address_type=excluded.address_type, label=excluded.label, street=excluded.street,
            house_number=excluded.house_number, postal_code=excluded.postal_code, city=excluded.city, country=excluded.country,
            sort_order=excluded.sort_order, updated_at=excluded.updated_at', $row);
    }

    public function setCustomerActive(string $id, bool $active, string $updatedAt): void
    {
        $this->execute('UPDATE customers SET active=:active, updated_at=:updated_at WHERE id=:id', ['active' => $active ? 1 : 0, 'updated_at' => $updatedAt, 'id' => $id]);
    }

    public function setCustomerNumber(string $id, string $number): void
    {
        $this->execute('UPDATE customers SET customer_number=:number WHERE id=:id', ['number' => $number, 'id' => $id]);
    }

    public function customerHasProjects(string $id): bool
    {
        $statement = $this->pdo->prepare('SELECT EXISTS(SELECT 1 FROM projects WHERE customer_id=:id)');
        $statement->execute(['id' => $id]);
        return (bool) $statement->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    public function projects(): array
    {
        return $this->pdo->query("SELECT p.*, c.customer_number,
            CASE WHEN c.customer_type='company' THEN c.company_name ELSE trim(c.first_name || ' ' || c.last_name) END AS linked_customer_name,
            trim(co.first_name || ' ' || co.last_name) AS contact_name,
            (SELECT COUNT(*) FROM offers o WHERE o.project_id=p.id) AS offer_count
            FROM projects p LEFT JOIN customers c ON c.id=p.customer_id LEFT JOIN customer_contacts co ON co.id=p.contact_id
            ORDER BY CASE p.status WHEN 'active' THEN 0 WHEN 'draft' THEN 1 WHEN 'completed' THEN 2 ELSE 3 END, p.updated_at DESC")->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function project(string $id): ?array
    {
        return $this->one("SELECT p.*, c.customer_number,
            CASE WHEN c.customer_type='company' THEN c.company_name ELSE trim(c.first_name || ' ' || c.last_name) END AS linked_customer_name,
            trim(co.first_name || ' ' || co.last_name) AS contact_name
            FROM projects p LEFT JOIN customers c ON c.id=p.customer_id LEFT JOIN customer_contacts co ON co.id=p.contact_id WHERE p.id=:id", ['id' => $id]);
    }

    /** @param array<string,mixed> $data */
    public function insertProject(array $data): void
    {
        $this->execute('INSERT INTO projects
            (id, project_number, name, description, customer_id, contact_id, customer_name, site_street, site_house_number,
             site_postal_code, site_city, site_country, site_address, status, created_at, updated_at)
            VALUES (:id,:project_number,:name,:description,:customer_id,:contact_id,:customer_name,:site_street,:site_house_number,
             :site_postal_code,:site_city,:site_country,:site_address,:status,:created_at,:updated_at)', $data);
    }

    /** @param array<string,mixed> $data */
    public function updateProject(array $data): void
    {
        $this->execute('UPDATE projects SET name=:name, description=:description, customer_id=:customer_id, contact_id=:contact_id,
            customer_name=:customer_name, site_street=:site_street, site_house_number=:site_house_number,
            site_postal_code=:site_postal_code, site_city=:site_city, site_country=:site_country, site_address=:site_address,
            status=:status, updated_at=:updated_at WHERE id=:id', $data);
    }

    public function setProjectNumber(string $id, string $number): void
    {
        $this->execute('UPDATE projects SET project_number=:number WHERE id=:id', ['number' => $number, 'id' => $id]);
    }

    public function begin(): void { $this->pdo->exec('BEGIN IMMEDIATE'); }
    public function commit(): void { $this->pdo->commit(); }
    public function rollBack(): void { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); }

    /** @return array<string,mixed>|null */
    private function one(string $sql, array $parameters): ?array
    {
        $statement = $this->pdo->prepare($sql); $statement->execute($parameters); $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }
    /** @return list<array<string,mixed>> */
    private function all(string $sql, array $parameters): array
    {
        $statement = $this->pdo->prepare($sql); $statement->execute($parameters); return $statement->fetchAll();
    }
    private function execute(string $sql, array $parameters): void
    {
        $statement = $this->pdo->prepare($sql); $statement->execute($parameters);
    }

    /** @param list<string> $keepIds */
    private function deleteMissingChildren(string $table, string $customerId, array $keepIds): void
    {
        if ($keepIds === []) { $this->execute("DELETE FROM {$table} WHERE customer_id=:customer_id", ['customer_id'=>$customerId]); return; }
        $placeholders=[]; $parameters=['customer_id'=>$customerId];
        foreach($keepIds as $index=>$id){$key='keep_'.$index;$placeholders[]=':'.$key;$parameters[$key]=$id;}
        $this->execute("DELETE FROM {$table} WHERE customer_id=:customer_id AND id NOT IN (".implode(',',$placeholders).')',$parameters);
    }
}
