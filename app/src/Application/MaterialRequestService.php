<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\DailyReportValidationException;
use App\Http\AccessDeniedException;
use App\Infrastructure\Persistence\DailyReportRepository;
use App\Infrastructure\Persistence\MaterialRequestRepository;
use App\Infrastructure\Persistence\OrderRepository;
use App\Security\RolePermissions;
use App\Support\Clock;
use App\Support\ListQuery;
use App\Support\Uuid;

/**
 * Materialanforderungen: vom Monteur gemeldeter Materialbedarf zu einem Auftrag.
 *
 * Organisatorische Aufgabe für die Beschaffung, keine Bestellung und keine
 * Ist-Kosten. Bearbeitung erfolgt unabhängig vom Bautagesbericht.
 */
final readonly class MaterialRequestService
{
    private const MAX_DESCRIPTION = 5000;
    private const MAX_NOTES = 2000;

    public function __construct(
        private MaterialRequestRepository $requests,
        private OrderRepository $orders,
        private DailyReportRepository $reports,
    ) {
    }

    /**
     * @param array<string,mixed> $parameters URL-Parameter (q, status, order, sort, dir, page, per_page)
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function page(array $parameters, array $actor): array
    {
        $query = ListQuery::fromArray($parameters, MaterialRequestRepository::LIST_SORTS, 'created', MaterialRequestRepository::LIST_FILTERS);
        $page = $this->requests->page($query, $this->isTechnician($actor));
        $page['items'] = array_map(fn (array $row): array => $this->map($row, $actor), $page['items']);
        return $page;
    }

    /** @param array<string,mixed> $actor @return list<array<string,mixed>> */
    public function forReport(string $reportId, array $actor): array
    {
        return array_map(fn (array $row): array => $this->map($row, $actor), $this->requests->forReport($reportId));
    }

    /** @param array<string,mixed> $actor @return list<array<string,mixed>> */
    public function openForOrder(string $orderId, array $actor): array
    {
        return array_map(fn (array $row): array => $this->map($row, $actor), $this->requests->openForOrder($orderId));
    }

    /**
     * @param array<string,mixed> $input orderId, dailyReportId (optional), description, notes, neededBy
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function create(array $input, array $actor): array
    {
        $orderId = trim((string) ($input['orderId'] ?? ''));
        $reportId = trim((string) ($input['dailyReportId'] ?? ''));
        if ($reportId !== '') {
            // Aus dem Bericht heraus gilt dessen Auftrag; ein abweichender Auftrag wäre inkonsistent.
            $report = $this->reports->find($reportId);
            if ($report === null) {
                throw new DailyReportValidationException(['Bautagesbericht wurde nicht gefunden.']);
            }
            if ($orderId !== '' && $orderId !== (string) $report['order_id']) {
                throw new DailyReportValidationException(['Der Bautagesbericht gehört nicht zum angegebenen Auftrag.']);
            }
            $orderId = (string) $report['order_id'];
        }
        $order = $this->orders->find($orderId);
        if ($order === null) {
            throw new DailyReportValidationException(['Auftrag wurde nicht gefunden.']);
        }
        $this->assertOrderAccess((string) $order['status'], $actor);
        $values = $this->validate($input);
        $id = Uuid::v4();
        $now = Clock::now();
        $this->requests->insert([
            'id' => $id,
            'order_id' => $orderId,
            'daily_report_id' => $reportId !== '' ? $reportId : null,
            'description' => $values['description'],
            'notes' => $values['notes'],
            'needed_by' => $values['neededBy'],
            'created_by_user_id' => (string) $actor['id'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $this->detail($id, $actor);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function update(string $id, array $input, array $actor): array
    {
        $current = $this->findAccessible($id, $actor);
        if (!$this->canEdit($current, $actor)) {
            throw new AccessDeniedException('reports.write.own');
        }
        if ($current['status'] !== 'open') {
            throw new DailyReportValidationException(['Erledigte Materialanforderungen können nicht mehr geändert werden. Bitte zuerst wieder öffnen.']);
        }
        $values = $this->validate($input);
        if (!$this->requests->update($id, $this->version($input), $values['description'], $values['notes'], $values['neededBy'], Clock::now())) {
            throw new DailyReportValidationException(['Die Materialanforderung wurde zwischenzeitlich geändert. Bitte neu laden.']);
        }
        return $this->detail($id, $actor);
    }

    /** Erledigen bzw. wieder öffnen; nur mit materials.manage (Büro/Admin). */
    public function setStatus(string $id, array $input, array $actor): array
    {
        if (!$this->canManage($actor)) {
            throw new AccessDeniedException('materials.manage');
        }
        $this->findAccessible($id, $actor);
        $status = (string) ($input['status'] ?? '');
        if (!in_array($status, ['open', 'done'], true)) {
            throw new DailyReportValidationException(['Ungültiger Status der Materialanforderung.']);
        }
        if (!$this->requests->setStatus($id, $this->version($input), $status, (string) $actor['id'], Clock::now())) {
            throw new DailyReportValidationException(['Die Materialanforderung wurde zwischenzeitlich geändert. Bitte neu laden.']);
        }
        return $this->detail($id, $actor);
    }

    /** @param array<string,mixed> $actor */
    public function delete(string $id, int $version, array $actor): void
    {
        $current = $this->findAccessible($id, $actor);
        if (!$this->canEdit($current, $actor)) {
            throw new AccessDeniedException('reports.write.own');
        }
        if ($current['status'] !== 'open') {
            throw new DailyReportValidationException(['Erledigte Materialanforderungen bleiben zur Nachvollziehbarkeit erhalten.']);
        }
        if (!$this->requests->deleteOpen($id, $version)) {
            throw new DailyReportValidationException(['Die Materialanforderung wurde zwischenzeitlich geändert. Bitte neu laden.']);
        }
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function detail(string $id, array $actor): array
    {
        return $this->map($this->findAccessible($id, $actor), $actor);
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    private function findAccessible(string $id, array $actor): array
    {
        $row = $this->requests->find($id);
        if ($row === null) {
            throw new DailyReportValidationException(['Materialanforderung wurde nicht gefunden.']);
        }
        $this->assertOrderAccess((string) $row['order_status'], $actor);
        return $row;
    }

    /** @param array<string,mixed> $input @return array{description:string,notes:string,neededBy:?string} */
    private function validate(array $input): array
    {
        $description = trim(str_replace("\r\n", "\n", (string) ($input['description'] ?? '')));
        $notes = trim((string) ($input['notes'] ?? ''));
        $neededBy = trim((string) ($input['neededBy'] ?? ''));
        $errors = [];
        if ($description === '') {
            $errors[] = 'Bitte das benötigte Material angeben.';
        } elseif (mb_strlen($description) > self::MAX_DESCRIPTION) {
            $errors[] = 'Die Materialangabe darf höchstens 5.000 Zeichen lang sein.';
        }
        if (mb_strlen($notes) > self::MAX_NOTES) {
            $errors[] = 'Die Bemerkung darf höchstens 2.000 Zeichen lang sein.';
        }
        if ($neededBy !== '' && (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $neededBy) !== 1
            || !checkdate((int) substr($neededBy, 5, 2), (int) substr($neededBy, 8, 2), (int) substr($neededBy, 0, 4)))) {
            $errors[] = 'Der gewünschte Liefertermin ist ungültig.';
        }
        if ($errors !== []) {
            throw new DailyReportValidationException($errors);
        }
        return ['description' => $description, 'notes' => $notes, 'neededBy' => $neededBy !== '' ? $neededBy : null];
    }

    /** @param array<string,mixed> $input */
    private function version(array $input): int
    {
        $version = (int) ($input['version'] ?? 0);
        if ($version < 1) {
            throw new DailyReportValidationException(['Versionsangabe fehlt.']);
        }
        return $version;
    }

    /** @param array<string,mixed> $actor */
    private function assertOrderAccess(string $orderStatus, array $actor): void
    {
        if ($this->isTechnician($actor) && $orderStatus === 'open') {
            throw new AccessDeniedException('orders.released.read');
        }
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $actor */
    private function canEdit(array $row, array $actor): bool
    {
        return $this->canManage($actor) || (string) $row['created_by_user_id'] === (string) ($actor['id'] ?? '');
    }

    /** @param array<string,mixed> $actor */
    private function canManage(array $actor): bool
    {
        return RolePermissions::allows((string) ($actor['role'] ?? ''), 'materials.manage');
    }

    /** @param array<string,mixed> $actor */
    private function isTechnician(array $actor): bool
    {
        return ($actor['role'] ?? '') === 'technician';
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $actor @return array<string,mixed> */
    private function map(array $row, array $actor): array
    {
        $open = $row['status'] === 'open';
        return [
            'id' => $row['id'],
            'orderId' => $row['order_id'],
            'orderNumber' => $row['order_number'],
            'orderTitle' => $row['order_title'],
            'customerName' => $row['customer_name'],
            'dailyReportId' => $row['daily_report_id'],
            'reportDate' => $row['report_date'],
            'description' => $row['description'],
            'notes' => $row['notes'],
            'neededBy' => $row['needed_by'],
            'status' => $row['status'],
            'creatorName' => $row['creator_name'],
            'completedByName' => $row['completed_by_name'],
            'completedAt' => $row['completed_at'],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
            'version' => (int) $row['version'],
            'canEdit' => $open && $this->canEdit($row, $actor),
            'canManage' => $this->canManage($actor),
        ];
    }
}
