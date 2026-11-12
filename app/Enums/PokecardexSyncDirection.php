<?php

namespace App\Enums;

/**
 * Verso di una scrittura della sincronizzazione.
 */
enum PokecardexSyncDirection: string
{
    /** Porta la quantità di Pokecardex su CardTrader. */
    case ToCardTrader = 'to_cardtrader';

    /** Porta la quantità di CardTrader su Pokecardex. */
    case ToPokecardex = 'to_pokecardex';
}
