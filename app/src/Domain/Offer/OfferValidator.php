<?php

declare(strict_types=1);

namespace App\Domain\Offer;

use App\Domain\Calculation\Decimal;
use App\Support\Uuid;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;

final class OfferValidator
{
    /** @param array<string, mixed> $offer */
    public function validate(array $offer): void
    {
        $errors = [];
        $seenIds = [];
        $this->rejectUnknown($offer,['schemaVersion','id','projectId','customerId','offerNumber','revision','status','title','createdAt','updatedAt','submittedAt','documentDate','documentTerms','offerAdjustment','nodes','calculation'],'offer',$errors);

        if (($offer['schemaVersion'] ?? null) !== 1) {
            $errors[] = 'schemaVersion muss 1 sein.';
        }
        foreach (['id'] as $field) {
            if (!is_string($offer[$field] ?? null) || !Uuid::isValid($offer[$field])) {
                $errors[] = $field . ' muss eine gueltige UUID sein.';
            }
        }
        if(($offer['projectId']??null)!==null&&(!is_string($offer['projectId'])||!Uuid::isValid($offer['projectId'])))$errors[]='projectId muss eine gültige UUID oder null sein.';
        if(array_key_exists('customerId',$offer)&&$offer['customerId']!==null&&(!is_string($offer['customerId'])||!Uuid::isValid($offer['customerId'])))$errors[]='customerId muss eine gültige UUID oder null sein.';
        if (!in_array($offer['status'] ?? null,['draft','submitted','commissioned','rejected'],true)) {
            $errors[] = 'Der Angebotsstatus ist ungültig.';
        }
        if (!is_string($offer['title'] ?? null) || trim($offer['title']) === '') {
            $errors[] = 'Die Angebotsbezeichnung darf nicht leer sein.';
        } elseif (mb_strlen($offer['title']) > 200) {
            $errors[] = 'Die Angebotsbezeichnung darf höchstens 200 Zeichen lang sein.';
        }
        if (($offer['offerNumber'] ?? null) !== null && !is_string($offer['offerNumber'])) {
            $errors[] = 'offerNumber muss Text oder null sein.';
        }
        if (!is_int($offer['revision'] ?? null) || $offer['revision'] < 0) {
            $errors[] = 'revision muss eine nichtnegative Ganzzahl sein.';
        }
        foreach (['createdAt', 'updatedAt'] as $field) {
            if (!$this->isTimestamp($offer[$field] ?? null)) {
                $errors[] = $field . ' muss ein gültiger ISO-8601-Zeitstempel sein.';
            }
        }
        if(array_key_exists('submittedAt',$offer)&&$offer['submittedAt']!==null&&!$this->isTimestamp($offer['submittedAt']))$errors[]='submittedAt muss ein gültiger ISO-8601-Zeitstempel sein.';
        if(array_key_exists('documentDate',$offer)&&$offer['documentDate']!==null&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$offer['documentDate']))$errors[]='documentDate muss ein gültiges Datum sein.';
        if (!is_array($offer['nodes'] ?? null) || !array_is_list($offer['nodes'])) {
            $errors[] = 'nodes muss eine Liste sein.';
        } else {
            foreach ($offer['nodes'] as $index => $node) {
                $this->validateNode($node, sprintf('nodes[%d]', $index), $seenIds, $errors);
            }
        }
        if (array_key_exists('documentTerms',$offer)) $this->validateDocumentTerms($offer['documentTerms'],$errors);
        if (array_key_exists('offerAdjustment',$offer)) $this->validateAdjustment($offer['offerAdjustment'],$errors);

