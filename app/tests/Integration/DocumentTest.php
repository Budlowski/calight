<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\DocumentService;
use App\Application\OfferPdfService;
use App\Application\OfferService;
use App\Application\OrderService;
use App\Application\PurchaseOrderPdfService;
use App\Application\PurchaseOrderService;
use App\Domain\Calculation\PriceCalculator;
use App\Domain\Document\DocumentValidationException;
use App\Domain\Offer\OfferCalculator;
use App\Domain\Offer\OfferValidator;
use App\Http\AccessDeniedException;
use App\Http\Request;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Persistence\AttachmentRepository;
use App\Infrastructure\Persistence\AttachmentStore;
use App\Infrastructure\Persistence\DocumentRepository;
use App\Infrastructure\Persistence\DocumentSettingsRepository;
use App\Infrastructure\Persistence\NumberRangeRepository;
use App\Infrastructure\Persistence\OfferJsonStore;
use App\Infrastructure\Persistence\OfferRepository;
use App\Infrastructure\Persistence\OrderRepository;
use App\Infrastructure\Persistence\ProjectRepository;
use App\Infrastructure\Persistence\PurchaseOrderRepository;
use App\Security\AuthorizationPolicy;
use App\Security\RolePermissions;
use App\Support\Uuid;
use App\Tests\TestCase;
use PDO;
use RuntimeException;

