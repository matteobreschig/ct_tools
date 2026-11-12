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
}
