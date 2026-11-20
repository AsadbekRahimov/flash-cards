<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ClientTimingReport;
use App\Models\Learner;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * `analytics:client-timing` / ClientTimingReport (audit 2026-11-18, «Скорость для ученика»): p50 / p75 / p95 of the `client.timing`
 * offsets per platform × cold / warm × class / solo, the number of opens per cell, cells under 30 opens marked. The percentiles are
 * computed in PHP, so sqlite and PostgreSQL give the same numbers (run this file on both).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-19 10:00:00', 'UTC'));
    $this->learner = Learner::factory()->create();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** @param array<string, mixed> $props */
function timingEvent(array $props, ?CarbonImmutable $at = null, ProductEventName $name = ProductEventName::ClientTiming): void
{
    ProductEvent::query()->create([
        'name' => $name->value,
        'learner_id' => test()->learner->id,
        'props' => ['open_id' => fake()->uuid(), 'entry' => 'home', 'locale' => 'ru', ...$props],
        'occurred_at' => $at ?? CarbonImmutable::now()->subHour(),
    ]);
}

/** A known set: 40 Android cold opens of class learners, 5 iOS warm solo, 1 of an unknown segment; plus events the report must skip. */
function seedTimingSet(): void
{
    for ($i = 1; $i <= 40; $i++) {
        timingEvent([
            'platform' => 'android', 'cold' => true, 'class' => true,
            'button_ready' => $i * 100, 'first_tap' => $i * 100 + 500,
            ...($i <= 20 ? ['first_answer_ack' => 5000] : []),
        ]);
    }
    for ($i = 1; $i <= 5; $i++) {
        timingEvent(['platform' => 'ios', 'cold' => false, 'class' => false, 'button_ready' => 1000, 'first_audio' => $i * 1000]);
    }
    // No `class` (no snapshot on the server); a tap before the button is a broken pair, left out of "understand".
    timingEvent(['platform' => 'other', 'cold' => false, 'button_ready' => 200, 'first_tap' => 100]);

    // Outside the 7-day window, and another event: not read.
    timingEvent(['platform' => 'android', 'cold' => true, 'class' => true, 'button_ready' => 99_999], CarbonImmutable::now()->subDays(8));
    timingEvent(['platform' => 'android', 'cold' => true, 'class' => true, 'button_ready' => 99_999], name: ProductEventName::TwaAuthenticated);
}

it('computes percentiles by linear interpolation between the closest ranks', function (array $values, float $p, ?float $expected): void {
    expect(ClientTimingReport::percentile($values, $p))->toEqualWithDelta($expected, 0.0001);
})->with([
    'empty' => [[], 50, null],
    'one value' => [[7], 95, 7.0],
    'median of four' => [[40, 10, 30, 20], 50, 25.0],
    'p75 of four' => [[10, 20, 30, 40], 75, 32.5],
    'p95 of four' => [[10, 20, 30, 40], 95, 38.5],
    'p95 of 1..100' => [range(1, 100), 95, 95.05],
    'max' => [[3, 1, 2], 100, 3.0],
]);

it('reports the cells of the window with their opens and percentiles, the total first', function (): void {
    seedTimingSet();

    $now = CarbonImmutable::now();
    $report = app(ClientTimingReport::class)->build($now->subDays(7), $now);

    expect($report['opens'])->toBe(46)
        ->and($report['truncated'])->toBeFalse()
        ->and(array_map(static fn (array $c): string => "{$c['platform']}|{$c['start']}|{$c['segment']}|{$c['opens']}", $report['cells']))
        ->toBe(['all|all|all|46', 'android|cold|class|40', 'ios|warm|solo|5', 'other|warm|unknown|1']);

    $android = $report['cells'][1];
    expect($android['small'])->toBeFalse()
        // 100, 200, …, 4000: p50 at rank 19.5 = 2050, p75 at 29.25 = 3025, p95 at 37.05 = 3805.
        ->and($android['metrics']['button_ready'])->toBe(['n' => 40, 'p50' => 2050, 'p75' => 3025, 'p95' => 3805])
        ->and($android['metrics']['understand'])->toBe(['n' => 40, 'p50' => 500, 'p75' => 500, 'p95' => 500])
        ->and($android['metrics']['first_answer_ack'])->toBe(['n' => 20, 'p50' => 5000, 'p75' => 5000, 'p95' => 5000])
        ->and($android['metrics']['first_audio'])->toBe(['n' => 0, 'p50' => null, 'p75' => null, 'p95' => null]);

    $ios = $report['cells'][2];
    expect($ios['small'])->toBeTrue()
        // 1000 … 5000: p50 3000, p75 at rank 3 = 4000, p95 at rank 3.8 = 4800.
        ->and($ios['metrics']['first_audio'])->toBe(['n' => 5, 'p50' => 3000, 'p75' => 4000, 'p95' => 4800]);

    expect($report['cells'][3]['metrics']['understand']['n'])->toBe(0)
        ->and($report['cells'][3]['small'])->toBeTrue()
        ->and($report['cells'][0]['small'])->toBeFalse()
        ->and($report['cells'][0]['metrics']['understand']['n'])->toBe(40)
        ->and($report['cells'][0]['metrics']['button_ready']['n'])->toBe(46);
});

