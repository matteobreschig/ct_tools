<?php

namespace App\Models\Concerns;

/**
 * Varianti dell'immagine carta. In `image_url` convivono due formati:
 * TCGdex (URL base senza estensione, la dimensione va appesa) e
 * CardTrader (URL completo del file, da usare così com'è).
 */
trait HasCardImageVariants
{
    public function getImageUrlLowAttribute(): ?string
    {
        return $this->cardImageVariant('low');
    }

    public function getImageUrlHighAttribute(): ?string
    {
        return $this->cardImageVariant('high');
    }

    protected function cardImageVariant(string $size): ?string
    {
        $url = $this->attributes['image_url'] ?? null;

        if (! $url) {
            return null;
        }

        if (preg_match('/\.(jpe?g|png|webp)$/i', $url)) {
            return $url;
        }

        return $url . '/' . $size . '.webp';
    }
}
