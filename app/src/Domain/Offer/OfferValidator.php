<?php

declare(strict_types=1);

namespace App\Domain\Offer;

use App\Domain\Calculation\Decimal;
use App\Support\Uuid;
use InvalidArgumentException;

final class OfferValidator
{
    /** @param array<string, mixed> $offer */
    public function validate(array $offer): void
    {
        $errors = [];
        $seenIds = [];

        if (($offer['schemaVersion'] ?? null) !== 1) {
            $errors[] = 'schemaVersion muss 1 sein.';
        }
        foreach (['id', 'projectId'] as $field) {
            if (!is_string($offer[$field] ?? null) || !Uuid::isValid($offer[$field])) {
                $errors[] = $field . ' muss eine gueltige UUID sein.';
            }
        }
        if (($offer['status'] ?? null) !== 'draft') {
            $errors[] = 'Ein bearbeitbarer Entwurf muss den Status draft haben.';
        }
        if (!is_string($offer['title'] ?? null) || trim($offer['title']) === '') {
            $errors[] = 'Die Angebotsbezeichnung darf nicht leer sein.';
        }
        if (!is_array($offer['nodes'] ?? null) || !array_is_list($offer['nodes'])) {
            $errors[] = 'nodes muss eine Liste sein.';
        } else {
            foreach ($offer['nodes'] as $index => $node) {
                $this->validateNode($node, sprintf('nodes[%d]', $index), $seenIds, $errors);
            }
        }

        if ($errors !== []) {
            throw new OfferValidationException($errors);
        }
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
            if (!is_string($node['name'] ?? null) || trim($node['name']) === '') {
                $errors[] = $path . '.name darf nicht leer sein.';
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

        if ($type !== 'position') {
            $errors[] = $path . '.type muss title oder position sein.';
            return;
        }

        if (!in_array($node['positionType'] ?? null, ['normal', 'bedarf', 'alternative'], true)) {
            $errors[] = $path . '.positionType ist ungueltig.';
        }
        foreach (['shortText', 'unit'] as $field) {
            if (!is_string($node[$field] ?? null) || trim($node[$field]) === '') {
                $errors[] = $path . '.' . $field . ' darf nicht leer sein.';
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
                Decimal::parse((string) $node['manualUnitPrice'], Decimal::MONEY_SCALE, $path . '.manualUnitPrice');
            } catch (InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
            }
        }
        foreach ($node['components'] as $index => $component) {
            $this->validateComponent($component, sprintf('%s.components[%d]', $path, $index), $seenIds, $errors);
        }
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
}
