<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Calculation\Decimal;
use App\Domain\Calculation\PriceCalculator;
use App\Domain\MasterData\MasterDataValidationException;
use App\Infrastructure\Persistence\MasterDataRepository;
use App\Infrastructure\Persistence\NumberRangeRepository;
use App\Support\Clock;
use App\Support\Uuid;
use DateTimeImmutable;
use PDOException;
use Throwable;

final readonly class MasterDataService
{
    private const TYPES = ['material', 'labor', 'external', 'other'];
    private const EXCHANGE_SCOPES = ['cost-items', 'services', 'categories', 'suppliers', 'trailing-texts'];

    public function __construct(
        private MasterDataRepository $repository,
        private PriceCalculator $calculator,
        private NumberRangeRepository $numberRanges,
    ) {
    }

    /** @return array<string,mixed> */
    public function overview(bool $includeArchived = true): array
    {
        $categories = array_map($this->mapCategory(...), $this->repository->categories($includeArchived));
        $suppliers = array_map($this->mapSupplier(...), $this->repository->suppliers($includeArchived));
        $costItems = array_map($this->mapCostItem(...), $this->repository->costItems($includeArchived));
        $trailingTexts = array_map($this->mapTrailingText(...), $this->repository->trailingTexts());
        $services = [];
        foreach ($this->repository->serviceTemplates($includeArchived) as $service) {
            $services[] = $this->mapService($service, $this->repository->serviceComponents((string) $service['id']));
        }
        return compact('categories', 'suppliers', 'costItems', 'services', 'trailingTexts');
    }

    /** @return array<string,mixed> */
    public function exportScope(string $scope): array
    {
        $this->validateExchangeScope($scope);
        $overview=$this->overview(true);
        $items=match($scope){
            'categories'=>$this->categoryExportTree($overview['categories']),
            'suppliers'=>$overview['suppliers'],
            'trailing-texts'=>$overview['trailingTexts'],
            'cost-items'=>array_map(static function(array $item):array{unset($item['categoryName'],$item['supplierName'],$item['salesPrice'],$item['copiedFromId']);return $item;},$overview['costItems']),
            'services'=>array_map(static function(array $item):array{unset($item['calculation'],$item['copiedFromId']);foreach($item['components'] as &$component)unset($component['salesPrice']);unset($component);return $item;},$overview['services']),
        };
        return['format'=>'calight-master-data','schemaVersion'=>1,'scope'=>$scope,'exportedAt'=>Clock::now(),'items'=>$items];
    }

    /** @param array<string,mixed> $document @return array{scope:string,imported:int} */
    public function importScope(string $scope,array $document):array
    {
        $this->validateExchangeScope($scope);
        if(($document['format']??null)!=='calight-master-data'||($document['schemaVersion']??null)!==1||($document['scope']??null)!==$scope){
            throw new MasterDataValidationException(['Die JSON-Datei gehört nicht zum gewählten Stammdatenbereich oder hat eine nicht unterstützte Version.']);
        }
        $items=$document['items']??null;
        if(!is_array($items)||!array_is_list($items)||count($items)>10000)throw new MasterDataValidationException(['Die Importdatei enthält keine gültige Datensatzliste.']);
        if($scope==='categories')$items=$this->flattenImportedCategories($items);
        $ids=[];
        foreach($items as $index=>$item){
            if(!is_array($item))throw new MasterDataValidationException([sprintf('Datensatz %d ist ungültig.',$index+1)]);
            $id=(string)($item['id']??'');if(!Uuid::isValid($id))throw new MasterDataValidationException([sprintf('Datensatz %d enthält keine gültige interne ID.',$index+1)]);
            if(isset($ids[$id]))throw new MasterDataValidationException(['Ein Datensatz ist in der Importdatei doppelt enthalten.']);$ids[$id]=true;
        }
        if($scope==='categories')$items=$this->sortedImportedCategories($items);
        $now=Clock::now();
        $this->repository->beginImmediate();
        try{
            foreach($items as $item)$this->importItem($scope,$item,$now);
            $this->repository->commit();
        }catch(Throwable $exception){
            $this->repository->rollBack();
            $this->translateWriteException($exception);
        }
        return['scope'=>$scope,'imported'=>count($items)];
    }

    /** @param list<array<string,mixed>> $categories @return list<array<string,mixed>> */
    private function categoryExportTree(array $categories):array
    {
        $children=[];foreach($categories as $category)$children[(string)($category['parentId']??'')][]=$category;
        $build=function(string $parent='')use(&$build,&$children):array{
            return array_map(function(array $category)use(&$build):array{$id=(string)$category['id'];unset($category['parentId']);$category['children']=$build($id);return$category;},$children[$parent]??[]);
        };
        return$build();
    }

    /** @param list<mixed> $nodes @return list<array<string,mixed>> */
    private function flattenImportedCategories(array $nodes):array
    {
        $result=[];
        $walk=function(array $items,?string $parentId,int $depth)use(&$walk,&$result):void{
            if($depth>50)throw new MasterDataValidationException(['Die importierte Kategoriehierarchie ist zu tief verschachtelt.']);
            foreach($items as $item){
                if(!is_array($item))throw new MasterDataValidationException(['Die Importdatei enthält eine ungültige Kategorie.']);
                $children=$item['children']??[];if(!is_array($children)||!array_is_list($children))throw new MasterDataValidationException(['Unterkategorien müssen als Liste angegeben werden.']);
                unset($item['children']);$item['parentId']=$parentId;$result[]=$item;
                if(count($result)>10000)throw new MasterDataValidationException(['Die Importdatei enthält zu viele Kategorien.']);
                $id=(string)($item['id']??'');if(!Uuid::isValid($id))throw new MasterDataValidationException(['Eine Kategorie enthält keine gültige interne ID.']);
                $walk($children,$id,$depth+1);
            }
        };
        $walk($nodes,null,0);return$result;
    }

    private function validateExchangeScope(string $scope):void
    {
        if(!in_array($scope,self::EXCHANGE_SCOPES,true))throw new MasterDataValidationException(['Unbekannter Stammdatenbereich.']);
    }

    /** @param array<string,mixed> $item */
    private function importItem(string $scope,array $item,string $now):void
    {
        $id=(string)($item['id']??'');
        if(!Uuid::isValid($id))throw new MasterDataValidationException(['Ein Datensatz enthält keine gültige interne ID.']);
        $active=filter_var($item['active']??true,FILTER_VALIDATE_BOOL,FILTER_NULL_ON_FAILURE);
        if($active===null)throw new MasterDataValidationException(['Der Aktivstatus eines Datensatzes ist ungültig.']);
        if($scope==='trailing-texts'){
            $data=$this->trailingTextData($item)+['id'=>$id,'created_at'=>$now,'updated_at'=>$now];$this->repository->upsertTrailingText($data);return;
        }
        if($scope==='suppliers'){
            $data=$this->supplierData($item)+['id'=>$id,'active'=>$active?1:0,'created_at'=>$now,'updated_at'=>$now];$this->repository->upsertSupplier($data);return;
        }
        if($scope==='categories'){
            $parentId=$this->nullableUuid($item['parentId']??null,'Übergeordnete Kategorie');
            if($parentId!==null&&$this->repository->category($parentId)===null)throw new MasterDataValidationException(['Eine übergeordnete Kategorie aus der Importdatei fehlt.']);
            $sort=filter_var($item['sortOrder']??0,FILTER_VALIDATE_INT);
            if($sort===false||$sort<0)throw new MasterDataValidationException(['Sortierung muss eine nicht negative ganze Zahl sein.']);
            $data=['id'=>$id,'parent_id'=>$parentId,'name'=>$this->requiredText($item,'name','Bezeichnung',150),'description'=>$this->optionalText($item,'description',2000),'sort_order'=>$sort,'active'=>$active?1:0,'created_at'=>$now,'updated_at'=>$now];
            $this->repository->upsertCategory($data);return;
        }
        if($scope==='cost-items'){
            $data=$this->costItemData($item,true)+['id'=>$id,'active'=>$active?1:0,'created_at'=>$now,'updated_at'=>$now];$this->repository->upsertCostItem($data);return;
        }
        [$service,$components]=$this->serviceData($item);$service+=['id'=>$id,'active'=>$active?1:0,'created_at'=>$now,'updated_at'=>$now];
        $this->repository->upsertServiceTemplate($service);$this->repository->replaceServiceComponents($id,$this->prepareComponents($id,$components));
    }

    /** @param list<array<string,mixed>> $items @return list<array<string,mixed>> */
    private function sortedImportedCategories(array $items):array
    {
        $byId=[];$parents=[];
        foreach($this->repository->categories(true) as $category)$parents[(string)$category['id']]=$category['parent_id'];
        foreach($items as $item){$id=(string)($item['id']??'');if(!Uuid::isValid($id))throw new MasterDataValidationException(['Eine Kategorie enthält keine gültige interne ID.']);if(isset($byId[$id]))throw new MasterDataValidationException(['Eine Kategorie ist in der Importdatei doppelt enthalten.']);$byId[$id]=$item;$parents[$id]=$this->nullableUuid($item['parentId']??null,'Übergeordnete Kategorie');}
        $result=[];$visiting=[];$done=[];
        $visit=function(string $id)use(&$visit,&$byId,&$parents,&$result,&$visiting,&$done):void{
            if(isset($done[$id]))return;if(isset($visiting[$id]))throw new MasterDataValidationException(['Die importierte Kategoriehierarchie enthält einen Kreis.']);$visiting[$id]=true;
            $parent=$parents[$id]??null;if($parent!==null){if(!array_key_exists($parent,$parents))throw new MasterDataValidationException(['Eine übergeordnete Kategorie aus der Importdatei fehlt.']);$visit($parent);}
            unset($visiting[$id]);$done[$id]=true;if(isset($byId[$id]))$result[]=$byId[$id];
        };
        foreach(array_keys($byId) as $id)$visit($id);return$result;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createTrailingText(array $input): array
    {
        $data = $this->trailingTextData($input);
        $data['id'] = Uuid::v4();
        $data['created_at'] = $data['updated_at'] = Clock::now();
        $this->write(fn () => $this->repository->createTrailingText($data));
        return $this->mapTrailingText($this->repository->trailingText($data['id']));
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function updateTrailingText(string $id, array $input): array
    {
        if ($this->repository->trailingText($id) === null) {
            throw new MasterDataValidationException(['Schlepptext wurde nicht gefunden.']);
        }
        $data = $this->trailingTextData($input) + ['id' => $id, 'updated_at' => Clock::now()];
        $this->write(fn () => $this->repository->updateTrailingText($data));
        return $this->mapTrailingText($this->repository->trailingText($id));
    }

    /** @return array{deleted: true, id: string} */
    public function deleteTrailingText(string $id): array
    {
        if ($this->repository->trailingText($id) === null) {
            throw new MasterDataValidationException(['Schlepptext wurde nicht gefunden.']);
        }
        if ($this->repository->trailingTextReferenced($id)) {
            throw new MasterDataValidationException([
                'Schlepptext ist noch mindestens einer Stammposition zugeordnet und kann nicht gelöscht werden.',
            ]);
        }
        $this->write(fn () => $this->repository->deleteTrailingText($id));
        return ['deleted' => true, 'id' => $id];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function preview(array $input): array
    {
        $rawComponents = $input['components'] ?? null;
        if (!is_array($rawComponents) || !array_is_list($rawComponents)) {
            throw new MasterDataValidationException(['Kalkulationsbausteine müssen als Liste übergeben werden.']);
        }
        $components = [];
        foreach ($rawComponents as $index => $component) {
            if (!is_array($component)) {
                throw new MasterDataValidationException([sprintf('Baustein %d ist ungültig.', $index + 1)]);
            }
            $type = (string) ($component['calculationType'] ?? '');
            if (!in_array($type, self::TYPES, true)) {
                throw new MasterDataValidationException([sprintf('Baustein %d hat einen ungültigen Kalkulationstyp.', $index + 1)]);
            }
            $components[] = [
                'type' => $type,
                'quantity' => $this->decimal($component, 'quantity', 'Menge', Decimal::QUANTITY_SCALE, false),
                'costPrice' => $this->decimal($component, 'costPrice', 'Kostenpreis', Decimal::COST_SCALE, false),
                'markupPercent' => $this->decimal($component, 'markupPercent', 'Zuschlag', Decimal::PERCENT_SCALE, true),
            ];
        }
        $result = $this->calculator->calculatePosition('1.000', $components);
        $result['componentSalesPrices'] = array_map(function (array $component): string {
            return $this->calculator->calculatePosition('1.000', [$component])['positionTotal'];
        }, $components);
        return $result;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function previewMasterPriceUpdates(array $input): array
    {
        $components = $input['components'] ?? null;
        if (!is_array($components) || !array_is_list($components)) {
            throw new MasterDataValidationException(['Kalkulationsbausteine müssen als Liste übergeben werden.']);
        }
        $updates = [];
        $changes = [];
        $warnings = [];
        foreach ($components as $index => $component) {
            if (!is_array($component) || ($component['priceMode'] ?? null) !== 'master') continue;
            $masterDataId = $this->nullableUuid($component['masterDataId'] ?? null, 'Artikelstammreferenz');
            if ($masterDataId === null) continue;
            $master = $this->repository->costItem($masterDataId);
            if ($master === null || !(bool) $master['active']) {
                $warnings[] = sprintf('Baustein „%s“: Stammartikel fehlt oder ist archiviert.', (string) ($component['name'] ?? 'Unbekannt'));
                continue;
            }
            $type = (string) ($component['calculationType'] ?? '');
            if (!in_array($type, self::TYPES, true)) {
                throw new MasterDataValidationException([sprintf('Baustein %d hat einen ungültigen Kalkulationstyp.', $index + 1)]);
            }
            $quantity = $this->decimal($component, 'quantity', 'Menge', Decimal::QUANTITY_SCALE, false);
            $oldCost = $this->decimal($component, 'costPrice', 'Kostenpreis', Decimal::COST_SCALE, false);
            $oldMarkup = $this->decimal($component, 'markupPercent', 'Zuschlag', Decimal::PERCENT_SCALE, true);
            $oldCostDecimal = Decimal::parse($oldCost, Decimal::COST_SCALE, 'Kostenpreis');
            $oldMarkupDecimal = Decimal::parse($oldMarkup, Decimal::PERCENT_SCALE, 'Zuschlag');
            $newCost = (string) $master['cost_price'];
            $newMarkup = (string) $master['markup_percent'];
            $newCostDecimal = Decimal::parse($newCost, Decimal::COST_SCALE, 'Kostenpreis');
            $newMarkupDecimal = Decimal::parse($newMarkup, Decimal::PERCENT_SCALE, 'Zuschlag');
            $oldSales = $this->calculator->calculatePosition('1.000', [[
                'type' => $type, 'quantity' => $quantity,
                'costPrice' => $oldCost, 'markupPercent' => $oldMarkup,
            ]])['positionTotal'];
            $newSales = $this->calculator->calculatePosition('1.000', [[
                'type' => $type, 'quantity' => $quantity,
                'costPrice' => $newCost, 'markupPercent' => $newMarkup,
            ]])['positionTotal'];
            if ($oldCostDecimal->compareTo($newCostDecimal) === 0 && $oldMarkupDecimal->compareTo($newMarkupDecimal) === 0) continue;
            $updates[] = [
                'index' => $index, 'costPrice' => $newCost, 'markupPercent' => $newMarkup,
                'salesPrice' => $newSales, 'sourceArticleNumber' => $master['article_number'],
            ];
            $changes[] = [
                'name' => (string) $component['name'], 'articleNumber' => $master['article_number'],
                'oldCostPrice' => $oldCost, 'newCostPrice' => $newCost,
                'oldMarkupPercent' => $oldMarkup, 'newMarkupPercent' => $newMarkup,
                'oldSalesPrice' => $oldSales, 'newSalesPrice' => $newSales,
            ];
        }
        return compact('updates', 'changes', 'warnings');
    }

    /** @param array<string,mixed> $input */
    public function createCategory(array $input): array
    {
        $data = $this->categoryData($input);
        $data['id'] = Uuid::v4();
        $data['created_at'] = $data['updated_at'] = Clock::now();
        $this->write(fn () => $this->repository->createCategory($data));
        return $this->mapCategory($this->requireCategory($data['id']));
    }

    /** @param array<string,mixed> $input */
    public function updateCategory(string $id, array $input): array
    {
        $this->requireCategory($id);
        $data = $this->categoryData($input);
        if ($this->repository->categoryWouldCycle($id, $data['parent_id'])) {
            throw new MasterDataValidationException(['Eine Kategorie kann nicht in sich selbst oder eine Unterkategorie verschoben werden.']);
        }
        $data['id'] = $id;
        $data['updated_at'] = Clock::now();
        $this->write(fn () => $this->repository->updateCategory($data));
        return $this->mapCategory($this->requireCategory($id));
    }

    public function setCategoryArchived(string $id, bool $archived): array
    {
        $category = $this->requireCategory($id);
        if ($archived && $this->repository->categoryHasActiveReferences($id)) {
            throw new MasterDataValidationException(['Kategorie kann erst archiviert werden, wenn aktive Unterkategorien und Stammartikel verschoben oder archiviert wurden.']);
        }
        if (!$archived && $category['parent_id'] !== null) {
            $parent = $this->requireCategory((string) $category['parent_id']);
            if (!(bool) $parent['active']) {
                throw new MasterDataValidationException(['Die übergeordnete Kategorie muss zuerst reaktiviert werden.']);
            }
        }
        $this->repository->setActive('master_categories', $id, !$archived, Clock::now());
        return $this->mapCategory($this->requireCategory($id));
    }

    /** @param array<string,mixed> $input */
    public function createSupplier(array $input): array
    {
        $data = $this->supplierData($input);
        $data['id'] = Uuid::v4();
        $data['created_at'] = $data['updated_at'] = Clock::now();
        $this->write(fn () => $this->repository->createSupplier($data));
        return $this->mapSupplier($this->requireSupplier($data['id']));
    }

    /** @param array<string,mixed> $input */
    public function updateSupplier(string $id, array $input): array
    {
        $this->requireSupplier($id);
        $data = $this->supplierData($input);
        $data['id'] = $id;
        $data['updated_at'] = Clock::now();
        $this->write(fn () => $this->repository->updateSupplier($data));
        return $this->mapSupplier($this->requireSupplier($id));
    }

    public function setSupplierArchived(string $id, bool $archived): array
    {
        $this->requireSupplier($id);
        if ($archived && $this->repository->supplierHasActiveItems($id)) {
            throw new MasterDataValidationException(['Lieferant kann erst archiviert werden, wenn zugeordnete aktive Stammartikel geändert oder archiviert wurden.']);
        }
        $this->repository->setActive('suppliers', $id, !$archived, Clock::now());
        return $this->mapSupplier($this->requireSupplier($id));
    }

    /** @param array<string,mixed> $input */
    public function createCostItem(array $input): array
    {
        $data = $this->costItemData($input);
        $data['id'] = Uuid::v4();
        $data['copied_from_id'] = null;
        $data['created_at'] = $data['updated_at'] = Clock::now();
        $this->write(fn () => $this->repository->createCostItem($data));
        return $this->mapCostItem($this->requireCostItem($data['id']));
    }

    /** @param array<string,mixed> $input */
    public function updateCostItem(string $id, array $input): array
    {
        $this->requireCostItem($id);
        $data = $this->costItemData($input);
        $data['id'] = $id;
        $data['updated_at'] = Clock::now();
        $this->write(fn () => $this->repository->updateCostItem($data));
        return $this->mapCostItem($this->requireCostItem($id));
    }

    public function copyCostItem(string $id): array
    {
        $source = $this->requireCostItem($id);
        $now = Clock::now();
        $copy = $source;
        $copy['id'] = Uuid::v4();
        $copy['article_number'] = null;
        $copy['name'] = 'Kopie von ' . $source['name'];
        $copy['copied_from_id'] = $id;
        $copy['created_at'] = $copy['updated_at'] = $now;
        unset($copy['active']);
        $this->write(fn () => $this->repository->createCostItem($this->only($copy, [
            'id', 'article_number', 'name', 'description', 'category_id', 'calculation_type', 'unit',
            'cost_price', 'markup_percent', 'supplier_id', 'supplier_article_number', 'copied_from_id',
            'created_at', 'updated_at',
        ])));
        return $this->mapCostItem($this->requireCostItem((string) $copy['id']));
    }

    public function setCostItemArchived(string $id, bool $archived): array
    {
        $item = $this->requireCostItem($id);
        if (!$archived && $item['category_id'] !== null) {
            $category = $this->requireCategory((string) $item['category_id']);
            if (!(bool) $category['active']) {
                throw new MasterDataValidationException(['Die zugeordnete Kategorie muss zuerst reaktiviert oder entfernt werden.']);
            }
        }
        if (!$archived && $item['supplier_id'] !== null) {
            $supplier = $this->requireSupplier((string) $item['supplier_id']);
            if (!(bool) $supplier['active']) {
                throw new MasterDataValidationException(['Der zugeordnete Lieferant muss zuerst reaktiviert oder entfernt werden.']);
            }
        }
        $this->repository->setActive('cost_items', $id, !$archived, Clock::now());
        return $this->mapCostItem($this->requireCostItem($id));
    }

    /** @param array<string,mixed> $input */
    public function createService(array $input): array
    {
        [$service, $components] = $this->serviceData($input);
        $service['id'] = Uuid::v4();
        $service['copied_from_id'] = null;
        $service['created_at'] = $service['updated_at'] = Clock::now();
        $components = $this->prepareComponents($service['id'], $components);

        $this->repository->beginImmediate();
        try {
            if ($service['article_number'] === null && filter_var($input['autoArticleNumber'] ?? false, FILTER_VALIDATE_BOOL)) {
                $service['article_number'] = $this->numberRanges->allocate('service_template', 'service_template', $service['id'], Uuid::v4(), new DateTimeImmutable());
            }
            $this->repository->createServiceTemplate($service);
            $this->repository->replaceServiceComponents($service['id'], $components);
            $this->repository->commit();
        } catch (Throwable $exception) {
            $this->repository->rollBack();
            $this->translateWriteException($exception);
        }
        return $this->serviceById($service['id']);
    }

    /** @param array<string,mixed> $input */
    public function updateService(string $id, array $input): array
    {
        $this->requireService($id);
        [$service, $components] = $this->serviceData($input);
        $service['id'] = $id;
        $service['updated_at'] = Clock::now();
        $components = $this->prepareComponents($id, $components);

        $this->repository->beginImmediate();
        try {
            if ($service['article_number'] === null && filter_var($input['autoArticleNumber'] ?? false, FILTER_VALIDATE_BOOL)) {
                $service['article_number'] = $this->numberRanges->allocate('service_template', 'service_template', $service['id'], Uuid::v4(), new DateTimeImmutable());
            }
            $this->repository->updateServiceTemplate($service);
            $this->repository->replaceServiceComponents($id, $components);
            $this->repository->commit();
        } catch (Throwable $exception) {
            $this->repository->rollBack();
            $this->translateWriteException($exception);
        }
        return $this->serviceById($id);
    }

    public function copyService(string $id): array
    {
        $source = $this->requireService($id);
        $components = $this->repository->serviceComponents($id);
        $newId = Uuid::v4();
        $now = Clock::now();
        $copy = [
            'id' => $newId,
            'article_number' => null,
            'short_text' => 'Kopie von ' . $source['short_text'],
            'long_text' => $source['long_text'],
            'unit' => $source['unit'],
            'trailing_text_id' => $source['trailing_text_id'],
            'copied_from_id' => $id,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $prepared = [];
        foreach ($components as $index => $component) {
            $component['id'] = Uuid::v4();
            $component['service_template_id'] = $newId;
            $component['sort_order'] = $index;
            $component['created_at'] = $component['updated_at'] = $now;
            $prepared[] = $this->only($component, [
                'id', 'service_template_id', 'master_data_id', 'source_article_number', 'name', 'calculation_type', 'unit',
                'quantity', 'cost_price', 'markup_percent', 'price_mode', 'sort_order', 'created_at', 'updated_at',
            ]);
        }

        $this->repository->beginImmediate();
        try {
            $this->repository->createServiceTemplate($copy);
            $this->repository->replaceServiceComponents($newId, $prepared);
            $this->repository->commit();
        } catch (Throwable $exception) {
            $this->repository->rollBack();
            $this->translateWriteException($exception);
        }
        return $this->serviceById($newId);
    }

    public function setServiceArchived(string $id, bool $archived): array
    {
        $this->requireService($id);
        $this->repository->setActive('service_templates', $id, !$archived, Clock::now());
        return $this->serviceById($id);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function categoryData(array $input): array
    {
        $name = $this->requiredText($input, 'name', 'Bezeichnung', 150);
        $parentId = $this->nullableUuid($input['parentId'] ?? null, 'Übergeordnete Kategorie');
        if ($parentId !== null) {
            $parent = $this->requireCategory($parentId);
            if (!(bool) $parent['active']) {
                throw new MasterDataValidationException(['Eine archivierte Kategorie kann nicht als übergeordnete Kategorie verwendet werden.']);
            }
        }
        $sortOrder = filter_var($input['sortOrder'] ?? 0, FILTER_VALIDATE_INT);
        if ($sortOrder === false || $sortOrder < 0) {
            throw new MasterDataValidationException(['Sortierung muss eine nicht negative ganze Zahl sein.']);
        }
        return [
            'parent_id' => $parentId,
            'name' => $name,
            'description' => $this->optionalText($input, 'description', 2000),
            'sort_order' => $sortOrder,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function supplierData(array $input): array
    {
        return [
            'supplier_number' => $this->nullableText($input, 'supplierNumber', 80),
            'name' => $this->requiredText($input, 'name', 'Name', 200),
            'contact' => $this->optionalText($input, 'contact', 2000),
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function costItemData(array $input,bool $allowArchivedReferences=false): array
    {
        $type = (string) ($input['calculationType'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            throw new MasterDataValidationException(['Ungültiger Kalkulationstyp.']);
        }
        $costPrice = $this->decimal($input, 'costPrice', 'Einkaufspreis/Kostensatz', Decimal::COST_SCALE, false);
        $markup = $this->decimal($input, 'markupPercent', 'Zuschlag', Decimal::PERCENT_SCALE, true);
        $categoryId = $this->nullableUuid($input['categoryId'] ?? null, 'Kategorie');
        if ($categoryId !== null) {
            $category = $this->repository->category($categoryId);
            if ($category === null) throw new MasterDataValidationException(['Kategorie wurde nicht gefunden.']);
            if (!$allowArchivedReferences&&!(bool) $category['active']) throw new MasterDataValidationException(['Eine archivierte Kategorie kann nicht zugeordnet werden.']);
        }
        $supplierId = $this->nullableUuid($input['supplierId'] ?? null, 'Lieferant');
        if ($supplierId !== null) {
            $supplier = $this->repository->supplier($supplierId);
            if ($supplier === null) throw new MasterDataValidationException(['Lieferant wurde nicht gefunden.']);
            if (!$allowArchivedReferences&&!(bool) $supplier['active']) throw new MasterDataValidationException(['Ein archivierter Lieferant kann nicht zugeordnet werden.']);
        }
        return [
            'article_number' => $this->nullableText($input, 'articleNumber', 100),
            'name' => $this->requiredText($input, 'name', 'Bezeichnung', 250),
            'description' => $this->optionalText($input, 'description', 5000),
            'category_id' => $categoryId,
            'calculation_type' => $type,
            'unit' => $this->requiredText($input, 'unit', 'Einheit', 40),
            'cost_price' => $costPrice,
            'markup_percent' => $markup,
            'supplier_id' => $supplierId,
            'supplier_article_number' => $this->optionalText($input, 'supplierArticleNumber', 100),
        ];
    }

    /** @param array<string,mixed> $input @return array{array<string,mixed>,list<array<string,mixed>>} */
    private function serviceData(array $input): array
    {
        $components = $input['components'] ?? null;
        if (!is_array($components) || !array_is_list($components)) {
            throw new MasterDataValidationException(['Kalkulationsbausteine müssen als Liste übergeben werden.']);
        }
        $validated = [];
        foreach ($components as $index => $component) {
            if (!is_array($component)) {
                throw new MasterDataValidationException([sprintf('Baustein %d ist ungültig.', $index + 1)]);
            }
            $type = (string) ($component['calculationType'] ?? '');
            if (!in_array($type, self::TYPES, true)) {
                throw new MasterDataValidationException([sprintf('Baustein %d hat einen ungültigen Kalkulationstyp.', $index + 1)]);
            }
            $masterDataId = $this->nullableUuid($component['masterDataId'] ?? null, 'Artikelstammreferenz');
            $masterData = null;
            if ($masterDataId !== null) {
                $masterData = $this->repository->costItem($masterDataId);
                if ($masterData === null) throw new MasterDataValidationException([sprintf('Stammartikel für Baustein %d wurde nicht gefunden.', $index + 1)]);
            }
            $priceMode = (string) ($component['priceMode'] ?? ($masterDataId === null ? 'manual' : 'master'));
            if ($priceMode === 'locked') $priceMode = 'manual';
            if (!in_array($priceMode, ['master', 'manual'], true)) {
                throw new MasterDataValidationException([sprintf('Preisbezug für Baustein %d ist ungültig.', $index + 1)]);
            }
            if ($masterDataId === null && $priceMode !== 'manual') {
                throw new MasterDataValidationException([sprintf('Baustein %d ohne Artikelstammreferenz muss einen manuellen Preis verwenden.', $index + 1)]);
            }
            $costPrice = $this->decimal($component, 'costPrice', 'Bausteinkostenpreis', Decimal::COST_SCALE, false);
            $markupPercent = $this->decimal($component, 'markupPercent', 'Bausteinzuschlag', Decimal::PERCENT_SCALE, true);
            $validated[] = [
                'id' => isset($component['id']) && Uuid::isValid((string) $component['id']) ? (string) $component['id'] : Uuid::v4(),
                'master_data_id' => $masterDataId,
                'source_article_number' => $masterDataId !== null
                    ? (array_key_exists('sourceArticleNumber', $component)
                        ? $this->nullableText($component, 'sourceArticleNumber', 100)
                        : $masterData['article_number'])
                    : null,
                'name' => $this->requiredText($component, 'name', 'Bausteinbezeichnung', 250),
                'calculation_type' => $type,
                'unit' => $this->requiredText($component, 'unit', 'Bausteineinheit', 40),
                'quantity' => $this->decimal($component, 'quantity', 'Bausteinmenge', Decimal::QUANTITY_SCALE, false),
                'cost_price' => $costPrice,
                'markup_percent' => $markupPercent,
                'price_mode' => $priceMode,
            ];
        }
        return [[
            'article_number' => $this->nullableText($input, 'articleNumber', 100),
            'short_text' => $this->requiredText($input, 'shortText', 'Kurztext', 250),
            'long_text' => $this->optionalText($input, 'longText', 10000),
            'unit' => $this->requiredText($input, 'unit', 'Einheit', 40),
            'trailing_text_id' => $this->validatedTrailingTextId($input['trailingTextId'] ?? null),
        ], $validated];
    }

    /** @param list<array<string,mixed>> $components @return list<array<string,mixed>> */
    private function prepareComponents(string $serviceId, array $components): array
    {
        $now = Clock::now();
        foreach ($components as $index => &$component) {
            $component['service_template_id'] = $serviceId;
            $component['sort_order'] = $index;
            $component['created_at'] = $component['updated_at'] = $now;
        }
        return $components;
    }

    /** @return array<string,mixed> */
    private function serviceById(string $id): array
    {
        return $this->mapService($this->requireService($id), $this->repository->serviceComponents($id));
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function mapCategory(array $row): array
    {
        return [
            'id' => $row['id'], 'parentId' => $row['parent_id'], 'name' => $row['name'],
            'description' => $row['description'], 'sortOrder' => (int) $row['sort_order'], 'active' => (bool) $row['active'],
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function mapSupplier(array $row): array
    {
        return [
            'id' => $row['id'], 'supplierNumber' => $row['supplier_number'], 'name' => $row['name'],
            'contact' => $row['contact'], 'active' => (bool) $row['active'],
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function mapCostItem(array $row): array
    {
        $calculation = $this->calculator->calculatePosition('1.000', [[
            'type' => (string) $row['calculation_type'], 'quantity' => '1.000',
            'costPrice' => (string) $row['cost_price'], 'markupPercent' => (string) $row['markup_percent'],
        ]]);
        return [
            'id' => $row['id'], 'articleNumber' => $row['article_number'], 'name' => $row['name'],
            'description' => $row['description'], 'categoryId' => $row['category_id'],
            'categoryName' => $row['category_name'] ?? null, 'calculationType' => $row['calculation_type'],
            'unit' => $row['unit'], 'costPrice' => $row['cost_price'], 'markupPercent' => $row['markup_percent'],
            'salesPrice' => $calculation['calculatedUnitPrice'], 'supplierId' => $row['supplier_id'],
            'supplierName' => $row['supplier_name'] ?? null, 'supplierArticleNumber' => $row['supplier_article_number'],
            'active' => (bool) $row['active'], 'copiedFromId' => $row['copied_from_id'],
        ];
    }

    /** @param array<string,mixed> $row @param list<array<string,mixed>> $components @return array<string,mixed> */
    private function mapService(array $row, array $components): array
    {
        $mappedComponents = [];
        $calculationInput = [];
        foreach ($components as $component) {
            $componentCalculation = $this->calculator->calculatePosition('1.000', [[
                'type' => $component['calculation_type'], 'quantity' => $component['quantity'],
                'costPrice' => $component['cost_price'], 'markupPercent' => $component['markup_percent'],
            ]]);
            $mappedComponents[] = [
                'id' => $component['id'], 'masterDataId' => $component['master_data_id'], 'name' => $component['name'],
                'sourceArticleNumber' => $component['source_article_number'],
                'calculationType' => $component['calculation_type'], 'unit' => $component['unit'],
                'quantity' => $component['quantity'], 'costPrice' => $component['cost_price'],
                'markupPercent' => $component['markup_percent'], 'priceMode' => $component['price_mode'] === 'locked' ? 'manual' : $component['price_mode'],
                'salesPrice' => $componentCalculation['positionTotal'],
            ];
            $calculationInput[] = [
                'type' => $component['calculation_type'], 'quantity' => $component['quantity'],
                'costPrice' => $component['cost_price'], 'markupPercent' => $component['markup_percent'],
            ];
        }
        $calculation = $this->calculator->calculatePosition('1.000', $calculationInput);
        $calculation['markupTotal'] = $calculation['contributionMargin'];
        $trailing = $row['trailing_text_id'] !== null
            ? $this->repository->trailingText((string) $row['trailing_text_id'])
            : null;
        return [
            'id' => $row['id'], 'articleNumber' => $row['article_number'], 'shortText' => $row['short_text'], 'longText' => $row['long_text'],
            'unit' => $row['unit'], 'active' => (bool) $row['active'], 'copiedFromId' => $row['copied_from_id'],
            'trailingTextId' => $row['trailing_text_id'],
            'trailingText' => $trailing !== null ? $this->mapTrailingText($trailing) : null,
            'components' => $mappedComponents, 'calculation' => $calculation,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function trailingTextData(array $input): array
    {
        return [
            'code' => $this->requiredText($input, 'code', 'Kürzel', 30),
            'name' => $this->requiredText($input, 'name', 'Bezeichnung', 500),
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function mapTrailingText(array $row): array
    {
        return ['id' => $row['id'], 'code' => $row['code'], 'name' => $row['name']];
    }

    private function validatedTrailingTextId(mixed $value): ?string
    {
        $id = $this->nullableUuid($value, 'Schlepptext');
        if ($id !== null && $this->repository->trailingText($id) === null) {
            throw new MasterDataValidationException(['Schlepptext wurde nicht gefunden.']);
        }
        return $id;
    }

    /** @return array<string,mixed> */
    private function requireCategory(string $id): array
    {
        $row = Uuid::isValid($id) ? $this->repository->category($id) : null;
        if ($row === null) throw new MasterDataValidationException(['Kategorie wurde nicht gefunden.']);
        return $row;
    }

    /** @return array<string,mixed> */
    private function requireSupplier(string $id): array
    {
        $row = Uuid::isValid($id) ? $this->repository->supplier($id) : null;
        if ($row === null) throw new MasterDataValidationException(['Lieferant wurde nicht gefunden.']);
        return $row;
    }

    /** @return array<string,mixed> */
    private function requireCostItem(string $id): array
    {
        $row = Uuid::isValid($id) ? $this->repository->costItem($id) : null;
        if ($row === null) throw new MasterDataValidationException(['Stammartikel wurde nicht gefunden.']);
        return $row;
    }

    /** @return array<string,mixed> */
    private function requireService(string $id): array
    {
        $row = Uuid::isValid($id) ? $this->repository->serviceTemplate($id) : null;
        if ($row === null) throw new MasterDataValidationException(['Stammposition wurde nicht gefunden.']);
        return $row;
    }

    /** @param array<string,mixed> $input */
    private function requiredText(array $input, string $key, string $label, int $maximum): string
    {
        $value = trim((string) ($input[$key] ?? ''));
        if ($value === '') throw new MasterDataValidationException([$label . ' darf nicht leer sein.']);
        if (mb_strlen($value) > $maximum) throw new MasterDataValidationException([$label . ' ist zu lang.']);
        return $value;
    }

    /** @param array<string,mixed> $input */
    private function optionalText(array $input, string $key, int $maximum): string
    {
        $value = trim((string) ($input[$key] ?? ''));
        if (mb_strlen($value) > $maximum) throw new MasterDataValidationException(['Eingabe ist zu lang.']);
        return $value;
    }

    /** @param array<string,mixed> $input */
    private function nullableText(array $input, string $key, int $maximum): ?string
    {
        $value = $this->optionalText($input, $key, $maximum);
        return $value === '' ? null : $value;
    }

    private function nullableUuid(mixed $value, string $label): ?string
    {
        if ($value === null || trim((string) $value) === '') return null;
        if (!Uuid::isValid((string) $value)) throw new MasterDataValidationException([$label . ' ist ungültig.']);
        return (string) $value;
    }

    /** @param array<string,mixed> $input */
    private function decimal(array $input, string $key, string $label, int $scale, bool $allowNegative): string
    {
        try {
            $value = Decimal::parse((string) ($input[$key] ?? ''), $scale, $label);
        } catch (Throwable $exception) {
            throw new MasterDataValidationException([$exception->getMessage()]);
        }
        if (!$allowNegative && $value->isNegative()) {
            throw new MasterDataValidationException([$label . ' darf nicht negativ sein.']);
        }
        return (string) $value;
    }

    private function write(callable $operation): void
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            $this->translateWriteException($exception);
        }
    }

    private function translateWriteException(Throwable $exception): never
    {
        if ($exception instanceof MasterDataValidationException) throw $exception;
        if ($exception instanceof PDOException && str_contains($exception->getMessage(), 'UNIQUE constraint failed')) {
            if (str_contains($exception->getMessage(), 'trailing_texts.code')) {
                throw new MasterDataValidationException(['Dieses Schlepptext-Kürzel wird bereits verwendet.']);
            }
            throw new MasterDataValidationException(['Bezeichnung oder Nummer wird im gewählten Bereich bereits verwendet.']);
        }
        throw $exception;
    }

    /** @param array<string,mixed> $data @param list<string> $keys @return array<string,mixed> */
    private function only(array $data, array $keys): array
    {
        return array_intersect_key($data, array_flip($keys));
    }
}
