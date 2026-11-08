<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\MasterData\MasterDataValidationException;
use App\Domain\Offer\OfferCalculator;
use App\Domain\Offer\OfferValidator;

final readonly class OfferCalculationService
{
    public function __construct(private MasterDataService $masterData, private OfferValidator $validator, private OfferCalculator $calculator)
    {
    }

    public function library(): array
    {
        $data=$this->masterData->overview(false);
        return ['costItems'=>$data['costItems'],'services'=>$data['services']];
    }

    public function calculate(array $offer): array
    {
        $this->validator->validate($offer);
        return $this->calculator->calculate($offer);
    }

    public function previewPriceUpdates(array $input): array
    {
        return $this->masterData->previewMasterPriceUpdates($input);
    }

    public function savePosition(array $input): array
    {
        $position=$input['position']??null;if(!is_array($position))throw new MasterDataValidationException(['Angebotsposition fehlt.']);
        $shortText=trim((string)($position['shortText']??''));if($shortText==='')throw new MasterDataValidationException(['Kurztext ist erforderlich.']);
        $sourceNumber=trim((string)($position['sourceServiceNumber']??''));
        $allServices=$this->masterData->overview(true)['services'];
        $duplicates=[];foreach($allServices as $service){
            if(($sourceNumber!==''&&strcasecmp((string)($service['articleNumber']??''),$sourceNumber)===0)||strcasecmp((string)$service['shortText'],$shortText)===0)$duplicates[]=['id'=>$service['id'],'articleNumber'=>$service['articleNumber'],'shortText'=>$service['shortText'],'active'=>$service['active']];
        }
        if($duplicates!==[]&&!filter_var($input['confirmDuplicate']??false,FILTER_VALIDATE_BOOL)&&empty($input['updateExistingId']))return ['saved'=>null,'duplicates'=>$duplicates];
        $components=[];foreach(($position['components']??[]) as $component){if(!is_array($component))continue;$components[]=[
            'masterDataId'=>$component['masterDataId']??null,'sourceArticleNumber'=>$component['sourceArticleNumber']??null,
            'name'=>$component['name']??'','calculationType'=>$component['calculationType']??'', 'unit'=>$component['unit']??'',
            'quantity'=>$component['quantity']??'','costPrice'=>$component['costPrice']??'','markupPercent'=>$component['markupPercent']??'',
            'priceMode'=>$component['priceMode']??(($component['masterDataId']??null)===null?'manual':'locked'),
        ];}
        $payload=['articleNumber'=>null,'autoArticleNumber'=>true,'shortText'=>$shortText,'longText'=>$position['longText']??'',
            'unit'=>$position['unit']??'','components'=>$components];
        $updateId=trim((string)($input['updateExistingId']??''));
        if($updateId!==''){
            $existing=array_values(array_filter($allServices,static fn(array $service):bool=>$service['id']===$updateId));
            if($existing===[])throw new MasterDataValidationException(['Zu aktualisierende Stammposition wurde nicht gefunden.']);
            $payload['articleNumber']=$existing[0]['articleNumber'];$payload['autoArticleNumber']=false;
        }
        $saved=$updateId!==''?$this->masterData->updateService($updateId,$payload):$this->masterData->createService($payload);
        return ['saved'=>$saved,'duplicates'=>$duplicates];
    }
}
