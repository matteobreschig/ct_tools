<?php

use App\Filament\Pages\Pokecardex;
use App\Filament\Resources\PokecardexSeries\Pages\ManagePokecardexSeries;
use App\Jobs\SyncPokecardex;
use App\Models\CtExpansionBlueprint;
use App\Models\Expansion;
use App\Models\PokecardexAccount;
use App\Models\PokecardexCard;
use App\Models\PokecardexCardMatch;
use App\Models\PokecardexDecision;
use App\Models\PokecardexSeries;
use App\Models\PokecardexSeriesRule;
use App\Models\PokecardexSyncRun;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

/*
 * Pagina "Sincronizzazione Pokecardex" e risorsa delle serie (§ 8 del design).
 */

function previewRun(User $user): PokecardexSyncRun
{
    return PokecardexSyncRun::create([
        'user_id' => $user->id, 'started_at' => now()->subMinute(), 'finished_at' => now(), 'outcome' => 'preview',
        'message' => 'Primo allineamento: serve la tua approvazione.', 'fingerprint' => 'abc',
        'pokecardex_copies' => 3, 'cardtrader_copies' => 1,
        'plan' => [
            'first_run' => true,
            'changes' => [
                ['key' => ['blueprint_id' => 287733, 'condition' => 'nm', 'language' => 'it', 'reverse' => false, 'first_edition' => false],
                    'name' => 'Froslass', 'collector_number' => '53', 'direction' => 'to_cardtrader', 'before' => 1, 'after' => 3, 'sales' => []],
                ['key' => ['blueprint_id' => 351495, 'condition' => 'nm', 'language' => 'it', 'reverse' => false, 'first_edition' => false],
                    'name' => 'Kadabra', 'collector_number' => '55', 'direction' => 'to_pokecardex', 'before' => 7, 'after' => 4,
                    'sales' => [['quantity' => 3, 'paid_at' => '2026-11-03', 'order' => 'C1']]],
            ],
            'reviews' => [['kind' => 'unmatched', 'message' => 'Versione speciale "Reverse (Masterball)" senza regola di serie.', 'payload' => ['card' => ['name' => 'Eevee', 'number' => '074/131']]]],
            'pending' => [],
        ],
    ]);
}

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create(['ct_api_key' => 'test-key']);
    PokecardexAccount::factory()->for($this->user)->create();
    $this->actingAs($this->user);
    Filament::setCurrentPanel('admin');
});

it('starts a sync from the button', function () {
    livewire(Pokecardex::class)
        ->callAction('sync')
        ->assertNotified();

    Queue::assertPushed(SyncPokecardex::class, fn (SyncPokecardex $job): bool => $job->user->is($this->user) && $job->approvedRunId === null);
});

it('does not start a sync without Pokecardex credentials', function () {
    $this->user->pokecardexAccount->delete();

    livewire(Pokecardex::class)->assertActionDisabled('sync');
});

it('does not start a second sync while one is running', function () {
    PokecardexSyncRun::create(['user_id' => $this->user->id, 'started_at' => now()]);

    livewire(Pokecardex::class)
        ->assertActionDisabled('sync')
        ->assertSee('Giro in corso');
});

it('shows the preview writes and the items to review', function () {
    previewRun($this->user);

    livewire(Pokecardex::class)
        ->assertSee('Anteprima da approvare')
        ->assertSee('Froslass')
        ->assertSee('CardTrader: 1 → 3')
        ->assertSee('Vendute su CardTrader il 03/11/2026')
        ->filterTable('section', 'reviews')
        ->assertSee('Eevee')
        ->assertSee('senza regola di serie');
});

it('approves a preview by running the sync with it', function () {
    $run = previewRun($this->user);

    livewire(Pokecardex::class)->callAction('apply');

    Queue::assertPushed(SyncPokecardex::class, fn (SyncPokecardex $job): bool => $job->approvedRunId === $run->id);
});

it('discards a preview', function () {
    $run = previewRun($this->user);

    livewire(Pokecardex::class)
        ->callAction('discard')
        ->assertActionHidden('apply');

    expect($run->refresh()->outcome)->toBe('discarded');
    Queue::assertNothingPushed();
});

