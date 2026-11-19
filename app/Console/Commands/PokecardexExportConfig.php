<?php

namespace App\Console\Commands;

use App\Models\Expansion;
use App\Models\PokecardexCardMatch;
use App\Models\PokecardexDecision;
use App\Models\PokecardexSeries;
use App\Models\PokecardexSeriesRule;
use App\Models\User;
use App\Services\Pokecardex\ConfigSqlScript;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Esporta in un file la configurazione della sincronizzazione Pokecardex fatta
 * a mano: serie abbinate a mano o ignorate, regole di serie, abbinamenti
 * manuali e decisioni dell'utente. Il catalogo e gli abbinamenti automatici
 * restano fuori: il primo giro li ricostruisce da solo. Le espansioni viaggiano
 * per `ct_id`, che non cambia tra un database e l'altro.
 */
class PokecardexExportConfig extends Command
{
    public const FORMAT = 1;

    protected $signature = 'pokecardex:export-config
        {email : Email dell\'utente di cui esportare le decisioni}
        {path? : File di destinazione (predefinito storage/app/pokecardex-config.json, o .sql con --sql)}
        {--sql : Scrive uno script SQL (MySQL o MariaDB) da eseguire sul database di destinazione}
        {--target-email= : Con --sql, email dell\'utente di destinazione (predefinita: la stessa)}';

    protected $description = 'Esporta regole, serie, abbinamenti manuali e decisioni della sincronizzazione Pokecardex';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error("Nessun utente con email {$this->argument('email')}.");

            return self::FAILURE;
        }

        $expansions = Expansion::query()->get(['id', 'ct_id', 'code'])->keyBy('id');

        $series = PokecardexSeries::query()
            ->where(fn ($query) => $query->where('expansion_source', 'manual')->orWhere('ignored', true))
            ->orderBy('id_serie')
            ->get()
            ->map(fn (PokecardexSeries $series): array => [
                'id_serie' => $series->id_serie,
                'lang' => $series->lang,
                'code' => $series->code,
                'name_us' => $series->name_us,
                'id_cardmarket' => $series->id_cardmarket,
                'linked_by_hand' => $series->expansion_source === 'manual',
                'expansion_ct_id' => $series->expansion_source === 'manual' ? $expansions->get($series->expansion_id)?->ct_id : null,
                'expansion_code' => $series->expansion_source === 'manual' ? $expansions->get($series->expansion_id)?->code : null,
                'ignored' => $series->ignored,
            ]);

        $rules = PokecardexSeriesRule::query()
            ->orderBy('id_serie')
            ->orderBy('id_version')
            ->get()
            ->map(fn (PokecardexSeriesRule $rule): array => [
                'id_serie' => $rule->id_serie,
                'id_version' => $rule->id_version,
                'expansion_ct_id' => $expansions->get($rule->expansion_id)?->ct_id,
                'expansion_code' => $expansions->get($rule->expansion_id)?->code,
            ]);

        $manualMatches = PokecardexCardMatch::query()
            ->where('source', 'manual')
            ->orderBy('id_card')
            ->orderBy('id_version')
            ->get()
            ->map(fn (PokecardexCardMatch $match): array => [
                'id_card' => $match->id_card,
                'id_version' => $match->id_version,
                'blueprint_id' => $match->blueprint_id,
            ]);

        $decisions = PokecardexDecision::query()
            ->where('user_id', $user->id)
            ->orderBy('kind')
            ->orderBy('subject')
            ->get()
            ->map(fn (PokecardexDecision $decision): array => [
                'kind' => $decision->kind,
                'subject' => $decision->subject,
                'value' => $decision->value,
            ]);

        $config = [
            'format' => self::FORMAT,
            'exported_at' => now()->toIso8601String(),
            'series' => $series->all(),
            'rules' => $rules->all(),
            'manual_matches' => $manualMatches->all(),
            'decisions' => $decisions->all(),
        ];

        $path = $this->argument('path') ?? storage_path('app/pokecardex-config.'.($this->option('sql') ? 'sql' : 'json'));

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->option('sql')
            ? (new ConfigSqlScript)->build($config, $this->option('target-email') ?? $user->email)
            : json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->info("Configurazione esportata in {$path}");
        $this->table(['Cosa', 'Righe'], [
            ['Serie abbinate a mano o ignorate', $series->count()],
            ['Regole di serie', $rules->count()],
            ['Abbinamenti manuali', $manualMatches->count()],
            ['Decisioni', $decisions->count()],
        ]);

        return self::SUCCESS;
    }
}
