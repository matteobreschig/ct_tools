<?php

namespace App\Services\Pokecardex;

/**
 * Esito dei freni: il giro può scrivere, deve passare dall'anteprima o si ferma.
 */
final readonly class SafetyVerdict
{
    public function __construct(
        public ?string $blockedReason = null,
        public ?string $previewReason = null,
    ) {}

    public function isBlocked(): bool
    {
        return $this->blockedReason !== null;
    }

    public function needsPreview(): bool
    {
        return $this->previewReason !== null;
    }
}
