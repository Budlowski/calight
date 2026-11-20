<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\DocumentArchive;
use App\Application\OfferService;
use App\Application\OrderService;
use App\Application\WorkReportPdfService;
use App\Application\WorkReportService;
use App\Domain\Calculation\PriceCalculator;
use App\Domain\Offer\OfferCalculator;
use App\Domain\Offer\OfferValidator;
use App\Domain\Order\OrderValidationException;
use App\Http\AccessDeniedException;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Pdf\SimplePdf;
use App\Infrastructure\Persistence\AttachmentStore;
use App\Infrastructure\Persistence\DocumentSettingsRepository;
use App\Infrastructure\Persistence\GeneratedDocumentRepository;
use App\Infrastructure\Persistence\NumberRangeRepository;
use App\Infrastructure\Persistence\OfferJsonStore;
use App\Infrastructure\Persistence\OfferRepository;
use App\Infrastructure\Persistence\OrderRepository;
use App\Infrastructure\Persistence\ProjectRepository;
use App\Infrastructure\Persistence\WorkReportRepository;
use App\Support\Uuid;
use App\Tests\TestCase;
use PDO;

/** Phase 3.2.3: Rapport-PDF, digitale Unterschrift, Rücklauf und Dokumentarchiv. */
final class DocumentOutputTest extends TestCase
{
    /** Kleine Test-Unterschrift (JPEG 200 × 80). */
    private const SIGNATURE = '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';

    public function testSimplePdfOmitsWatermarkForBindingDocumentsAndEmbedsSeveralImages(): void
    {
        $jpeg = (string) base64_decode(self::SIGNATURE);
        $draft = new SimplePdf();
        $draft->text(40, 800, 10, 'Text');
        $this->assertTrue(str_contains($draft->output(), '(ENTWURF) Tj'), 'Standard bleibt das Entwurfs-Wasserzeichen.');

        $binding = new SimplePdf(null);
        $binding->jpeg($jpeg, 40, 600, 100, 40);
        $binding->page();
        $binding->jpeg($jpeg, 40, 600, 100, 40);
        $binding->jpeg($jpeg . ' ', 40, 500, 100, 40);
        $output = $binding->output();
        $this->assertTrue(!str_contains($output, 'ENTWURF'), 'Verbindliche Dokumente ohne Wasserzeichen.');
        $this->assertSame(2, substr_count($output, '/Subtype /Image'), 'Gleiche Bilder werden einmal eingebettet, verschiedene getrennt.');
        $this->assertTrue(str_contains($output, '/Im1') && str_contains($output, '/Im2'));
    }

    public function testWorkReportPdfShowsProofWithoutPricesAndIsArchivedPerState(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'pdf' => $pdfService, 'office' => $office, 'technician' => $technician, 'customer' => $customer, 'pdo' => $pdo, 'documents' => $documents] = $env;

            // Eigenständiger Rapport ohne Auftrag und Projekt, mit langer Materialliste (mehrseitig).
            $draft = $service->create(['customerId' => $customer, 'workDate' => '2026-11-18'], $technician);
            $materials = [];
            for ($index = 1; $index <= 70; $index++) {
                $materials[] = ['quantity' => (string) $index, 'unit' => 'Stk', 'description' => 'Material Nummer ' . $index];
            }
            $draft = $this->saveProof($service, $draft, [
                'description' => 'Heizungsregelung geprueft und Sensor getauscht',
                'contactPerson' => 'Herr Mack',
                'labor' => [['employeeId' => $env['anna'], 'hours' => '3,5'], ['workerName' => 'Aushilfe Ben', 'hours' => '1']],
                'materials' => $materials,
            ], $technician);

            $preview = $pdfService->pdf($draft['id'], $technician);
            $this->assertTrue(str_contains($preview['pdf'], '(ENTWURF) Tj'), 'Entwürfe nur als Vorschau mit Wasserzeichen.');
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM generated_documents')->fetchColumn(), 'Entwürfe werden nicht archiviert.');

