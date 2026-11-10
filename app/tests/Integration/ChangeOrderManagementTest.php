<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\OfferPdfService;
use App\Application\OfferService;
use App\Application\OrderService;
use App\Domain\Calculation\PriceCalculator;
use App\Domain\Offer\OfferCalculator;
use App\Domain\Offer\OfferValidationException;
use App\Domain\Offer\OfferValidator;
use App\Domain\Order\OrderValidationException;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Persistence\DocumentSettingsRepository;
use App\Infrastructure\Persistence\NumberRangeRepository;
use App\Infrastructure\Persistence\OfferJsonStore;
use App\Infrastructure\Persistence\OfferRepository;
use App\Infrastructure\Persistence\OrderRepository;
use App\Infrastructure\Persistence\ProjectRepository;
use App\Support\Clock;
use App\Support\Uuid;
use App\Tests\TestCase;
use PDO;

final class ChangeOrderManagementTest extends TestCase
{
    public function testAcceptedRevisionExtendsOrderWithoutChangingOriginalBasis(): void
    {
        $fixture = $this->fixture();
        try {
            ['pdo' => $pdo, 'offers' => $offers, 'orders' => $orders, 'customerId' => $customerId] = $fixture;
            $order = $this->createOfferOrder($offers, $orders, $customerId, '100.00');
            $originalBasis = (string) $pdo->query("SELECT basis_json FROM orders WHERE id='" . $order['id'] . "'")->fetchColumn();
            $originalHash = (string) $pdo->query("SELECT basis_sha256 FROM orders WHERE id='" . $order['id'] . "'")->fetchColumn();

            $created = $orders->createChangeOrder($order['id'], 'Zusätzliche Leitungswege');
            $loaded = $offers->loadDraft($created['id']);
            $this->assertSame('change_order', $loaded['offer']['documentType']);
            $this->assertSame($order['id'], $loaded['offer']['orderId']);
            $loaded['offer']['nodes'] = [$this->position('25.00')];
            $etag = $offers->saveDraft($created['id'], $loaded['offer'], $loaded['etag']);
            $submitted = $offers->submitOffer($created['id'], $etag, null, '2026-11-10', true);
            $this->assertTrue(str_starts_with($submitted['offerNumber'], 'NA'));

            $revision = $offers->createRevision($created['id']);
            $revisionDraft = $offers->loadDraft($revision['id']);
            $revisionDraft['offer']['nodes'][0]['manualUnitPrice'] = '40.00';
            $revisionEtag = $offers->saveDraft($revision['id'], $revisionDraft['offer'], $revisionDraft['etag']);
            $revisionSubmitted = $offers->submitOffer($revision['id'], $revisionEtag, null, '2026-11-10', true);
            $this->assertSame($submitted['offerNumber'], $revisionSubmitted['offerNumber']);

            $accepted = $orders->acceptChangeOrder($revision['id'], 'submitted');
            $this->assertSame('100.00', $accepted['originalNetTotal']);
            $this->assertSame('40.00', $accepted['acceptedAddendaNetTotal']);
            $this->assertSame('140.00', $accepted['currentContractNetTotal']);
            $this->assertSame($originalBasis, (string) $pdo->query("SELECT basis_json FROM orders WHERE id='" . $order['id'] . "'")->fetchColumn());
            $this->assertSame($originalHash, (string) $pdo->query("SELECT basis_sha256 FROM orders WHERE id='" . $order['id'] . "'")->fetchColumn());
            $this->assertThrows(OrderValidationException::class, fn() => $orders->acceptChangeOrder($revision['id'], 'submitted'));
            $this->assertThrows(OfferValidationException::class, fn() => $offers->changeStatus($revision['id'], 'commissioned', 'rejected'));
            $this->assertThrows(OfferValidationException::class, fn() => $offers->createRevision($revision['id']));
        } finally {
            $this->remove($fixture['tmp']);
        }
    }

