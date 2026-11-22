<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\DailyReportService;
use App\Application\OperationalSettingsService;
use App\Application\TimeApprovalService;
use App\Application\TimeTrackingService;
use App\Domain\Document\DocumentValidationException;
use App\Http\AccessDeniedException;
use App\Http\Request;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Persistence\AttachmentStore;
use App\Infrastructure\Persistence\DailyReportRepository;
use App\Infrastructure\Persistence\DocumentSettingsRepository;
use App\Infrastructure\Persistence\EmployeeRepository;
use App\Infrastructure\Persistence\OrderRepository;
use App\Infrastructure\Persistence\TimeApprovalRepository;
use App\Infrastructure\Persistence\TimeTrackingRepository;
use App\Security\AuthorizationPolicy;
use App\Security\ConfidentialDataFilter;
use App\Security\RolePermissions;
use App\Support\Uuid;
use App\Tests\TestCase;
use PDO;

/**
 * Monteurs-Frontend Phase 2: persönliche Zeiterfassung über die bestehenden Dienste
 * (eigener Mitarbeiter, Berichtsstunden, Abwesenheiten, Reisekosten, Einreichung, Sperren)
 * sowie Schutz verkaufsseitiger Werte für Monteure.
 */
final class MobileTimeTrackingTest extends TestCase
{
    public function testOfficeAndAdminRecordOwnTimesOnlyWithLinkedEmployee(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'office' => $office, 'admin' => $admin, 'pdo' => $pdo] = $env;
            $officeEmployee = $this->employee($pdo, $office['id'], 'Olga Office');
            $this->assertSame(['id' => $officeEmployee, 'name' => 'Olga Office', 'active' => true], $service->ownEmployee($office));
            $day = $service->createEntry(['date' => '2026-11-16', 'start' => '08:00', 'end' => '12:00'], $office);
            $this->assertSame([$officeEmployee, 240], [$day['employee']['id'], $day['totals']['netMinutes']], 'Büro erfasst eigene Zeit ohne Mitarbeiterauswahl.');

