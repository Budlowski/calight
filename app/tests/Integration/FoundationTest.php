<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\OfferService;
use App\Domain\Offer\OfferValidator;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Persistence\OfferConflictException;
use App\Infrastructure\Persistence\OfferJsonStore;
use App\Infrastructure\Persistence\OfferRepository;
use App\Infrastructure\Persistence\ProjectRepository;
use App\Tests\TestCase;

final class FoundationTest extends TestCase
{
    public function testMigrationIsReproducibleAndDraftRoundTripDetectsConflict(): void
    {
        $root = dirname(__DIR__, 2);
        $temporary = sys_get_temp_dir() . '/kalkulation-test-' . bin2hex(random_bytes(8));
        mkdir($temporary, 0770, true);

        try {
            $pdo = ConnectionFactory::create($temporary . '/app.sqlite');
            $migrator = new Migrator($pdo, $root . '/migrations');
            $this->assertSame(['0001_foundation', '0002_master_data', '0003_service_component_price_mode', '0004_service_numbers_and_component_origin', '0005_remove_legacy_service_price_locked', '0006_service_template_number_range', '0007_customers_and_projects'], $migrator->migrate());
            $this->assertSame([], $migrator->migrate());

            $projects = new ProjectRepository($pdo);
            $offers = new OfferRepository($pdo);
            $store = new OfferJsonStore($temporary . '/offers', new OfferValidator());
            $service = new OfferService($pdo, $projects, $offers, $store);
            $projectId = $projects->create('E2E Projekt', 'Musterkunde', 'Musterstraße 1');
            $offerId = $service->createDraft($projectId, 'E2E Angebot');

            $loaded = $service->loadDraft($offerId);
            $this->assertSame('E2E Angebot', $loaded['offer']['title']);
            $oldEtag = $loaded['etag'];
            $changed = $loaded['offer'];
            $changed['title'] = 'Gespeichert und geladen';
            $newEtag = $service->saveDraft($offerId, $changed, $oldEtag);
            $this->assertTrue($newEtag !== $oldEtag);
            $this->assertSame('Gespeichert und geladen', $service->loadDraft($offerId)['offer']['title']);

            $this->assertThrows(
                OfferConflictException::class,
                fn () => $service->saveDraft($offerId, $changed, $oldEtag),
            );
        } finally {
            $this->removeTree($temporary);
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . '/' . $item;
            is_dir($child) ? $this->removeTree($child) : unlink($child);
        }
        rmdir($path);
    }
}
