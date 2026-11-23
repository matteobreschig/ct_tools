<?php

use App\Jobs\SyncProducts;

it('rejects a number match when the set total differs', function () {
    expect(SyncProducts::setTotalMatches('11/101', 128))->toBeFalse();
});

it('accepts a number match when the set total is the same', function () {
    expect(SyncProducts::setTotalMatches('011/128', 128))->toBeTrue()
        ->and(SyncProducts::setTotalMatches('130/128', 128))->toBeTrue();
});

it('keeps the match when either total is missing', function () {
    expect(SyncProducts::setTotalMatches('11', 128))->toBeTrue()
        ->and(SyncProducts::setTotalMatches('0', 128))->toBeTrue()
        ->and(SyncProducts::setTotalMatches('11/101', 0))->toBeTrue()
        ->and(SyncProducts::setTotalMatches('11/', 128))->toBeTrue();
});
