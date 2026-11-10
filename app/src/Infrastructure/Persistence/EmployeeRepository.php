<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;

final readonly class EmployeeRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return list<array<string,mixed>> */
    public function all(bool $includeInactive=true):array
    {
        $sql='SELECT e.*,u.username,u.role,u.active user_active FROM employees e LEFT JOIN users u ON u.id=e.user_id';
        if(!$includeInactive)$sql.=' WHERE e.active=1';
        return$this->pdo->query($sql.' ORDER BY e.active DESC,e.display_name COLLATE NOCASE')->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(string $id):?array
    {
        $s=$this->pdo->prepare('SELECT e.*,u.username,u.role,u.active user_active FROM employees e LEFT JOIN users u ON u.id=e.user_id WHERE e.id=:id');$s->execute(['id'=>$id]);$row=$s->fetch();return is_array($row)?$row:null;
    }

    /** @return array<string,mixed>|null */
    public function findByUser(string $userId):?array
    {
        $s=$this->pdo->prepare('SELECT * FROM employees WHERE user_id=:id AND active=1');$s->execute(['id'=>$userId]);$row=$s->fetch();return is_array($row)?$row:null;
    }

    /** @param array<string,mixed> $data */
    public function insert(array $data):void
    {
        $s=$this->pdo->prepare('INSERT INTO employees(id,user_id,personnel_number,display_name,hourly_cost_rate,active,created_at,updated_at) VALUES(:id,:user_id,:personnel_number,:display_name,:hourly_cost_rate,:active,:created_at,:updated_at)');$s->execute($data);
    }

    /** @param array<string,mixed> $data */
    public function update(array $data):bool
    {
        unset($data['created_at']);$s=$this->pdo->prepare('UPDATE employees SET user_id=:user_id,personnel_number=:personnel_number,display_name=:display_name,hourly_cost_rate=:hourly_cost_rate,active=:active,updated_at=:updated_at WHERE id=:id');$s->execute($data);return$s->rowCount()===1;
    }
}
