<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\DailyReportPdfService;
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
use App\Infrastructure\Persistence\DocumentSettingsRepository;
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

    public function testDailyReportPdfEmbedsPhotosWithoutCostsAndMaterialRequests(): void
    {
        $this->withEnvironment(function (array $env, string $tmp): void {
            [$materials, $reports, $orders, $office, $technician, , $customer, $pdo] = $env;
            $order = $orders->createRegie(['customerId' => $customer, 'title' => 'Fotodoku', 'orderDate' => '2026-11-10']);
            $orders->changeStatus($order['id'], 'open', 'in_progress');
            $report = $reports->create(['orderId' => $order['id'], 'reportDate' => '2026-11-10', 'activities' => 'Kabeltrasse montiert', 'notes' => 'Zugang ueber Hof', 'entries' => []], $technician);
            $photo = $this->file($tmp, (string) base64_decode('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'));
            $reports->addAttachment($report['id'], $photo, 'Trasse.jpg', $technician);
            $reports->addAttachment($report['id'], $this->file($tmp, base64_decode(self::PNG)), 'Skizze.png', $technician);
            $materials->create(['dailyReportId' => $report['id'], 'description' => 'Geheime Materialanforderung'], $technician);

            $document = (new DailyReportPdfService($reports, new DocumentSettingsRepository($pdo)))->pdf($report['id'], $office);
            $pdf = $document['pdf'];
            $this->assertTrue(str_starts_with($pdf, '%PDF-1.4') && str_contains($pdf, 'Kabeltrasse montiert') && str_contains($pdf, 'Zugang ueber Hof'));
            $this->assertSame(1, substr_count($pdf, '/Subtype /Image'), 'JPEG-Fotos werden eingebettet, andere Formate nur aufgeführt.');
            $this->assertTrue(str_contains($pdf, 'Skizze.png'));
            $this->assertTrue(!str_contains($pdf, 'Geheime Materialanforderung'), 'Materialanforderungen sind nicht Teil des Dokuments.');
            $this->assertTrue(!str_contains($pdf, 'ENTWURF') && str_ends_with($document['fileName'], '.pdf'));
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

            $test([$materials, $reports, $orders, $office, $technician, $otherTechnician, $customer, $pdo], $tmp);
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
