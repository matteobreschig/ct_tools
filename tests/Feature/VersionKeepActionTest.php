<?php

use App\Filament\Resources\CardGroups\Pages\ViewCardGroup;
use App\Filament\Resources\CardGroups\RelationManagers\VersionsRelationManager;
use App\Models\CardGroup;
use App\Models\Product;
use App\Models\User;
use App\Models\VersionKeepSetting;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

/*
 * Azione "Copie da tenere" sulle righe della tabella Versioni. La vista
 * card_groups esiste solo su MySQL: nei test una vista minima su sqlite.
 */

beforeEach(function () {
    DB::statement("CREATE VIEW card_groups AS
        SELECT 'n:' || user_id || ':' || meta_name AS group_key, user_id, 'meta' AS group_type,
               meta_name, MAX(name) AS name
        FROM products GROUP BY user_id, meta_name");

    $this->user = User::factory()->create(['ct_api_key' => 'test-key']);
    $this->actingAs($this->user);
    Filament::setCurrentPanel('admin');

    $this->product = Product::factory()->for($this->user)->create([
        'meta_name' => 'exeggutor-alola', 'blueprint_id' => 129001, 'language' => 'it',
        'condition' => 'nm', 'reverse' => false,
    ]);
    $this->group = CardGroup::where('user_id', $this->user->id)->firstOrFail();
});

function versionsTable(CardGroup $group)
{
    return livewire(VersionsRelationManager::class, [
        'ownerRecord' => $group,
        'pageClass' => ViewCardGroup::class,
    ]);
}

it('saves the keep for a version', function () {
    versionsTable($this->group)
        ->callAction(TestAction::make('set_keep_quantity')->table($this->product), ['keep_quantity' => 1])
        ->assertNotified();

    $this->assertDatabaseHas('version_keep_settings', [
        'user_id' => $this->user->id, 'blueprint_id' => 129001, 'language' => 'it',
        'condition' => 'nm', 'reverse' => false, 'keep_quantity' => 1,
    ]);
});

it('updates the keep instead of duplicating it', function () {
    VersionKeepSetting::factory()->for($this->user)->create([
        'blueprint_id' => 129001, 'language' => 'it', 'condition' => 'nm', 'reverse' => false, 'keep_quantity' => 1,
    ]);

    versionsTable($this->group)
        ->callAction(TestAction::make('set_keep_quantity')->table($this->product), ['keep_quantity' => 2]);

    expect(VersionKeepSetting::count())->toBe(1)
        ->and(VersionKeepSetting::first()->keep_quantity)->toBe(2);
});

it('deletes the keep when set to 0 or left empty', function (mixed $value) {
    VersionKeepSetting::factory()->for($this->user)->create([
        'blueprint_id' => 129001, 'language' => 'it', 'condition' => 'nm', 'reverse' => false,
    ]);

    versionsTable($this->group)
        ->callAction(TestAction::make('set_keep_quantity')->table($this->product), ['keep_quantity' => $value]);

    expect(VersionKeepSetting::count())->toBe(0);
})->with([0, null]);

it('shows the keep in a read only column', function () {
    VersionKeepSetting::factory()->for($this->user)->create([
        'blueprint_id' => 129001, 'language' => 'it', 'condition' => 'nm', 'reverse' => false, 'keep_quantity' => 4,
    ]);

    versionsTable($this->group)
        ->assertTableColumnStateSet('keep_quantity', 4, $this->product);
});

it('does not touch the keep of another user', function () {
    $other = User::factory()->create();
    $otherKeep = VersionKeepSetting::factory()->for($other)->create([
        'blueprint_id' => 129001, 'language' => 'it', 'condition' => 'nm', 'reverse' => false, 'keep_quantity' => 5,
    ]);

    versionsTable($this->group)
        ->callAction(TestAction::make('set_keep_quantity')->table($this->product), ['keep_quantity' => 1]);

    expect($otherKeep->refresh()->keep_quantity)->toBe(5)
        ->and(VersionKeepSetting::count())->toBe(2);
});
