<?php

use App\Models\Expansion;
use App\Models\PokecardexCardMatch;
use App\Models\PokecardexDecision;
use App\Models\PokecardexSeries;
use App\Models\PokecardexSeriesRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

/*
 * Configurazione fatta a mano in sviluppo, portata in produzione con un file.
 * Le espansioni viaggiano per ct_id: in produzione il loro id interno cambia.
 */

beforeEach(function () {
    $this->path = storage_path('framework/testing/pokecardex-config.json');
    File::ensureDirectoryExists(dirname($this->path));
    File::delete($this->path);

    $this->user = User::factory()->create(['email' => 'seller@example.test']);
    $other = User::factory()->create();

    $prizePack = Expansion::factory()->create(['ct_id' => 3001, 'code' => 'playprizep']);
    $promos = Expansion::factory()->create(['ct_id' => 3002, 'code' => 'svpromo']);
    $auto = Expansion::factory()->create(['ct_id' => 3003, 'code' => 'twm']);

    PokecardexSeries::factory()->create(['id_serie' => 206, 'code' => 'PRZP', 'expansion_id' => $prizePack->id, 'expansion_source' => 'manual']);
    PokecardexSeries::factory()->create(['id_serie' => 137, 'code' => 'TOPPS', 'ignored' => true]);
    PokecardexSeries::factory()->create(['id_serie' => 240, 'code' => 'TWM', 'expansion_id' => $auto->id, 'expansion_source' => 'auto']);

    PokecardexSeriesRule::create(['id_serie' => 240, 'id_version' => 94, 'expansion_id' => $promos->id]);
    PokecardexCardMatch::create(['id_card' => 26633, 'id_version' => 1, 'blueprint_id' => 413214, 'source' => 'manual']);
    PokecardexCardMatch::create(['id_card' => 26600, 'id_version' => 1, 'blueprint_id' => 287733, 'source' => 'cardmarket']);
    PokecardexDecision::create(['user_id' => $this->user->id, 'kind' => 'side', 'subject' => '389952|it|0|0', 'value' => 'cardtrader']);
    PokecardexDecision::create(['user_id' => $other->id, 'kind' => 'side', 'subject' => '1|it|0|0', 'value' => 'pokecardex']);
});

afterEach(function () {
    File::delete($this->path);
});

/**
 * Svuota tutto e ricrea le espansioni con id interni diversi, come in
 * produzione.
 */
function wipeToProduction(): void
{
    PokecardexDecision::query()->delete();
    PokecardexCardMatch::query()->delete();
    PokecardexSeriesRule::query()->delete();
    PokecardexSeries::query()->delete();
    Expansion::query()->delete();

    Expansion::factory()->create(['ct_id' => 9999]);
    Expansion::factory()->create(['ct_id' => 3002, 'code' => 'svpromo']);
    Expansion::factory()->create(['ct_id' => 3001, 'code' => 'playprizep']);
}

it('exports only what was set by hand', function () {
    $this->artisan('pokecardex:export-config', ['email' => 'seller@example.test', 'path' => $this->path])->assertSuccessful();

    $config = json_decode(File::get($this->path), true);

    expect(collect($config['series'])->pluck('id_serie')->sort()->values()->all())->toBe([137, 206])
        ->and($config['rules'])->toBe([['id_serie' => 240, 'id_version' => 94, 'expansion_ct_id' => 3002, 'expansion_code' => 'svpromo']])
        ->and($config['manual_matches'])->toBe([['id_card' => 26633, 'id_version' => 1, 'blueprint_id' => 413214]])
        ->and($config['decisions'])->toBe([['kind' => 'side', 'subject' => '389952|it|0|0', 'value' => 'cardtrader']]);
});

it('imports the configuration onto the production expansions', function () {
    $this->artisan('pokecardex:export-config', ['email' => 'seller@example.test', 'path' => $this->path])->assertSuccessful();
    wipeToProduction();
    $production = User::factory()->create(['email' => 'owner@example.test']);

    $this->artisan('pokecardex:import-config', ['email' => 'owner@example.test', 'path' => $this->path])->assertSuccessful();

    $prizePack = Expansion::where('ct_id', 3001)->sole();
    $promos = Expansion::where('ct_id', 3002)->sole();

    expect(PokecardexSeries::find(206)->expansion_id)->toBe($prizePack->id)
        ->and(PokecardexSeries::find(206)->expansion_source)->toBe('manual')
        ->and(PokecardexSeries::find(206)->code)->toBe('PRZP')
        ->and(PokecardexSeries::find(137)->ignored)->toBeTrue()
        ->and(PokecardexSeriesRule::sole()->only(['id_serie', 'id_version', 'expansion_id']))->toBe(['id_serie' => 240, 'id_version' => 94, 'expansion_id' => $promos->id])
        ->and(PokecardexCardMatch::sole()->only(['id_card', 'id_version', 'blueprint_id', 'source']))->toBe(['id_card' => 26633, 'id_version' => 1, 'blueprint_id' => 413214, 'source' => 'manual'])
        ->and(PokecardexDecision::sole()->only(['user_id', 'kind', 'subject', 'value']))->toBe(['user_id' => $production->id, 'kind' => 'side', 'subject' => '389952|it|0|0', 'value' => 'cardtrader']);
});

it('keeps catalog data of series that already exist', function () {
    $this->artisan('pokecardex:export-config', ['email' => 'seller@example.test', 'path' => $this->path])->assertSuccessful();
    wipeToProduction();
    PokecardexSeries::factory()->create(['id_serie' => 206, 'code' => 'PRZP', 'name_us' => 'Prize Pack Series', 'cards_loaded_at' => now()]);

    $this->artisan('pokecardex:import-config', ['email' => 'seller@example.test', 'path' => $this->path])->assertSuccessful();

    expect(PokecardexSeries::find(206)->name_us)->toBe('Prize Pack Series')
        ->and(PokecardexSeries::find(206)->cards_loaded_at)->not->toBeNull();
});

it('writes nothing when an expansion is missing in production', function () {
    $this->artisan('pokecardex:export-config', ['email' => 'seller@example.test', 'path' => $this->path])->assertSuccessful();
    wipeToProduction();
    Expansion::where('ct_id', 3002)->delete();

    $this->artisan('pokecardex:import-config', ['email' => 'seller@example.test', 'path' => $this->path])
        ->expectsOutputToContain('svpromo')
        ->assertFailed();

    expect(PokecardexSeries::count())->toBe(0)
        ->and(PokecardexSeriesRule::count())->toBe(0)
        ->and(PokecardexDecision::count())->toBe(0);
});

it('fails for an unknown user', function () {
    $this->artisan('pokecardex:export-config', ['email' => 'nobody@example.test', 'path' => $this->path])->assertFailed();
});
