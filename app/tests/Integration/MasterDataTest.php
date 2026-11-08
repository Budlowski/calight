<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\MasterDataService;
use App\Domain\Calculation\PriceCalculator;
use App\Domain\MasterData\MasterDataValidationException;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Persistence\MasterDataRepository;
use App\Infrastructure\Persistence\NumberRangeRepository;
use App\Tests\TestCase;

final class MasterDataTest extends TestCase
{
    public function testTrailingTextCrudUniquenessAndServiceReference():void
    {
        [$service,$temporary]=$this->service();try{$defaults=$service->overview()['trailingTexts'];$this->assertSame(5,count($defaults));$text=$service->createTrailingText(['code'=>'IB','name'=>'Inbetriebnahme']);$updated=$service->updateTrailingText($text['id'],['code'=>'IBN','name'=>'Inbetriebnahme und Nachweis']);$this->assertSame('IBN',$updated['code']);$this->assertThrows(MasterDataValidationException::class,fn()=>$service->createTrailingText(['code'=>'ibn','name'=>'Doppelt']));$position=$service->createService(['shortText'=>'Prüfung','unit'=>'Stk.','trailingTextId'=>$text['id'],'components'=>[]]);$this->assertSame('Inbetriebnahme und Nachweis',$position['trailingText']['name']);$this->assertThrows(MasterDataValidationException::class,fn()=>$service->deleteTrailingText($text['id']));$free=$service->createTrailingText(['code'=>'F','name'=>'Frei']);$this->assertSame(true,$service->deleteTrailingText($free['id'])['deleted']);}finally{$this->removeTree($temporary);}
    }
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
            $legacySaved=$service->updateService($template['id'],[
                'articleNumber'=>$template['articleNumber'],'shortText'=>$template['shortText'],'longText'=>$template['longText'],
                'unit'=>$template['unit'],'components'=>$locked,
            ]);
            $this->assertSame('manual',$legacySaved['components'][0]['priceMode']);
            $this->assertSame('5.0000',$legacySaved['components'][0]['costPrice']);

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

    public function testCompleteMasterDataScopesCanBeExportedAndImported():void
    {
        [$source,$sourceTemporary]=$this->service();[$target,$targetTemporary]=$this->service();
        try{
            $category=$source->createCategory(['name'=>'Elektro','description'=>'Hauptgruppe','parentId'=>null,'sortOrder'=>1]);
            $child=$source->createCategory(['name'=>'Installationsmaterial','description'=>'','parentId'=>$category['id'],'sortOrder'=>2]);
            $supplier=$source->createSupplier(['supplierNumber'=>'70042','name'=>'Großhandel','contact'=>'Einkauf']);
            $article=$source->createCostItem(['articleNumber'=>'M-42','name'=>'Installationsrohr','description'=>'M20','categoryId'=>$child['id'],'calculationType'=>'material','unit'=>'m','costPrice'=>'2.5000','markupPercent'=>'20.00','supplierId'=>$supplier['id'],'supplierArticleNumber'=>'GH-42']);
            $trailing=$source->createTrailingText(['code'=>'TEST','name'=>'Lieferung und Montage gemäß Planung']);
            $service=$source->createService(['articleNumber'=>'LP-42','shortText'=>'Installationsrohr verlegen','longText'=>'Komplettleistung','unit'=>'m','trailingTextId'=>$trailing['id'],'components'=>[['masterDataId'=>$article['id'],'sourceArticleNumber'=>'M-42','name'=>'Installationsrohr','calculationType'=>'material','unit'=>'m','quantity'=>'1.000','costPrice'=>'2.5000','markupPercent'=>'20.00','priceMode'=>'master']]]);

            foreach(['categories','suppliers','cost-items','trailing-texts','services'] as $scope){
                $document=$source->exportScope($scope);
                $this->assertSame('calight-master-data',$document['format']);
                $this->assertSame($scope,$document['scope']);
                if($scope==='categories')$this->assertSame($child['id'],$document['items'][0]['children'][0]['id']??null);
                $target->importScope($scope,$document);
            }
            $imported=$target->overview(true);
            $importedCategory=array_values(array_filter($imported['categories'],static fn(array $item):bool=>$item['id']===$child['id']))[0]??null;
            $importedArticle=array_values(array_filter($imported['costItems'],static fn(array $item):bool=>$item['id']===$article['id']))[0]??null;
            $importedService=array_values(array_filter($imported['services'],static fn(array $item):bool=>$item['id']===$service['id']))[0]??null;
            $this->assertSame($category['id'],$importedCategory['parentId']??null);
            $this->assertSame($child['id'],$importedArticle['categoryId']??null);
            $this->assertSame($supplier['id'],$importedArticle['supplierId']??null);
            $this->assertSame($article['id'],$importedService['components'][0]['masterDataId']??null);
            $this->assertSame($trailing['id'],$importedService['trailingTextId']??null);
            $this->assertSame('3.00',$importedService['calculation']['calculatedUnitPrice']??null);

            $articleDocument=$source->exportScope('cost-items');
            foreach($articleDocument['items'] as &$item)if($item['id']===$article['id'])$item['name']='Installationsrohr aktualisiert';
            unset($item);
            $this->assertSame(1,$target->importScope('cost-items',$articleDocument)['imported']);
            $updated=array_values(array_filter($target->overview(true)['costItems'],static fn(array $item):bool=>$item['id']===$article['id']))[0]??null;
            $this->assertSame('Installationsrohr aktualisiert',$updated['name']??null);
            $this->assertThrows(MasterDataValidationException::class,fn()=>$target->importScope('services',$source->exportScope('cost-items')));
        }finally{$this->removeTree($sourceTemporary);$this->removeTree($targetTemporary);}
    }

    /** @return array{MasterDataService,string} */
    private function service(): array
    {
        $root = dirname(__DIR__, 2);
        $temporary = sys_get_temp_dir() . '/kalkulation-master-test-' . bin2hex(random_bytes(8));
        mkdir($temporary, 0770, true);
        $pdo = ConnectionFactory::create($temporary . '/app.sqlite');
        (new Migrator($pdo, $root . '/migrations'))->migrate();
        return [new MasterDataService(new MasterDataRepository($pdo), new PriceCalculator(), new NumberRangeRepository($pdo)), $temporary];
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
