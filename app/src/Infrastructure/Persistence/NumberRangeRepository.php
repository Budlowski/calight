<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use DateTimeImmutable;
use PDO;
use RuntimeException;

final readonly class NumberRangeRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function allocate(string $rangeCode, string $entityType, string $entityId, string $assignmentId, DateTimeImmutable $now): string
    {
        $existing = $this->assignment($entityType, $entityId);
        if ($existing !== null) return (string) $existing['business_number'];

        $range = $this->range($rangeCode);
        $period = match ($range['reset_mode']) {
            'yearly' => $now->format('Y'),
            'monthly' => $now->format('Y-m'),
            default => null,
        };
        $counter = (int) $range['next_counter'];
        if ($period !== null && $range['counter_period'] !== $period) $counter = 1;

        $entitySource = match ($rangeCode) {
            'customer' => 'customers customer_number',
            'project' => 'projects project_number',
            'service_template' => 'service_templates article_number',
            'offer' => 'offers offer_number',
            default => null,
        };
        $entityCollision = $entitySource === null ? null : $this->entityCollisionStatement($entitySource);
        $collision = $this->pdo->prepare('SELECT EXISTS(SELECT 1 FROM assigned_numbers WHERE number_range_id = :range_id AND business_number = :number COLLATE NOCASE)');
        for ($attempt = 0; $attempt < 100000; $attempt++, $counter++) {
            $number = $this->format($range, $counter, $now);
            $collision->execute(['range_id' => $range['id'], 'number' => $number]);
            if ((bool) $collision->fetchColumn()) continue;
            if ($entityCollision !== null) {
                $entityCollision->execute(['number' => $number]);
                if ((bool) $entityCollision->fetchColumn()) continue;
            }

            $this->insertAssignment($assignmentId, $range, $number, $entityType, $entityId, $counter, false, $now);
            $statement = $this->pdo->prepare(
                'UPDATE number_ranges SET next_counter = :next_counter, counter_period = :counter_period,
                 updated_at = :updated_at WHERE id = :id'
            );
            $statement->execute([
                'next_counter' => $counter + 1, 'counter_period' => $period,
                'updated_at' => $now->format(DATE_ATOM), 'id' => $range['id'],
            ]);
            return $number;
        }
        throw new RuntimeException('Keine freie Nummer im Nummernkreis gefunden.');
    }

    public function reserveManual(string $rangeCode, string $entityType, string $entityId, string $assignmentId, string $number, DateTimeImmutable $now): string
    {
        $existing = $this->assignment($entityType, $entityId);
        if ($existing !== null) {
            if (strcasecmp((string) $existing['business_number'], $number) !== 0) {
                throw new RuntimeException('Eine bereits verwendete Nummer kann nicht geändert werden.');
            }
            return (string) $existing['business_number'];
        }
        $range = $this->range($rangeCode);
        if (!(bool) $range['manual_numbers_allowed']) throw new RuntimeException('Manuelle Nummern sind in diesem Nummernkreis nicht erlaubt.');
        $this->insertAssignment($assignmentId, $range, $number, $entityType, $entityId, null, true, $now);
        return $number;
    }

    /** @return array<string,mixed> */
    private function range(string $code): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM number_ranges WHERE code = :code');
        $statement->execute(['code' => $code]);
        $range = $statement->fetch();
        if (!is_array($range)) throw new RuntimeException('Nummernkreis fehlt: ' . $code);
        return $range;
    }

    /** @return array<string,mixed>|null */
    private function assignment(string $entityType, string $entityId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM assigned_numbers WHERE entity_type = :type AND entity_id = :id');
        $statement->execute(['type' => $entityType, 'id' => $entityId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $range */
    private function format(array $range, int $counter, DateTimeImmutable $now): string
    {
        return strtr((string) $range['format'], [
            '{K}' => (string) $range['prefix'], '{JJJJ}' => $now->format('Y'), '{JJ}' => $now->format('y'),
            '{MM}' => $now->format('m'), '{NR}' => str_pad((string) $counter, (int) $range['minimum_length'], '0', STR_PAD_LEFT),
        ]);
    }

    /** @param array<string,mixed> $range */
    private function insertAssignment(string $id, array $range, string $number, string $entityType, string $entityId, ?int $counter, bool $manual, DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO assigned_numbers
             (id, number_range_id, business_number, entity_type, entity_id, counter_value, assigned_manually, assigned_at)
             VALUES (:id, :range_id, :number, :entity_type, :entity_id, :counter, :manual, :assigned_at)'
        );
        $statement->execute([
            'id' => $id, 'range_id' => $range['id'], 'number' => $number, 'entity_type' => $entityType,
            'entity_id' => $entityId, 'counter' => $counter, 'manual' => $manual ? 1 : 0, 'assigned_at' => $now->format(DATE_ATOM),
        ]);
    }

    private function entityCollisionStatement(string $source): \PDOStatement
    {
        [$table, $column] = explode(' ', $source, 2);
        return $this->pdo->prepare(sprintf('SELECT EXISTS(SELECT 1 FROM %s WHERE %s = :number COLLATE NOCASE)', $table, $column));
    }
}
