<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Calculation\Decimal;
use App\Domain\Document\DocumentValidationException;
use App\Http\AccessDeniedException;
use App\Infrastructure\Persistence\CostCenterRepository;
use App\Security\RolePermissions;
use App\Support\Clock;
use App\Support\Uuid;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use PDO;

/**
 * Allgemeine Kostenstellen (Stammdaten → Kostenstellen): interne Zuordnungsziele neben
 * Kundenaufträgen, z. B. K100 Verwaltung & Büro. Keine Buchhaltung, keine Umlagen.
 *
 * - Verwalten (anlegen, bearbeiten, (de)aktivieren, Vorlage übernehmen): cost_centers.manage
 *   (Administratoren).
 * - Übersicht: master_data.read; Beträge (Bestellungen, Auslagen) nur mit costs.read.
 * - Nummern sind dauerhaft (kein Jahresbezug) und eindeutig; verwendete Kostenstellen werden
 *   nie gelöscht, nur deaktiviert.
 */
final readonly class CostCenterService
{
    public function __construct(private PDO $pdo, private CostCenterRepository $centers, private string $templatePath)
    {
    }

    /**
     * Übersicht mit Arbeitsstunden und – mit costs.read – zuordenbaren Beträgen im Kalenderjahr.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function overview(array $actor, ?string $year = null): array
    {
        $this->require($actor, 'master_data.read');
        $year = $year !== null && preg_match('/^\d{4}$/D', $year) === 1 ? $year : (new DateTimeImmutable(Clock::now()))->format('Y');
        $costs = $this->can($actor, 'costs.read');
        $usage = $this->centers->usage($year . '-01-01', $year . '-12-31');
        $items = [];
        foreach ($this->centers->all() as $center) {
            $data = $usage[$center['id']] ?? [];
            $item = [
                'id' => $center['id'], 'number' => $center['number'], 'name' => $center['name'], 'description' => $center['description'],
                'active' => (bool) $center['active'], 'version' => (int) $center['version'], 'minutes' => (int) ($data['minutes'] ?? 0),
            ];
            if ($costs) {
                $purchases = array_map(static fn (array $row): ?string => (int) $row['allocation_count'] > 0 ? (string) $row['allocated_amount'] : PurchaseOrderService::actualCostOf($row),
                    $data['purchaseValues'] ?? []);
                $item += [
                    'purchaseTotal' => $this->sum(array_filter($purchases, static fn (?string $value): bool => $value !== null)),
                    // Bestellungen ohne bekannte Ist-Kosten werden gezählt, nicht geschätzt.
                    'purchasesWithoutCost' => count(array_filter($purchases, static fn (?string $value): bool => $value === null)),
                    'expenseTotal' => $this->sum($data['expenseValues'] ?? []),
                ];
            }
            $items[] = $item;
        }
        return ['year' => $year, 'items' => $items, 'canManage' => $this->can($actor, 'cost_centers.manage'), 'canCosts' => $costs,
            'templateAvailable' => $this->template() !== []];
    }

    /**
     * Anlegen (id leer) oder bearbeiten.
     *
     * @param array<string,mixed> $input number, name, description, active, version
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function save(?string $id, array $input, array $actor): array
    {
        $this->require($actor, 'cost_centers.manage');
        $number = trim((string) ($input['number'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $errors = [];
        if ($number === '' || mb_strlen($number) > 20 || preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._\/-]*$/u', $number) !== 1) {
            $errors[] = 'Bitte eine Kostenstellennummer angeben (höchstens 20 Zeichen, z. B. K100).';
        } elseif ($this->centers->numberTaken($number, $id)) {
            $errors[] = 'Die Kostenstellennummer ' . $number . ' ist bereits vergeben.';
        }
        if ($name === '' || mb_strlen($name) > 120) {
            $errors[] = 'Bitte eine Bezeichnung angeben (höchstens 120 Zeichen).';
        }
        if (mb_strlen($description) > 500) {
            $errors[] = 'Die Beschreibung darf höchstens 500 Zeichen lang sein.';
        }
        if ($errors !== []) {
            throw new DocumentValidationException($errors);
        }
        $data = ['number' => $number, 'name' => $name, 'description' => $description, 'active' => filter_var($input['active'] ?? true, FILTER_VALIDATE_BOOL) ? 1 : 0, 'now' => Clock::now()];
        if ($id === null) {
            $id = Uuid::v4();
            $this->centers->insert($data + ['id' => $id]);
        } elseif (!$this->centers->update($id, (int) ($input['version'] ?? 0), $data)) {
            throw new DocumentValidationException([$this->centers->find($id) === null ? 'Die Kostenstelle wurde nicht gefunden.' : 'Die Kostenstelle wurde zwischenzeitlich geändert. Bitte neu laden.']);
        }
        return $this->centers->find($id) ?? [];
    }

    /** Löschen nur, solange nicht verwendet; sonst deaktivieren. @param array<string,mixed> $actor */
    public function delete(string $id, int $version, array $actor): void
    {
        $this->require($actor, 'cost_centers.manage');
        if ($this->centers->inUse($id)) {
            throw new DocumentValidationException(['Die Kostenstelle wird bereits verwendet und kann nur deaktiviert werden.']);
        }
        if (!$this->centers->delete($id, $version)) {
            throw new DocumentValidationException(['Die Kostenstelle wurde nicht gefunden oder zwischenzeitlich geändert.']);
        }
    }

    /**
     * Optionale Vorlage übernehmen (config/cost-center-template.json). Idempotent: nur Nummern,
     * die noch nicht existieren, werden angelegt; bestehende bleiben unverändert.
     *
     * @param array<string,mixed> $actor
     * @return array{created:list<string>,skipped:list<string>}
     */
    public function importTemplate(array $actor): array
    {
        $this->require($actor, 'cost_centers.manage');
        $created = [];
        $skipped = [];
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            foreach ($this->template() as $entry) {
                if ($this->centers->numberTaken($entry['number'], null)) {
                    $skipped[] = $entry['number'];
                    continue;
                }
                $this->centers->insert(['id' => Uuid::v4(), 'number' => $entry['number'], 'name' => $entry['name'], 'description' => '', 'active' => 1, 'now' => Clock::now()]);
                $created[] = $entry['number'];
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return ['created' => $created, 'skipped' => $skipped];
    }

    /** @return list<array{number:string,name:string}> */
    private function template(): array
    {
        if (!is_file($this->templatePath)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($this->templatePath), true);
        $entries = is_array($data) && is_array($data['costCenters'] ?? null) ? $data['costCenters'] : [];
        return array_values(array_filter(array_map(static fn (mixed $entry): ?array => is_array($entry) && is_string($entry['number'] ?? null) && is_string($entry['name'] ?? null)
            ? ['number' => trim($entry['number']), 'name' => trim($entry['name'])] : null, $entries)));
    }

    /** @param list<string> $values */
    private function sum(array $values): string
    {
        $sum = BigDecimal::zero();
        foreach ($values as $value) {
            $sum = $sum->plus($value);
        }
        return Decimal::money($sum);
    }

    /** @param array<string,mixed> $actor */
    private function require(array $actor, string $permission): void
    {
        if (!$this->can($actor, $permission)) {
            throw new AccessDeniedException($permission);
        }
    }

    /** @param array<string,mixed> $actor */
    private function can(array $actor, string $permission): bool
    {
        return RolePermissions::allows((string) ($actor['role'] ?? ''), $permission);
    }
}
