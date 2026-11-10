<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\OfferService;
use App\Domain\Offer\OfferValidator;
use App\Domain\Offer\OfferValidationException;
use App\Domain\Offer\OfferCalculator;
use App\Domain\Calculation\PriceCalculator;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Persistence\OfferConflictException;
use App\Infrastructure\Persistence\OfferJsonStore;
use App\Infrastructure\Persistence\OfferRepository;
use App\Infrastructure\Persistence\ProjectRepository;
use App\Tests\TestCase;
use App\Support\Uuid;
use RuntimeException;

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
            $this->assertSame(['0001_foundation', '0002_master_data', '0003_service_component_price_mode', '0004_service_numbers_and_component_origin', '0005_remove_legacy_service_price_locked', '0006_service_template_number_range', '0007_customers_and_projects', '0008_document_settings', '0009_pdf_document_options', '0010_offer_document_terms', '0011_larger_document_logo', '0012_offer_title_summary', '0013_document_filename_pattern', '0014_trailing_texts', '0015_normalize_component_price_modes', '0016_offer_submission', '0017_offer_revisions', '0018_orders_and_optional_projects', '0019_change_orders'], $migrator->migrate());
            $this->assertSame([], $migrator->migrate());

            $projects = new ProjectRepository($pdo);
            $offers = new OfferRepository($pdo);
            $store = new OfferJsonStore($temporary . '/offers', new OfferValidator());
            $service = new OfferService($pdo, $projects, $offers, $store,new OfferCalculator(new PriceCalculator()));
            $projectId = $projects->create('E2E Projekt', 'Musterkunde', 'Musterstraße 1');
            $offerId = $service->createDraft($projectId, 'E2E Angebot');

            $loaded = $service->loadDraft($offerId);
            $this->assertSame('E2E Angebot', $loaded['offer']['title']);
            $this->assertSame(null, $loaded['offer']['offerNumber']);
            $this->assertSame(null, $loaded['metadata']['offer_number']);
            $oldEtag = $loaded['etag'];
            $changed = $loaded['offer'];
            $changed['title'] = 'Gespeichert und geladen';
            $changed['nodes'] = [[
                'id'=>Uuid::v4(),'type'=>'title','name'=>'Titel 1','description'=>'','children'=>[[
                    'id'=>Uuid::v4(),'type'=>'title','name'=>'Untertitel','description'=>'','children'=>[[
                        'id'=>Uuid::v4(),'type'=>'position','positionType'=>'normal','shortText'=>'Leitung verlegen',
                        'longText'=>'Ausführlicher Text','quantity'=>'12.500','unit'=>'m','manualUnitPrice'=>null,'components'=>[],
                    ]],
                ]],
            ]];
            $newEtag = $service->saveDraft($offerId, $changed, $oldEtag);
            $this->assertTrue($newEtag !== $oldEtag);
            $this->assertSame('Gespeichert und geladen', $service->loadDraft($offerId)['offer']['title']);
            $this->assertSame('Leitung verlegen', $service->loadDraft($offerId)['offer']['nodes'][0]['children'][0]['children'][0]['shortText']);
            $overview=$service->overview();
            $this->assertSame(1,count($overview));
            $this->assertSame('Gespeichert und geladen',$overview[0]['title']);
            $this->assertSame('draft',$overview[0]['status']);
            $this->assertTrue(!array_key_exists('draftVersion',$overview[0]));

            $copyId=$service->copyAsDraft($offerId);
            $copy=$service->loadDraft($copyId);
            $this->assertSame('Kopie von Gespeichert und geladen',$copy['offer']['title']);
            $this->assertSame('draft',$copy['offer']['status']);
            $this->assertSame(null,$copy['offer']['offerNumber']);
            $this->assertTrue($copy['offer']['nodes'][0]['id']!==$changed['nodes'][0]['id']);
            $this->assertTrue($copy['offer']['nodes'][0]['children'][0]['children'][0]['id']!==$changed['nodes'][0]['children'][0]['children'][0]['id']);
            $service->deleteDraft($copyId);

            $this->assertThrows(
                OfferConflictException::class,
                fn () => $service->saveDraft($offerId, $changed, $oldEtag),
            );

            $deleted=$service->deleteDraft($offerId);
            $this->assertSame(true,$deleted['deleted']);
            $this->assertSame($projectId,$deleted['projectId']);
            $this->assertSame(null,$offers->find($offerId));
            $this->assertSame(0,count($service->overview()));
            $this->assertThrows(RuntimeException::class,fn()=>$service->loadDraft($offerId));

            $submittedId=$service->createDraft($projectId,'Verbindliches Angebot');
            $pdo->prepare('UPDATE offers SET status=:status, offer_number=:number WHERE id=:id')->execute(['status'=>'submitted','number'=>'AG-1','id'=>$submittedId]);
            $this->assertThrows(OfferValidationException::class,fn()=>$service->deleteDraft($submittedId));
            $this->assertTrue($offers->find($submittedId)!==null);
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
