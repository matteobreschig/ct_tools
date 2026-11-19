<?php

namespace App\Services\Pokecardex;

use App\Models\Product;

/**
 * Una chiave carta coi suoi tre valori: quantità su Pokecardex (P), su
 * CardTrader (C) e base concordata all'ultimo giro (null se mai vista).
 */
final readonly class KeyState
{
    /**
     * @param  list<PokecardexRow>  $rows  righe Pokecardex che danno questa chiave
     * @param  list<Product>  $products  inserzioni CardTrader con questa chiave
     */
    public function __construct(
        public CardKey $key,
        public int $pokecardex,
        public int $cardtrader,
        public ?int $base,
        public array $rows = [],
        public array $products = [],
    ) {}
}
