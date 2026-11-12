<?php

namespace App\Services\Pokecardex;

use App\Enums\PokecardexMatchStatus;
use App\Models\CtExpansionBlueprint;
use App\Models\Expansion;
use App\Models\PokecardexCard;
use App\Models\PokecardexCardMatch;
use App\Models\PokecardexSeries;
use App\Models\PokecardexSeriesRule;
use Illuminate\Support\Collection;

/**
 * Abbinamenti tra carte Pokecardex e blueprint CardTrader (§ 5.3, § 5.4 e § 6
 * del design), nei due versi. Gli abbinamenti riusciti si salvano; quelli
 * manuali vincono sempre e non vengono mai sovrascritti.
 */
final class CardMatcher
{
    public function __construct(
        private PokecardexCatalog $pokecardex,
        private BlueprintCatalog $cardtrader,
    ) {}

    /**
     * Da carta e versione Pokecardex al blueprint CardTrader.
     */
    public function match(PokecardexCard $card, int $idVersion): CardMatch
    {
        $versionName = $card->version($idVersion)['nom_version_us'] ?? null;
        $traits = Translator::versionTraits($idVersion) ?? [
            'reverse' => Translator::isReverseName($versionName),
            'first_edition' => false,
        ];

        $manual = PokecardexCardMatch::query()
            ->where(['id_card' => $card->id_card, 'id_version' => $idVersion, 'source' => 'manual'])
            ->first();

        if ($manual !== null) {
            return $this->matched($manual->blueprint_id, $traits, 'manual');
        }

        return Translator::versionTraits($idVersion) === null
            ? $this->matchSpecialVersion($card, $idVersion, $versionName, $traits)
            : $this->matchByCardmarket($card, $idVersion, $traits);
    }

    /**
     * Da un blueprint CardTrader (con reverse e prima edizione) alla carta e
     * versione Pokecardex.
     */
    public function matchBlueprint(int $blueprintId, Expansion $expansion, bool $reverse, bool $firstEdition): BlueprintMatch
    {
        $saved = PokecardexCardMatch::query()
            ->where('blueprint_id', $blueprintId)
            ->get()
            ->filter(fn (PokecardexCardMatch $match): bool => $this->versionFits(
                PokecardexCard::find($match->id_card), $match->id_version, $reverse, $firstEdition,
            ));

        if ($saved->count() === 1) {
            return new BlueprintMatch(PokecardexMatchStatus::Matched, $saved->first()->id_card, $saved->first()->id_version);
        }

        if ($saved->count() > 1) {
            return BlueprintMatch::review('Più carte Pokecardex abbinate a questo blueprint.');
        }

        $loaded = $this->cardtrader->load($expansion);
        $blueprint = CtExpansionBlueprint::find($blueprintId);

        if ($blueprint === null && ! $loaded) {
            return new BlueprintMatch(PokecardexMatchStatus::Pending, reason: 'CardTrader sta ancora preparando il catalogo dell\'espansione.');
        }

        if ($blueprint === null) {
            return BlueprintMatch::review('Blueprint assente dal catalogo CardTrader della sua espansione.');
        }

        $rules = PokecardexSeriesRule::query()->where('expansion_id', $expansion->id)->get();

        return $rules->isNotEmpty()
            ? $this->matchBlueprintByRules($blueprint, $rules, $reverse, $firstEdition)
            : $this->matchBlueprintByCardmarket($blueprint, $expansion, $reverse, $firstEdition);
    }

