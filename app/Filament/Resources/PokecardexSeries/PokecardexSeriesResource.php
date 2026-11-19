<?php

namespace App\Filament\Resources\PokecardexSeries;

use App\Filament\Resources\PokecardexSeries\Pages\ManagePokecardexSeries;
use App\Models\Expansion;
use App\Models\PokecardexSeries;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Serie Pokecardex e loro espansione CardTrader (§ 5.4 del design), con le
 * regole delle versioni speciali (§ 5.3). Le serie arrivano da Pokecardex:
 * qui si abbinano, non si creano né si cancellano.
 */
class PokecardexSeriesResource extends Resource
{
    protected static ?string $model = PokecardexSeries::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Pokecardex';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Serie Pokecardex';

    protected static ?string $pluralModelLabel = 'Serie Pokecardex';

    protected static ?string $recordTitleAttribute = 'name_us';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Toggle::make('ignored')
                    ->label('Ignora la serie')
                    ->helperText('Per le serie che non esistono su CardTrader: le loro carte restano fuori dalla sincronizzazione.')
                    ->columnSpanFull(),
                Select::make('expansion_id')
                    ->label('Espansione CardTrader')
                    ->options(fn (): array => self::expansionOptions())
                    ->searchable()
                    ->helperText('Per le carte Standard, Reverse e 1st Edition della serie.')
                    ->columnSpanFull(),
                Repeater::make('rules')
                    ->label('Regole delle versioni speciali')
                    ->relationship()
                    ->schema(fn (?PokecardexSeries $record): array => [
                        Select::make('id_version')
                            ->label('Versione Pokecardex')
                            ->options($record?->specialVersionOptions() ?? [])
                            ->required()
                            ->distinct(),
                        Select::make('expansion_id')
                            ->label('Espansione CardTrader')
                            ->options(self::expansionOptions())
                            ->searchable()
                            ->required(),
                    ])
                    ->columns(2)
                    ->defaultItems(0)
                    ->addActionLabel('Aggiungi regola')
                    ->helperText('La versione sta su CardTrader in questa espansione: si cerca per ID Cardmarket, poi per numero di collezione.')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name_us')
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')
                    ->label('Codice')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name_us')
                    ->label('Serie')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('expansion.name_en')
                    ->label('Espansione CardTrader')
                    ->description(fn (PokecardexSeries $record): ?string => $record->expansion?->code)
                    ->placeholder('Non abbinata'),
                IconColumn::make('ignored')
                    ->label('Ignorata')
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedEyeSlash)
                    ->falseIcon(null)
                    ->trueColor('gray'),
                TextColumn::make('expansion_source')
                    ->label('Abbinata')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === 'manual' ? 'a mano' : 'da sola')
                    ->color(fn (?string $state): string => $state === 'manual' ? 'info' : 'gray'),
                TextColumn::make('rules_count')
                    ->label('Regole')
                    ->counts('rules'),
                TextColumn::make('cards_count')
                    ->label('Carte in catalogo')
                    ->counts('cards'),
            ])
            ->filters([
                TernaryFilter::make('expansion_id')
                    ->label('Espansione')
                    ->nullable()
                    ->trueLabel('Abbinate')
                    ->falseLabel('Non abbinate'),
                TernaryFilter::make('with_cards')
                    ->label('Carte in catalogo')
                    ->default(true)
                    ->trueLabel('Solo con carte')
                    ->falseLabel('Solo senza carte')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->has('cards'),
                        false: fn (Builder $query): Builder => $query->doesntHave('cards'),
                    ),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Abbina')
                    ->mutateDataUsing(fn (array $data): array => [
                        ...$data,
                        'expansion_source' => filled($data['expansion_id'] ?? null) ? 'manual' : null,
                    ]),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function expansionOptions(): array
    {
        return Expansion::query()
            ->orderBy('name_en')
            ->get(['id', 'code', 'name_en'])
            ->mapWithKeys(fn (Expansion $expansion): array => [$expansion->id => "{$expansion->name_en} ({$expansion->code})"])
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePokecardexSeries::route('/'),
        ];
    }
}