            // Historischer Rapport ohne Auftrag (vor der Auftragspflicht eingereicht).
            $submitted = $this->legacySubmit($pdo, $service, $draft, $technician, 'RP2026-0801');
            $billed = $service->saveBilling($submitted['id'], ['version' => $submitted['version'], 'items' => [
                ['type' => 'labor', 'description' => 'Monteurstunden', 'quantity' => '4.5', 'unit' => 'h', 'unitPrice' => '87.65', 'costItemId' => ''],
            ]], $office);

            $first = $pdfService->pdf($billed['id'], $office);
            $pdf = $first['pdf'];
            $this->assertTrue(str_starts_with($pdf, '%PDF-1.4') && str_ends_with($pdf, '%%EOF'));
            $this->assertTrue(str_contains($pdf, (string) $submitted['reportNumber']));
            $this->assertTrue(str_contains($pdf, 'Heizungsregelung geprueft') && str_contains($pdf, 'Material Nummer 70') && str_contains($pdf, 'Aushilfe Ben'));
            $this->assertTrue(!str_contains($pdf, 'ENTWURF'), 'Eingereichte Rapporte ohne Wasserzeichen.');
            $this->assertTrue(!str_contains($pdf, '87,65') && !str_contains($pdf, '394,43') && !str_contains($pdf, 'Monteurstunden'), 'Keine kaufmännischen Positionen oder Preise im Rapport-PDF.');
            $this->assertTrue(!str_contains($pdf, '(Auftrag)'), 'Ohne Auftrag keine Auftragsangabe.');
            $this->assertTrue(substr_count($pdf, '/Type /Page ') >= 2, 'Lange Materiallisten laufen über mehrere Seiten.');
            $this->assertTrue(str_contains($pdf, (string) $submitted['submittedSha256']), 'Prüfsumme des Leistungsnachweises im Dokument.');

            // Derselbe Stand liefert dieselbe archivierte Datei.
            $this->assertSame($pdf, $pdfService->pdf($billed['id'], $technician)['pdf']);
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM generated_documents')->fetchColumn());

            // Digitale Unterschrift: nur für genau den angezeigten Stand.
            $image = 'data:image/jpeg;base64,' . self::SIGNATURE;
            $this->assertThrows(OrderValidationException::class, fn () => $service->sign($billed['id'], ['version' => $billed['version'], 'signerName' => 'Frau Mack', 'image' => $image, 'submittedSha256' => str_repeat('0', 64)], $technician));
            $this->assertThrows(OrderValidationException::class, fn () => $service->sign($billed['id'], ['version' => $billed['version'], 'signerName' => 'Frau Mack', 'image' => 'data:image/png;base64,AAAA', 'submittedSha256' => $billed['submittedSha256']], $technician));
            $this->assertThrows(AccessDeniedException::class, fn () => $service->sign($billed['id'], ['version' => $billed['version'], 'signerName' => 'X', 'image' => $image, 'submittedSha256' => $billed['submittedSha256']], $env['colleague']));
            $signed = $service->sign($billed['id'], ['version' => $billed['version'], 'signerName' => 'Frau Mack', 'image' => $image, 'submittedSha256' => $billed['submittedSha256']], $technician);
            $this->assertSame('signature', $signed['confirmationKind']);
            $this->assertSame('on_site', $signed['confirmationMethod']);
            $this->assertSame('Frau Mack', $signed['confirmedByName']);
            $this->assertSame($billed['submittedSha256'], $signed['submittedSha256'], 'Der Leistungsnachweis bleibt unverändert.');
            $evidence = $service->confirmationEvidence($signed['id'], $office);
            $this->assertSame(base64_decode(self::SIGNATURE), $evidence['contents'], 'Unterschrift wird unverändert gespeichert.');
            $this->assertThrows(OrderValidationException::class, fn () => $service->sign($signed['id'], ['version' => $signed['version'], 'signerName' => 'Nochmal', 'image' => $image, 'submittedSha256' => $signed['submittedSha256']], $technician));

