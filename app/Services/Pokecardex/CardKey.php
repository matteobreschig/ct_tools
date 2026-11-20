<?php

namespace App\Services\Pokecardex;

use App\Enums\ConditionType;
use App\Enums\Language;

/**
 * Chiave carta comune ai due lati (§ 4.1 del design).
 */
final readonly class CardKey
{
    public function __construct(
        public int $blueprintId,
        public ConditionType $condition,
        public Language $language,
        public bool $reverse,
        public bool $firstEdition,
    ) {}

    public function id(): string
    {
        return implode('|', [
            $this->blueprintId,
            $this->condition->value,
            $this->language->value,
            (int) $this->reverse,
            (int) $this->firstEdition,
        ]);
    }

    /**
     * La carta senza condizione (blueprint, lingua, reverse, prima edizione):
     * le scritture di una carta vanno insieme e il lato scelto vale per lei.
     */
    public function variantId(): string
    {
        return implode('|', [$this->blueprintId, $this->language->value, (int) $this->reverse, (int) $this->firstEdition]);
    }

    /**
     * @param  array{blueprint_id: int, language: string, reverse: bool, first_edition: bool}  $attributes
     */
    public static function variantIdFromAttributes(array $attributes): string
    {
        return implode('|', [$attributes['blueprint_id'], $attributes['language'], (int) $attributes['reverse'], (int) $attributes['first_edition']]);
    }

    /**
     * @return array{blueprint_id: int, condition: string, language: string, reverse: bool, first_edition: bool}
     */
    public function attributes(): array
    {
        return [
            'blueprint_id' => $this->blueprintId,
            'condition' => $this->condition->value,
            'language' => $this->language->value,
            'reverse' => $this->reverse,
            'first_edition' => $this->firstEdition,
        ];
    }

    /**
     * La chiave con il reverse tolto se CardTrader lo decide da solo per
     * questo blueprint (`CtExpansionBlueprint::fixedReverseIds()`): reverse e
     * normale finiscono sulla stessa inserzione, quindi sono la stessa chiave.
     *
     * @param  array<int, true>  $fixedReverse
     */
    public function canonical(array $fixedReverse): self
    {
        if (! $this->reverse || ! isset($fixedReverse[$this->blueprintId])) {
            return $this;
        }

        return new self($this->blueprintId, $this->condition, $this->language, false, $this->firstEdition);
    }

    /**
     * Come `canonical()`, su un identificativo di chiave o di carta già
     * composto: il reverse è il penultimo pezzo.
     *
     * @param  array<int, true>  $fixedReverse
     */
    public static function canonicalId(string $id, array $fixedReverse): string
    {
        $parts = explode('|', $id);

        if (count($parts) < 4 || ! isset($fixedReverse[(int) $parts[0]])) {
            return $id;
        }

        $parts[count($parts) - 2] = '0';

        return implode('|', $parts);
    }
}