    /**
     * @param  array{reverse: bool, first_edition: bool}  $traits
     */
    private function matchByCardmarket(PokecardexCard $card, int $idVersion, array $traits): CardMatch
    {
        $expansion = $card->series?->expansion;

        if ($expansion === null) {
            return CardMatch::review("La serie Pokecardex della carta non è abbinata a un'espansione CardTrader.");
        }

        if ($card->id_cardmarket === null) {
            return CardMatch::review('Carta senza ID Cardmarket su Pokecardex.');
        }

        $loaded = $this->cardtrader->load($expansion);

        $candidates = CtExpansionBlueprint::query()->where('cardmarket_product_id', $card->id_cardmarket)->get();

        if ($candidates->isEmpty()) {
            return CardMatch::pending($loaded
                ? 'Il blueprint CardTrader non esiste ancora.'
                : 'CardTrader sta ancora preparando il catalogo dell\'espansione.');
        }

        if ($candidates->count() > 1) {
            return CardMatch::review("L'ID Cardmarket {$card->id_cardmarket} porta a {$candidates->count()} blueprint CardTrader.");
        }

        $blueprint = $candidates->first();
        $blueprintNumber = PokecardexCard::normalizeCollectorNumber($blueprint->collector_number);

        if ($blueprintNumber !== $card->collectorNumber()) {
            return CardMatch::review("L'ID Cardmarket porta a un blueprint con numero diverso ({$blueprintNumber} invece di {$card->collectorNumber()}).");
        }

        $this->remember($card->id_card, $idVersion, $blueprint->blueprint_id, 'cardmarket');

        return $this->matched($blueprint->blueprint_id, $traits, 'cardmarket');
    }

    /**
     * @param  array{reverse: bool, first_edition: bool}  $traits
     */
    private function matchSpecialVersion(PokecardexCard $card, int $idVersion, ?string $versionName, array $traits): CardMatch
    {
        $rule = PokecardexSeriesRule::query()
            ->with('expansion')
            ->where(['id_serie' => $card->id_serie, 'id_version' => $idVersion])
            ->first();

        if ($rule === null) {
            return CardMatch::review('Versione speciale "'.($versionName ?? $idVersion).'" senza regola di serie.');
        }

        if (! $this->cardtrader->load($rule->expansion)) {
            return CardMatch::pending('CardTrader sta ancora preparando il catalogo dell\'espansione della regola.');
        }

        $blueprints = CtExpansionBlueprint::query()->where('expansion_ct_id', $rule->expansion->ct_id)->get();

        // Prima l'ID Cardmarket dentro l'espansione della regola: nelle
        // espansioni miste (Prize Pack) lo stesso numero compare più volte.
        $candidates = $card->id_cardmarket === null
            ? collect()
            : $blueprints->where('cardmarket_product_id', $card->id_cardmarket);

        if ($candidates->count() !== 1) {
            $candidates = $blueprints->filter(fn (CtExpansionBlueprint $blueprint): bool => PokecardexCard::normalizeCollectorNumber($blueprint->collector_number) === $card->collectorNumber());
        }

        if ($candidates->count() !== 1) {
            return CardMatch::review("La regola di serie porta a {$candidates->count()} blueprint con numero {$card->collectorNumber()}.");
        }

        $blueprintId = $candidates->first()->blueprint_id;
        $this->remember($card->id_card, $idVersion, $blueprintId, 'rule');

        return $this->matched($blueprintId, $traits, 'rule');
    }

    /**
     * @param  Collection<int, PokecardexSeriesRule>  $rules
     */
    private function matchBlueprintByRules(CtExpansionBlueprint $blueprint, Collection $rules, bool $reverse, bool $firstEdition): BlueprintMatch
    {
        $number = PokecardexCard::normalizeCollectorNumber($blueprint->collector_number);

        $candidates = $rules->flatMap(function (PokecardexSeriesRule $rule) use ($blueprint, $number, $reverse, $firstEdition): array {
            $series = PokecardexSeries::find($rule->id_serie);

            if ($series === null) {
                return [];
            }

            $this->pokecardex->loadCards($series);

            $cards = PokecardexCard::query()
                ->where('id_serie', $series->id_serie)
                ->get()
                ->filter(fn (PokecardexCard $card): bool => $this->versionFits($card, $rule->id_version, $reverse, $firstEdition));

            $sameCardmarket = $blueprint->cardmarket_product_id === null
                ? collect()
                : $cards->where('id_cardmarket', $blueprint->cardmarket_product_id);

            return ($sameCardmarket->count() === 1 ? $sameCardmarket : $cards->filter(fn (PokecardexCard $card): bool => $card->collectorNumber() === $number))
                ->map(fn (PokecardexCard $card): array => [$card->id_card, $rule->id_version])
                ->values()
                ->all();
        });

        if ($candidates->count() !== 1) {
            return BlueprintMatch::review("Le regole di serie portano a {$candidates->count()} carte Pokecardex con numero {$number}.");
        }

        [$idCard, $idVersion] = $candidates->first();
        $this->remember($idCard, $idVersion, $blueprint->blueprint_id, 'rule');

        return new BlueprintMatch(PokecardexMatchStatus::Matched, $idCard, $idVersion);
    }

