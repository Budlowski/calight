<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\DailyReportService;
use App\Application\MaterialRequestService;
use App\Application\OfferService;
use App\Application\OrderService;
use App\Domain\Calculation\PriceCalculator;
use App\Domain\DailyReportValidationException;
use App\Domain\Offer\OfferCalculator;
use App\Domain\Offer\OfferValidator;
use App\Http\AccessDeniedException;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Persistence\AttachmentStore;
use App\Infrastructure\Persistence\DailyReportRepository;
use App\Infrastructure\Persistence\EmployeeRepository;
use App\Infrastructure\Persistence\MaterialRequestRepository;
use App\Infrastructure\Persistence\NumberRangeRepository;
use App\Infrastructure\Persistence\OfferJsonStore;
use App\Infrastructure\Persistence\OfferRepository;
use App\Infrastructure\Persistence\OrderRepository;
use App\Infrastructure\Persistence\ProjectRepository;
use App\Security\AuthorizationPolicy;
use App\Http\Request;
use App\Support\Uuid;
use App\Tests\TestCase;
use PDO;

/** Phase 3.2.2: Materialanforderungen und Anhänge zu Bautagesberichten. */
final class MaterialRequestAndAttachmentTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public function testRoutesSeparateMaterialManagementFromReporting(): void
    {
        $status = new Request('PATCH', '/api/material-requests/id/status', [], [], [], '');
        $create = new Request('POST', '/api/material-requests', [], [], [], '');
        $upload = new Request('POST', '/api/daily-reports/id/attachments', [], [], [], '');
        $download = new Request('GET', '/api/daily-reports/id/attachments/a', [], [], [], '');
        $this->assertSame(['reports.read', 'materials.manage'], AuthorizationPolicy::required($status));
        $this->assertSame(['reports.write'], AuthorizationPolicy::required($create));
        $this->assertSame(['reports.write'], AuthorizationPolicy::required($upload));
        $this->assertSame(['reports.read'], AuthorizationPolicy::required($download));
        $this->assertTrue(\App\Security\RolePermissions::allows('office', 'materials.manage'));
        $this->assertTrue(!\App\Security\RolePermissions::allows('technician', 'materials.manage'));
    }

    public function testMaterialRequestsLifecyclePermissionsAndIndependenceFromReport(): void
    {
        $this->withEnvironment(function (array $env): void {
            [$materials, $reports, $orders, $office, $technician, $otherTechnician, $customer] = $env;
            $released = $orders->createRegie(['customerId' => $customer, 'title' => 'Freigegeben', 'orderDate' => '2026-11-10']);
            $orders->changeStatus($released['id'], 'open', 'in_progress');
            $open = $orders->createRegie(['customerId' => $customer, 'title' => 'Noch offen', 'orderDate' => '2026-11-10']);
            $report = $reports->create(['orderId' => $released['id'], 'reportDate' => '2026-11-10', 'activities' => 'Montage', 'entries' => []], $technician);

            $request = $materials->create(['dailyReportId' => $report['id'], 'description' => "50 m NYM-J 3x1,5\n10 Schalterdosen", 'neededBy' => '2026-11-12'], $technician);
            $this->assertSame($released['id'], $request['orderId'], 'Aus dem Bericht wird der Auftrag übernommen.');
            $this->assertSame('open', $request['status']);
            $this->assertTrue($request['canEdit'] && !$request['canManage']);

            $this->assertThrows(AccessDeniedException::class, fn () => $materials->create(['orderId' => $open['id'], 'description' => 'Kabel'], $technician));
            $this->assertThrows(DailyReportValidationException::class, fn () => $materials->create(['orderId' => $released['id'], 'description' => '  '], $office));
            $this->assertThrows(DailyReportValidationException::class, fn () => $materials->create(['orderId' => $released['id'], 'description' => 'Kabel', 'neededBy' => '2026-02-30'], $office));
            $this->assertThrows(DailyReportValidationException::class, fn () => $materials->create(['orderId' => $open['id'], 'dailyReportId' => $report['id'], 'description' => 'Kabel'], $office));

            // Fremde Anforderungen darf ein Monteur weder ändern noch erledigen.
            $this->assertThrows(AccessDeniedException::class, fn () => $materials->update($request['id'], ['version' => 1, 'description' => 'x'], $otherTechnician));
            $this->assertThrows(AccessDeniedException::class, fn () => $materials->setStatus($request['id'], ['version' => 1, 'status' => 'done'], $technician));

            $updated = $materials->update($request['id'], ['version' => 1, 'description' => '60 m NYM-J 3x1,5', 'notes' => 'Bitte bis Mittag'], $technician);
            $this->assertSame(2, $updated['version']);
            $this->assertThrows(DailyReportValidationException::class, fn () => $materials->update($request['id'], ['version' => 1, 'description' => 'veraltet'], $technician));

            // Speichern des Berichts lässt die Anforderung unverändert.
            $reportDetail = $reports->detail($report['id'], $technician);
            $reports->update($report['id'], ['version' => $reportDetail['version'], 'reportDate' => '2026-11-10', 'activities' => 'Montage fertig', 'entries' => []], $technician);
            $this->assertSame('60 m NYM-J 3x1,5', $materials->detail($request['id'], $office)['description']);

            $done = $materials->setStatus($request['id'], ['version' => 2, 'status' => 'done'], $office);
            $this->assertSame('done', $done['status']);
            $this->assertTrue($done['completedAt'] !== null && $done['completedByName'] === 'Büro');
            $this->assertTrue(!$done['canEdit'], 'Erledigte Anforderungen sind schreibgeschützt.');
            $this->assertThrows(DailyReportValidationException::class, fn () => $materials->update($request['id'], ['version' => 3, 'description' => 'x'], $office));
            $this->assertThrows(DailyReportValidationException::class, fn () => $materials->delete($request['id'], 3, $office));

            $this->assertSame(0, $materials->page([], $office)['total'], 'Standardansicht zeigt nur offene Anforderungen.');
            $this->assertSame(1, $materials->page(['status' => 'done'], $office)['total']);
            $reopened = $materials->setStatus($request['id'], ['version' => 3, 'status' => 'open'], $office);
            $this->assertSame(null, $reopened['completedAt']);
            $this->assertSame(1, $materials->page(['q' => 'nym'], $office)['total']);

            $direct = $materials->create(['orderId' => $open['id'], 'description' => 'Verteiler'], $office);
            $this->assertSame(null, $direct['dailyReportId']);
            $this->assertSame(2, $materials->page(['status' => 'all'], $office)['total']);
            $this->assertSame(1, $materials->page(['status' => 'all'], $technician)['total'], 'Anforderungen offener Aufträge bleiben Monteuren verborgen.');
            $this->assertThrows(AccessDeniedException::class, fn () => $materials->detail($direct['id'], $technician));
            $this->assertSame(1, count($materials->openForOrder($open['id'], $office)));

            // Wird der Bericht gelöscht, bleibt die Anforderung mit Auftragsbezug erhalten.
            $current = $reports->detail($report['id'], $technician);
            $reports->delete($report['id'], (int) $current['version'], $technician);
            $kept = $materials->detail($request['id'], $office);
            $this->assertSame(null, $kept['dailyReportId']);
            $this->assertSame($released['id'], $kept['orderId']);

            $materials->delete($direct['id'], 1, $office);
            $this->assertSame(1, $materials->page(['status' => 'all'], $office)['total']);
        });
    }

    public function testAttachmentsAreTypeCheckedProtectedAndRemovedWithReport(): void
    {
        $this->withEnvironment(function (array $env, string $tmp): void {
            [, $reports, $orders, $office, $technician, $otherTechnician, $customer] = $env;
            $order = $orders->createRegie(['customerId' => $customer, 'title' => 'Fotos', 'orderDate' => '2026-11-10']);
            $orders->changeStatus($order['id'], 'open', 'in_progress');
            $report = $reports->create(['orderId' => $order['id'], 'reportDate' => '2026-11-10', 'activities' => 'Montage', 'entries' => []], $technician);

            $photo = $this->file($tmp, base64_decode(self::PNG));
            $attachment = $reports->addAttachment($report['id'], $photo, '../../Baustelle "Nord".jpg', $technician);
            $this->assertSame('image/png', $attachment['mimeType'], 'Der Typ wird am Inhalt erkannt.');
            $this->assertSame('Baustelle Nord.png', $attachment['name'], 'Pfadanteile und Anführungszeichen werden entfernt, die Endung passt zum Inhalt.');
            $this->assertTrue($attachment['preview']);
            $this->assertTrue(!is_file($photo), 'Die Upload-Datei wurde in die Ablage übernommen.');

            $file = $reports->attachmentFile($report['id'], $attachment['id'], $office);
            $this->assertTrue(is_file($file['path']) && str_starts_with($file['path'], $tmp . '/documents/daily-reports/'));
            $this->assertSame(base64_decode(self::PNG), file_get_contents($file['path']));
            $this->assertSame(1, count($reports->detail($report['id'], $office)['attachments']));

            $this->assertThrows(DailyReportValidationException::class, fn () => $reports->addAttachment($report['id'], $this->file($tmp, "PK\x03\x04" . str_repeat("\0", 60)), 'archiv.zip', $technician));
            $this->assertThrows(DailyReportValidationException::class, fn () => $reports->addAttachment($report['id'], $this->file($tmp, ''), 'leer.jpg', $technician));
            $this->assertThrows(AccessDeniedException::class, fn () => $reports->addAttachment($report['id'], $this->file($tmp, base64_decode(self::PNG)), 'fremd.png', $otherTechnician));
            $this->assertThrows(AccessDeniedException::class, fn () => $reports->deleteAttachment($report['id'], $attachment['id'], $otherTechnician));
            $this->assertThrows(DailyReportValidationException::class, fn () => $reports->attachmentFile($report['id'], Uuid::v4(), $office));

            $pdf = $reports->addAttachment($report['id'], $this->file($tmp, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"), 'Plan.pdf', $office);
            $this->assertTrue(!$pdf['preview'] && !$reports->attachmentFile($report['id'], $pdf['id'], $office)['inline'], 'PDFs werden als Download ausgeliefert.');
            $reports->deleteAttachment($report['id'], $pdf['id'], $office);
            $this->assertSame(1, count($reports->detail($report['id'], $office)['attachments']));

            $current = $reports->detail($report['id'], $technician);
            $reports->delete($report['id'], (int) $current['version'], $technician);
            $this->assertTrue(!is_file($file['path']), 'Dateien werden mit dem Bericht entfernt.');
        });
    }

    /** @param callable(array,string):void $test */
    private function withEnvironment(callable $test): void
    {
        $tmp = sys_get_temp_dir() . '/calight-material-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0770, true);
        try {
            $pdo = ConnectionFactory::create($tmp . '/db.sqlite');
            (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
            $now = '2026-11-10T08:00:00Z';
            $customer = Uuid::v4();
            $pdo->prepare("INSERT INTO customers(id,customer_number,customer_type,company_name,created_at,updated_at) VALUES(:id,'K1','company','Kunde',:now,:now)")
                ->execute(['id' => $customer, 'now' => $now]);
            $office = $this->user($pdo, 'office', 'Büro', $now);
            $technician = $this->user($pdo, 'technician', 'Monteur', $now);
            $otherTechnician = $this->user($pdo, 'technician', 'Kollege', $now);

            $offers = new OfferRepository($pdo);
            $validator = new OfferValidator();
            $numbers = new NumberRangeRepository($pdo);
            $offerService = new OfferService($pdo, new ProjectRepository($pdo), $offers, new OfferJsonStore($tmp . '/offers', $validator), new OfferCalculator(new PriceCalculator()), $numbers);
            $orders = new OrderService($pdo, new OrderRepository($pdo), $offers, $offerService, $numbers);
            $reportRepository = new DailyReportRepository($pdo);
            $reports = new DailyReportService($pdo, $reportRepository, new EmployeeRepository($pdo), new OrderRepository($pdo), new AttachmentStore($tmp . '/documents'));
            $materials = new MaterialRequestService(new MaterialRequestRepository($pdo), new OrderRepository($pdo), $reportRepository);

            $test([$materials, $reports, $orders, $office, $technician, $otherTechnician, $customer], $tmp);
        } finally {
            $this->remove($tmp);
        }
    }

    /** @return array{id:string,role:string} */
    private function user(PDO $pdo, string $role, string $name, string $now): array
    {
        $id = Uuid::v4();
        $pdo->prepare('INSERT INTO users(id,username,password_hash,display_name,role,active,created_at,updated_at) VALUES(:id,:username,\'hash\',:name,:role,1,:now,:now)')
            ->execute(['id' => $id, 'username' => $name . '-' . substr($id, 0, 8), 'name' => $name, 'role' => $role, 'now' => $now]);
        return ['id' => $id, 'role' => $role];
    }

    private function file(string $tmp, string $content): string
    {
        $path = $tmp . '/upload-' . bin2hex(random_bytes(6));
        file_put_contents($path, $content);
        return $path;
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
