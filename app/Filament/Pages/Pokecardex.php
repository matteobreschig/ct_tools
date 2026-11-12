<?php

namespace App\Filament\Pages;

use App\Jobs\SyncPokecardex;
use App\Models\CtExpansionBlueprint;
use App\Models\Expansion;
use App\Models\PokecardexSyncRun;
use App\Models\Product;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
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
 * elenco di cosa è da rivedere o in attesa, storico dei giri.
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
            ])
            ->filters([
                SelectFilter::make('section')
                    ->label('Sezione')
                    ->options([
                        'changes' => 'Scritture',
                        'reviews' => 'Da rivedere',
                        'pending' => 'In attesa',
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
     * Righe della sezione scelta, dal piano dell'ultimo giro.
     *
     * @return Collection<int, array{card: string, number: ?string, details: string, what: string, why: ?string, color: string}>
     */
    private function rows(string $section, ?string $direction): Collection
    {
        $plan = $this->planRun()?->plan ?? [];
        $items = collect($plan[$section] ?? []);

        if ($section === 'changes' && $direction !== null) {
            $items = $items->where('direction', $direction);
        }

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

        return $items->values()->map(function (array $item) use ($section, $products, $blueprints, $expansionCodes): array {
            $key = $section === 'changes' ? $item['key'] : ($item['payload']['key'] ?? null);
            $known = $key !== null ? ($products->get($key['blueprint_id']) ?? $blueprints->get($key['blueprint_id'])) : null;
            $card = $item['payload']['card'] ?? null;

            return [
                'card' => $item['name'] ?? $known?->name ?? $card['name'] ?? 'Carta sconosciuta',
                'number' => collect([
                    $item['collector_number'] ?? $known?->collector_number ?? $card['number'] ?? null,
                    $key !== null ? strtoupper((string) $expansionCodes->get($blueprints->get($key['blueprint_id'])?->expansion_ct_id)) : null,
                ])->filter()->implode(' · ') ?: null,
                'details' => $key !== null ? $this->keyLabel($key) : $this->rowLabel($item['payload']['row'] ?? []),
                'what' => $section === 'changes' ? $this->changeLabel($item) : $this->kindLabel($item['kind']),
                'why' => $section === 'changes' ? $this->salesLabel($item['sales'] ?? []) : $item['message'],
                'color' => $section === 'changes'
                    ? ($item['after'] > $item['before'] ? 'success' : 'danger')
                    : ($section === 'pending' ? 'gray' : 'warning'),
            ];
        });
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