    private function matchBlueprintByCardmarket(CtExpansionBlueprint $blueprint, Expansion $expansion, bool $reverse, bool $firstEdition): BlueprintMatch
    {
        $idVersion = Translator::pokecardexVersion($reverse, $firstEdition);

        if ($idVersion === null) {
            return BlueprintMatch::review('Reverse di prima edizione: nessuna versione Pokecardex corrispondente.');
        }

        if ($blueprint->cardmarket_product_id === null) {
            return BlueprintMatch::review('Blueprint senza ID Cardmarket.');
        }

        $series = PokecardexSeries::query()->where('expansion_id', $expansion->id)->get();
        $series->each(fn (PokecardexSeries $item) => $this->pokecardex->loadCards($item));

        $cards = PokecardexCard::query()->where('id_cardmarket', $blueprint->cardmarket_product_id)->get();

        if ($cards->isEmpty()) {
            return BlueprintMatch::review($series->isEmpty()
                ? "L'espansione CardTrader non è abbinata a nessuna serie Pokecardex."
                : 'Nessuna carta Pokecardex con questo ID Cardmarket.');
        }

        if ($cards->count() > 1) {
            return BlueprintMatch::review("L'ID Cardmarket {$blueprint->cardmarket_product_id} porta a {$cards->count()} carte Pokecardex.");
        }

        $card = $cards->first();

        if ($card->version($idVersion) === null) {
            return BlueprintMatch::review('La carta Pokecardex non ha la versione '.($idVersion === Translator::REVERSE ? 'Reverse' : ($idVersion === Translator::FIRST_EDITION ? '1st Edition' : 'Standard')).'.');
        }

        $this->remember($card->id_card, $idVersion, $blueprint->blueprint_id, 'cardmarket');

        return new BlueprintMatch(PokecardexMatchStatus::Matched, $card->id_card, $idVersion);
    }

    /**
     * La versione Pokecardex corrisponde a reverse e prima edizione dell'inserzione?
     */
    private function versionFits(?PokecardexCard $card, int $idVersion, bool $reverse, bool $firstEdition): bool
    {
        $traits = Translator::versionTraits($idVersion);

        if ($traits !== null) {
            return $traits['reverse'] === $reverse && $traits['first_edition'] === $firstEdition;
        }

        $version = $card?->version($idVersion);

        return $version !== null
            && ! $firstEdition
            && Translator::isReverseName($version['nom_version_us'] ?? null) === $reverse;
    }

    /**
     * @param  array{reverse: bool, first_edition: bool}  $traits
     */
    private function matched(int $blueprintId, array $traits, string $source): CardMatch
    {
        return new CardMatch(
            PokecardexMatchStatus::Matched,
            blueprintId: $blueprintId,
            reverse: $traits['reverse'],
            firstEdition: $traits['first_edition'],
            source: $source,
        );
    }

    private function remember(int $idCard, int $idVersion, int $blueprintId, string $source): void
    {
        $match = PokecardexCardMatch::firstOrNew(['id_card' => $idCard, 'id_version' => $idVersion]);

        if ($match->source === 'manual') {
            return;
        }

        $match->fill(['blueprint_id' => $blueprintId, 'source' => $source])->save();
    }
}