/** Phase 3.4.2: zentrale Dokumentenübersicht und geschäftsbezogene Anhänge. */
final class DocumentTest extends TestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    public function testPermissionsAndRoutes(): void
    {
        $this->assertTrue(RolePermissions::allows('office', 'documents.read'));
        $this->assertTrue(!RolePermissions::allows('technician', 'documents.read'), 'Techniker sehen Dokumente nur in den Fachmodulen.');
        $policy = static fn (string $method, string $path): array => AuthorizationPolicy::required(new Request($method, $path, [], [], [], ''));
        $this->assertSame(['documents.read'], $policy('GET', '/documents'));
        $this->assertSame(['documents.read'], $policy('GET', '/api/documents'));
        $this->assertSame(['documents.read'], $policy('POST', '/api/attachments'));
        $this->assertSame(['documents.read'], $policy('DELETE', '/api/attachments/x/links/order/y'));
    }

    public function testAttachmentStorePathsStayInsideTheDocumentArea(): void
    {
        $tmp = sys_get_temp_dir() . '/calight-store-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0770, true);
        try {
            $store = new AttachmentStore($tmp);
            $id = Uuid::v4();
            $this->assertTrue(str_ends_with($store->absolute('attachments/2026/11/' . $id . '.pdf'), '/attachments/2026/11/' . $id . '.pdf'));
            foreach (['attachments/2026/13/' . $id . '.pdf', 'attachments/../daily-reports/' . $id . '.pdf', '/etc/passwd', 'attachments/2026/11/x.pdf'] as $bad) {
                $this->assertThrows(RuntimeException::class, fn () => $store->absolute($bad));
            }
            $this->assertThrows(RuntimeException::class, fn () => $store->storeContents('x', 'attachments/2026/00', $id, 'pdf'));
            // Bestehende Ablagen bleiben gültig.
            $this->assertTrue(str_ends_with($store->absolute('daily-reports/' . $id . '/' . $id . '.jpg'), '.jpg'));
        } finally {
            $this->remove($tmp);
        }
    }

    public function testUploadValidatesFileNameTypeSizeAndTargets(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['documents' => $documents, 'office' => $office, 'technician' => $technician, 'customer' => $customer, 'tmp' => $tmp, 'pdo' => $pdo, 'files' => $files] = $env;
            $target = [['type' => 'customer', 'id' => $customer]];

            $upload = $documents->upload($this->pdf($tmp), '../../geheim/Abnahme <Protokoll>.exe.pdf', 'Abnahmeprotokoll', 'Unterschrieben vor Ort', $target, $office);
            $row = $pdo->query('SELECT * FROM attachments WHERE id = ' . $pdo->quote($upload['id']))->fetch();
            $this->assertSame('Abnahme _Protokoll_.exe.pdf', $row['original_name'], 'Sicherer Name ohne Pfadanteile; Endung folgt dem erkannten Typ.');
            $this->assertTrue((bool) preg_match('#^attachments/\d{4}/\d{2}/[a-f0-9-]{36}\.pdf$#', (string) $row['storage_path']), 'Interner Name aus der UUID.');
            $this->assertTrue(is_file($files . '/' . $row['storage_path']));
            $this->assertSame(self::PDF, $documents->file($upload['id'], $office)['contents']);

            $script = $tmp . '/tool.sh';
            file_put_contents($script, "#!/bin/sh\necho hi\n");
            $this->assertThrows(DocumentValidationException::class, fn () => $documents->upload($script, 'tool.pdf', '', '', $target, $office), 'Typ wird am Inhalt erkannt.');
            $large = $tmp . '/large.pdf';
            file_put_contents($large, self::PDF);
            $handle = fopen($large, 'r+');
            ftruncate($handle, DocumentService::MAX_BYTES + 1);
            fclose($handle);
            $this->assertThrows(DocumentValidationException::class, fn () => $documents->upload($large, 'gross.pdf', '', '', $target, $office), 'Maximale Größe 15 MB.');
            $this->assertThrows(DocumentValidationException::class, fn () => $documents->upload($this->pdf($tmp), 'a.pdf', '', '', [], $office), 'Mindestens eine Zuordnung.');
            $this->assertThrows(DocumentValidationException::class, fn () => $documents->upload($this->pdf($tmp), 'a.pdf', '', '', [['type' => 'folder', 'id' => 'x']], $office));
            $this->assertThrows(DocumentValidationException::class, fn () => $documents->upload($this->pdf($tmp), 'a.pdf', '', '', [['type' => 'order', 'id' => Uuid::v4()]], $office), 'Objekt muss existieren.');
            $this->assertThrows(AccessDeniedException::class, fn () => $documents->upload($this->pdf($tmp), 'a.pdf', '', '', $target, $technician));
            $this->assertThrows(AccessDeniedException::class, fn () => $documents->file($upload['id'], $technician));
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM attachments')->fetchColumn(), 'Abgelehnte Uploads hinterlassen keine Datensätze.');
        });
    }

    public function testMultipleLinksShowOnceAndUnlinkKeepsFileUntilLastLink(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['documents' => $documents, 'office' => $office, 'customer' => $customer, 'contact' => $contact, 'project' => $project, 'regie' => $regie, 'second' => $second, 'tmp' => $tmp, 'pdo' => $pdo, 'files' => $files] = $env;
            $upload = $documents->upload($this->pdf($tmp), 'Vertrag.pdf', 'Wartungsvertrag', '', [
                ['type' => 'customer', 'id' => $customer], ['type' => 'contact', 'id' => $contact], ['type' => 'order', 'id' => $regie['id']], ['type' => 'order', 'id' => $regie['id']],
            ], $office);
            $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM attachment_links')->fetchColumn(), 'Doppelte Zuordnungen werden zusammengefasst.');
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM attachments')->fetchColumn(), 'Datei nur einmal gespeichert.');

            $all = $documents->page(['type' => 'attachment'], $office);
            $this->assertSame(1, $all['total'], 'Ein Dokument erscheint trotz mehrerer Zuordnungen nur einmal.');
            $this->assertSame(1, $this->count($documents->page(['customer' => $customer], $office), 'attachment'));
            $this->assertSame(1, $this->count($documents->page(['order' => $regie['id']], $office), 'attachment'), 'Direkt dem Auftrag zugeordnet.');
            $this->assertSame(1, $this->count($documents->page(['contact' => $contact], $office), 'attachment'));
            $this->assertSame(0, $this->count($documents->page(['order' => $second['id']], $office), 'attachment'), 'Nicht beim fremden Auftrag.');
            $this->assertSame(0, $this->count($documents->page(['project' => $project], $office), 'attachment'));
            $this->assertSame(1, $this->count($documents->page(['q' => 'wartungsvertrag'], $office), 'attachment'), 'Suche über Titel.');
            $this->assertSame(1, $this->count($documents->page(['format' => 'pdf', 'period' => '30'], $office), 'attachment'), 'Dateityp und Zeitraum.');
            $this->assertSame(0, $this->count($documents->page(['format' => 'image'], $office), 'attachment'));
            $reference = $documents->referenceData($office);
            $this->assertTrue(in_array($customer, array_column($reference['customers'], 'id'), true) && in_array($regie['id'], array_column($reference['orders'], 'id'), true), 'Auswahllisten für Kunde und Auftrag.');
            $this->assertThrows(AccessDeniedException::class, fn () => $documents->referenceData($env['technician']));

            // Indirekt: Anhang am Projekt erscheint beim Kunden des Projekts.
            $documents->upload($this->pdf($tmp), 'Plan.pdf', 'Projektplan', '', [['type' => 'project', 'id' => $project]], $office);
            $this->assertSame(2, $this->count($documents->page(['customer' => $customer], $office), 'attachment'));

            $path = (string) $pdo->query('SELECT storage_path FROM attachments WHERE id = ' . $pdo->quote($upload['id']))->fetchColumn();
            $this->assertThrows(DocumentValidationException::class, fn () => $documents->unlink($upload['id'], 'order', $second['id'], $office), 'Nicht bestehende Zuordnung.');
            $this->assertSame(false, $documents->unlink($upload['id'], 'order', $regie['id'], $office)['deleted']);
            $this->assertSame(false, $documents->unlink($upload['id'], 'contact', $contact, $office)['deleted']);
            $this->assertTrue(is_file($files . '/' . $path), 'Datei bleibt, solange eine Zuordnung besteht.');
            $this->assertSame(true, $documents->unlink($upload['id'], 'customer', $customer, $office)['deleted']);
            $this->assertTrue(!is_file($files . '/' . $path), 'Mit der letzten Zuordnung wird die Datei gelöscht.');
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM attachments WHERE id = ' . $pdo->quote($upload['id']))->fetchColumn());
        });
    }

    public function testModuleDocumentsAreListedReadOnlyAndArchivesStayUntouched(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['documents' => $documents, 'purchases' => $purchases, 'office' => $office, 'technician' => $technician, 'supplier' => $supplier, 'regie' => $regie, 'customer' => $customer, 'tmp' => $tmp, 'pdo' => $pdo, 'files' => $files] = $env;
            $order = $purchases->createInternal(['supplierId' => $supplier, 'orderId' => $regie['id']], $office);
            $order = $purchases->update($order['id'], ['version' => $order['version'], 'items' => [['description' => 'Kabel', 'quantity' => '10', 'unitPrice' => '2.50']]], $office);
            $order = $purchases->issue($order['id'], ['version' => $order['version']], $office);
            $order = $purchases->addInvoice($order['id'], ['version' => $order['version'], 'kind' => 'invoice', 'invoiceNumber' => 'R-1', 'invoiceDate' => '2026-11-20', 'netAmount' => '25.00'],
                ['path' => $this->pdf($tmp), 'name' => 'Rechnung R-1.pdf'], $office);
            $before = $pdo->query('SELECT storage_path, sha256 FROM purchase_order_documents ORDER BY storage_path')->fetchAll();

            $page = $documents->page(['order' => $regie['id']], $office);
            $types = array_column($page['items'], 'type');
            $this->assertTrue(in_array('purchase_order', $types, true) && in_array('supplier_document', $types, true), 'Bestell-PDF und Lieferantenrechnung beim Auftrag.');
            $this->assertSame(2, $this->count($documents->page(['customer' => $customer], $office), 'purchase_order') + $this->count($documents->page(['customer' => $customer], $office), 'supplier_document'), 'Indirekt beim Kunden des Auftrags.');
            $purchaseRow = array_values(array_filter($page['items'], static fn (array $item): bool => $item['type'] === 'purchase_order'))[0];
            $this->assertTrue(str_starts_with($purchaseRow['url'], '/api/purchase-orders/' . $order['id'] . '/documents/'), 'Download über die Route des Fachmoduls.');
            $this->assertSame(false, $purchaseRow['removable'], 'Fachdokumente sind in der Übersicht nicht löschbar.');
            $this->assertTrue(!array_key_exists('context', $purchaseRow), 'Keine internen Kontextdaten in der Antwort.');
            $this->assertSame($before, $pdo->query('SELECT storage_path, sha256 FROM purchase_order_documents ORDER BY storage_path')->fetchAll(), 'Archiv unverändert.');
            foreach ($before as $document) {
                $this->assertTrue(is_file($files . '/' . $document['storage_path']));
            }
            $this->assertThrows(AccessDeniedException::class, fn () => $documents->page([], $technician), 'Keine zentrale Übersicht ohne documents.read.');
            // Ausgegebene Bestell-PDFs enthalten Preise: Download nur mit Kostenrecht.
            $this->assertTrue(str_starts_with($purchases->documentFile($order['id'], substr($purchaseRow['id'], strlen('purchase:')), $office)['contents'], '%PDF'), 'Mit Kostenrecht abrufbar.');
        });
    }

    public function testMissingFilesDoNotBreakTheOverview(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['documents' => $documents, 'office' => $office, 'customer' => $customer, 'tmp' => $tmp, 'pdo' => $pdo, 'files' => $files] = $env;
            $upload = $documents->upload($this->pdf($tmp), 'Weg.pdf', '', '', [['type' => 'customer', 'id' => $customer]], $office);
            unlink($files . '/' . $pdo->query('SELECT storage_path FROM attachments')->fetchColumn());
            $this->assertSame(1, $this->count($documents->page([], $office), 'attachment'), 'Übersicht funktioniert weiter.');
            $this->assertThrows(DocumentValidationException::class, fn () => $documents->file($upload['id'], $office), 'Verständliche Meldung statt Fehler 500.');
        });
    }

    public function testLinksToDeletedObjectsAreIgnored(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['documents' => $documents, 'office' => $office, 'customer' => $customer, 'tmp' => $tmp, 'pdo' => $pdo] = $env;
            $upload = $documents->upload($this->pdf($tmp), 'X.pdf', '', '', [['type' => 'customer', 'id' => $customer]], $office);
            // Zuordnung auf ein nicht (mehr) vorhandenes Objekt, z. B. gelöschten Angebotsentwurf.
            $pdo->prepare("INSERT INTO attachment_links (attachment_id, target_type, target_id, created_by_user_id, created_at) VALUES (:a, 'offer', :o, :u, '2026-11-20T08:00:00Z')")
                ->execute(['a' => $upload['id'], 'o' => Uuid::v4(), 'u' => $office['id']]);
            $row = array_values(array_filter($documents->page([], $office)['items'], static fn (array $item): bool => $item['type'] === 'attachment'))[0];
            $this->assertSame(1, count($row['objects']), 'Nur vorhandene Objekte werden angezeigt.');
        });
    }

    /** @param array<string,mixed> $page */
    private function count(array $page, string $type): int
    {
        return count(array_filter($page['items'], static fn (array $item): bool => $item['type'] === $type));
    }

    private function pdf(string $tmp): string
    {
        $path = $tmp . '/' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($path, self::PDF);
        return $path;
    }

    /** @param callable(array<string,mixed>):void $test */
    private function withEnvironment(callable $test): void
    {
        $tmp = sys_get_temp_dir() . '/calight-documents-' . bin2hex(random_bytes(6));
        mkdir($tmp . '/documents', 0770, true);
        try {
            $pdo = ConnectionFactory::create($tmp . '/db.sqlite');
            (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate(); self::useReleasedOrderVisibility($pdo);
            $now = '2026-11-12T08:00:00Z';
            $customer = Uuid::v4();
            $pdo->prepare("INSERT INTO customers(id,customer_number,customer_type,company_name,created_at,updated_at) VALUES(:id,'K1','company','Matzko-Mack',:now,:now)")->execute(['id' => $customer, 'now' => $now]);
            $contact = Uuid::v4();
            $pdo->prepare("INSERT INTO customer_contacts(id,customer_id,first_name,last_name,sort_order,created_at,updated_at) VALUES(:id,:customer,'Eva','Klein',0,:now,:now)")->execute(['id' => $contact, 'customer' => $customer, 'now' => $now]);
            $project = Uuid::v4();
            $pdo->prepare("INSERT INTO projects(id,project_number,name,customer_id,status,created_at,updated_at) VALUES(:id,'P1','Neubau',:customer,'active',:now,:now)")->execute(['id' => $project, 'customer' => $customer, 'now' => $now]);
            $supplier = Uuid::v4();
            $pdo->prepare("INSERT INTO suppliers(id,name,contact,active,created_at,updated_at) VALUES(:id,'Großhandel','',1,:now,:now)")->execute(['id' => $supplier, 'now' => $now]);
            $offers = new OfferRepository($pdo);
            $validator = new OfferValidator();
            $calculator = new OfferCalculator(new PriceCalculator());
            $numbers = new NumberRangeRepository($pdo);
            $offerService = new OfferService($pdo, new ProjectRepository($pdo), $offers, new OfferJsonStore($tmp . '/offers', $validator), $calculator, $numbers,
                new OfferPdfService($pdo, new DocumentSettingsRepository($pdo), $validator, $calculator));
            $orders = new OrderService($pdo, new OrderRepository($pdo), $offers, $offerService, $numbers);
            $store = new AttachmentStore($tmp . '/documents');
            $test([
                'pdo' => $pdo,
                'tmp' => $tmp,
                'files' => $tmp . '/documents',
                'documents' => new DocumentService($pdo, new AttachmentRepository($pdo), new DocumentRepository($pdo), $store),
                'purchases' => new PurchaseOrderService($pdo, new PurchaseOrderRepository($pdo), $numbers, $store, new PurchaseOrderPdfService(new DocumentSettingsRepository($pdo))),
                'customer' => $customer,
                'contact' => $contact,
                'project' => $project,
                'supplier' => $supplier,
                'regie' => $orders->createRegie(['customerId' => $customer, 'title' => 'Elektroinstallation', 'orderDate' => '2026-11-12']),
                'second' => $orders->createRegie(['customerId' => $customer, 'title' => 'Wartung', 'orderDate' => '2026-11-12']),
                'office' => $this->user($pdo, 'office', $now),
                'technician' => $this->user($pdo, 'technician', $now),
            ]);
        } finally {
            $this->remove($tmp);
        }
    }

    /** @return array{id:string,role:string} */
    private function user(PDO $pdo, string $role, string $now): array
    {
        $id = Uuid::v4();
        $pdo->prepare('INSERT INTO users(id,username,password_hash,display_name,role,active,created_at,updated_at) VALUES(:id,:username,\'hash\',:name,:role,1,:now,:now)')
            ->execute(['id' => $id, 'username' => $role . '-' . substr($id, 0, 8), 'name' => ucfirst($role), 'role' => $role, 'now' => $now]);
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
