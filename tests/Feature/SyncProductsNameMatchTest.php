<?php

use App\Jobs\SyncProducts;

it('rejects a number match on a different card', function () {
    expect(SyncProducts::namesMatch('Genesect EX', 'Moltres'))->toBeFalse();
});

it('accepts the same card despite case, accents and punctuation', function () {
    expect(SyncProducts::namesMatch('Moltres', 'Moltres'))->toBeTrue()
        ->and(SyncProducts::namesMatch('Genesect EX', 'Genesect ex'))->toBeTrue()
        ->and(SyncProducts::namesMatch('Flabebe', 'Flabébé'))->toBeTrue()
        ->and(SyncProducts::namesMatch('Mr. Mime', 'Mr Mime'))->toBeTrue();
});

it('accepts a name that contains the other', function () {
    expect(SyncProducts::namesMatch('Basic Fire Energy', 'Fire Energy'))->toBeTrue()
        ->and(SyncProducts::namesMatch('Pikachu', 'Pikachu (Pokemon Center)'))->toBeTrue();
});

it('keeps the match when a name is missing', function () {
    expect(SyncProducts::namesMatch('Moltres', null))->toBeTrue()
        ->and(SyncProducts::namesMatch('', 'Moltres'))->toBeTrue();
});
