<?php

namespace App\Services\Pokecardex;

use App\Enums\ConditionType;
use App\Enums\Language;

/**
 * Traduzioni tra i codici di Pokecardex e quelli di CardTrader (§ 5 del design).
 */
final class Translator
{
    /** `id_version` Pokecardex delle versioni che non chiedono regole. */
    public const STANDARD = 1;

    public const REVERSE = 2;

    public const FIRST_EDITION = 6;

    /** Condizione CardTrader → `id_etat` Pokecardex. NM diventa Excellent (decisione dell'utente). */
    private const CONDITIONS_TO_POKECARDEX = [
        'nm' => 6,
        'sp' => 2,
        'mp' => 3,
        'pl' => 4,
        'po' => 5,
    ];

    /** `id_langue` Pokecardex → lingua CardTrader. Coreano, cinese, thai e indonesiano non esistono su CardTrader. */
    private const LANGUAGES = [
        1 => 'fr',
        2 => 'en',
        3 => 'de',
        4 => 'it',
        5 => 'jp',
        6 => 'es',
        7 => 'pt',
        9 => 'nl',
        10 => 'ru',
        11 => 'pl',
    ];

    public static function conditionFromPokecardex(int $idEtat): ?ConditionType
    {
        return match ($idEtat) {
            1, 6 => ConditionType::NM,
            2 => ConditionType::SP,
            3 => ConditionType::MP,
            4 => ConditionType::PL,
            5 => ConditionType::PO,
            default => null,
        };
    }

    public static function conditionToPokecardex(ConditionType $condition): int
    {
        return self::CONDITIONS_TO_POKECARDEX[$condition->value];
    }

    public static function languageFromPokecardex(int $idLangue): ?Language
    {
        return isset(self::LANGUAGES[$idLangue]) ? Language::from(self::LANGUAGES[$idLangue]) : null;
    }

    public static function languageToPokecardex(Language $language): ?int
    {
        $idLangue = array_search($language->value, self::LANGUAGES, true);

        return $idLangue === false ? null : $idLangue;
    }

    /**
     * Proprietà CardTrader di una versione sicura. Null per le versioni
     * speciali, che passano dalle regole di serie (§ 5.3).
     *
     * @return array{reverse: bool, first_edition: bool}|null
     */
    public static function versionTraits(int $idVersion): ?array
    {
        return match ($idVersion) {
            self::STANDARD => ['reverse' => false, 'first_edition' => false],
            self::REVERSE => ['reverse' => true, 'first_edition' => false],
            self::FIRST_EDITION => ['reverse' => false, 'first_edition' => true],
            default => null,
        };
    }

    /**
     * Versione Pokecardex sicura per le proprietà di un'inserzione CardTrader.
     * Null se nessuna versione sicura le rappresenta (reverse di prima edizione).
     */
    public static function pokecardexVersion(bool $reverse, bool $firstEdition): ?int
    {
        return match (true) {
            $reverse && $firstEdition => null,
            $reverse => self::REVERSE,
            $firstEdition => self::FIRST_EDITION,
            default => self::STANDARD,
        };
    }

    /**
     * Le versioni speciali di Pokecardex chiamate "Reverse (...)" stanno su
     * CardTrader come inserzioni reverse delle espansioni dedicate.
     */
    public static function isReverseName(?string $versionName): bool
    {
        return str_starts_with((string) $versionName, 'Reverse');
    }
}
