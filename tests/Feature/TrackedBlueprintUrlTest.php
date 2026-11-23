<?php

use App\Filament\Resources\TrackedBlueprints\Pages\CreateTrackedBlueprint;
use App\Models\Expansion;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

/*
 * La pagina cardtrader.com è dietro la challenge di Cloudflare (403): il form
 * ricava tutto dall'id nell'URL e dall'API, senza leggere la pagina.
 */

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['ct_api_key' => 'test-key']));

    Http::fake([
        'www.cardtrader.com/*' => Http::response('Just a moment...', 403),
        'api.cardtrader.com/*' => Http::response([
            '413201' => [[
                'id' => 1,
                'blueprint_id' => 413201,
                'name_en' => 'Charizard',
                'price' => ['cents' => 4550, 'currency' => 'EUR'],
                'expansion' => ['id' => 99, 'name_en' => '30th Celebration'],
                'properties_hash' => ['collector_number' => '4/102', 'pokemon_reverse' => false],
            ], [
                'id' => 2,
                'blueprint_id' => 413201,
                'name_en' => 'Charizard',
                'price' => ['cents' => 3990, 'currency' => 'EUR'],
                'expansion' => ['id' => 99, 'name_en' => '30th Celebration'],
                'properties_hash' => ['collector_number' => '4/102', 'pokemon_reverse' => false],
            ]],
        ]),
    ]);
});

it('fills the form from the API when the CardTrader page is blocked', function () {
    $expansion = Expansion::factory()->create(['ct_id' => 99, 'tcgdex_code' => null]);

    livewire(CreateTrackedBlueprint::class)
        ->fillForm(['blueprint_url' => 'https://www.cardtrader.com/it-IT/cards/413201-charizard-30th-celebration-stamp-4-102-30th-celebration'])
        ->assertFormSet([
            'blueprint_id' => '413201',
            'blueprint_name' => 'Charizard',
            'price' => 39.9,
            'blueprint_expansion_id' => $expansion->id,
            'blueprint_collector_number' => 4,
        ]);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'www.cardtrader.com'));
});

it('clears the url when it holds no blueprint id', function () {
    livewire(CreateTrackedBlueprint::class)
        ->fillForm(['blueprint_url' => 'https://www.cardtrader.com/it-IT/pokemon'])
        ->assertFormSet(['blueprint_url' => null]);
});
