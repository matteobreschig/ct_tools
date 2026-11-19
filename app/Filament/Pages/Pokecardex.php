<?php

namespace App\Filament\Pages;

use App\Jobs\SyncPokecardex;
use App\Models\CtExpansionBlueprint;
use App\Models\Expansion;
use App\Models\PokecardexCard;
use App\Models\PokecardexCardMatch;
use App\Models\PokecardexDecision;
use App\Models\PokecardexSyncRun;
use App\Models\Product;
use App\Models\User;
use App\Services\Pokecardex\CardKey;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Sincronizzazione Pokecardex ↔ CardTrader (§ 8.1 del design): stato
 * dell'ultimo giro, bottone "Sincronizza ora", anteprima da approvare,
 * elenco di cosa è da rivedere o in attesa, storico dei giri. Dalle righe:
 * scegliere quale lato vince per una carta, abbinare a mano, ignorare.
 */
class Pokecardex extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.pokecardex';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Pokecardex';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Sincronizzazione';

    protected static ?string $title = 'Sincronizzazione Pokecardex';

    /** Un giro senza fine registrata da più di così si considera interrotto. */
    private const RUNNING_TIMEOUT_MINUTES = 60;

    public function latestRun(): ?PokecardexSyncRun
    {
        return PokecardexSyncRun::query()->where('user_id', Auth::id())->latest('id')->first();
    }

    /**
     * L'ultimo giro con un piano salvato: quello mostrato nella tabella.
     */
    public function planRun(): ?PokecardexSyncRun
    {
        return PokecardexSyncRun::query()->where('user_id', Auth::id())->whereNotNull('plan')->latest('id')->first();
    }

    public function isRunning(): bool
    {
        $run = $this->latestRun();

        return $run !== null && $run->finished_at === null && $run->started_at->isAfter(now()->subMinutes(self::RUNNING_TIMEOUT_MINUTES));
    }

    /**
     * @return Collection<int, PokecardexSyncRun>
     */
    public function recentRuns(): Collection
    {
        return PokecardexSyncRun::query()->where('user_id', Auth::id())->latest('id')->limit(10)->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')
                ->label('Sincronizza ora')
                ->icon(Heroicon::OutlinedArrowPath)
                ->disabled(fn (): bool => $this->isRunning() || ! $this->hasCredentials())
                ->tooltip(fn (): ?string => $this->hasCredentials() ? null : 'Salva prima le credenziali Pokecardex nel profilo.')
                ->requiresConfirmation()
                ->modalHeading('Sincronizzare adesso?')
                ->modalDescription('Rilegge CardTrader e Pokecardex e allinea le differenze. Al primo giro, o se ci sono molte copie da togliere, si ferma all\'anteprima e aspetta la tua approvazione.')
                ->action(function (): void {
                    SyncPokecardex::dispatch($this->user());

                    Notification::make()->title('Giro avviato')->body('Ci vogliono alcuni minuti: la pagina si aggiorna da sola.')->success()->send();
                }),
            Action::make('apply')
                ->label('Applica anteprima')
                ->icon(Heroicon::OutlinedCheck)
                ->color('success')
                ->visible(fn (): bool => $this->latestRun()?->outcome === 'preview' && ! $this->isRunning())
                ->requiresConfirmation()
                ->modalHeading('Applicare l\'anteprima?')
                ->modalDescription(fn (): string => $this->previewSummary())
                ->action(function (): void {
                    SyncPokecardex::dispatch($this->user(), $this->latestRun()->id);

                    Notification::make()->title('Giro avviato')->body('Le scritture partono solo se nel frattempo non è cambiato niente.')->success()->send();
                }),
            Action::make('discard')
                ->label('Scarta anteprima')
                ->color('gray')
                ->visible(fn (): bool => $this->latestRun()?->outcome === 'preview' && ! $this->isRunning())
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->latestRun()->update(['outcome' => 'discarded', 'message' => 'Anteprima scartata.']);
                    $this->resetTable();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (array $filters, int $page, int $recordsPerPage): LengthAwarePaginator {
                $section = $filters['section']['value'] ?? 'changes';
                $direction = $filters['direction']['value'] ?? null;
                $rows = $this->rows($section, $direction);

                return new LengthAwarePaginator($rows->forPage($page, $recordsPerPage), $rows->count(), $recordsPerPage, $page);
            })
            ->columns([
                TextColumn::make('card')
                    ->label('Carta')
                    ->description(fn (array $record): ?string => $record['number']),
                TextColumn::make('details')
                    ->label('Versione'),
                TextColumn::make('what')
                    ->label('Cosa succede')
                    ->badge()
                    ->color(fn (array $record): string => $record['color']),
                TextColumn::make('why')
                    ->label('Motivo')
                    ->wrap(),
                TextColumn::make('choice')
                    ->label('Tua scelta')
                    ->badge()
                    ->color('info')
                    ->placeholder(''),
            ])
            ->recordActions([
                Action::make('chooseSide')
                    ->label('Scegli lato')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->visible(fn (array $record): bool => $record['variant'] !== null && in_array($record['kind'], ['change', 'conflict'], true))
                    ->modalHeading('Quale lato vince per questa carta?')
                    ->modalDescription('Vale per tutta la carta (ogni condizione) al prossimo giro: premi "Sincronizza ora" per una nuova anteprima. Dopo le scritture la scelta si consuma.')
                    ->schema([
                        Radio::make('side')
                            ->label('Lato')
                            ->options([
                                'pokecardex' => 'Vince Pokecardex: quantità e condizioni di Pokecardex',
                                'cardtrader' => 'Vince CardTrader: quantità e condizioni di CardTrader',
                                'default' => 'Regola predefinita',
                            ])
                            ->default(fn (array $record): string => $record['side'] ?? 'default')
                            ->required(),
                    ])
                    ->action(function (array $record, array $data): void {
                        $this->decide(PokecardexDecision::SIDE, $record['variant'], $data['side'] === 'default' ? null : $data['side']);
                        Notification::make()->title('Scelta salvata')->body('Vale dal prossimo giro.')->success()->send();
                    }),
                Action::make('matchBlueprint')
                    ->label('Abbina')
                    ->icon(Heroicon::OutlinedLink)
                    ->visible(fn (array $record): bool => $record['row'] !== null)
                    ->modalHeading('Abbina la carta Pokecardex a un blueprint CardTrader')
                    ->schema([
                        Select::make('blueprint_id')
                            ->label('Blueprint CardTrader')
                            ->helperText('Cerca per nome o per ID blueprint. Compaiono le espansioni CardTrader già caricate dai giri.')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => $this->searchBlueprints($search))
                            ->getOptionLabelUsing(fn ($value): ?string => $this->blueprintLabel((int) $value))
                            ->required(),
                    ])
                    ->action(function (array $record, array $data): void {
                        $this->saveManualMatch($record['row']['id_card'], $record['row']['id_version'], (int) $data['blueprint_id']);
                    }),
                Action::make('matchCard')
                    ->label('Abbina')
                    ->icon(Heroicon::OutlinedLink)
                    ->visible(fn (array $record): bool => $record['row'] === null && $record['kind'] === 'no_target')
                    ->modalHeading('Abbina l\'inserzione CardTrader a una carta Pokecardex')
                    ->schema([
                        Select::make('id_card')
                            ->label('Carta Pokecardex')
                            ->helperText('Cerca per nome. Compaiono le carte già nel catalogo locale.')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => $this->searchCards($search))
                            ->getOptionLabelUsing(fn ($value): ?string => $this->cardLabel((int) $value))
                            ->live()
                            ->required(),
                        Select::make('id_version')
                            ->label('Versione')
                            ->options(fn (Get $get): array => collect(PokecardexCard::find($get('id_card'))?->versions ?? [])
                                ->mapWithKeys(fn (array $version): array => [$version['id_version'] => $version['nom_version_us'] ?? (string) $version['id_version']])
                                ->all())
                            ->required(),
                    ])
                    ->action(function (array $record, array $data): void {
                        $this->saveManualMatch((int) $data['id_card'], (int) $data['id_version'], $record['blueprint_id']);
                    }),
                Action::make('ignore')
                    ->label('Ignora')
                    ->icon(Heroicon::OutlinedEyeSlash)
                    ->color('gray')
                    ->visible(fn (array $record): bool => $record['section'] === 'reviews')
                    ->requiresConfirmation()
                    ->modalDescription('La revisione sparisce da questo elenco anche nei prossimi giri. La ritrovi in "Ignorate".')
                    ->action(function (array $record): void {
                        $this->decide(PokecardexDecision::IGNORE, $record['hash'], 'ignored');
                        $this->resetTable();
                    }),
                Action::make('restore')
                    ->label('Ripristina')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->visible(fn (array $record): bool => $record['section'] === 'ignored')
                    ->action(function (array $record): void {
                        $this->decide(PokecardexDecision::IGNORE, $record['hash'], null);
                        $this->resetTable();
                    }),
            ])
            ->filters([
                SelectFilter::make('section')
                    ->label('Sezione')
                    ->options([
                        'changes' => 'Scritture',
                        'reviews' => 'Da rivedere',
                        'pending' => 'In attesa',
                        'ignored' => 'Ignorate',
                    ])
                    ->default('changes')
                    ->selectablePlaceholder(false),
                SelectFilter::make('direction')
                    ->label('Lato')
                    ->options([
                        'to_cardtrader' => 'Su CardTrader',
                        'to_pokecardex' => 'Su Pokecardex',
                    ]),
            ])
            ->deferFilters(false)
            ->emptyStateHeading('Niente da mostrare')
            ->emptyStateDescription('Avvia un giro con "Sincronizza ora".');
    }

    /**
     * Righe della sezione scelta, dal piano dell'ultimo giro. "Da rivedere"
     * esclude le revisioni ignorate, che compaiono in "Ignorate".
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(string $section, ?string $direction): Collection
    {
        $plan = $this->planRun()?->plan ?? [];
        $items = collect($plan[$section === 'ignored' ? 'reviews' : $section] ?? []);

        if (in_array($section, ['reviews', 'ignored'], true)) {
            $ignored = PokecardexDecision::query()->where('user_id', Auth::id())->where('kind', PokecardexDecision::IGNORE)->pluck('subject')->flip();
            $items = $items->filter(fn (array $item): bool => isset($ignored[$this->reviewHash($item)]) === ($section === 'ignored'));
        }

        if ($section === 'changes' && $direction !== null) {
            $items = $items->where('direction', $direction);
        }

        $sides = PokecardexDecision::query()->where('user_id', Auth::id())->where('kind', PokecardexDecision::SIDE)->pluck('value', 'subject');
        $blueprintIds = $items->pluck('key.blueprint_id')->merge($items->pluck('payload.key.blueprint_id'))->filter()->unique();
        $products = Product::query()
            ->where('user_id', Auth::id())
            ->whereIn('blueprint_id', $blueprintIds)
            ->get(['blueprint_id', 'name', 'collector_number'])
            ->keyBy('blueprint_id');
        $blueprints = CtExpansionBlueprint::query()
            ->whereIn('blueprint_id', $blueprintIds)
            ->get(['blueprint_id', 'name', 'collector_number', 'expansion_ct_id'])
            ->keyBy('blueprint_id');
        $expansionCodes = Expansion::query()
            ->whereIn('ct_id', $blueprints->pluck('expansion_ct_id')->unique())
            ->pluck('code', 'ct_id');

        return $items->values()->map(function (array $item) use ($section, $products, $blueprints, $expansionCodes, $sides): array {
            $isChange = $section === 'changes';
            $key = $isChange ? $item['key'] : ($item['payload']['key'] ?? null);
            $known = $key !== null ? ($products->get($key['blueprint_id']) ?? $blueprints->get($key['blueprint_id'])) : null;
            $card = $item['payload']['card'] ?? null;
            $row = $item['payload']['row'] ?? null;
            $variant = $key !== null ? CardKey::variantIdFromAttributes($key) : null;
            $side = $variant !== null ? $sides->get($variant) : null;

            return [
                'section' => $section,
                'kind' => $isChange ? 'change' : $item['kind'],
                'hash' => $isChange ? null : $this->reviewHash($item),
                'variant' => $variant,
                'side' => $side,
                'blueprint_id' => $key['blueprint_id'] ?? null,
                'row' => $row !== null ? ['id_card' => (int) $row['id_card'], 'id_version' => (int) $row['id_version']] : null,
                'card' => $item['name'] ?? $known?->name ?? $card['name'] ?? 'Carta sconosciuta',
                'number' => collect([
                    $item['collector_number'] ?? $known?->collector_number ?? $card['number'] ?? null,
                    $key !== null ? strtoupper((string) $expansionCodes->get($blueprints->get($key['blueprint_id'])?->expansion_ct_id)) : null,
                ])->filter()->implode(' · ') ?: null,
                'details' => $key !== null ? $this->keyLabel($key) : $this->rowLabel($row ?? []),
                'what' => $isChange ? $this->changeLabel($item) : $this->kindLabel($item['kind']),
                'why' => $isChange ? $this->salesLabel($item['sales'] ?? []) : $item['message'],
                'choice' => match ($side) {
                    'pokecardex' => 'Vince Pokecardex',
                    'cardtrader' => 'Vince CardTrader',
                    default => null,
                },
                'color' => $isChange
                    ? ($item['after'] > $item['before'] ? 'success' : 'danger')
                    : ($section === 'pending' ? 'gray' : 'warning'),
            ];
        });
    }

    /**
     * Impronta stabile di una revisione: tipo più riga Pokecardex o chiave
     * CardTrader. Non dipende dal testo del messaggio.
     *
     * @param  array{kind: string, payload: array<string, mixed>}  $item
     */
    private function reviewHash(array $item): string
    {
        $subject = $item['payload']['row'] ?? $item['payload']['key'] ?? $item['payload'];

        return sha1($item['kind'].'|'.json_encode($subject));
    }

    private function decide(string $kind, string $subject, ?string $value): void
    {
        $attributes = ['user_id' => Auth::id(), 'kind' => $kind, 'subject' => $subject];

        $value === null
            ? PokecardexDecision::query()->where($attributes)->delete()
            : PokecardexDecision::updateOrCreate($attributes, ['value' => $value]);
    }

    private function saveManualMatch(int $idCard, int $idVersion, int $blueprintId): void
    {
        PokecardexCardMatch::updateOrCreate(
            ['id_card' => $idCard, 'id_version' => $idVersion],
            ['blueprint_id' => $blueprintId, 'source' => 'manual'],
        );

        Notification::make()->title('Abbinamento salvato')->body('Vale dal prossimo giro: premi "Sincronizza ora" per una nuova anteprima.')->success()->send();
    }

    /**
     * @return array<int, string>
     */
    private function searchBlueprints(string $search): array
    {
        return CtExpansionBlueprint::query()
            ->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->when(ctype_digit($search), fn ($query) => $query->orWhere('blueprint_id', (int) $search)))
            ->orderBy('name')
            ->limit(50)
            ->pluck('blueprint_id')
            ->mapWithKeys(fn (int $blueprintId): array => [$blueprintId => $this->blueprintLabel($blueprintId)])
            ->all();
    }

    private function blueprintLabel(int $blueprintId): ?string
    {
        $blueprint = CtExpansionBlueprint::find($blueprintId);

        if ($blueprint === null) {
            return null;
        }

        $code = Expansion::query()->where('ct_id', $blueprint->expansion_ct_id)->value('code');

        return "{$blueprint->name} #{$blueprint->collector_number} (".strtoupper((string) $code).", {$blueprint->blueprint_id})";
    }

    /**
     * @return array<int, string>
     */
    private function searchCards(string $search): array
    {
        return PokecardexCard::query()
            ->where('name_en', 'like', "%{$search}%")
            ->orderBy('name_en')
            ->limit(50)
            ->pluck('id_card')
            ->mapWithKeys(fn (int $idCard): array => [$idCard => $this->cardLabel($idCard)])
            ->all();
    }

    private function cardLabel(int $idCard): ?string
    {
        $card = PokecardexCard::with('series')->find($idCard);

        return $card === null ? null : "{$card->name_en} {$card->num_card} ({$card->series?->code})";
    }

    /**
     * @param  array{condition: string, language: string, reverse: bool, first_edition: bool}  $key
     */
    private function keyLabel(array $key): string
    {
        return collect([
            strtoupper($key['condition']),
            strtoupper($key['language']),
            $key['reverse'] ? 'Reverse' : null,
            $key['first_edition'] ? '1ª edizione' : null,
        ])->filter()->implode(' · ');
    }

    /**
     * @param  array<string, int>  $row
     */
    private function rowLabel(array $row): string
    {
        return $row === [] ? '' : "Pokecardex: versione {$row['id_version']}, {$row['quantite']} copie";
    }

    /**
     * @param  array{direction: string, before: int, after: int}  $change
     */
    private function changeLabel(array $change): string
    {
        $side = $change['direction'] === 'to_cardtrader' ? 'CardTrader' : 'Pokecardex';

        return "{$side}: {$change['before']} → {$change['after']}";
    }

    /**
     * @param  list<array{quantity: int, paid_at: string}>  $sales
     */
    private function salesLabel(array $sales): ?string
    {
        if ($sales === []) {
            return null;
        }

        return 'Vendute su CardTrader il '.collect($sales)->pluck('paid_at')->unique()->map(fn (string $date): string => date('d/m/Y', strtotime($date)))->implode(', ');
    }

    private function kindLabel(string $kind): string
    {
        return match ($kind) {
            'unmatched' => 'Senza abbinamento',
            'no_target' => 'Senza corrispondenza',
            'quarantine' => 'Carta da rivedere',
            'language' => 'Lingua',
            'condition' => 'Condizione',
            'conflict' => 'Conflitto',
            'pending' => 'In attesa',
            default => $kind,
        };
    }

    private function previewSummary(): string
    {
        $changes = collect($this->latestRun()?->plan['changes'] ?? []);
        $count = fn (string $direction, bool $adds): int => $changes
            ->where('direction', $direction)
            ->sum(fn (array $change): int => $adds ? max(0, $change['after'] - $change['before']) : max(0, $change['before'] - $change['after']));

        return sprintf(
            'CardTrader: %d copie aggiunte (ferme), %d tolte. Pokecardex: %d copie aggiunte, %d tolte. Il giro rilegge tutto e scrive solo se il piano non è cambiato.',
            $count('to_cardtrader', true),
            $count('to_cardtrader', false),
            $count('to_pokecardex', true),
            $count('to_pokecardex', false),
        );
    }

    private function hasCredentials(): bool
    {
        return $this->user()->pokecardexAccount()->exists();
    }

    private function user(): User
    {
        return Auth::user();
    }
}
