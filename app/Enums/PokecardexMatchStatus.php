<?php

namespace App\Enums;

/**
 * Esito di un abbinamento tra Pokecardex e CardTrader (§ 6 del design).
 */
enum PokecardexMatchStatus: string
{
    /** Abbinamento sicuro: si può scrivere. */
    case Matched = 'matched';

    /** Serve una decisione dell'utente prima di scrivere. */
    case Review = 'review';

    /** Il blueprint CardTrader non esiste ancora: si riprova al prossimo giro. */
    case Pending = 'pending';
}
