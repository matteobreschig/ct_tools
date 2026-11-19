<?php

namespace App\Console\Commands;

use App\Models\Expansion;
use App\Models\PokecardexCardMatch;
use App\Models\PokecardexDecision;
use App\Models\PokecardexSeries;
use App\Models\PokecardexSeriesRule;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Importa il file di `pokecardex:export-config`. Le espansioni si ritrovano per
 * `ct_id`: se ne manca anche una sola non scrive niente. Si può rilanciare:
 * aggiorna le righe che ci sono già e non cancella niente.
 */
class PokecardexImportConfig extends Command
{
    protected $signature = 'pokecardex:import-config
        {email : Email dell\'utente a cui assegnare le decisioni}
        {path : File prodotto da pokecardex:export-config}';

    protected $description = 'Importa regole, serie, abbinamenti manuali e decisioni della sincronizzazione Pokecardex';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error("Nessun utente con email {$this->argument('email')}.");

            return self::FAILURE;
        }

        $path = $this->argument('path');
        $config = File::exists($path) ? json_decode(File::get($path), true) : null;

        if (! is_array($config) || ($config['format'] ?? null) !== PokecardexExportConfig::FORMAT) {
            $this->error("{$path} non è un file di pokecardex:export-config leggibile.");

            return self::FAILURE;
        }

        $series = $config['series'] ?? [];
        $rules = $config['rules'] ?? [];
        $manualMatches = $config['manual_matches'] ?? [];
        $decisions = $config['decisions'] ?? [];

        $needed = collect($series)->where('linked_by_hand', true)->whereNotNull('expansion_ct_id')
            ->merge($rules)
            ->mapWithKeys(fn (array $item): array => [$item['expansion_ct_id'] => $item['expansion_code'] ?? (string) $item['expansion_ct_id']]);
        $expansionIds = Expansion::query()->whereIn('ct_id', $needed->keys())->pluck('id', 'ct_id');
        $missing = $needed->reject(fn (string $code, int $ctId): bool => $expansionIds->has($ctId));

        if ($missing->isNotEmpty()) {
            $this->error('Espansioni CardTrader assenti in questo database: '.$missing->map(fn (string $code, int $ctId): string => "{$code} ({$ctId})")->implode(', ').'.');
            $this->line('Sincronizza prima i prodotti CardTrader, poi rilancia l\'importazione. Non ho scritto niente.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($series, $rules, $manualMatches, $decisions, $expansionIds, $user): void {
            foreach ($series as $item) {
                $model = PokecardexSeries::query()->firstOrNew(['id_serie' => $item['id_serie']]);

                if (! $model->exists) {
                    $model->fill(collect($item)->only(['lang', 'code', 'name_us', 'id_cardmarket'])->all());
                }

                if ($item['linked_by_hand']) {
                    $model->expansion_id = $item['expansion_ct_id'] === null ? null : $expansionIds[$item['expansion_ct_id']];
                    $model->expansion_source = 'manual';
                }

                $model->ignored = (bool) $item['ignored'];
                $model->save();
            }

            foreach ($rules as $item) {
                PokecardexSeriesRule::query()->updateOrCreate(
                    ['id_serie' => $item['id_serie'], 'id_version' => $item['id_version']],
                    ['expansion_id' => $expansionIds[$item['expansion_ct_id']]],
                );
            }

            foreach ($manualMatches as $item) {
                PokecardexCardMatch::query()->updateOrCreate(
                    ['id_card' => $item['id_card'], 'id_version' => $item['id_version']],
                    ['blueprint_id' => $item['blueprint_id'], 'source' => 'manual'],
                );
            }

            foreach ($decisions as $item) {
                PokecardexDecision::query()->updateOrCreate(
                    ['user_id' => $user->id, 'kind' => $item['kind'], 'subject' => $item['subject']],
                    ['value' => $item['value']],
                );
            }
        });

        $this->info('Configurazione importata. Vale dal prossimo giro: premi "Sincronizza ora" per una nuova anteprima.');
        $this->table(['Cosa', 'Righe'], [
            ['Serie abbinate a mano o ignorate', count($series)],
            ['Regole di serie', count($rules)],
            ['Abbinamenti manuali', count($manualMatches)],
            ['Decisioni', count($decisions)],
        ]);

        return self::SUCCESS;
    }
}