it('links a series by hand and saves its rules', function () {
    $series = PokecardexSeries::factory()->create(['code' => 'PRZP', 'name_us' => 'Prize Pack']);
    PokecardexCard::factory()->create(['id_serie' => $series->id_serie, 'versions' => [['id_version' => 144, 'nom_version_us' => 'Non Holo (PPS)', 'langues' => [2]]]]);
    $prizePack = Expansion::factory()->create(['code' => 'playprizep', 'name_en' => 'Play! Pokémon Prize Pack Series']);

    livewire(ManagePokecardexSeries::class)
        ->callAction(TestAction::make('edit')->table($series), [
            'expansion_id' => $prizePack->id,
            'rules' => [['id_version' => 144, 'expansion_id' => $prizePack->id]],
        ])
        ->assertHasNoFormErrors();

    expect($series->refresh()->expansion_id)->toBe($prizePack->id)
        ->and($series->expansion_source)->toBe('manual')
        ->and($series->rules()->sole()->id_version)->toBe(144);
});

it('forgets the matches of a rule when the rule changes', function () {
    $series = PokecardexSeries::factory()->create();
    $card = PokecardexCard::factory()->create(['id_serie' => $series->id_serie]);
    $rule = PokecardexSeriesRule::create(['id_serie' => $series->id_serie, 'id_version' => 144, 'expansion_id' => Expansion::factory()->create()->id]);
    PokecardexCardMatch::create(['id_card' => $card->id_card, 'id_version' => 144, 'blueprint_id' => 1, 'source' => 'rule']);
    PokecardexCardMatch::create(['id_card' => $card->id_card, 'id_version' => 1, 'blueprint_id' => 2, 'source' => 'cardmarket']);

    $rule->update(['expansion_id' => Expansion::factory()->create()->id]);

    expect(PokecardexCardMatch::pluck('id_version')->all())->toBe([1]);
});

it('saves the side chosen for a card', function () {
    previewRun($this->user);

    livewire(Pokecardex::class)
        ->callAction(TestAction::make('chooseSide')->table(0), ['side' => 'cardtrader'])
        ->assertNotified();

    expect(PokecardexDecision::sole())
        ->subject->toBe('287733|it|0|0')
        ->value->toBe('cardtrader');

    livewire(Pokecardex::class)->assertSee('Vince CardTrader');
});

it('ignores a review and restores it', function () {
    previewRun($this->user);

    livewire(Pokecardex::class)
        ->filterTable('section', 'reviews')
        ->callAction(TestAction::make('ignore')->table(0))
        ->assertDontSee('Eevee')
        ->filterTable('section', 'ignored')
        ->assertSee('Eevee')
        ->callAction(TestAction::make('restore')->table(0))
        ->assertDontSee('Eevee');

    expect(PokecardexDecision::count())->toBe(0);
});

it('matches a Pokecardex row to a blueprint by hand', function () {
    $run = previewRun($this->user);
    $plan = $run->plan;
    $plan['reviews'][0]['payload']['row'] = ['id_collection' => 9, 'id_card' => 26600, 'id_version' => 197, 'id_etat' => 6, 'id_langue' => 2, 'quantite' => 1];
    $run->update(['plan' => $plan]);
    $blueprint = CtExpansionBlueprint::factory()->create(['name' => 'Eevee']);

    livewire(Pokecardex::class)
        ->filterTable('section', 'reviews')
        ->callAction(TestAction::make('matchBlueprint')->table(0), ['blueprint_id' => $blueprint->blueprint_id])
        ->assertNotified();

    expect(PokecardexCardMatch::sole())
        ->id_card->toBe(26600)
        ->id_version->toBe(197)
        ->blueprint_id->toBe($blueprint->blueprint_id)
        ->source->toBe('manual');
});

it('matches a CardTrader listing to a Pokecardex card by hand', function () {
    $run = previewRun($this->user);
    $plan = $run->plan;
    $plan['reviews'] = [['kind' => 'no_target', 'message' => 'Nessuna carta.', 'payload' => ['key' => ['blueprint_id' => 4242, 'condition' => 'nm', 'language' => 'it', 'reverse' => true, 'first_edition' => false]]]];
    $run->update(['plan' => $plan]);
    $card = PokecardexCard::factory()->create(['name_en' => 'Pikachu']);

    livewire(Pokecardex::class)
        ->filterTable('section', 'reviews')
        ->callAction(TestAction::make('matchCard')->table(0), ['id_card' => $card->id_card, 'id_version' => 2])
        ->assertNotified();

    expect(PokecardexCardMatch::sole())
        ->id_card->toBe($card->id_card)
        ->id_version->toBe(2)
        ->blueprint_id->toBe(4242);
});