        if ($errors !== []) {
            throw new OfferValidationException($errors);
        }
    }

    /** @param mixed $terms @param list<string> $errors */
    private function validateDocumentTerms(mixed $terms,array &$errors):void
    {
        if(!is_array($terms)){$errors[]='documentTerms muss ein Objekt sein.';return;}
        $this->rejectUnknown($terms,['introText','closingText','paymentTerms','additionalTerms','validityMode','validityDays','validityDate'],'documentTerms',$errors);
        foreach(['introText','closingText','paymentTerms','additionalTerms','validityDate'] as $field)if(array_key_exists($field,$terms)&&$terms[$field]!==null&&!is_string($terms[$field]))$errors[]='documentTerms.'.$field.' muss Text oder null sein.';
        if(!in_array($terms['validityMode']??'default',['default','duration','date'],true))$errors[]='documentTerms.validityMode ist ungültig.';
        if(array_key_exists('validityDays',$terms)&&$terms['validityDays']!==null&&(!is_int($terms['validityDays'])||$terms['validityDays']<1||$terms['validityDays']>3650))$errors[]='documentTerms.validityDays ist ungültig.';
        if(($terms['validityMode']??'default')==='date'&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($terms['validityDate']??'')))$errors[]='documentTerms.validityDate ist ungültig.';
        if(($terms['validityMode']??'default')==='duration'&&(!is_int($terms['validityDays']??null)||$terms['validityDays']<1||$terms['validityDays']>3650))$errors[]='documentTerms.validityDays ist erforderlich.';
    }

    /**
     * @param mixed $node
     * @param array<string, true> $seenIds
     * @param list<string> $errors
     */
    private function validateNode(mixed $node, string $path, array &$seenIds, array &$errors): void
    {
        if (!is_array($node)) {
            $errors[] = $path . ' muss ein Objekt sein.';
            return;
        }

        $id = $node['id'] ?? null;
        if (!is_string($id) || !Uuid::isValid($id)) {
            $errors[] = $path . '.id muss eine gueltige UUID sein.';
        } elseif (isset($seenIds[$id])) {
            $errors[] = $path . '.id kommt mehrfach vor.';
        } else {
            $seenIds[$id] = true;
        }

        $type = $node['type'] ?? null;
        if ($type === 'title') {
            $this->rejectUnknown($node,['id','type','titleType','name','description','children','calculation'],$path,$errors);
            if (!in_array($node['titleType'] ?? 'normal', ['normal','bedarf','alternative'], true)) $errors[]=$path.'.titleType ist ungueltig.';
            if (!is_string($node['name'] ?? null) || trim($node['name']) === '') {
                $errors[] = $path . '.name darf nicht leer sein.';
            }
            if (!is_string($node['description'] ?? null)) {
                $errors[] = $path . '.description muss Text sein.';
            }
            if (!is_array($node['children'] ?? null) || !array_is_list($node['children'])) {
                $errors[] = $path . '.children muss eine Liste sein.';
                return;
            }
            foreach ($node['children'] as $index => $child) {
                $this->validateNode($child, sprintf('%s.children[%d]', $path, $index), $seenIds, $errors);
            }
            return;
        }

        if ($type === 'text') {
            $this->rejectUnknown($node,['id','type','title','text','calculation'],$path,$errors);
            if(array_key_exists('title',$node)&&!is_string($node['title']))$errors[]=$path.'.title muss Text sein.';
            if(!is_string($node['text']??null)||trim((string)$node['text'])==='')$errors[]=$path.'.text darf nicht leer sein.';
            return;
        }

        if ($type !== 'position') {
            $errors[] = $path . '.type muss title, position oder text sein.';
            return;
        }
        $this->rejectUnknown($node,['id','type','positionType','workStatus','shortText','longText','trailingText','quantity','unit','manualUnitPrice','components','sourceServiceId','sourceServiceNumber','calculation'],$path,$errors);

        if (!in_array($node['positionType'] ?? null, ['normal', 'bedarf', 'alternative'], true)) {
            $errors[] = $path . '.positionType ist ungueltig.';
        }
        if (!in_array($node['workStatus'] ?? 'open', ['open', 'in_progress', 'done'], true)) {
            $errors[] = $path . '.workStatus ist ungueltig.';
        }
        foreach (['shortText', 'unit'] as $field) {
            if (!is_string($node[$field] ?? null) || trim($node[$field]) === '') {
                $errors[] = $path . '.' . $field . ' darf nicht leer sein.';
            }
        }
        if (!is_string($node['longText'] ?? null)) {
            $errors[] = $path . '.longText muss Text sein.';
        }
        if (($node['sourceServiceId'] ?? null) !== null
            && (!is_string($node['sourceServiceId']) || !Uuid::isValid($node['sourceServiceId']))) {
            $errors[] = $path . '.sourceServiceId muss eine gültige UUID oder null sein.';
        }
        if (($node['sourceServiceNumber'] ?? null) !== null && !is_string($node['sourceServiceNumber'])) {
            $errors[] = $path . '.sourceServiceNumber muss Text oder null sein.';
        }
        if (array_key_exists('trailingText', $node) && $node['trailingText'] !== null) {
            $trailing = $node['trailingText'];
            if (!is_array($trailing)) {
                $errors[] = $path . '.trailingText muss ein Objekt oder null sein.';
            } else {
                $this->rejectUnknown($trailing, ['id', 'code', 'name'], $path . '.trailingText', $errors);
                if (($trailing['id'] ?? null) !== null
                    && (!is_string($trailing['id']) || !Uuid::isValid($trailing['id']))) {
                    $errors[] = $path . '.trailingText.id ist ungültig.';
                }
                foreach (['code', 'name'] as $field) {
                    if (!is_string($trailing[$field] ?? null) || trim($trailing[$field]) === '') {
                        $errors[] = $path . '.trailingText.' . $field . ' darf nicht leer sein.';
                    }
                }
            }
        }
        try {
            $quantity = Decimal::parse((string) ($node['quantity'] ?? ''), Decimal::QUANTITY_SCALE, $path . '.quantity');
            if ($quantity->isNegative()) {
                $errors[] = $path . '.quantity darf nicht negativ sein.';
            }
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        }
        if (!is_array($node['components'] ?? null) || !array_is_list($node['components'])) {
            $errors[] = $path . '.components muss eine Liste sein.';
            return;
        }
        if (($node['manualUnitPrice'] ?? null) !== null) {
            try {
                $manualPrice = Decimal::parse((string) $node['manualUnitPrice'], Decimal::MONEY_SCALE, $path . '.manualUnitPrice');
                if ($manualPrice->isNegative()) {
                    $errors[] = $path . '.manualUnitPrice darf nicht negativ sein.';
                }
            } catch (InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
            }
        }
        foreach ($node['components'] as $index => $component) {
            $this->validateComponent($component, sprintf('%s.components[%d]', $path, $index), $seenIds, $errors);
        }
    }

    /** @param mixed $adjustment @param list<string> $errors */
    private function validateAdjustment(mixed $adjustment,array &$errors):void
    {
        if(!is_array($adjustment)){$errors[]='offerAdjustment muss ein Objekt sein.';return;}
        $this->rejectUnknown($adjustment,['type','mode','value','label'],'offerAdjustment',$errors);
        $type=$adjustment['type']??'none';$mode=$adjustment['mode']??'percentage';
        if(!in_array($type,['none','discount','surcharge'],true))$errors[]='offerAdjustment.type ist ungueltig.';
        if(!in_array($mode,['percentage','absolute'],true))$errors[]='offerAdjustment.mode ist ungueltig.';
        if(array_key_exists('label',$adjustment)&&$adjustment['label']!==null&&!is_string($adjustment['label']))$errors[]='offerAdjustment.label muss Text oder null sein.';
        if($type==='none')return;
        try{
            $scale=$mode==='percentage'?Decimal::PERCENT_SCALE:Decimal::MONEY_SCALE;
            $value=Decimal::parse((string)($adjustment['value']??''),$scale,'offerAdjustment.value');
            if($value->isNegative())$errors[]='offerAdjustment.value darf nicht negativ sein.';
            if($type==='discount'&&$mode==='percentage'&&$value->isGreaterThan('100'))$errors[]='Ein prozentualer Rabatt darf höchstens 100 % betragen.';
        }catch(InvalidArgumentException $exception){$errors[]=$exception->getMessage();}
    }

    /**
     * @param mixed $component
     * @param array<string, true> $seenIds
     * @param list<string> $errors
     */
    private function validateComponent(mixed $component, string $path, array &$seenIds, array &$errors): void
    {
        if (!is_array($component)) {
            $errors[] = $path . ' muss ein Objekt sein.';
            return;
        }
        $this->rejectUnknown($component,['id','name','calculationType','unit','quantity','costPrice','markupPercent','masterDataId','priceLocked','priceMode','sourceArticleNumber','masterDataSnapshot','salesPrice'],$path,$errors);
        $id = $component['id'] ?? null;
        if (!is_string($id) || !Uuid::isValid($id)) {
            $errors[] = $path . '.id muss eine gueltige UUID sein.';
        } elseif (isset($seenIds[$id])) {
            $errors[] = $path . '.id kommt mehrfach vor.';
        } else {
            $seenIds[$id] = true;
        }
        if (!is_string($component['name'] ?? null) || trim($component['name']) === '') {
            $errors[] = $path . '.name darf nicht leer sein.';
        }
        if (!in_array($component['calculationType'] ?? null, ['material', 'labor', 'external', 'other'], true)) {
            $errors[] = $path . '.calculationType ist ungueltig.';
        }
        if (!is_string($component['unit'] ?? null) || trim($component['unit']) === '') {
            $errors[] = $path . '.unit darf nicht leer sein.';
        }
        if (($component['masterDataId'] ?? null) !== null
            && (!is_string($component['masterDataId']) || !Uuid::isValid($component['masterDataId']))) {
            $errors[] = $path . '.masterDataId muss eine gültige UUID oder null sein.';
        }
        if (($component['sourceArticleNumber'] ?? null) !== null && !is_string($component['sourceArticleNumber'])) {
            $errors[] = $path . '.sourceArticleNumber muss Text oder null sein.';
        }
        foreach ([
            'quantity' => Decimal::QUANTITY_SCALE,
            'costPrice' => Decimal::COST_SCALE,
            'markupPercent' => Decimal::PERCENT_SCALE,
        ] as $field => $scale) {
            try {
                $value = Decimal::parse((string) ($component[$field] ?? ''), $scale, $path . '.' . $field);
                if ($field !== 'markupPercent' && $value->isNegative()) {
                    $errors[] = $path . '.' . $field . ' darf nicht negativ sein.';
                }
            } catch (InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
            }
        }
        if (array_key_exists('priceMode',$component)) {
            if(!in_array($component['priceMode'],['master','locked','manual'],true))$errors[]=$path.'.priceMode ist ungueltig.';
            if(($component['masterDataId']??null)===null&&$component['priceMode']!=='manual')$errors[]=$path.'.priceMode muss ohne Stammdatenreferenz manual sein.';
        } elseif (!is_bool($component['priceLocked'] ?? null)) $errors[] = $path . '.priceLocked muss ein Wahrheitswert sein.';
    }

    /** @param array<string,mixed> $value @param list<string> $allowed @param list<string> $errors */
    private function rejectUnknown(array $value,array $allowed,string $path,array &$errors):void
    {
        foreach(array_diff(array_keys($value),$allowed) as $field)$errors[]=$path.'.'.$field.' ist kein zulässiges Feld.';
    }

    private function isTimestamp(mixed $value): bool
    {
        if (!is_string($value)
            || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            return false;
        }
        try {
            new DateTimeImmutable($value);
            return true;
        } catch (Exception) {
            return false;
        }
    }
}
