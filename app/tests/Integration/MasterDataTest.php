<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\MasterDataService;
use App\Domain\Calculation\PriceCalculator;
use App\Domain\MasterData\MasterDataValidationException;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Persistence\MasterDataRepository;
use App\Tests\TestCase;

final class MasterDataTest extends TestCase
{
    public function testCategoryHierarchyCrudAndCycleProtection(): void
    {
        [$service, $temporary] = $this->service();
        try {
            $root = $service->createCategory(['name' => 'Elektro', 'description' => '', 'parentId' => null, 'sortOrder' => 0]);
            $child = $service->createCategory(['name' => 'Schalter', 'description' => '', 'parentId' => $root['id'], 'sortOrder' => 0]);
            $this->assertSame($root['id'], $child['parentId']);

            $updated = $service->updateCategory($child['id'], ['name' => 'Schalterprogramme', 'description' => 'Unterputz', 'parentId' => $root['id'], 'sortOrder' => 2]);
            $this->assertSame('Schalterprogramme', $updated['name']);
            $this->assertSame(2, $updated['sortOrder']);

            $this->assertThrows(MasterDataValidationException::class, fn () => $service->updateCategory($root['id'], [
                'name' => 'Elektro', 'description' => '', 'parentId' => $child['id'], 'sortOrder' => 0,
            ]));
            $this->assertThrows(MasterDataValidationException::class, fn () => $service->setCategoryArchived($root['id'], true));
            $archivedChild = $service->setCategoryArchived($child['id'], true);
            $this->assertSame(false, $archivedChild['active']);
            $this->assertSame(false, $service->setCategoryArchived($root['id'], true)['active']);
            $this->assertThrows(MasterDataValidationException::class, fn () => $service->setCategoryArchived($child['id'], false));
        } finally {
            $this->removeTree($temporary);
        }
    }

    public function testCostItemCrudCopyArchiveAndExactPrice(): void
    {
        [$service, $temporary] = $this->service();
        try {
            $category = $service->createCategory(['name' => 'Material', 'parentId' => null]);
            $supplier = $service->createSupplier(['supplierNumber' => '70001', 'name' => 'Elektrohandel', 'contact' => 'Innendienst']);
            $item = $service->createCostItem([
                'articleNumber' => 'M-100', 'name' => 'Steckdose', 'description' => 'Unterputz',
                'categoryId' => $category['id'], 'calculationType' => 'material', 'unit' => 'Stk.',
                'costPrice' => '10.0000', 'markupPercent' => '20.00', 'supplierId' => $supplier['id'],
                'supplierArticleNumber' => 'L-42',
            ]);
            $this->assertSame('12.00', $item['salesPrice']);
            $this->assertThrows(MasterDataValidationException::class, fn () => $service->setSupplierArchived($supplier['id'], true));

            $updated = $service->updateCostItem($item['id'], [
                'articleNumber' => 'M-100', 'name' => 'Steckdose UP', 'description' => 'Unterputz',
                'categoryId' => $category['id'], 'calculationType' => 'material', 'unit' => 'Stk.',
                'costPrice' => '11.2500', 'markupPercent' => '20.00', 'supplierId' => $supplier['id'],
                'supplierArticleNumber' => 'L-42',
            ]);
            $this->assertSame('13.50', $updated['salesPrice']);

            $copy = $service->copyCostItem($item['id']);
            $this->assertTrue($copy['id'] !== $item['id']);
            $this->assertSame(null, $copy['articleNumber']);
            $this->assertSame($item['id'], $copy['copiedFromId']);
            $this->assertSame(false, $service->setCostItemArchived($item['id'], true)['active']);
        } finally {
            $this->removeTree($temporary);
        }
    }