    public function testOpenAndRejectedAddendaDoNotChangeRegieOrderTotal(): void
    {
        $fixture = $this->fixture();
        try {
            ['offers' => $offers, 'orders' => $orders, 'customerId' => $customerId] = $fixture;
            $order = $orders->createRegie(['customerId' => $customerId, 'title' => 'Regieauftrag', 'orderDate' => '2026-11-10']);
            $draft = $orders->createChangeOrder($order['id'], 'Noch nicht beauftragt');
            $loaded = $offers->loadDraft($draft['id']);
            $loaded['offer']['nodes'] = [$this->position('15.00')];
            $etag = $offers->saveDraft($draft['id'], $loaded['offer'], $loaded['etag']);
            $offers->submitOffer($draft['id'], $etag, null, '2026-11-10', true);
            $offers->changeStatus($draft['id'], 'submitted', 'rejected');
            $detail = $orders->detail($order['id']);
            $this->assertSame(null, $detail['originalNetTotal']);
            $this->assertSame('0.00', $detail['acceptedAddendaNetTotal']);
            $this->assertSame(null, $detail['currentContractNetTotal']);

            $acceptedDraft = $orders->createChangeOrder($order['id'], 'Beauftragter Regienachtrag');
            $acceptedLoaded = $offers->loadDraft($acceptedDraft['id']);
            $acceptedLoaded['offer']['nodes'] = [$this->position('20.00')];
            $acceptedEtag = $offers->saveDraft($acceptedDraft['id'], $acceptedLoaded['offer'], $acceptedLoaded['etag']);
            $offers->submitOffer($acceptedDraft['id'], $acceptedEtag, null, '2026-11-10', true);
            $accepted = $orders->acceptChangeOrder($acceptedDraft['id'], 'submitted');
            $this->assertSame(null, $accepted['originalNetTotal']);
            $this->assertSame('20.00', $accepted['acceptedAddendaNetTotal']);
            $this->assertSame('20.00', $accepted['currentContractNetTotal']);
        } finally {
            $this->remove($fixture['tmp']);
        }
    }

    /** @return array{tmp:string,pdo:PDO,offers:OfferService,orders:OrderService,customerId:string} */
    private function fixture(): array
    {
        $root = dirname(__DIR__, 2);
        $tmp = sys_get_temp_dir() . '/calight-change-orders-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0770, true);
        $pdo = ConnectionFactory::create($tmp . '/db.sqlite');
        (new Migrator($pdo, $root . '/migrations'))->migrate();
        $customerId = Uuid::v4();
        $pdo->prepare("INSERT INTO customers(id,customer_number,customer_type,company_name,created_at,updated_at) VALUES(:id,'10003','company','Kunde',:now,:now)")
            ->execute(['id' => $customerId, 'now' => Clock::now()]);
        $offerRepository = new OfferRepository($pdo);
        $validator = new OfferValidator();
        $calculator = new OfferCalculator(new PriceCalculator());
        $numbers = new NumberRangeRepository($pdo);
        $offers = new OfferService(
            $pdo,
            new ProjectRepository($pdo),
            $offerRepository,
            new OfferJsonStore($tmp . '/offers', $validator),
            $calculator,
            $numbers,
            new OfferPdfService($pdo, new DocumentSettingsRepository($pdo), $validator, $calculator),
        );
        return [
            'tmp' => $tmp,
            'pdo' => $pdo,
            'offers' => $offers,
            'orders' => new OrderService($pdo, new OrderRepository($pdo), $offerRepository, $offers, $numbers),
            'customerId' => $customerId,
        ];
    }

    /** @return array<string,mixed> */
    private function createOfferOrder(OfferService $offers, OrderService $orders, string $customerId, string $price): array
    {
        $offerId = $offers->createDraft(null, 'Auftragsgrundlage', $customerId);
        $loaded = $offers->loadDraft($offerId);
        $loaded['offer']['nodes'] = [$this->position($price)];
        $etag = $offers->saveDraft($offerId, $loaded['offer'], $loaded['etag']);
        $offers->submitOffer($offerId, $etag, null, '2026-11-10', true);
        $offers->changeStatus($offerId, 'submitted', 'commissioned');
        return $orders->createFromOffer($offerId);
    }

    /** @return array<string,mixed> */
    private function position(string $price): array
    {
        return [
            'id' => Uuid::v4(), 'type' => 'position', 'positionType' => 'normal', 'workStatus' => 'done',
            'shortText' => 'Leistung', 'longText' => '', 'quantity' => '1.000', 'unit' => 'Stk.',
            'manualUnitPrice' => $price, 'components' => [],
        ];
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) return;
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $child = $path . '/' . $item;
            is_dir($child) ? $this->remove($child) : unlink($child);
        }
        rmdir($path);
    }
}
