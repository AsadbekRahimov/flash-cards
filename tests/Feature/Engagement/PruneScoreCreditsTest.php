<?php

declare(strict_types=1);

/*
 * `leagues:prune-score-credits` (docs/32 §2.7, task S16): the journal rows older than `leagues.scoring.credits_retention_days` go in
 * batches, the day totals stay; a day of a month that is not confirmed yet (and of the current month) is never touched, whatever the
 * retention says. Nightly, without overlapping.
 */

use App\Domain\Engagement\Services\Scoring\ScoreCreditPruner;
use App\Models\LeagueMonth;
use App\Models\LearnerScoreDay;
use App\Models\ScoreCredit;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // 2026-11-25 20:00 UTC = 2026-11-26 01:00 in Tashkent: the rating day is the league zone's.
    $this->travelTo(CarbonImmutable::parse('2026-11-25 20:00:00', 'UTC'));
    config(['leagues.timezone' => 'Asia/Tashkent', 'leagues.scoring.credits_retention_days' => 10]);
});

/** @param  list<string>  $days */
function pscCredits(array $days): void
{
    foreach ($days as $day) {
        ScoreCredit::factory()->create(['rating_day' => $day]);
    }
}

/** @return list<string> */
function pscDays(): array
{
    return DB::table('score_credits')->orderBy('rating_day')->pluck('rating_day')->map(fn (mixed $d): string => substr((string) $d, 0, 10))->all();
}

it('is scheduled nightly in the league zone, without overlapping', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'leagues:prune-score-credits'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('40 4 * * *')
        ->and($event->timezone)->toBe('Asia/Tashkent')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('keeps the current month even when the retention is shorter', function (): void {
    // today 2026-11-26, retention 10 days -> 2026-11-16, but the open month 2026-11 is kept from its 1st.
    pscCredits(['2026-10-30', '2026-10-31', '2026-11-01', '2026-11-15', '2026-11-26']);

    $this->artisan('leagues:prune-score-credits')
        ->expectsOutputToContain('Score credits before 2026-11-01 deleted: 2')
        ->assertSuccessful();

    expect(pscDays())->toBe(['2026-11-01', '2026-11-15', '2026-11-26']);
});

it('deletes the days past the retention in batches and keeps the day totals', function (): void {
    config(['leagues.scoring.credits_retention_days' => 180]);
    // 2026-11-26 - 180 days = 2026-05-30
    pscCredits(['2026-05-01', '2026-05-02', '2026-05-20', '2026-05-29', '2026-05-30', '2026-06-15']);
    $day = LearnerScoreDay::factory()->create(['rating_day' => '2026-05-01']);

    $this->artisan('leagues:prune-score-credits', ['--batch' => 2])
        ->expectsOutputToContain('Score credits before 2026-05-30 deleted: 4')
        ->assertSuccessful();

    expect(pscDays())->toBe(['2026-05-30', '2026-06-15'])
        ->and(LearnerScoreDay::query()->whereKey($day->id)->exists())->toBeTrue();

    // idempotent: nothing more to delete
    $this->artisan('leagues:prune-score-credits')->expectsOutputToContain('deleted: 0')->assertSuccessful();
});

it('never passes the first day of a month that is not confirmed yet', function (string $status): void {
    // October still waits: its days stay although they are past the retention of 10 days.
    LeagueMonth::factory()->startingOn('2026-09-01')->confirmed()->create();
    LeagueMonth::factory()->startingOn('2026-10-01')->create(['status' => $status]);
    pscCredits(['2026-09-29', '2026-09-30', '2026-10-01', '2026-10-20']);

    expect(app(ScoreCreditPruner::class)->prune(CarbonImmutable::now()))->toBe(2)
        ->and(pscDays())->toBe(['2026-10-01', '2026-10-20']);
})->with(['open', 'closing', 'pending_review']);

it('prunes a confirmed month like any other', function (): void {
    LeagueMonth::factory()->startingOn('2026-10-01')->confirmed()->create();
    LeagueMonth::factory()->startingOn('2026-11-01')->create();
    pscCredits(['2026-10-01', '2026-10-31', '2026-11-01']);

    expect(app(ScoreCreditPruner::class)->cutoff(CarbonImmutable::now())->toDateString())->toBe('2026-11-01')
        ->and(app(ScoreCreditPruner::class)->prune(CarbonImmutable::now()))->toBe(2)
        ->and(pscDays())->toBe(['2026-11-01']);
});

it('rejects a bad batch', function (): void {
    $this->artisan('leagues:prune-score-credits', ['--batch' => 0])->assertExitCode(2);
});