it('prints the table, marking the small cells', function (): void {
    seedTimingSet();

    $this->artisan('analytics:client-timing', ['--days' => 7])
        ->expectsOutputToContain('Opens of the Mini App with timing marks')
        ->expectsTable(
            ['Platform', 'Start', 'Segment', 'Opens', 'Measure', 'n', 'p50', 'p75', 'p95', 'Note'],
            [
                ['all', 'all', 'all', 46, 'start → button', 46, 1750, 2875, 3775, ''],
                ['all', 'all', 'all', 46, 'button → tap', 40, 500, 500, 500, ''],
                ['all', 'all', 'all', 46, 'start → 1st answer', 20, 5000, 5000, 5000, ''],
                ['all', 'all', 'all', 46, 'start → 1st audio', 5, 3000, 4000, 4800, ''],
                ['android', 'cold', 'class', 40, 'start → button', 40, 2050, 3025, 3805, ''],
                ['android', 'cold', 'class', 40, 'button → tap', 40, 500, 500, 500, ''],
                ['android', 'cold', 'class', 40, 'start → 1st answer', 20, 5000, 5000, 5000, ''],
                ['android', 'cold', 'class', 40, 'start → 1st audio', 0, '—', '—', '—', ''],
                ['ios', 'warm', 'solo', 5, 'start → button', 5, 1000, 1000, 1000, 'too small (< 30 opens)'],
                ['ios', 'warm', 'solo', 5, 'button → tap', 0, '—', '—', '—', 'too small (< 30 opens)'],
                ['ios', 'warm', 'solo', 5, 'start → 1st answer', 0, '—', '—', '—', 'too small (< 30 opens)'],
                ['ios', 'warm', 'solo', 5, 'start → 1st audio', 5, 3000, 4000, 4800, 'too small (< 30 opens)'],
                ['other', 'warm', 'unknown', 1, 'start → button', 1, 200, 200, 200, 'too small (< 30 opens)'],
                ['other', 'warm', 'unknown', 1, 'button → tap', 0, '—', '—', '—', 'too small (< 30 opens)'],
                ['other', 'warm', 'unknown', 1, 'start → 1st answer', 0, '—', '—', '—', 'too small (< 30 opens)'],
                ['other', 'warm', 'unknown', 1, 'start → 1st audio', 0, '—', '—', '—', 'too small (< 30 opens)'],
            ],
        )
        ->assertSuccessful();
});

it('says so when there is nothing in the window', function (): void {
    $this->artisan('analytics:client-timing')
        ->expectsOutputToContain('No client.timing events in the window')
        ->assertSuccessful();
});

it('refuses a window that is not 1..90 days', function (string $days): void {
    $this->artisan('analytics:client-timing', ['--days' => $days])
        ->expectsOutputToContain('--days')
        ->assertExitCode(2);
})->with(['0', '91', 'week', '-3']);

it('gives one measure per platform over every start and segment, from the same opens as the report', function (): void {
    seedTimingSet();

    $now = CarbonImmutable::now();
    $byPlatform = app(ClientTimingReport::class)->byPlatform($now->subDays(7), $now);

    // Android: 100..4000 ms over 40 opens; iOS: five opens of 1000 ms; the open of "other" has its own entry.
    expect($byPlatform['opens'])->toBe(46)
        ->and($byPlatform['truncated'])->toBeFalse()
        ->and(array_keys($byPlatform['platforms']))->toBe(['android', 'ios', 'other'])
        ->and($byPlatform['platforms']['android'])->toBe(['n' => 40, 'p50' => 2050, 'p75' => 3025, 'p95' => 3805])
        ->and($byPlatform['platforms']['ios'])->toBe(['n' => 5, 'p50' => 1000, 'p75' => 1000, 'p95' => 1000]);

    // Another measure on request: the first sound only iOS has.
    $audio = app(ClientTimingReport::class)->byPlatform($now->subDays(7), $now, 'first_audio');
    expect($audio['platforms']['ios']['p75'])->toBe(4000)
        ->and($audio['platforms']['android'])->toBe(['n' => 0, 'p50' => null, 'p75' => null, 'p95' => null]);
});
