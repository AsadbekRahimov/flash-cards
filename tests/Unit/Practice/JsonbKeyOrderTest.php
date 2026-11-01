<?php

declare(strict_types=1);

use App\Domain\Practice\Support\IsoTime;
use App\Domain\Practice\Support\JsonbKeyOrder;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('orders the keys of an object the way jsonb does: shorter first, then bytewise', function (): void {
    $ordered = JsonbKeyOrder::object(['verdict' => 1, 'pair' => 2, 'reveal' => 3, 'first_attempt' => 4, 'a' => 5, 'B' => 6, 'ab' => 7]);

    expect(array_keys($ordered))->toBe(['B', 'a', 'ab', 'pair', 'reveal', 'verdict', 'first_attempt']);
});

it('goes into the objects inside objects and inside lists, and leaves lists in their order', function (): void {
    $value = JsonbKeyOrder::normalize([
        'reveal' => ['translation' => 't', 'expected' => 'e', 'diff' => [['ok' => true, 'got' => 'x', 'expected' => 'y'], ['b' => 1, 'a' => 2]]],
        'list' => [3, 1, 2],
    ]);

    expect(array_keys($value))->toBe(['list', 'reveal'])
        ->and($value['list'])->toBe([3, 1, 2])
        ->and(array_keys($value['reveal']))->toBe(['diff', 'expected', 'translation'])
        ->and(array_keys($value['reveal']['diff'][0]))->toBe(['ok', 'got', 'expected'])
        ->and(array_keys($value['reveal']['diff'][1]))->toBe(['a', 'b']);
});

it('passes scalars, nulls and empty arrays through', function (): void {
    expect(JsonbKeyOrder::normalize('x'))->toBe('x')
        ->and(JsonbKeyOrder::normalize(null))->toBeNull()
        ->and(JsonbKeyOrder::normalize([]))->toBe([])
        ->and(JsonbKeyOrder::object(['b' => null, 'a' => []]))->toBe(['a' => [], 'b' => null]);
});

it('is the order PostgreSQL gives back (PostgreSQL)', function (): void {
    $value = ['verdict' => 'correct', 'first_attempt' => true, 'reveal' => ['translation' => 'x', 'expected' => 'y', 'diff' => [['ok' => true, 'got' => 'a', 'expected' => 'b']]], 'pair' => null, 'a' => 1];

    $stored = json_decode((string) DB::selectOne('select ?::jsonb as j', [json_encode($value)])->j, true);

    expect(json_encode(JsonbKeyOrder::normalize($value)))->toBe(json_encode($stored));
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'jsonb is PostgreSQL-only.');

it('formats a time as ISO 8601 in UTC with milliseconds', function (): void {
    expect(IsoTime::format(CarbonImmutable::parse('2026-10-30 10:00:05.321', 'UTC')))->toBe('2026-10-30T10:00:05.321Z')
        ->and(IsoTime::format(CarbonImmutable::parse('2026-10-30 15:00:05', 'Asia/Tashkent')))->toBe('2026-10-30T10:00:05.000Z');
});

it('leaves a mutable Carbon alone when it formats it', function (): void {
    $time = Carbon::parse('2026-10-30 15:00:05', 'Asia/Tashkent');

    IsoTime::format($time);

    expect($time->getTimezone()->getName())->toBe('Asia/Tashkent');
});
