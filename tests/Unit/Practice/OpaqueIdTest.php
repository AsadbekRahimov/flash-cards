<?php

declare(strict_types=1);

use App\Domain\Practice\Support\OpaqueId;
use Illuminate\Support\Str;

afterEach(fn () => Str::createRandomStringsNormally());

it('is a prefix and 10 random characters of a 62-character alphabet', function (): void {
    foreach (['e', 'f', 'o', 'l', 'r'] as $prefix) {
        expect(OpaqueId::make($prefix))->toMatch('/\A'.$prefix.'[A-Za-z0-9]{10}\z/');
    }
});

it('does not repeat: 500 ids of a snapshot are distinct', function (): void {
    $ids = [];
    for ($i = 0; $i < 500; $i++) {
        $ids[] = OpaqueId::make('e', $ids);
    }

    expect(array_unique($ids))->toHaveCount(500);
});

it('draws another string when the id is already taken in the snapshot', function (): void {
    Str::createRandomStringsUsingSequence(['AAAAAAAAAA', 'AAAAAAAAAA', 'BBBBBBBBBB']);

    expect(OpaqueId::make('e', ['eAAAAAAAAAA']))->toBe('eBBBBBBBBBB');
});
