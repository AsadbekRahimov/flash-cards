<?php

declare(strict_types=1);

/*
 * StoredDate::of() — a local date the way a `date` column holds it. Runs on whatever driver the suite runs on (sqlite
 * by default, PostgreSQL with DB_CONNECTION=pgsql): on sqlite the column is TEXT, so a bound in any other form than
 * the one Eloquent writes ('2026-10-28 00:00:00') silently misses the boundary day; PostgreSQL reads either.
 */

use App\Domain\Learners\Support\StoredDate;
use App\Models\Challenge;
use App\Models\LeagueWeek;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('is the representation Eloquent writes for a date cast: Y-m-d 00:00:00', function (): void {
    expect(StoredDate::of('2026-10-28'))->toBe('2026-10-28 00:00:00')
        ->and(StoredDate::of('2026-01-01'))->toBe('2026-01-01 00:00:00')
        ->and(StoredDate::of('2028-02-29'))->toBe('2028-02-29 00:00:00')
        // already in the stored form: unchanged
        ->and(StoredDate::of('2026-10-28 00:00:00'))->toBe('2026-10-28 00:00:00');

    $model = new LearnerDailyStats;
    foreach (['2026-10-28', '2026-12-31'] as $date) {
        expect(StoredDate::of($date))->toBe((string) $model->fromDateTime($date))
            ->and(StoredDate::of($date))->toBe((string) (new LeagueWeek)->fromDateTime($date)); // the same on the other date columns
    }
});

it('matches a row written through the model, as an equality and as the bounds of a range, on this driver', function (): void {
    $learner = Learner::factory()->create();
    foreach (['2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30'] as $date) {
        LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => $date, 'reviews_count' => 3]);
    }
    $stats = fn () => DB::table('learner_daily_stats')->where('learner_id', $learner->id);

    expect($stats()->where('date', StoredDate::of('2026-10-28'))->count())->toBe(1)
        // both bounds are inclusive: the first and the last day of the range are in it
        ->and($stats()->where('date', '>=', StoredDate::of('2026-10-27'))->where('date', '<=', StoredDate::of('2026-10-29'))->count())->toBe(3)
        ->and($stats()->where('date', '>', StoredDate::of('2026-10-28'))->count())->toBe(2)
        ->and($stats()->where('date', '<', StoredDate::of('2026-10-28'))->count())->toBe(2)
        ->and($stats()->where('date', '<=', StoredDate::of('2026-10-26'))->count())->toBe(1);
});

it('is the form the unique (learner_id, date) sees: a second write of the same day is a duplicate', function (): void {
    $learner = Learner::factory()->create();
    LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => '2026-10-28']);

    $insert = fn (string $date): int => LearnerDailyStats::query()->insertOrIgnore([
        'learner_id' => $learner->id,
        'date' => $date,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Through the helper the day is recognised as taken on every driver.
    expect($insert(StoredDate::of('2026-10-28')))->toBe(0)
        ->and(LearnerDailyStats::query()->where('learner_id', $learner->id)->count())->toBe(1)
        ->and($insert(StoredDate::of('2026-10-29')))->toBe(1);
});

it('bounds the other date columns that are compared with it: league weeks and challenges', function (): void {
    $learner = Learner::factory()->create();
    LeagueWeek::factory()->startingOn('2026-10-12')->create();
    LeagueWeek::factory()->startingOn('2026-10-19')->create();

    expect(LeagueWeek::query()->where('starts_on', StoredDate::of('2026-10-19'))->count())->toBe(1)
        ->and(LeagueWeek::query()->where('starts_on', '<', StoredDate::of('2026-10-19'))->count())->toBe(1)
        ->and(LeagueWeek::query()->where('ends_on', '<=', StoredDate::of('2026-10-18'))->count())->toBe(1);

    $other = Learner::factory()->create();
    $challenge = Challenge::factory()->create([
        'challenger_id' => $learner->id,
        'opponent_id' => $other->id,
        'starts_on' => '2026-10-29',
        'ends_on' => '2026-11-04',
    ]);

    expect(Challenge::query()->where('starts_on', StoredDate::of('2026-10-29'))->whereKey($challenge->id)->exists())->toBeTrue()
        ->and(Challenge::query()->where('ends_on', '>=', StoredDate::of('2026-11-04'))->whereKey($challenge->id)->exists())->toBeTrue();
});
