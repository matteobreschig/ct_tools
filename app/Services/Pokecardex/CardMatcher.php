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

        // Una regola di serie vince anche sulle versioni sicure: in alcuni set
        // CardTrader tiene le reverse in un'espansione a parte (Ascended
        // Heroes: `e-asc`), e l'ID Cardmarket porterebbe alla carta sbagliata.
        $rule = PokecardexSeriesRule::query()
            ->with('expansion')
            ->where(['id_serie' => $card->id_serie, 'id_version' => $idVersion])
            ->first();

        if ($rule !== null) {
            return $this->matchByRule($card, $idVersion, $rule, $traits);
        }

        return Translator::versionTraits($idVersion) === null
            ? CardMatch::review('Versione speciale "'.($versionName ?? $idVersion).'" senza regola di serie.')
            : $this->matchByCardmarket($card, $idVersion, $traits);
    }

    /**
     * Da un blueprint CardTrader (con reverse e prima edizione) alla carta e
     * versione Pokecardex.
     */
    public function matchBlueprint(int $blueprintId, Expansion $expansion, bool $reverse, bool $firstEdition): BlueprintMatch
    {
        $savedBlueprint = CtExpansionBlueprint::find($blueprintId);
        $saved = PokecardexCardMatch::query()
            ->where('blueprint_id', $blueprintId)
            ->get()
            ->filter(fn (PokecardexCardMatch $match): bool => $this->versionFits(
                PokecardexCard::find($match->id_card), $match->id_version, $reverse, $firstEdition, $savedBlueprint,
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
        $blueprintNumber = PokecardexCard::normalizeCollectorNumber($blueprint->displayedNumber());

        if (! $this->sameCollectorNumber($card, $blueprintNumber)) {
            return CardMatch::review("L'ID Cardmarket porta a un blueprint con numero diverso ({$blueprintNumber} invece di {$card->collectorNumber()}).");
        }

        $this->remember($card->id_card, $idVersion, $blueprint->blueprint_id, 'cardmarket');

        return $this->matched($blueprint->blueprint_id, $traits, 'cardmarket');
    }

    /**
     * @param  array{reverse: bool, first_edition: bool}  $traits
     */
    private function matchByRule(PokecardexCard $card, int $idVersion, PokecardexSeriesRule $rule, array $traits): CardMatch
    {
        if (! $this->cardtrader->load($rule->expansion)) {
            return CardMatch::pending('CardTrader sta ancora preparando il catalogo dell\'espansione della regola.');
        }

        $blueprints = CtExpansionBlueprint::query()->where('expansion_ct_id', $rule->expansion->ct_id)->get();

        // Prima l'ID Cardmarket dentro l'espansione della regola: nelle
        // espansioni miste (Prize Pack) lo stesso numero compare più volte.
        // Un ID Cardmarket che porta a numero e nome diversi è un errore di
        // Pokecardex (Lunatone 74 col codice di Latios 101): non ci si fida.
        $candidates = $card->id_cardmarket === null
            ? collect()
            : $blueprints->where('cardmarket_product_id', $card->id_cardmarket)
                ->filter(fn (CtExpansionBlueprint $blueprint): bool => PokecardexCard::collectorDigits($blueprint->collector_number) === PokecardexCard::collectorDigits($card->num_card)
                    || PokecardexCard::normalizeName($blueprint->name) === PokecardexCard::normalizeName($card->name_en));

        // Poi per numero (solo le cifre: "189nh" e "189/198" sono lo stesso) e,
        // nelle espansioni miste come le Theme Deck Exclusives, per nome.
        if ($candidates->count() !== 1) {
            $candidates = $blueprints->filter(fn (CtExpansionBlueprint $blueprint): bool => PokecardexCard::collectorDigits($blueprint->collector_number) === PokecardexCard::collectorDigits($card->num_card));

            if ($candidates->count() > 1) {
                $candidates = $candidates->filter(fn (CtExpansionBlueprint $blueprint): bool => PokecardexCard::normalizeName($blueprint->name) === PokecardexCard::normalizeName($card->name_en))->values();
            }
        }

        $label = PokecardexCard::normalizeVariantLabel($card->version($idVersion)['nom_version_us'] ?? null);
        $candidates = $this->sameVariant($candidates, $blueprints, $label);

        if ($candidates->count() !== 1) {
            return CardMatch::review("La regola di serie porta a {$candidates->count()} blueprint con numero {$card->collectorNumber()}.");
        }

        if ($this->isCosmos($label)) {
            $traits['reverse'] = $this->cosmosReverse($candidates->first());
        }

        $blueprintId = $candidates->first()->blueprint_id;
        $this->remember($card->id_card, $idVersion, $blueprintId, 'rule');

        return $this->matched($blueprintId, $traits, 'rule');
    }

    /**
     * Varianti dichiarate da CardTrader (Prize Pack: "Non-Holo | 150/159",
     * "Cosmos Holo | 150/159"): blueprint diversi, ID Cardmarket diversi, mentre
     * Pokecardex ha una carta sola con più versioni e un ID Cardmarket solo. Se
     * i blueprint trovati o i loro gemelli (stesso numero e nome) dichiarano
     * una variante, vince quello con la variante della versione Pokecardex.
     *
     * @param  Collection<int, CtExpansionBlueprint>  $candidates
     * @param  Collection<int, CtExpansionBlueprint>  $blueprints  tutti i blueprint dell'espansione
     * @return Collection<int, CtExpansionBlueprint>
     */
    private function sameVariant(Collection $candidates, Collection $blueprints, ?string $variant): Collection
    {
        if ($variant === null || $candidates->isEmpty()) {
            return $candidates;
        }

        $twins = $blueprints->filter(fn (CtExpansionBlueprint $blueprint): bool => $candidates->contains(
            fn (CtExpansionBlueprint $candidate): bool => PokecardexCard::collectorDigits($candidate->collector_number) === PokecardexCard::collectorDigits($blueprint->collector_number)
                && mb_strtolower((string) $candidate->name) === mb_strtolower((string) $blueprint->name),
        ));

        // La variante serve solo a scegliere tra gemelli: un blueprint senza
        // gemelli resta valido anche se la sua etichetta ("Ultra Rare | …",
        // "Poké Ball Reverse Holo | …") non somiglia al nome Pokecardex.
        if ($twins->count() < 2 || $twins->every(fn (CtExpansionBlueprint $blueprint): bool => $blueprint->variantLabel() === null)) {
            return $candidates;
        }

        $exact = $twins->filter(fn (CtExpansionBlueprint $blueprint): bool => $blueprint->variantLabel() === $variant)->values();

        if ($exact->count() === 1) {
            return $exact;
        }

        // Le Cosmos vanno sul gemello dedicato ("Cosmos Holo", "Reverse Cosmos
        // Holo") se c'è; altrimenti restano sul blueprint trovato, come reverse.
        if ($this->isCosmos($variant)) {
            $cosmos = $twins->filter(fn (CtExpansionBlueprint $blueprint): bool => $this->isCosmosBlueprint($blueprint))->values();

            if ($cosmos->count() === 1) {
                return $cosmos;
            }
        }

        return $candidates->reject(fn (CtExpansionBlueprint $blueprint): bool => $this->isCosmosBlueprint($blueprint))->values();
    }

    private function isCosmos(?string $label): bool
    {
        return $label !== null && str_contains($label, 'cosmos');
    }

    /**
     * Stesso numero di collezione tra carta Pokecardex e blueprint trovato con
     * l'ID Cardmarket. Se il numero Pokecardex è fatto di sole cifre bastano le
     * cifre: CardTrader scrive "PAL 203" o "104e" dove Pokecardex ha "203/193"
     * o "104/109". Con una lettera ("95a") serve il numero identico, perché
     * "95b" è un'altra carta.
     */
    private function sameCollectorNumber(PokecardexCard $card, ?string $blueprintNumber): bool
    {
        $cardNumber = $card->collectorNumber();

        if ($cardNumber === null || $blueprintNumber === null) {
            return false;
        }

        if (! ctype_digit($cardNumber)) {
            return $cardNumber === $blueprintNumber;
        }

        return PokecardexCard::collectorDigits($blueprintNumber) === $cardNumber;
    }

    /**
     * Blueprint dedicato alle sole Cosmos: "Cosmos Holo" o "Reverse Cosmos
     * Holo", non "Non-Holo / Cosmos Holo".
     */
    private function isCosmosBlueprint(CtExpansionBlueprint $blueprint): bool
    {
        $label = $blueprint->variantLabel();

        return $label !== null && str_contains($label, 'cosmos') && ! str_contains($label, 'nonholo');
    }

    /**
     * Come CardTrader registra una Cosmos: reverse ovunque (stesso blueprint
     * della Non Holo, "Reverse Cosmos Holo", "Non-Holo / Cosmos Holo"), tranne
     * sul blueprint "Cosmos Holo" puro.
     */
    private function cosmosReverse(CtExpansionBlueprint $blueprint): bool
    {
        return $blueprint->variantLabel() !== 'cosmosholo';
    }

    /**
     * La versione Pokecardex può stare su questo blueprint gemello?
     */
    private function variantMatches(?string $label, CtExpansionBlueprint $blueprint): bool
    {
        if ($this->isCosmosBlueprint($blueprint)) {
            return $this->isCosmos($label);
        }

        return $blueprint->variantLabel() !== 'nonholo' || ! $this->isCosmos($label);
    }

    /**
     * @param  Collection<int, PokecardexSeriesRule>  $rules
     */
    private function matchBlueprintByRules(CtExpansionBlueprint $blueprint, Collection $rules, bool $reverse, bool $firstEdition): BlueprintMatch
    {
        $number = PokecardexCard::normalizeCollectorNumber($blueprint->collector_number);

        $hasTwins = CtExpansionBlueprint::query()
            ->where('expansion_ct_id', $blueprint->expansion_ct_id)
            ->where('blueprint_id', '!=', $blueprint->blueprint_id)
            ->where('name', $blueprint->name)
            ->get()
            ->contains(fn (CtExpansionBlueprint $twin): bool => PokecardexCard::collectorDigits($twin->collector_number) === PokecardexCard::collectorDigits($blueprint->collector_number));
        $variant = $hasTwins ? $blueprint->variantLabel() : null;

        $candidates = $rules->flatMap(function (PokecardexSeriesRule $rule) use ($blueprint, $reverse, $firstEdition, $variant, $hasTwins): array {
            $series = PokecardexSeries::find($rule->id_serie);

            if ($series === null) {
                return [];
            }

            $this->pokecardex->loadCards($series);

            $cards = PokecardexCard::query()
                ->where('id_serie', $series->id_serie)
                ->get()
                ->filter(fn (PokecardexCard $card): bool => $this->versionFits($card, $rule->id_version, $reverse, $firstEdition, $blueprint)
                    && (! $hasTwins || $this->variantMatches(PokecardexCard::normalizeVariantLabel($card->version($rule->id_version)['nom_version_us'] ?? null), $blueprint)));

            $sameCardmarket = $blueprint->cardmarket_product_id === null
                ? collect()
                : $cards->where('id_cardmarket', $blueprint->cardmarket_product_id)
                    ->filter(fn (PokecardexCard $card): bool => PokecardexCard::collectorDigits($card->num_card) === PokecardexCard::collectorDigits($blueprint->collector_number)
                        || PokecardexCard::normalizeName($card->name_en) === PokecardexCard::normalizeName($blueprint->name));

            // Col numero, a parità di numero conta anche il nome: nelle
            // espansioni miste lo stesso numero compare su carte diverse.
            $sameNumber = $cards->filter(fn (PokecardexCard $card): bool => PokecardexCard::collectorDigits($card->num_card) === PokecardexCard::collectorDigits($blueprint->collector_number));

            if ($sameNumber->count() > 1 || $variant !== null) {
                $sameNumber = $sameNumber->filter(fn (PokecardexCard $card): bool => PokecardexCard::normalizeName($card->name_en) === PokecardexCard::normalizeName($blueprint->name));
            }

            return ($sameCardmarket->count() === 1 ? $sameCardmarket : $sameNumber)
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
    private function versionFits(?PokecardexCard $card, int $idVersion, bool $reverse, bool $firstEdition, ?CtExpansionBlueprint $blueprint = null): bool
    {
        $traits = Translator::versionTraits($idVersion);

        if ($traits !== null) {
            return $traits['reverse'] === $reverse && $traits['first_edition'] === $firstEdition;
        }

        $version = $card?->version($idVersion);
        $label = PokecardexCard::normalizeVariantLabel($version['nom_version_us'] ?? null);
        $expectedReverse = $this->isCosmos($label) && $blueprint !== null
            ? $this->cosmosReverse($blueprint)
            : Translator::isReverseName($version['nom_version_us'] ?? null);

        return $version !== null
            && ! $firstEdition
            && $expectedReverse === $reverse;
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
