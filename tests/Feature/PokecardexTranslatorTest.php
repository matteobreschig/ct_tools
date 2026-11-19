<?php

use App\Enums\ConditionType;
use App\Enums\Language;
use App\Services\Pokecardex\Translator;

it('translates Pokecardex conditions to CardTrader', function (int $idEtat, ConditionType $condition) {
    expect(Translator::conditionFromPokecardex($idEtat))->toBe($condition);
})->with([
    'Neuve' => [1, ConditionType::NM],
    'Excellent' => [6, ConditionType::NM],
    'Très bon' => [2, ConditionType::SP],
    'Bon' => [3, ConditionType::MP],
    'Moyen' => [4, ConditionType::PL],
    'Abîmée' => [5, ConditionType::PO],
]);

it('translates CardTrader conditions to Pokecardex, NM as Excellent', function (ConditionType $condition, int $idEtat) {
    expect(Translator::conditionToPokecardex($condition))->toBe($idEtat);
})->with([
    [ConditionType::NM, 6],
    [ConditionType::SP, 2],
    [ConditionType::MP, 3],
    [ConditionType::PL, 4],
    [ConditionType::PO, 5],
]);

it('does not translate unknown conditions', function () {
    expect(Translator::conditionFromPokecardex(99))->toBeNull();
});

it('translates languages both ways', function (int $idLangue, Language $language) {
    expect(Translator::languageFromPokecardex($idLangue))->toBe($language)
        ->and(Translator::languageToPokecardex($language))->toBe($idLangue);
})->with([
    [1, Language::FR],
    [2, Language::EN],
    [3, Language::DE],
    [4, Language::IT],
    [5, Language::JP],
    [6, Language::ES],
    [7, Language::PT],
    [9, Language::NL],
    [10, Language::RU],
    [11, Language::PL],
]);

it('leaves out languages one side does not have', function () {
    expect(Translator::languageFromPokecardex(8))->toBeNull()
        ->and(Translator::languageFromPokecardex(12))->toBeNull()
        ->and(Translator::languageToPokecardex(Language::SV))->toBeNull();
});

it('translates the safe versions and leaves the special ones to rules', function () {
    expect(Translator::versionTraits(1))->toBe(['reverse' => false, 'first_edition' => false])
        ->and(Translator::versionTraits(2))->toBe(['reverse' => true, 'first_edition' => false])
        ->and(Translator::versionTraits(6))->toBe(['reverse' => false, 'first_edition' => true])
        ->and(Translator::versionTraits(197))->toBeNull()
        ->and(Translator::pokecardexVersion(reverse: false, firstEdition: false))->toBe(1)
        ->and(Translator::pokecardexVersion(reverse: true, firstEdition: false))->toBe(2)
        ->and(Translator::pokecardexVersion(reverse: false, firstEdition: true))->toBe(6)
        ->and(Translator::pokecardexVersion(reverse: true, firstEdition: true))->toBeNull();
});
