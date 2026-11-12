<?php

use App\Livewire\MyProfilePokecardex;
use App\Models\PokecardexAccount;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Filament::setCurrentPanel('admin');
});

it('saves the Pokecardex credentials on the account with the password encrypted', function () {
    livewire(MyProfilePokecardex::class)
        ->fillForm(['username' => 'ash', 'password' => 'pikachu-secret'])
        ->call('submit')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $account = $this->user->fresh()->pokecardexAccount;

    expect($account->username)->toBe('ash')
        ->and($account->password)->toBe('pikachu-secret');

    $storedPassword = DB::table('pokecardex_accounts')->where('id', $account->id)->value('password');
    expect($storedPassword)->not->toBe('pikachu-secret')
        ->and(decrypt($storedPassword, false))->toBe('pikachu-secret');
});

it('requires a password the first time', function () {
    livewire(MyProfilePokecardex::class)
        ->fillForm(['username' => 'ash', 'password' => null])
        ->call('submit')
        ->assertHasFormErrors(['password' => 'required']);

    expect(PokecardexAccount::count())->toBe(0);
});

it('keeps the saved password when the field is left empty', function () {
    PokecardexAccount::factory()->for($this->user)->create(['username' => 'ash', 'password' => 'old-secret']);

    livewire(MyProfilePokecardex::class)
        ->assertSchemaStateSet(['username' => 'ash', 'password' => null])
        ->fillForm(['username' => 'misty', 'password' => null])
        ->call('submit')
        ->assertHasNoFormErrors();

    $account = $this->user->fresh()->pokecardexAccount;

    expect($account->username)->toBe('misty')
        ->and($account->password)->toBe('old-secret');
});

it('forgets the saved session and the rejection when credentials change', function () {
    PokecardexAccount::factory()->for($this->user)->create([
        'username' => 'ash',
        'password' => 'wrong-secret',
        'session_cookies' => [['Name' => 'CardexCookie_sid', 'Value' => 'old']],
        'credentials_rejected_at' => now(),
    ]);

    livewire(MyProfilePokecardex::class)
        ->fillForm(['username' => 'ash', 'password' => 'right-secret'])
        ->call('submit')
        ->assertHasNoFormErrors();

    $account = $this->user->fresh()->pokecardexAccount;

    expect($account->credentials_rejected_at)->toBeNull()
        ->and($account->session_cookies)->toBeNull()
        ->and($account->password)->toBe('right-secret');
});

it('removes the credentials', function () {
    PokecardexAccount::factory()->for($this->user)->create();

    livewire(MyProfilePokecardex::class)
        ->call('removeCredentials')
        ->assertNotified();

    expect($this->user->fresh()->pokecardexAccount)->toBeNull();
});

it('keeps each account credentials separate', function () {
    $otherAccount = PokecardexAccount::factory()->create(['username' => 'brock']);

    livewire(MyProfilePokecardex::class)
        ->assertSchemaStateSet(['username' => null])
        ->fillForm(['username' => 'ash', 'password' => 'pikachu-secret'])
        ->call('submit');

    expect($otherAccount->fresh()->username)->toBe('brock')
        ->and(PokecardexAccount::count())->toBe(2);
});