            // Keine Zuordnung anhand des Namens: ein gleichnamiger Mitarbeiter ohne Benutzerkonto zählt nicht.
            $this->employee($pdo, null, 'Admin');
            $this->assertSame(null, $service->ownEmployee($admin));
            $this->assertThrows(DocumentValidationException::class, fn () => $service->createEntry(['date' => '2026-11-16', 'start' => '08:00', 'end' => '09:00'], $admin));
            $this->employee($pdo, $admin['id'], 'Andrea Admin');
            $this->assertSame(60, $service->createEntry(['date' => '2026-11-16', 'start' => '08:00', 'end' => '09:00'], $admin)['totals']['netMinutes']);
        });
    }

    public function testReportHoursKeepTheirDurationAndOnlyTimesAreCompleted(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'tech' => $tech, 'employee' => $employee, 'customer' => $customer, 'reports' => $reports] = $env;
            $reports->create(['customerId' => $customer, 'reportDate' => '2026-11-17', 'activities' => 'Montage',
                'entries' => [['employeeId' => $employee, 'durationHours' => '4', 'targetKey' => 'order']]], $tech);
            $booking = $service->day(null, '2026-11-17', $tech)['entries'][0];
            $this->assertSame([true, false, true], [$booking['durationFixed'], $booking['canDelete'], $booking['canEditTimes']]);
            $this->assertThrows(DocumentValidationException::class, fn () => $service->updateEntry($booking['id'], ['version' => $booking['version'], 'start' => '07:00', 'end' => '12:00'], $tech));
            $this->assertThrows(DocumentValidationException::class, fn () => $service->deleteEntry($booking['id'], ['version' => $booking['version']], $tech));
            // Mobil wird bei Berichtsbuchungen keine Dauer gesendet; eine untergeschobene Dauer bleibt wirkungslos.
            $day = $service->updateEntry($booking['id'], ['version' => $booking['version'], 'start' => '07:00', 'end' => '11:30', 'breakMinutes' => '30', 'durationMinutes' => '600',
                'travelMinutes' => '30', 'assignmentKind' => 'external'], $tech);
            $this->assertSame([240, '07:00', 30], [$day['entries'][0]['netMinutes'], $day['entries'][0]['start'], $day['entries'][0]['travelMinutes']]);
        });
    }

    public function testAbsencesTravelExpensesAndSubmissionLockTheWeek(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'tech' => $tech, 'otherTech' => $otherTech, 'order' => $order, 'approvalsAt' => $approvalsAt, 'pdo' => $pdo] = $env;
            $service->createEntry(['date' => '2026-11-16', 'start' => '07:00', 'end' => '15:30', 'breakMinutes' => '30', 'orderId' => $order], $tech);
            $day = $service->saveAbsence(['date' => '2026-11-17', 'absenceType' => 'vacation', 'fullDay' => true], $tech);
            $this->assertSame(['Urlaub', true, 0], [$day['absences'][0]['label'], $day['absences'][0]['fullDay'], $day['totals']['netMinutes']], 'Abwesenheit ist keine Arbeitszeit.');
            $partial = $service->saveAbsence(['date' => '2026-11-18', 'absenceType' => 'sick', 'fullDay' => false, 'hours' => '4:30'], $tech)['absences'][0];
            $this->assertSame(270, $partial['minutes']);
            $this->assertSame('compensatory', $service->saveAbsence(['id' => $partial['id'], 'version' => $partial['version'], 'date' => '2026-11-18', 'absenceType' => 'compensatory', 'hours' => '2'], $tech)['absences'][0]['type']);
            $this->assertThrows(AccessDeniedException::class, fn () => $service->saveAbsence(['id' => $partial['id'], 'version' => $partial['version'] + 1, 'absenceType' => 'sick', 'fullDay' => true], $otherTech));

            $travel = $service->saveDetails(['date' => '2026-11-16', 'travelCategory' => 'single_long', 'mealLunch' => true, 'location' => 'Hamburg'], $tech)['travel'];
            $this->assertSame(['single_long', true], [$travel['category'], $travel['meals']['lunch']]);
            $trips = $service->saveTrip(['date' => '2026-11-16', 'kilometers' => '42.5', 'purpose' => 'Baustelle', 'orderId' => $order], $tech)['trips'];
            $this->assertSame('42.5', (string) $trips[0]['kilometers']);
            $this->assertThrows(DocumentValidationException::class, fn () => $service->saveTrip(['date' => '2026-11-16', 'kilometers' => '0', 'purpose' => 'x'], $tech));

            $approvals = $approvalsAt('2026-11-22');
            $period = $approvals->period(null, 'week', '2026-11-16', $tech);
            $this->assertSame(true, $period['canSubmit']);
            $this->assertThrows(DocumentValidationException::class, fn () => $approvals->submit(['type' => 'week', 'date' => '2026-11-16'], $tech), 'Vollständigkeit muss bestätigt werden.');
            $this->assertSame('submitted', $approvals->submit(['type' => 'week', 'date' => '2026-11-16', 'confirmComplete' => true], $tech)['status']);

            // Eingereichte Tage sind für alle Erfassungsarten gesperrt und nur lesend.
            $locked = $service->day(null, '2026-11-16', $tech);
            $this->assertSame([false, 'submitted', false], [$locked['canEdit'], $locked['lockStatus'], $locked['entries'][0]['canEditTimes']]);
            $this->assertThrows(DocumentValidationException::class, fn () => $service->createEntry(['date' => '2026-11-16', 'start' => '16:00', 'end' => '17:00'], $tech));
            $this->assertThrows(DocumentValidationException::class, fn () => $service->saveAbsence(['date' => '2026-11-19', 'absenceType' => 'vacation', 'fullDay' => true], $tech));
            $this->assertThrows(DocumentValidationException::class, fn () => $service->saveDetails(['date' => '2026-11-16', 'travelCategory' => 'none'], $tech));
            $this->assertThrows(DocumentValidationException::class, fn () => $service->addExpense(['date' => '2026-11-16', 'amount' => '5', 'purpose' => 'Parken'], null, $tech));
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM time_submissions')->fetchColumn());
        });
    }

    public function testTechniciansGetNoSalesValues(): void
    {
        $this->assertTrue(!RolePermissions::allows('technician', 'commercial.read'));
        $this->assertTrue(RolePermissions::allows('office', 'commercial.read') && RolePermissions::allows('admin', 'commercial.read'));
        $this->assertSame(['measurements.read', 'commercial.read'], AuthorizationPolicy::required(new Request('GET', '/api/measurements/x/pdf', [], [], [], '')));
        $this->assertSame(['measurements.read'], AuthorizationPolicy::required(new Request('GET', '/api/measurements/x', [], [], [], '')), 'Mengen bleiben lesbar.');

        $measurement = [
            'id' => 'm1', 'measurementNumber' => 'AM-1', 'canComplete' => false, 'netTotal' => '125.00', 'title' => 'Teilaufmaß',
            'sources' => [['label' => 'Grundlage', 'adjustment' => ['subtotal' => '100.00', 'signed' => '-10.00', 'label' => 'Nachlass'],
                'nodes' => [['kind' => 'position', 'shortText' => 'Kabel', 'unit' => 'm', 'agreedQuantity' => '100.000', 'unitPrice' => '2.50', 'quantity' => '10.000']]]],
            'orphanItems' => [['shortText' => 'Alt', 'quantity' => '1.000', 'lineTotal' => '9.00']],
            'totals' => [['label' => 'Grundlage', 'itemsTotal' => '25.00', 'netTotal' => '25.00']],
            'progress' => ['positions' => 1, 'started' => 1],
        ];
        $visible = ConfidentialDataFilter::salesValues($measurement);
        $json = json_encode($visible, JSON_THROW_ON_ERROR);
        foreach (['125.00', '2.50', '9.00', '25.00', 'Nachlass', '-10.00'] as $value) {
            $this->assertTrue(!str_contains($json, $value), 'Verkaufswert sichtbar: ' . $value);
        }
        $this->assertSame([false, [], 'Kabel', '100.000', '10.000', 1], [$visible['commercial'], $visible['totals'], $visible['sources'][0]['nodes'][0]['shortText'],
            $visible['sources'][0]['nodes'][0]['agreedQuantity'], $visible['sources'][0]['nodes'][0]['quantity'], $visible['progress']['started']]);

        $order = ConfidentialDataFilter::salesValues(['items' => [['orderNumber' => 'AU-1', 'title' => 'Montage', 'contractNetTotal' => '1000.00']], 'total' => 1, 'pageCount' => 1]);
        $this->assertSame([null, 'Montage', 1], [$order['items'][0]['contractNetTotal'], $order['items'][0]['title'], $order['total']], 'Seitenzähler bleiben unverändert.');
        $detail = ConfidentialDataFilter::salesValues(['originalNetTotal' => '1.00', 'currentContractNetTotal' => '2.00', 'acceptedAddendaNetTotal' => '1.00',
            'basis' => ['nodes' => []], 'changeOrders' => [['title' => 'NA', 'netTotal' => '3.00', 'acceptedNetTotal' => '3.00']]]);
        $this->assertSame([null, null, null, null, null, 'NA'], [$detail['originalNetTotal'], $detail['currentContractNetTotal'], $detail['basis'],
            $detail['changeOrders'][0]['netTotal'], $detail['changeOrders'][0]['acceptedNetTotal'], $detail['changeOrders'][0]['title']]);
    }

    /** @param callable(array<string,mixed>):void $test */
    private function withEnvironment(callable $test): void
    {
        $tmp = sys_get_temp_dir() . '/calight-mobile-times-' . bin2hex(random_bytes(6));
        mkdir($tmp . '/documents', 0770, true);
        try {
            $pdo = ConnectionFactory::create($tmp . '/db.sqlite');
            (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate(); self::useReleasedOrderVisibility($pdo);
            $now = '2026-11-01T08:00:00Z';
            $customer = Uuid::v4();
            $order = Uuid::v4();
            $pdo->prepare("INSERT INTO customers(id,customer_number,customer_type,company_name,created_at,updated_at) VALUES(:id,'K1','company','Kunde',:now,:now)")
                ->execute(['id' => $customer, 'now' => $now]);
            $pdo->prepare("INSERT INTO orders(id,order_number,customer_id,order_type,title,description,order_date,status,created_at,updated_at)
                VALUES(:id,'AU-1',:customer,'regie','Montage','','2026-11-01','in_progress',:now,:now)")
                ->execute(['id' => $order, 'customer' => $customer, 'now' => $now]);
            $tech = $this->user($pdo, 'technician');
            $otherTech = $this->user($pdo, 'technician');
            $store = new AttachmentStore($tmp . '/documents');
            $service = new TimeTrackingService(new TimeTrackingRepository($pdo), $store);
            $reports = new DailyReportService($pdo, new DailyReportRepository($pdo), new EmployeeRepository($pdo), new OrderRepository($pdo));
            $settings = new OperationalSettingsService($pdo);
            $this->employee($pdo, $otherTech['id'], 'Eva Elektrikerin');
            $test([
                'pdo' => $pdo, 'service' => $service, 'reports' => $reports, 'customer' => $customer, 'order' => $order,
                'tech' => $tech, 'otherTech' => $otherTech, 'office' => $this->user($pdo, 'office'), 'admin' => $this->user($pdo, 'admin'),
                'employee' => $this->employee($pdo, $tech['id'], 'Max Monteur'),
                'approvalsAt' => fn (string $today): TimeApprovalService => new TimeApprovalService(new TimeApprovalRepository($pdo), new TimeTrackingRepository($pdo), $service, $settings,
                    new DocumentSettingsRepository($pdo), $store, $today, $reports),
            ]);
        } finally {
            $this->remove($tmp);
        }
    }

    private function employee(PDO $pdo, ?string $userId, string $name): string
    {
        $id = Uuid::v4();
        $pdo->prepare("INSERT INTO employees (id, user_id, display_name, hourly_cost_rate, active, created_at, updated_at) VALUES (:id, :user, :name, '42.0000', 1, '2026-11-01T08:00:00Z', '2026-11-01T08:00:00Z')")
            ->execute(['id' => $id, 'user' => $userId, 'name' => $name]);
        return $id;
    }

    /** @return array{id:string,role:string} */
    private function user(PDO $pdo, string $role): array
    {
        $id = Uuid::v4();
        $pdo->prepare("INSERT INTO users(id,username,password_hash,display_name,role,active,created_at,updated_at) VALUES(:id,:username,'hash',:name,:role,1,'2026-11-01T08:00:00Z','2026-11-01T08:00:00Z')")
            ->execute(['id' => $id, 'username' => $role . '-' . substr($id, 0, 8), 'name' => ucfirst($role), 'role' => $role]);
        return ['id' => $id, 'role' => $role];
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . '/' . $item;
            is_dir($child) ? $this->remove($child) : unlink($child);
        }
        rmdir($path);
    }
}