            // Neuer Bestätigungsstand = neues PDF mit Unterschrift; das alte bleibt archiviert.
            $confirmedPdf = $pdfService->pdf($signed['id'], $office);
            $this->assertTrue($confirmedPdf['pdf'] !== $pdf && str_contains($confirmedPdf['pdf'], '/Subtype /Image') && str_contains($confirmedPdf['pdf'], 'Frau Mack'));
            $this->assertTrue(str_contains($confirmedPdf['fileName'], 'bestaetigt'));
            $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM generated_documents')->fetchColumn());
            foreach ($pdo->query('SELECT storage_path FROM generated_documents')->fetchAll(PDO::FETCH_COLUMN) as $path) {
                $this->assertTrue(is_file($documents . '/' . $path) && str_starts_with((string) $path, 'work-reports/' . $signed['id'] . '/'), 'Archiv liegt unter APP_DOCUMENTS_PATH/work-reports.');
            }
        });
    }

    public function testReturnedDocumentManualConfirmationAndPermissions(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'pdf' => $pdfService, 'office' => $office, 'technician' => $technician, 'customer' => $customer, 'regie' => $regie, 'orders' => $orders, 'tmp' => $tmp] = $env;
            $orders->changeStatus($regie['id'], 'open', 'in_progress');
            $report = $this->submitted($service, ['orderId' => $regie['id']], $technician, $env['anna']);
            $this->assertTrue(str_contains($pdfService->pdf($report['id'], $office)['pdf'], (string) $regie['orderNumber']), 'Mit Auftrag erscheint die Auftragsnummer.');

            $this->assertThrows(AccessDeniedException::class, fn () => $service->requestConfirmation($report['id'], ['version' => $report['version']], $technician));
            $requested = $service->requestConfirmation($report['id'], ['version' => $report['version']], $office);
            $this->assertTrue($requested['confirmationRequestedAt'] !== null && $requested['confirmationStatus'] === 'none', 'Versendet ist keine Bestätigung.');

            $file = static function (string $content) use ($tmp): string {
                $path = $tmp . '/upload-' . bin2hex(random_bytes(4));
                file_put_contents($path, $content);
                return $path;
            };
            $pdfReturn = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
            $this->assertThrows(AccessDeniedException::class, fn () => $service->uploadConfirmation($report['id'], $file($pdfReturn), 'scan.pdf', ['version' => $requested['version'], 'signerName' => 'Herr Mack'], $technician));
            $this->assertThrows(OrderValidationException::class, fn () => $service->uploadConfirmation($report['id'], $file("PK\x03\x04" . str_repeat("\0", 40)), 'scan.zip', ['version' => $requested['version'], 'signerName' => 'Herr Mack'], $office));
            $this->assertThrows(OrderValidationException::class, fn () => $service->uploadConfirmation($report['id'], $file($pdfReturn), 'scan.pdf', ['version' => $requested['version'], 'signerName' => ''], $office));
            $returned = $service->uploadConfirmation($report['id'], $file($pdfReturn), '../Rücklauf "Mack".pdf', ['version' => $requested['version'], 'signerName' => 'Herr Mack'], $office);
            $this->assertSame('returned_document', $returned['confirmationKind']);
            $this->assertSame('later', $returned['confirmationMethod']);
            $this->assertSame('Rücklauf Mack.pdf', $returned['confirmationEvidence']['name']);
            $evidence = $service->confirmationEvidence($returned['id'], $technician);
            $this->assertSame($pdfReturn, $evidence['contents'], 'Das Originaldokument bleibt unverändert.');
            $this->assertTrue(!$evidence['inline'], 'PDF-Nachweise werden heruntergeladen.');

            // Manuelle Bestätigung ohne Nachweis bleibt möglich, aber ausdrücklich gekennzeichnet.
            $manual = $this->submitted($service, ['customerId' => $customer], $technician, $env['anna']);
            $confirmed = $service->confirm($manual['id'], ['version' => $manual['version'], 'confirmedByName' => 'Herr Mack', 'method' => 'later'], $office);
            $this->assertSame('manual', $confirmed['confirmationKind']);
            $this->assertSame(null, $confirmed['confirmationEvidence']);
            $this->assertThrows(OrderValidationException::class, fn () => $service->confirmationEvidence($manual['id'], $office));
            $this->assertTrue(str_contains($pdfService->pdf($manual['id'], $office)['pdf'], 'manuell dokumentiert'), 'Manuelle Bestätigung wird im PDF als solche ausgewiesen.');

            // Korrekturen erhalten eine eigene Bestätigung; die Bestätigung des Originals bleibt.
            $correction = $service->create(['correctionOf' => $manual['id']], $technician);
            $this->assertSame('none', $correction['confirmationStatus']);
            $this->assertSame('confirmed', $service->detail($manual['id'], $office)['confirmationStatus']);
        });
    }

    /** @param array<string,mixed> $target @param array<string,mixed> $actor @return array<string,mixed> */
    public function testWorkLocationAppearsInPdfAndSignPageWithSharedStatement(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'pdf' => $pdfService, 'office' => $office, 'technician' => $technician, 'customer' => $customer, 'regie' => $regie, 'orders' => $orders] = $env;

            // Eigenständiger Rapport mit Einsatzort; Tätigkeit leer, Tätigkeitsart leer.
            $draft = $service->create(['customerId' => $customer, 'workDate' => '2026-11-19'], $technician);
            $draft = $this->saveProof($service, $draft, [
                'workLocation' => 'Werk 2, Produktionshalle 4',
                'description' => 'Fertigmontage Schalter',
                'labor' => [['employeeId' => $env['anna'], 'hours' => '5']],
                'materials' => [['quantity' => '12', 'unit' => 'Stk.', 'description' => 'Leuchtmittel E27']],
            ], $technician);
            $submitted = $service->submit($draft['id'], ['version' => $draft['version'], 'createOrder' => true], $technician);
            $pdf = $pdfService->pdf($submitted['id'], $office)['pdf'];
            $this->assertTrue(str_contains($pdf, '(Werk 2, Produktionshalle 4) Tj'), 'Einsatzort im PDF.');
            $this->assertTrue(str_contains($pdf, '(Einsatzort / Objekt) Tj'));
            $this->assertTrue(!str_contains($pdf, iconv('UTF-8', 'Windows-1252', '(Tätigkeit) Tj')), 'Leere Spalte Tätigkeit entfällt.');
            $this->assertTrue(str_contains($pdf, (string) $submitted['submittedSha256']), 'Prüfsumme vollständig enthalten.');

            // Ohne Einsatzort keine leere Angabe im PDF.
            $orders->changeStatus($regie['id'], 'open', 'in_progress');
            $plain = $this->submitted($service, ['orderId' => $regie['id']], $technician, $env['anna']);
            $this->assertSame('', $plain['workLocation']);
            $this->assertTrue(!str_contains($pdfService->pdf($plain['id'], $office)['pdf'], '(Einsatzort / Objekt) Tj'));

            // Unterschriftsseite: Einsatzort sichtbar, Bestätigungstext identisch zum PDF, keine Preise.
            $detail = $service->detail($submitted['id'], $technician);
            $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $render = static function (array $report) use ($escape): string {
                $csrfToken = 'token';
                $companyName = 'Muster GmbH';
                ob_start();
                require dirname(__DIR__, 2) . '/templates/work-reports/sign.php';
                return (string) ob_get_clean();
            };
            $html = $render($detail);
            $this->assertTrue(str_contains($html, 'Werk 2, Produktionshalle 4'), 'Einsatzort auf der Unterschriftsseite.');
            $statement = WorkReportPdfService::confirmationStatement((string) $submitted['reportNumber']);
            $this->assertTrue(str_contains($html, $escape(implode(' ', $statement))), 'Gleicher Bestätigungstext wie im PDF.');
            $this->assertTrue(str_contains($statement[1], 'Verkaufspreise und Abrechnungsbeträge sind nicht Gegenstand'));
            $this->assertTrue(!str_contains($html, '€') && !str_contains($html, 'unitPrice'), 'Keine Preise auf der Unterschriftsseite.');
            $this->assertTrue(!str_contains($render($service->detail($plain['id'], $technician)), 'Einsatzort'), 'Ohne Einsatzort keine leere Zeile.');

            // Nach der Unterschrift: Name und Zeitpunkt sichtbar, Prüfsumme unverändert gebunden.
            $signed = $service->sign($submitted['id'], ['version' => $submitted['version'], 'signerName' => 'Frau Mack', 'image' => 'data:image/jpeg;base64,' . self::SIGNATURE, 'submittedSha256' => $submitted['submittedSha256']], $technician);
            $this->assertSame($submitted['submittedSha256'], $signed['submittedSha256']);
            $signedPdf = $pdfService->pdf($signed['id'], $office)['pdf'];
            $this->assertTrue(str_contains($signedPdf, '(Frau Mack) Tj') && str_contains($signedPdf, '/Subtype /Image'));
            $this->assertTrue(str_contains($signedPdf, (string) $submitted['submittedSha256']) && str_contains($signedPdf, '(Werk 2, Produktionshalle 4) Tj'));
        });
    }

    /**
     * Simuliert einen vor der Auftragspflicht eingereichten Rapport ohne Auftrag (Bestandsdaten):
     * gleicher Status, gleiche Prüfsummenbildung wie WorkReportService::submit, aber ohne Auftrag.
     *
     * @param array<string,mixed> $draft @param array<string,mixed> $actor @return array<string,mixed>
     */
    private function legacySubmit(PDO $pdo, WorkReportService $service, array $draft, array $actor, string $number): array
    {
        $repository = new WorkReportRepository($pdo);
        $row = $repository->find($draft['id']);
        $hash = (new \ReflectionMethod(WorkReportService::class, 'proofHash'))->invoke($service, $row, $repository->labor($draft['id']), $repository->materials($draft['id']));
        $pdo->prepare("UPDATE work_reports SET status = 'submitted', report_number = :number, submitted_by_user_id = :user, submitted_at = :at, submitted_sha256 = :hash,
            version = version + 1, updated_at = :at WHERE id = :id AND status = 'draft' AND order_id IS NULL")
            ->execute(['number' => $number, 'user' => $actor['id'], 'at' => '2026-11-01T08:00:00Z', 'hash' => $hash, 'id' => $draft['id']]);
        return $service->detail($draft['id'], $actor);
    }

    public function testWorkReportPdfShowsSubmissionDateAndDatedLaborRows(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'pdf' => $pdfService, 'office' => $office, 'technician' => $technician, 'customer' => $customer, 'pdo' => $pdo] = $env;
            $ben = Uuid::v4();
            $pdo->prepare("INSERT INTO employees(id,display_name,hourly_cost_rate,active,created_at,updated_at) VALUES(:id,'Ben Monteur','40.0000',1,'2026-11-18T08:00:00Z','2026-11-18T08:00:00Z')")->execute(['id' => $ben]);
            $draft = $service->create(['customerId' => $customer, 'workDate' => '2026-11-18'], $technician);
            $draft = $this->saveProof($service, $draft, [
                'description' => 'Montage',
                'labor' => [
                    ['workDate' => '2026-11-19', 'employeeId' => $env['anna'], 'hours' => '5'],
                    ['workDate' => '2026-11-18', 'employeeId' => $env['anna'], 'hours' => '8'],
                    ['workDate' => '2026-11-18', 'employeeId' => $ben, 'hours' => '6,5'],
                ],
            ], $technician);
            $preview = $pdfService->pdf($draft['id'], $technician)['pdf'];
            $this->assertTrue(!str_contains($preview, '(Eingereicht am) Tj'), 'Entwurf ohne Einreichungsdatum.');
            $submitted = $service->submit($draft['id'], ['version' => $draft['version'], 'createOrder' => true], $technician);
            $pdf = $pdfService->pdf($submitted['id'], $office)['pdf'];
            $this->assertTrue(str_contains($pdf, '(Eingereicht am) Tj') && str_contains($pdf, '(Datum) Tj'));
            $this->assertTrue(!str_contains($pdf, '(Leistungsdatum) Tj'), 'Bei datierten Positionen kein pauschales Leistungsdatum im Kopf.');
            $first = strpos($pdf, '(18.11.2026) Tj');
            $last = strpos($pdf, '(19.11.2026) Tj');
            $this->assertTrue($first !== false && $last !== false && $first < $last, 'Positionen nach Datum geordnet.');
            $this->assertSame($pdf, $pdfService->pdf($submitted['id'], $office)['pdf'], 'Archivierte Fassung bleibt unverändert.');
        });
    }

    private function submitted(WorkReportService $service, array $target, array $actor, string $employee): array
    {
        $draft = $service->create([...$target, 'workDate' => '2026-11-18'], $actor);
        $saved = $this->saveProof($service, $draft, [
            'description' => 'Regiearbeiten',
            'labor' => [['employeeId' => $employee, 'hours' => '2']],
            'materials' => [['quantity' => '1', 'unit' => 'Stk.', 'description' => 'Sensor']],
        ], $actor);
        return $service->submit($saved['id'], ['version' => $saved['version']], $actor);
    }

    /** @param array<string,mixed> $report @param array<string,mixed> $changes @param array<string,mixed> $actor @return array<string,mixed> */
    private function saveProof(WorkReportService $service, array $report, array $changes, array $actor): array
    {
        return $service->update($report['id'], [
            'version' => $report['version'], 'workDate' => $report['workDate'], 'workDateTo' => '', 'contactPerson' => '',
            'description' => '', 'notes' => '', 'labor' => [], 'materials' => [], ...$changes,
        ], $actor);
    }

    /** @param callable(array<string,mixed>):void $test */
    private function withEnvironment(callable $test): void
    {
        $tmp = sys_get_temp_dir() . '/calight-documents-' . bin2hex(random_bytes(6));
        mkdir($tmp . '/documents', 0770, true);
        try {
            $pdo = ConnectionFactory::create($tmp . '/db.sqlite');
            (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
            $now = '2026-11-18T08:00:00Z';
            $customer = Uuid::v4();
            $pdo->prepare("INSERT INTO customers(id,customer_number,customer_type,company_name,street,house_number,postal_code,city,created_at,updated_at)
                VALUES(:id,'K1','company','Matzko-Mack','Hauptstr.','1','71101','Schoenaich',:now,:now)")->execute(['id' => $customer, 'now' => $now]);
            $anna = Uuid::v4();
            $pdo->prepare("INSERT INTO employees(id,display_name,hourly_cost_rate,active,created_at,updated_at) VALUES(:id,'Anna Monteur','40.0000',1,:now,:now)")
                ->execute(['id' => $anna, 'now' => $now]);
            $offers = new OfferRepository($pdo);
            $validator = new OfferValidator();
            $numbers = new NumberRangeRepository($pdo);
            $offerService = new OfferService($pdo, new ProjectRepository($pdo), $offers, new OfferJsonStore($tmp . '/offers', $validator), new OfferCalculator(new PriceCalculator()), $numbers);
            $orders = new OrderService($pdo, new OrderRepository($pdo), $offers, $offerService, $numbers);
            $store = new AttachmentStore($tmp . '/documents');
            $service = new WorkReportService($pdo, new WorkReportRepository($pdo), new OrderRepository($pdo), $numbers, new PriceCalculator(), $store, $orders);
            $test([
                'pdo' => $pdo,
                'tmp' => $tmp,
                'documents' => $tmp . '/documents',
                'service' => $service,
                'pdf' => new WorkReportPdfService($service, new DocumentSettingsRepository($pdo), new DocumentArchive(new GeneratedDocumentRepository($pdo), $store)),
                'orders' => $orders,
                'regie' => $orders->createRegie(['customerId' => $customer, 'title' => 'Stoerung Heizung', 'orderDate' => '2026-11-18']),
                'customer' => $customer,
                'anna' => $anna,
                'office' => $this->user($pdo, 'office', $now),
                'technician' => $this->user($pdo, 'technician', $now),
                'colleague' => $this->user($pdo, 'technician', $now),
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
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                $this->remove($path . '/' . $item);
            }
        }
        rmdir($path);
    }
}