    public function testServiceComponentsCopyArchiveAndCalculation(): void
    {
        [$service, $temporary] = $this->service();
        try {
            $material = $service->createCostItem([
                'articleNumber' => 'M-200', 'name' => 'Steckdose', 'calculationType' => 'material', 'unit' => 'Stk.',
                'costPrice' => '5.0000', 'markupPercent' => '20.00',
            ]);
            $template = $service->createService([
                'articleNumber' => 'LP-100', 'shortText' => 'Steckdose montieren', 'longText' => 'Liefern und montieren', 'unit' => 'Stk.',
                'components' => [
                    ['masterDataId' => $material['id'], 'name' => 'Steckdose', 'calculationType' => 'material', 'unit' => 'Stk.', 'quantity' => '1.000', 'costPrice' => '5.0000', 'markupPercent' => '20.00', 'priceMode' => 'master'],
                    ['masterDataId' => null, 'name' => 'Monteur', 'calculationType' => 'labor', 'unit' => 'h', 'quantity' => '0.500', 'costPrice' => '40.0000', 'markupPercent' => '25.00', 'priceMode' => 'manual'],
                ],
            ]);
            $this->assertSame(2, count($template['components']));
            $this->assertSame('31.00', $template['calculation']['calculatedUnitPrice']);
            $this->assertSame('5.00', $template['calculation']['materialCosts']);
            $this->assertSame('20.00', $template['calculation']['laborCosts']);
            $this->assertSame('6.00', $template['components'][0]['salesPrice']);
            $this->assertSame('master', $template['components'][0]['priceMode']);
            $this->assertSame('M-200', $template['components'][0]['sourceArticleNumber']);
            $this->assertSame('manual', $template['components'][1]['priceMode']);
            $this->assertSame('LP-100', $template['articleNumber']);
            $this->assertThrows(MasterDataValidationException::class, fn () => $service->createService([
                'articleNumber' => 'LP-100', 'shortText' => 'Doppelte Nummer', 'unit' => 'Stk.', 'components' => [],
            ]));
            $automatic = $service->createService([
                'autoArticleNumber' => true, 'shortText' => 'Automatisch nummeriert', 'unit' => 'Stk.', 'components' => [],
            ]);
            $this->assertSame('LP00001', $automatic['articleNumber']);
            $service->createService([
                'articleNumber' => 'LP00002', 'shortText' => 'Manuelle Kollision', 'unit' => 'Stk.', 'components' => [],
            ]);
            $nextAutomatic = $service->createService([
                'autoArticleNumber' => true, 'shortText' => 'Kollision übersprungen', 'unit' => 'Stk.', 'components' => [],
            ]);
            $this->assertSame('LP00003', $nextAutomatic['articleNumber']);
            $withoutNumber = $service->createService([
                'shortText' => 'Nachträglich nummeriert', 'unit' => 'Stk.', 'components' => [],
            ]);
            $this->assertSame(null, $withoutNumber['articleNumber']);
            $numberedOnUpdate = $service->updateService($withoutNumber['id'], [
                'autoArticleNumber' => true, 'shortText' => $withoutNumber['shortText'],
                'longText' => $withoutNumber['longText'], 'unit' => $withoutNumber['unit'], 'components' => [],
            ]);
            $this->assertSame('LP00004', $numberedOnUpdate['articleNumber']);

            $service->updateCostItem($material['id'], [
                'articleNumber' => 'M-200', 'name' => 'Steckdose', 'calculationType' => 'material', 'unit' => 'Stk.',
                'costPrice' => '6.0000', 'markupPercent' => '20.00',
            ]);
            $resavedWithoutPriceUpdate = $service->updateService($template['id'], [
                'articleNumber' => $template['articleNumber'], 'shortText' => $template['shortText'],
                'longText' => $template['longText'], 'unit' => $template['unit'], 'components' => $template['components'],
            ]);
            $this->assertSame('5.0000', $resavedWithoutPriceUpdate['components'][0]['costPrice']);
            $priceUpdate = $service->previewMasterPriceUpdates(['components' => $template['components']]);
            $this->assertSame(1, count($priceUpdate['changes']));
            $this->assertSame('6.0000', $priceUpdate['updates'][0]['costPrice']);
            $numericallyCurrent = $template['components'];
            $numericallyCurrent[0]['costPrice'] = '6.0';
            $numericallyCurrent[0]['markupPercent'] = '20.0';
            $this->assertSame(0, count($service->previewMasterPriceUpdates(['components' => $numericallyCurrent])['changes']));
            $locked = $template['components'];
            $locked[0]['priceMode'] = 'locked';
            $this->assertSame(0, count($service->previewMasterPriceUpdates(['components' => $locked])['changes']));

            $copy = $service->copyService($template['id']);
            $this->assertTrue($copy['id'] !== $template['id']);
            $this->assertTrue($copy['components'][0]['id'] !== $template['components'][0]['id']);
            $this->assertSame($template['id'], $copy['copiedFromId']);
            $this->assertSame(null, $copy['articleNumber']);
            $this->assertSame(false, $service->setServiceArchived($template['id'], true)['active']);
        } finally {
            $this->removeTree($temporary);
        }
    }

    /** @return array{MasterDataService,string} */
    private function service(): array
    {
        $root = dirname(__DIR__, 2);
        $temporary = sys_get_temp_dir() . '/kalkulation-master-test-' . bin2hex(random_bytes(8));
        mkdir($temporary, 0770, true);
        $pdo = ConnectionFactory::create($temporary . '/app.sqlite');
        (new Migrator($pdo, $root . '/migrations'))->migrate();
        return [new MasterDataService(new MasterDataRepository($pdo), new PriceCalculator()), $temporary];
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) return;
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $child = $path . '/' . $item;
            is_dir($child) ? $this->removeTree($child) : unlink($child);
        }
        rmdir($path);
    }
}
