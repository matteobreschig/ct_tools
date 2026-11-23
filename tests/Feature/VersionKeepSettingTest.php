<?php

use App\Models\Product;
use App\Models\User;
use App\Models\VersionKeepSetting;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Deroga per versione alla vendita dinamica: copie da tenere ferme,
 * in aggiunta al minimo del gruppo carta.
 */

it('builds the same key for a product and its setting', function () {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create([
        'blueprint_id' => 413201, 'language' => 'it', 'condition' => 'nm', 'reverse' => true,
    ]);
    $setting = VersionKeepSetting::factory()->for($user)->create([
        'blueprint_id' => 413201, 'language' => 'it', 'condition' => 'nm', 'reverse' => true,
    ]);

    expect(VersionKeepSetting::keyFor($product))->toBe('413201:it:nm:1')
        ->and($setting->key())->toBe(VersionKeepSetting::keyFor($product));
});

it('is unique per user and version', function () {
    $user = User::factory()->create();
    $attributes = ['blueprint_id' => 1, 'language' => 'it', 'condition' => 'nm', 'reverse' => false];

    VersionKeepSetting::factory()->for($user)->create($attributes);

    expect(fn () => VersionKeepSetting::factory()->for($user)->create($attributes))
        ->toThrow(QueryException::class);
});
