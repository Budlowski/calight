<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Calculation\Decimal;
use App\Domain\DailyReportValidationException;
use App\Domain\Document\DocumentValidationException;
use App\Infrastructure\Persistence\EmployeeRepository;
use App\Infrastructure\Persistence\UserRepository;
use App\Security\RolePermissions;
use App\Support\Clock;
use App\Support\Uuid;
use Brick\Math\RoundingMode;
use PDO;
use PDOException;
use Throwable;

/**
 * Mitarbeiter (Stammdaten → Mitarbeiter). Mitarbeiter und Benutzerkonten bleiben getrennte
 * Entitäten; ein Konto gehört höchstens zu einem Mitarbeiter und umgekehrt (UNIQUE user_id).
 * Kontooptionen: kein Konto, vorhandenes Konto verknüpfen oder – nur mit users.manage – ein neues
 * Konto in derselben Transaktion anlegen. Keine Zuordnung über Namen. Kostensätze wirken nur auf
 * künftige Buchungen (Snapshots bleiben unverändert).
 */
final readonly class EmployeeService
{
    public function __construct(
        private EmployeeRepository $employees,
        private UserRepository $users,
        private ?PDO $pdo = null,
        private ?UserAccessService $accounts = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function overview(): array
    {
        return ['employees' => $this->employees->all(), 'users' => $this->users->all(), 'roles' => RolePermissions::ROLES];
    }

    /**
     * @param array<string,mixed> $input displayName, personnelNumber, hourlyCostRate, active,
     *        accountMode (none|existing|new; ohne Angabe wie bisher über userId), userId, newUser{username, displayName, password, role}
     * @param array<string,mixed> $actor
     * @return array<string,mixed> Mitarbeiter inkl. hints
     */
    public function save(?string $id, array $input, array $actor = []): array
    {
        $name = trim((string) ($input['displayName'] ?? ''));
        $personnel = trim((string) ($input['personnelNumber'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) {
            throw new DailyReportValidationException(['Der Anzeigename ist erforderlich und darf höchstens 150 Zeichen lang sein.']);
        }
        if (mb_strlen($personnel) > 50) {
            throw new DailyReportValidationException(['Die Personalnummer darf höchstens 50 Zeichen lang sein.']);
        }
        try {
            $rate = (string) Decimal::parse((string) ($input['hourlyCostRate'] ?? ''), Decimal::COST_SCALE, 'Interner Stundenkostensatz')->toScale(Decimal::COST_SCALE, RoundingMode::HalfUp);
        } catch (\InvalidArgumentException $e) {
            throw new DailyReportValidationException([$e->getMessage()]);
        }
        if (str_starts_with($rate, '-')) {
            throw new DailyReportValidationException(['Der interne Stundenkostensatz darf nicht negativ sein.']);
        }
        $mode = (string) ($input['accountMode'] ?? (trim((string) ($input['userId'] ?? '')) !== '' ? 'existing' : 'none'));
        if (!in_array($mode, ['none', 'existing', 'new'], true)) {
            throw new DailyReportValidationException(['Ungültige Auswahl für das Benutzerkonto.']);
        }
        if ($mode === 'existing' && trim((string) ($input['userId'] ?? '')) === '') {
            throw new DailyReportValidationException(['Bitte ein vorhandenes Benutzerkonto auswählen.']);
        }
        $active = filter_var($input['active'] ?? true, FILTER_VALIDATE_BOOL);
        $now = Clock::now();
        $data = ['id' => $id ?: Uuid::v4(), 'user_id' => null, 'personnel_number' => $personnel !== '' ? $personnel : null, 'display_name' => $name,
            'hourly_cost_rate' => $rate, 'active' => $active ? 1 : 0, 'created_at' => $now, 'updated_at' => $now];

        $this->transaction(function () use (&$data, $id, $mode, $input, $actor): void {
            if ($mode === 'existing') {
                $data['user_id'] = trim((string) $input['userId']);
                if ($this->users->findAccount($data['user_id']) === null) {
                    throw new DailyReportValidationException(['Das Benutzerkonto wurde nicht gefunden.']);
                }
            } elseif ($mode === 'new') {
                if ($this->accounts === null) {
                    throw new DailyReportValidationException(['Benutzerkonten können hier nicht angelegt werden.']);
                }
                $newUser = is_array($input['newUser'] ?? null) ? $input['newUser'] : [];
                try {
                    $data['user_id'] = $this->accounts->createWithinTransaction([
                        'username' => $newUser['username'] ?? '', 'displayName' => trim((string) ($newUser['displayName'] ?? '')) ?: $data['display_name'],
                        'password' => $newUser['password'] ?? '', 'role' => $newUser['role'] ?? 'technician', 'active' => true,
                    ], $actor);
                } catch (DocumentValidationException $exception) {
                    throw new DailyReportValidationException($exception->errors);
                }
            }
            try {
                if ($id === null) {
                    $this->employees->insert($data);
                } elseif (!$this->employees->update($data)) {
                    throw new DailyReportValidationException(['Mitarbeiter wurde nicht gefunden.']);
                }
            } catch (PDOException $e) {
                if (str_starts_with((string) $e->getCode(), '23')) {
                    throw new DailyReportValidationException(['Benutzerkonto oder Personalnummer ist bereits einem Mitarbeiter zugeordnet.']);
                }
                throw $e;
            }
        });

        $employee = $this->employees->find($data['id']) ?? [];
        return $employee + ['hints' => $this->hints($employee)];
    }

    /**
     * Mitarbeiter- und Benutzerdeaktivierung sind getrennte Vorgänge; Hinweise auf die jeweils
     * andere noch aktive bzw. inaktive Entität.
     *
     * @param array<string,mixed> $employee
     * @return list<string>
     */
    private function hints(array $employee): array
    {
        if (($employee['user_id'] ?? null) === null) {
            return [];
        }
        if (!(int) $employee['active'] && (int) $employee['user_active']) {
            return ['Der Mitarbeiter ist inaktiv, das verknüpfte Benutzerkonto „' . $employee['username'] . '“ ist weiterhin aktiv und kann sich anmelden. Deaktivierung bei Bedarf unter Einstellungen → Benutzerkonten.'];
        }
        if ((int) $employee['active'] && !(int) $employee['user_active']) {
            return ['Das verknüpfte Benutzerkonto „' . $employee['username'] . '“ ist deaktiviert; der Mitarbeiter kann keine eigenen Zeiten erfassen.'];
        }
        return [];
    }

    private function transaction(callable $operation): void
    {
        if ($this->pdo === null || $this->pdo->inTransaction()) {
            $operation();
            return;
        }
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $operation();
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
