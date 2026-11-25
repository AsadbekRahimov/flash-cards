<?php

declare(strict_types=1);

/*
 * The monthly rating with prizes (docs/13 §7.6): month boundaries in the league time zone, the points of a month = the
 * weekly formula over the local dates of the month under the daily cap, who takes part, the materialised scores (full,
 * incremental, one learner), the top list and the place, the eligibility for a prize, the close into `pending_review`
 * (idempotent, resumable) with the candidates and their signals, and the commands / schedule.
 */

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Engagement\DTO\MonthlyStanding;
use App\Domain\Engagement\Enums\LeagueMonthStatus;
use App\Domain\Engagement\Services\LeagueService;
use App\Domain\Engagement\Services\MonthlyRatingService;
use App\Domain\Engagement\Support\LeaguePoints;
use App\Domain\Learners\Services\DailyStatsService;
use App\Domain\Learners\Services\LearnerDeletionService;
use App\Domain\Learners\Services\LearnerModerationService;
use App\Domain\Learners\Services\LearnerRegistrar;
use App\Domain\Learners\Services\LearnerSettingsService;
use App\Domain\Operations\Support\PureCache;
use App\Models\LeagueGroup;
use App\Models\LeagueMember;
use App\Models\LeagueMonth;
use App\Models\LeagueMonthCandidate;
use App\Models\LeagueMonthScore;
use App\Models\LeagueWeek;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\ProductEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

uses(RefreshDatabase::class);

/** Monday 2026-11-16 10:00 in Tashkent (05:00 UTC); the month is 2026-11-01 … 2026-11-30. */
function mrNow(string $time = '2026-11-16 05:00:00'): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'UTC');
}

function mrService(): MonthlyRatingService
{
    return app(MonthlyRatingService::class);
}

/** An onboarded learner registered long ago (eligible by the account's age unless said otherwise). */
function mrLearner(array $attributes = []): Learner
{
    return Learner::factory()->onboarded()->create(['created_at' => '2026-09-01 00:00:00', 'updated_at' => '2026-09-01 00:00:00', ...$attributes]);
}

/** A day row of a learner (local date). */
function mrDay(Learner $learner, string $date, int $reviews = 0, int $correct = 0, int $new = 0, bool $goalMet = false, int $game = 0, int $timeMs = 0): LearnerDailyStats
{
    return LearnerDailyStats::factory()->create([
        'learner_id' => $learner->id,
        'date' => $date,
        'reviews_count' => $reviews,
        'correct_count' => $correct,
        'new_words_count' => $new,
        'goal_met_at' => $goalMet ? mrNow() : null,
        'game_points' => $game,
        'time_spent_ms' => $timeMs,
    ]);
}

function mrScore(LeagueMonth $month, Learner $learner): ?LeagueMonthScore
{
    return LeagueMonthScore::query()->where('league_month_id', $month->id)->where('learner_id', $learner->id)->first();
}

/** @return list<string> `rank:points:learner_id` of the top list */
function mrTop(LeagueMonth $month): array
{
    return array_map(fn (MonthlyStanding $s): string => "{$s->rank}:{$s->points}:{$s->learnerId}", mrService()->top($month));
}

beforeEach(function (): void {
    config([
        'leagues.timezone' => 'Asia/Tashkent',
        'leagues.monthly.enabled' => true,
        'leagues.monthly.daily_cap' => 300,
        'leagues.monthly.top' => 5,
        'leagues.monthly.places' => 3,
        'leagues.monthly.prizes' => [
            1 => ['ru' => 'Telegram Premium на 3 месяца', 'uz' => 'Telegram Premium 3 oyga'],
            2 => ['ru' => 'Telegram Premium на 1 месяц', 'uz' => 'Telegram Premium 1 oyga'],
            3 => ['ru' => 'Telegram Premium на 1 месяц', 'uz' => 'Telegram Premium 1 oyga'],
        ],
        'leagues.monthly.candidates' => 4,
        'leagues.monthly.min_active_days' => 3,
        'leagues.monthly.min_account_days' => 7,
        'leagues.monthly.close_delay_minutes' => 180,
        'leagues.monthly.refresh_recent_days' => 3,
    ]);
    $this->travelTo(mrNow());
});

// --- calendar ----------------------------------------------------------------------------

it('keys the month by the 1st in the league time zone, one row per month, and knows when it ends and closes', function (): void {
    // 2026-10-31 23:30 Tashkent = 18:30 UTC → still October; 2026-11-01 00:30 Tashkent = 19:30 UTC → November.
    $october = mrService()->currentMonth(mrNow('2026-10-31 18:30:00'));
    $november = mrService()->currentMonth(mrNow('2026-10-31 19:30:00'));
    $again = mrService()->currentMonth(mrNow('2026-11-30 18:59:59'));

    expect($october->starts_on->toDateString())->toBe('2026-10-01')
        ->and($october->ends_on->toDateString())->toBe('2026-10-31')
        ->and($november->starts_on->toDateString())->toBe('2026-11-01')
        ->and($november->ends_on->toDateString())->toBe('2026-11-30')
        ->and($november->status)->toBe('open')
        ->and($again->id)->toBe($november->id)
        ->and(LeagueMonth::query()->count())->toBe(2)
        ->and(mrService()->findMonth(mrNow('2026-12-01 10:00:00')))->toBeNull()
        ->and(mrService()->previousMonth(mrNow())?->id)->toBe($october->id)
        // October ended at 2026-11-01 00:00 Tashkent (10-31 19:00 UTC); its close is due three hours later.
        ->and(mrService()->endsAt($october)->toIso8601String())->toBe('2026-10-31T19:00:00+00:00')
        ->and(mrService()->hasEnded($october, mrNow('2026-10-31 18:59:59')))->toBeFalse()
        ->and(mrService()->hasEnded($october, mrNow('2026-10-31 19:00:00')))->toBeTrue()
        ->and(mrService()->isDue($october, mrNow('2026-10-31 21:59:59')))->toBeFalse()
        ->and(mrService()->isDue($october, mrNow('2026-10-31 22:00:00')))->toBeTrue()
        // Days left include today: 15 on the 16th, 1 on the 30th, 0 once December began.
        ->and(mrService()->daysLeft($november, mrNow()))->toBe(15)
        ->and(mrService()->daysLeft($november, mrNow('2026-11-30 18:00:00')))->toBe(1)
        ->and(mrService()->daysLeft($november, mrNow('2026-11-30 19:00:00')))->toBe(0);
});

// --- points --------------------------------------------------------------------------------

it('sums the weekly formula over the local dates of the month only — the same points the league week counts', function (): void {
    $learner = mrLearner();
    // Rows are attributed to the month by their local date, exactly as to a league week: 10-31 and 12-01 are out, 11-01 and 11-30 in.
    mrDay($learner, '2026-10-31', 100, 100, 10);
    mrDay($learner, '2026-11-01', 10, 8, 2, true, 7);   // 10 + 8 + 4 + 10 + 7 = 39
    mrDay($learner, '2026-11-16', 20, 15, 0, false, 0); // 35
    mrDay($learner, '2026-11-30', 5, 5, 1);             // 12
    mrDay($learner, '2026-12-01', 100, 100, 10);

    $month = mrService()->currentMonth(mrNow());
    expect(mrService()->refresh($month, mrNow(), full: true))->toBeTrue();

    $score = mrScore($month, $learner);
    expect($score?->points)->toBe(86)
        ->and($score?->raw_points)->toBe(86)
        ->and($score?->active_days)->toBe(3)
        ->and($score?->reviews)->toBe(35)
        ->and($score?->correct)->toBe(28)
        ->and($score?->new_words)->toBe(3)
        ->and($score?->goal_days)->toBe(1)
        ->and($score?->game_points)->toBe(7)
        ->and($score?->max_day_points)->toBe(39)
        ->and($score?->capped_days)->toBe(0)
        ->and($score?->participant)->toBeTrue()
        ->and(LeaguePoints::ofCounts(10, 8, 2, true, 7))->toBe(39);

    // The week that contains 11-16 counts the same row with the same formula.
    $week = LeagueWeek::factory()->startingOn('2026-11-16')->create();
    $group = LeagueGroup::factory()->create(['league_week_id' => $week->id]);
    LeagueMember::factory()->create(['league_group_id' => $group->id, 'learner_id' => $learner->id]);
    expect(app(LeagueService::class)->standings($group, mrNow())->first()?->points)->toBe(35);
});

it('caps what one day adds to the month, counts the capped days, and runs uncapped with a cap of 0', function (): void {
    $learner = mrLearner();
    mrDay($learner, '2026-11-02', 400, 390, 50); // 400 + 390 + 100 = 890 raw
    mrDay($learner, '2026-11-03', 10, 10, 0);    // 20

    $month = mrService()->currentMonth(mrNow());
    mrService()->refresh($month, mrNow(), full: true);
    $score = mrScore($month, $learner);
    expect($score?->points)->toBe(320)
        ->and($score?->raw_points)->toBe(910)
        ->and($score?->capped_days)->toBe(1)
        ->and($score?->max_day_points)->toBe(890);

    config(['leagues.monthly.daily_cap' => 0]);
    mrService()->refresh($month, mrNow(), full: true);
    $score = mrScore($month, $learner);
    expect($score?->points)->toBe(910)->and($score?->capped_days)->toBe(0);
});

// --- participation -----------------------------------------------------------------------

it('keeps out the deleted, the blocked and the opted out, counts the not onboarded — and lets an opt-out back in at the next refresh', function (): void {
    $in = mrLearner(['first_name' => 'In']);
    $deleted = mrLearner(['first_name' => 'Gone']);
    $blocked = mrLearner(['first_name' => 'Blocked', 'blocked_at' => mrNow()]);
    // Never answered the bot's questions, studies in the Mini App: a participant like in the weekly league (docs/34 §3).
    $fresh = Learner::factory()->create(['first_name' => 'Fresh', 'onboarding_state' => 'timezone']);
    $optedOut = mrLearner(['first_name' => 'Out', 'meta' => [LeagueService::OPT_OUT_META => true]]);
    $optedIn = mrLearner(['first_name' => 'Back', 'meta' => [LeagueService::OPT_OUT_META => false]]);
    foreach ([$in, $deleted, $blocked, $fresh, $optedOut, $optedIn] as $i => $learner) {
        mrDay($learner, '2026-11-10', 100 - $i * 5, 50);
    }
    $deleted->delete();

    $month = mrService()->currentMonth(mrNow());
    mrService()->refresh($month, mrNow(), full: true);

    expect(LeagueMonthScore::query()->where('league_month_id', $month->id)->count())->toBe(6)
        ->and(mrScore($month, $in)?->participant)->toBeTrue()
        ->and(mrScore($month, $deleted)?->participant)->toBeFalse()
        ->and(mrScore($month, $blocked)?->participant)->toBeFalse()
        ->and(mrScore($month, $fresh)?->participant)->toBeTrue()
        ->and(mrScore($month, $optedOut)?->participant)->toBeFalse()
        ->and(mrScore($month, $optedIn)?->participant)->toBeTrue()
        ->and(mrTop($month))->toBe(["1:150:{$in->id}", "2:135:{$fresh->id}", "3:125:{$optedIn->id}"])
        ->and($month->fresh()?->participants_count)->toBe(3)
        ->and(mrService()->myLine($month, $optedOut)?->rank)->toBeNull()
        ->and(mrService()->myLine($month, $optedOut)?->participant)->toBeFalse()
        ->and(mrService()->myLine($month, $in)?->rank)->toBe(1)
        ->and(mrService()->myLine($month, $fresh)?->rank)->toBe(2)
        // Why "no place": the opted out and the blocked are told apart; a participant has no reason.
        ->and(mrService()->myLine($month, $optedOut)?->notCountedReason)->toBe('opted_out')
        ->and(mrService()->myLine($month, $blocked)?->notCountedReason)->toBe('blocked')
        ->and(mrService()->myLine($month, $in)?->notCountedReason)->toBeNull()
        ->and(mrService()->myLine($month, $fresh)?->notCountedReason)->toBeNull();

    // Opting back in (and a block lifted) takes effect at the next refresh of the row.
    $optedOut->forceFill(['meta' => [LeagueService::OPT_OUT_META => false]])->save();
    $blocked->forceFill(['blocked_at' => null])->save();
    mrService()->refreshLearner($month, $optedOut, mrNow());
    expect(mrScore($month, $optedOut)?->participant)->toBeTrue()
        ->and(mrScore($month, $blocked)?->participant)->toBeFalse(); // not refreshed yet
    mrService()->refresh($month, mrNow(), full: true);
    expect(mrScore($month, $blocked)?->participant)->toBeTrue();
});

it('names the block before the opt-out when both keep a learner out', function (): void {
    $both = mrLearner(['blocked_at' => mrNow(), 'meta' => [LeagueService::OPT_OUT_META => true]]);
    mrDay($both, '2026-11-10', 10, 10);
    $month = mrService()->currentMonth(mrNow());
    mrService()->refresh($month, mrNow(), full: true);

    expect(mrService()->myLine($month, $both)?->participant)->toBeFalse()
        ->and(mrService()->myLine($month, $both)?->notCountedReason)->toBe('blocked');
});

it('puts a learner who never finished the onboarding in the bot at the same place in the week and in the month, and makes them a prize candidate (docs/34 §3)', function (): void {
    // Umar studies only in the Mini App: the bot's questions are unanswered. Another learner is onboarded and scores less.
    $umar = Learner::factory()->create(['first_name' => 'Umar', 'onboarding_state' => 'level', 'created_at' => '2026-09-01 00:00:00']);
    $other = mrLearner(['first_name' => 'Other']);
    foreach (['2026-11-03', '2026-11-04', '2026-11-16'] as $date) {
        mrDay($umar, $date, 100, 90);
        mrDay($other, $date, 30, 20);
    }

    $leagues = app(LeagueService::class);
    $umarMember = $leagues->place($umar, mrNow());
    $otherMember = $leagues->place($other, mrNow());
    expect($umarMember)->not->toBeNull()
        ->and($otherMember?->league_group_id)->toBe($umarMember?->league_group_id);
    $group = LeagueGroup::query()->findOrFail($umarMember?->league_group_id);
    $week = $leagues->standings($group, mrNow());
    expect($week->first()?->learnerId)->toBe($umar->id)
        ->and($week->first()?->rank)->toBe(1);

    $month = mrService()->currentMonth(mrNow());
    mrService()->refresh($month, mrNow(), full: true);
    expect(mrScore($month, $umar)?->participant)->toBeTrue()
        ->and(mrTop($month)[0] ?? null)->toBe('1:'.mrScore($month, $umar)?->points.":{$umar->id}")
        ->and(mrService()->myLine($month, $umar)?->rank)->toBe(1)
        ->and(mrService()->myLine($month, $umar)?->notCountedReason)->toBeNull();

    // Three active days and an old account meet the thresholds of the beforeEach: the close takes Umar as the first candidate.
    expect(mrService()->closeMonth($month, mrNow('2026-11-30 22:00:00')))->toBeTrue()
        ->and(mrScore($month, $umar)?->final_rank)->toBe(1);
    $candidates = LeagueMonthCandidate::query()->where('league_month_id', $month->id)->orderBy('rank')->pluck('learner_id')->all();
    expect($candidates)->toBe([$umar->id, $other->id]);
});

// --- eligibility -------------------------------------------------------------------------

it('tells whether a learner may win a prize and what is missing: active days and the age of the account at the month end', function (): void {
    $month = mrService()->currentMonth(mrNow());
    $steady = mrLearner();
    $newcomer = mrLearner(['created_at' => '2026-11-25 10:00:00']); // 5 local days before 11-30
    $sporadic = mrLearner();
    foreach (['2026-11-03', '2026-11-04', '2026-11-05'] as $date) {
        mrDay($steady, $date, 10, 10);
        mrDay($newcomer, $date, 10, 10);
    }
    mrDay($sporadic, '2026-11-03', 10, 10);
    mrDay($sporadic, '2026-11-04', 0, 0); // a row without points is not an active day
    mrService()->refresh($month, mrNow(), full: true);

    $me = mrService()->myLine($month, $steady);
    expect($me?->eligible)->toBeTrue()->and($me?->missingActiveDays)->toBe(0)->and($me?->missingAccountDays)->toBe(0);

    $me = mrService()->myLine($month, $newcomer);
    expect($me?->eligible)->toBeFalse()->and($me?->missingActiveDays)->toBe(0)->and($me?->missingAccountDays)->toBe(2)
        ->and(mrService()->accountAgeDays($month, $newcomer))->toBe(5);

    $me = mrService()->myLine($month, $sporadic);
    expect($me?->eligible)->toBeFalse()->and($me?->activeDays)->toBe(1)->and($me?->missingActiveDays)->toBe(2)->and($me?->missingAccountDays)->toBe(0);

    // The account's age is counted in local days of the league zone: registered 2026-11-23 23:30 Tashkent (18:30 UTC) = 7 days before 11-30.
    $edge = mrLearner(['created_at' => '2026-11-23 18:30:00']);
    expect(mrService()->accountAgeDays($month, $edge))->toBe(7);
    // … while 2026-11-24 00:30 Tashkent (11-23 19:30 UTC) is 6.
    $edge->forceFill(['created_at' => '2026-11-23 19:30:00'])->save();
    expect(mrService()->accountAgeDays($month, $edge->fresh() ?? $edge))->toBe(6)
        ->and(mrService()->myLine($month, $steady))->not->toBeNull()
        ->and(mrService()->myLine($month, mrLearner()))->toBeNull(); // no row: nothing to show
});

// --- refresh -----------------------------------------------------------------------------

it('refreshes only the recently active learners by default, everybody with `full`, and nothing of a month that is not open', function (): void {
    $month = mrService()->currentMonth(mrNow());
    $early = mrLearner();   // active on 11-02 only: a row that no longer changes
    $recent = mrLearner();  // active today
    mrDay($early, '2026-11-02', 10, 10);
    mrDay($recent, '2026-11-16', 10, 10);

    expect(mrService()->refresh($month, mrNow()))->toBeTrue()
        ->and(mrScore($month, $early))->toBeNull()
        ->and(mrScore($month, $recent)?->points)->toBe(20)
        ->and($month->fresh()?->refreshed_at?->toIso8601String())->toBe(mrNow()->toIso8601String())
        ->and($month->fresh()?->participants_count)->toBe(1);

    expect(mrService()->refresh($month, mrNow(), full: true))->toBeTrue()
        ->and(mrScore($month, $early)?->points)->toBe(20)
        ->and($month->fresh()?->participants_count)->toBe(2);

    // A change of an old row (hand-made) is picked up by the full run only; a change of a recent row by the incremental one.
    LearnerDailyStats::query()->where('learner_id', $early->id)->update(['reviews_count' => 30]);
    LearnerDailyStats::query()->where('learner_id', $recent->id)->update(['reviews_count' => 30]);
    mrService()->refresh($month, mrNow());
    expect(mrScore($month, $early)?->points)->toBe(20)->and(mrScore($month, $recent)?->points)->toBe(40);
    mrService()->refresh($month, mrNow(), full: true);
    expect(mrScore($month, $early)?->points)->toBe(40);

    // Not open: nothing is written, not even one learner's row.
    $month->forceFill(['status' => LeagueMonthStatus::PendingReview->value])->save();
    LearnerDailyStats::query()->where('learner_id', $recent->id)->update(['reviews_count' => 100]);
    expect(mrService()->refresh($month, mrNow(), full: true))->toBeFalse()
        ->and(mrService()->refreshLearner($month, $recent, mrNow()))->toBeFalse()
        ->and(mrScore($month, $recent)?->points)->toBe(40);

    // Off: nothing at all.
    $month->forceFill(['status' => LeagueMonthStatus::Open->value])->save();
    config(['leagues.monthly.enabled' => false]);
    expect(mrService()->refresh($month, mrNow(), full: true))->toBeFalse()
        ->and(mrService()->refreshDue(mrNow()))->toBe(0);
});

it('refreshes the current month and the one that just ended while it waits for its close, through the command', function (): void {
    $learner = mrLearner();
    mrDay($learner, '2026-11-30', 10, 10);
    mrDay($learner, '2026-12-01', 5, 5);
    $november = mrService()->currentMonth(mrNow());

    // 2026-12-01 01:00 Tashkent: November ended an hour ago and is not due yet; December is created by the run.
    $this->travelTo(mrNow('2026-11-30 20:00:00'));
    $this->artisan('leagues:refresh-month')->expectsOutput('League months refreshed: 2')->assertSuccessful();

    $december = mrService()->findMonthStartingOn('2026-12-01');
    expect($december)->not->toBeNull()
        ->and(mrScore($november, $learner)?->points)->toBe(20)
        ->and($december === null ? null : mrScore($december, $learner)?->points)->toBe(10);

    $november->forceFill(['status' => LeagueMonthStatus::PendingReview->value])->save();
    $this->artisan('leagues:refresh-month', ['--full' => true])->expectsOutput('League months refreshed: 1')->assertSuccessful();
});

// --- top and place -------------------------------------------------------------------------

it('orders the rating by points, then active days, then the older account; the top is limited and the place counts those ahead', function (): void {
    $month = mrService()->currentMonth(mrNow());
    $a = mrLearner(); // 100 points over 2 days
    $b = mrLearner(); // 100 points over 1 day → behind a
    $c = mrLearner(); // 100 points over 2 days, newer id → behind a
    $d = mrLearner(); // 50
    mrDay($a, '2026-11-02', 25, 25);
    mrDay($a, '2026-11-03', 25, 25);
    mrDay($b, '2026-11-02', 50, 50);
    mrDay($c, '2026-11-02', 25, 25);
    mrDay($c, '2026-11-03', 25, 25);
    mrDay($d, '2026-11-02', 25, 25);
    $rest = [];
    foreach (range(1, 3) as $i) {
        $rest[$i] = mrLearner();
        mrDay($rest[$i], '2026-11-02', 10, $i);
    }
    mrService()->refresh($month, mrNow(), full: true);

    expect(mrTop($month))->toBe(["1:100:{$a->id}", "2:100:{$c->id}", "3:100:{$b->id}", "4:50:{$d->id}", "5:13:{$rest[3]->id}"])
        ->and(mrService()->myLine($month, $a)?->rank)->toBe(1)
        ->and(mrService()->myLine($month, $c)?->rank)->toBe(2)
        ->and(mrService()->myLine($month, $b)?->rank)->toBe(3)
        ->and(mrService()->myLine($month, $d)?->rank)->toBe(4)
        ->and(mrService()->myLine($month, $rest[1])?->rank)->toBe(7); // beyond the top of 5, still placed
});

it('caches the top list as arrays until a refresh or the close drops it, and reads numbers back as integers', function (): void {
    $month = mrService()->currentMonth(mrNow());
    $learner = mrLearner(['first_name' => 'Aziz']);
    mrDay($learner, '2026-11-02', 10, 10);
    mrService()->refresh($month, mrNow(), full: true);
    expect(mrTop($month))->toBe(["1:20:{$learner->id}"]);

    // A row changed in the table but no refresh: the cached list is served; a refresh drops the key.
    LeagueMonthScore::query()->where('learner_id', $learner->id)->update(['points' => 99]);
    expect(mrTop($month))->toBe(["1:20:{$learner->id}"]);
    mrService()->refresh($month, mrNow(), full: true);
    expect(mrTop($month))->toBe(["1:20:{$learner->id}"]); // recomputed from the day rows: 20 again

    // Redis hands numbers of arrays back typed, but a store may stringify: the DTO casts.
    PureCache::store()->put(MonthlyRatingService::topKey($month->id), [['learner_id' => (string) $learner->id, 'name' => 'Aziz', 'points' => '20', 'active_days' => '1', 'rank' => '1']], 60);
    $row = mrService()->top($month)[0];
    expect($row->points)->toBe(20)->and($row->rank)->toBe(1)->and($row->learnerId)->toBe($learner->id)->and($row->activeDays)->toBe(1);
});

// --- close -----------------------------------------------------------------------------------

it('closes a due month once into pending_review: final ranks, the best eligible as candidates with signals, the event; a repeat does nothing', function (): void {
    config(['leagues.monthly.candidates' => 3, 'leagues.monthly.min_active_days' => 2]);
    $month = mrService()->currentMonth(mrNow());
    $top = mrLearner(['first_name' => 'Top']);
    $second = mrLearner(['first_name' => 'Second']);
    $young = mrLearner(['first_name' => 'Young', 'created_at' => '2026-11-28 00:00:00']); // 2 days old at the end: not eligible
    $sporadic = mrLearner(['first_name' => 'Once']); // one active day: not eligible
    $third = mrLearner(['first_name' => 'Third']);
    $fourth = mrLearner(['first_name' => 'Fourth']);
    $out = mrLearner(['first_name' => 'Out', 'meta' => [LeagueService::OPT_OUT_META => true]]);
    foreach (['2026-11-03', '2026-11-04'] as $date) {
        mrDay($top, $date, 100, 90, 5, true, 0, 100 * 3000);
        mrDay($second, $date, 80, 70, 0, false, 0, 80 * 800);
        mrDay($young, $date, 90, 80);
        mrDay($third, $date, 30, 20);
        mrDay($fourth, $date, 20, 10);
        mrDay($out, $date, 500, 500);
    }
    mrDay($sporadic, '2026-11-03', 200, 200);

    // Not due yet (2026-12-01 02:59 Tashkent): refused, nothing changes.
    expect(mrService()->closeMonth($month, mrNow('2026-11-30 21:59:59')))->toBeFalse()
        ->and($month->fresh()?->status)->toBe('open');

    $closeAt = mrNow('2026-11-30 22:00:00');
    expect(mrService()->closeMonth($month, $closeAt))->toBeTrue();

    $month->refresh();
    expect($month->status)->toBe('pending_review')
        ->and($month->closed_at?->toIso8601String())->toBe($closeAt->toIso8601String())
        ->and($month->participants_count)->toBe(6)
        // Final ranks of every participant (420, 340, then 300 over two days before 300 over one day — the cap — and so on);
        // the opted-out row has none.
        ->and(mrScore($month, $top)?->final_rank)->toBe(1)
        ->and(mrScore($month, $young)?->final_rank)->toBe(2)
        ->and(mrScore($month, $second)?->final_rank)->toBe(3)
        ->and(mrScore($month, $sporadic)?->final_rank)->toBe(4)
        ->and(mrScore($month, $sporadic)?->points)->toBe(300)
        ->and(mrScore($month, $third)?->final_rank)->toBe(5)
        ->and(mrScore($month, $fourth)?->final_rank)->toBe(6)
        ->and(mrScore($month, $out)?->final_rank)->toBeNull()
        ->and(mrService()->myLine($month, $second)?->rank)->toBe(3);

    // Candidates: the three best ELIGIBLE participants (the young account and the one-day learner are skipped).
    $candidates = LeagueMonthCandidate::query()->where('league_month_id', $month->id)->orderBy('rank')->get();
    expect($candidates->pluck('learner_id')->all())->toBe([$top->id, $second->id, $third->id])
        ->and($candidates->pluck('rank')->all())->toBe([1, 2, 3])
        ->and($candidates->pluck('status')->unique()->all())->toBe(['candidate'])
        ->and($candidates[0]?->points)->toBe(420)
        ->and($candidates[0]?->signals['overall_rank'])->toBe(1)
        ->and($candidates[0]?->signals['accuracy'])->toBe(0.9)
        ->and($candidates[0]?->signals['avg_ms_per_review'])->toBe(3000)
        ->and($candidates[0]?->signals['account_age_days'])->toBe(90)
        ->and($candidates[0]?->signals['flags'])->toBe([])
        ->and($candidates[1]?->signals['overall_rank'])->toBe(3)
        ->and($candidates[1]?->signals['flags'])->toBe(['too_fast'])
        ->and(ProductEvent::query()->where('name', ProductEventName::LeagueMonthClosed->value)->count())->toBe(1);

    // Idempotent: a second close (the safety run) changes nothing and sends no second event.
    LeagueMonthCandidate::query()->whereKey($candidates[0]?->id)->update(['status' => 'disqualified']);
    expect(mrService()->closeMonth($month, $closeAt->addMinutes(5)))->toBeFalse()
        ->and(mrService()->closeDue($closeAt->addMinutes(5)))->toBe(0)
        ->and(LeagueMonthCandidate::query()->where('league_month_id', $month->id)->where('status', 'disqualified')->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', ProductEventName::LeagueMonthClosed->value)->count())->toBe(1);
});

it('resumes a close left `closing` by a crash: the candidates of the interrupted run are replaced, the ranks rewritten', function (): void {
    config(['leagues.monthly.min_active_days' => 1, 'leagues.monthly.min_account_days' => 0]);
    $month = mrService()->currentMonth(mrNow());
    $a = mrLearner();
    $b = mrLearner();
    mrDay($a, '2026-11-03', 10, 10);
    mrDay($b, '2026-11-03', 30, 30);
    // The interrupted run: the barrier passed, a stale candidate row and a stale rank were left behind.
    $month->forceFill(['status' => LeagueMonthStatus::Closing->value])->save();
    LeagueMonthScore::factory()->create(['league_month_id' => $month->id, 'learner_id' => $a->id, 'points' => 999, 'final_rank' => 1]);
    LeagueMonthCandidate::factory()->create(['league_month_id' => $month->id, 'learner_id' => $a->id, 'rank' => 1, 'points' => 999]);

    $this->artisan('leagues:close-month')->expectsOutput('League months closed: 0')->assertSuccessful(); // not due yet
    $this->travelTo(mrNow('2026-11-30 22:00:00'));
    $this->artisan('leagues:close-month')->expectsOutput('League months closed: 1')->assertSuccessful();

    $candidates = LeagueMonthCandidate::query()->where('league_month_id', $month->id)->orderBy('rank')->get();
    expect($month->fresh()?->status)->toBe('pending_review')
        ->and($candidates->pluck('learner_id')->all())->toBe([$b->id, $a->id])
        ->and($candidates->pluck('points')->all())->toBe([60, 20])
        ->and(mrScore($month, $a)?->points)->toBe(20)
        ->and(mrScore($month, $a)?->final_rank)->toBe(2)
        ->and(mrScore($month, $b)?->final_rank)->toBe(1);

    $this->artisan('leagues:close-month')->expectsOutput('League months closed: 0')->assertSuccessful();
});

it('closes a month a refresh never touched (nobody studied) with no candidates', function (): void {
    $month = mrService()->currentMonth(mrNow());
    expect(mrService()->closeMonth($month, mrNow('2026-11-30 22:00:00')))->toBeTrue()
        ->and($month->fresh()?->status)->toBe('pending_review')
        ->and($month->fresh()?->participants_count)->toBe(0)
        ->and(LeagueMonthCandidate::query()->count())->toBe(0)
        ->and(mrService()->top($month))->toBe([]);
});

it('is scheduled: the refresh every ten minutes, the close at the delay after the 1st 00:00 of the league zone with a safety run', function (): void {
    $events = collect(app(Schedule::class)->events());
    $refresh = $events->first(fn ($e) => str_contains((string) $e->command, 'leagues:refresh-month'));
    $close = $events->first(fn ($e) => str_contains((string) $e->command, 'leagues:close-month'));

    expect($refresh)->not->toBeNull()
        ->and($refresh?->expression)->toBe('*/10 * * * *')
        ->and($close)->not->toBeNull()
        ->and($close?->expression)->toBe('0,5 3 * * *')
        ->and($close?->timezone)->toBe('Asia/Tashkent')
        ->and($events->filter(fn ($e) => str_contains((string) $e->command, 'leagues:close-month'))->count())->toBe(1);
});

// --- signals and the breakdown ------------------------------------------------------------

it('flags what looks odd next to a candidate without deciding anything', function (): void {
    config(['leagues.monthly.signals' => ['min_accuracy' => 0.55, 'max_accuracy' => 0.98, 'min_ms_per_review' => 1500, 'young_account_days' => 30, 'spike_ratio' => 3.0, 'capped_days' => 2]]);
    $month = mrService()->currentMonth(mrNow());
    $learner = mrLearner(['created_at' => '2026-11-10 00:00:00']); // 20 days old at the end
    foreach (['2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'] as $date) {
        mrDay($learner, $date, 10, 10, 0, false, 0, 10 * 1000); // 20 a day, every answer "correct", a second each
    }
    mrDay($learner, '2026-11-07', 400, 400, 0, false, 0, 400 * 1000); // 800: a spike (3.75 × the mean day of 213), capped
    mrDay($learner, '2026-11-08', 200, 200, 0, false, 0, 200 * 1000); // 400: capped again
    mrService()->refresh($month, mrNow(), full: true);

    $score = mrScore($month, $learner);
    expect($score)->not->toBeNull();
    $signals = mrService()->signals($month, $score ?? new LeagueMonthScore, $learner);
    expect($signals['accuracy'])->toBe(1.0)
        ->and($signals['avg_ms_per_review'])->toBe(1000)
        ->and($signals['account_age_days'])->toBe(20)
        ->and($signals['capped_days'])->toBe(2)
        ->and($signals['max_day_points'])->toBe(800)
        ->and($signals['flags'])->toBe(['high_accuracy', 'too_fast', 'young_account', 'spiky', 'capped_often']);

    $days = mrService()->dailyBreakdown($month, $learner->id);
    expect($days)->toHaveCount(6)
        ->and($days[4])->toBe(['date' => '2026-11-07', 'reviews' => 400, 'correct' => 400, 'new_words' => 0, 'goal_met' => false, 'game_points' => 0, 'time_spent_ms' => 400000, 'points' => 800, 'counted' => 300]);
});

// --- reading -------------------------------------------------------------------------------

it('assembles the overview: a fresh own line, the top with names, the prizes of the config, the days left, the previous month', function (): void {
    $me = mrLearner(['first_name' => 'Me', 'username' => 'secret']);
    $rival = mrLearner(['first_name' => 'Rival']);
    mrDay($rival, '2026-11-02', 50, 50);
    mrDay($me, '2026-11-16', 10, 10);
    // A confirmed October with a winner, and my own final place there.
    $october = LeagueMonth::factory()->startingOn('2026-10-01')->confirmed()->create();
    LeagueMonthCandidate::factory()->winner(1)->create(['league_month_id' => $october->id, 'learner_id' => $rival->id, 'points' => 900]);
    LeagueMonthScore::factory()->create(['league_month_id' => $october->id, 'learner_id' => $me->id, 'points' => 120, 'final_rank' => 17]);

    $overview = mrService()->overview($me, mrNow());

    expect($overview->enabled)->toBeTrue()
        ->and($overview->startsOn)->toBe('2026-11-01')
        ->and($overview->endsOn)->toBe('2026-11-30')
        ->and($overview->closesAt)->toBe('2026-11-30T19:00:00+00:00')
        ->and($overview->daysLeft)->toBe(15)
        ->and($overview->rules)->toBe(['daily_cap' => 300, 'min_active_days' => 3, 'min_account_days' => 7, 'places' => 3, 'top' => 5])
        ->and(array_map(fn ($p) => [$p->place, $p->title('ru'), $p->title('uz')], $overview->prizes))->toBe([
            [1, 'Telegram Premium на 3 месяца', 'Telegram Premium 3 oyga'],
            [2, 'Telegram Premium на 1 месяц', 'Telegram Premium 1 oyga'],
            [3, 'Telegram Premium на 1 месяц', 'Telegram Premium 1 oyga'],
        ])
        // My own row was refreshed on the way (the rival's not: the scheduler does that), so the top has me only.
        ->and(array_map(fn (MonthlyStanding $s) => [$s->rank, $s->name, $s->points], $overview->top))->toBe([[1, 'Me', 20]])
        ->and($overview->me?->rank)->toBe(1)
        ->and($overview->me?->points)->toBe(20)
        ->and($overview->optedOut)->toBeFalse()
        ->and($overview->lastMonth?->status)->toBe('confirmed')
        ->and($overview->lastMonth?->winners)->toBe([['place' => 1, 'name' => 'Rival', 'points' => 900]])
        ->and($overview->lastMonth?->me)->toBe(['rank' => 17, 'points' => 120]);

    $meView = mrService()->meView($me, mrNow());
    expect($meView->daysLeft)->toBe(15)->and($meView->me?->rank)->toBe(1);

    // The prizes of the month itself win over the config; an empty list hides them; a deleted winner has no name.
    $november = mrService()->currentMonth(mrNow());
    $november->forceFill(['prizes' => [['place' => 2, 'ru' => 'Книга', 'uz' => '']]])->save();
    expect(array_map(fn ($p) => [$p->place, $p->title('ru'), $p->title('uz')], mrService()->prizes($november)))->toBe([[2, 'Книга', 'Книга']]);
    $november->forceFill(['prizes' => []])->save();
    expect(mrService()->prizes($november))->toBe([]);
    $rival->delete();
    expect(mrService()->lastMonth($me, mrNow())?->winners)->toBe([['place' => 1, 'name' => null, 'points' => 900]]);

    // A previous month still waiting for the review shows no winners and keeps my final place.
    $october->forceFill(['status' => LeagueMonthStatus::PendingReview->value])->save();
    expect(mrService()->lastMonth($me, mrNow())?->winners)->toBe([])
        ->and(mrService()->lastMonth($me, mrNow())?->me)->toBe(['rank' => 17, 'points' => 120]);

    config(['leagues.monthly.enabled' => false]);
    $off = mrService()->overview($me, mrNow());
    expect($off->enabled)->toBeFalse()->and($off->top)->toBe([])->and($off->me)->toBeNull()->and($off->lastMonth)->toBeNull();
});

it('never writes to the month tables from the review transaction: a grade touches learner_daily_stats only', function (): void {
    $learner = mrLearner();
    mrService()->currentMonth(mrNow());

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(DailyStatsService::class)->recordReview($learner, 4, 1000, true, mrNow());
    $queries = array_map(static fn (array $q): string => (string) $q['query'], DB::getQueryLog());
    DB::disableQueryLog();

    expect(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'league_month')))->toBe([]);
});

// --- default prizes ------------------------------------------------------------------------

it('promises no prize by default: the shipped config has empty titles, blank ones never become a prize, partial ones show only what is set', function (): void {
    $month = LeagueMonth::factory()->create();

    // What the repository ships (no LEAGUES_MONTHLY_PRIZE_* in the environment): every title is empty.
    $shipped = (array) (require config_path('leagues.php'))['monthly']['prizes'];
    foreach ($shipped as $titles) {
        expect(array_filter((array) $titles, static fn (mixed $t): bool => $t !== ''))->toBe([]);
    }
    config(['leagues.monthly.prizes' => $shipped]);
    expect(mrService()->prizes($month))->toBe([]);

    // Spaces only are no title.
    config(['leagues.monthly.prizes' => [1 => ['ru' => '  ', 'uz' => ''], 2 => ['ru' => "\t", 'uz' => ' ']]]);
    expect(mrService()->prizes($month))->toBe([]);

    // Only the places that are set.
    config(['leagues.monthly.prizes' => [1 => ['ru' => '', 'uz' => ''], 2 => ['ru' => ' Книга ', 'uz' => ''], 3 => ['ru' => '', 'uz' => '']]]);
    expect(array_map(fn ($p) => [$p->place, $p->title('ru'), $p->title('uz')], mrService()->prizes($month)))->toBe([[2, 'Книга', 'Книга']]);
});

// --- participation changes inside the open month ---------------------------------------------------

/** Three learners with a day row each: 300 / 200 / 100 points, the leader first. @return array{0: LeagueMonth, 1: list<Learner>} */
function mrThreeRanked(): array
{
    $month = mrService()->currentMonth(mrNow());
    $learners = [mrLearner(), mrLearner(), mrLearner()];
    foreach ([300, 200, 100] as $i => $reviews) {
        mrDay($learners[$i], '2026-11-10', reviews: $reviews, correct: $reviews, new: 0);
    }
    mrService()->refresh($month, mrNow(), full: true);

    return [$month, $learners];
}

it('takes a blocked leader out of the top at once and puts them back when unblocked', function (): void {
    [$month, $learners] = mrThreeRanked();
    $before = mrTop($month);
    expect($before)->toHaveCount(3)->and($before[0])->toEndWith(':'.$learners[0]->id);

    $admin = User::factory()->create();
    $moderation = app(LearnerModerationService::class);

    $moderation->block($learners[0], $admin);
    $top = mrTop($month);
    expect($top)->toHaveCount(2)
        ->and($top[0])->toEndWith(':'.$learners[1]->id)
        ->and($top[0])->toStartWith('1:')
        ->and($top[1])->toStartWith('2:')
        ->and(mrScore($month, $learners[0])?->participant)->toBeFalse();

    $moderation->unblock($learners[0], $admin);
    expect(mrTop($month))->toBe($before);
});

it('takes a learner who opted out of the leagues out of the top at once, and back on opting in', function (): void {
    [$month, $learners] = mrThreeRanked();
    $before = mrTop($month);
    $settings = app(LearnerSettingsService::class);

    $settings->update($learners[0], ['leagues_opt_out' => true]);
    $top = mrTop($month);
    expect($top)->toHaveCount(2)->and($top[0])->toEndWith(':'.$learners[1]->id);

    $settings->update($learners[0], ['leagues_opt_out' => false]);
    expect(mrTop($month))->toBe($before);
});

it('takes a deleted account out of the top at once, and back when it is restored', function (): void {
    [$month, $learners] = mrThreeRanked();
    $before = mrTop($month);

    app(LearnerDeletionService::class)->delete($learners[0]);
    $top = mrTop($month);
    expect($top)->toHaveCount(2)->and($top[0])->toEndWith(':'.$learners[1]->id);

    $this->travelTo(mrNow()->addMinute());
    app(LearnerRegistrar::class)->fromTelegramUser(['id' => $learners[0]->telegram_user_id, 'first_name' => 'Back']);
    expect(mrTop($month))->toHaveCount(3)->and(mrTop($month)[0])->toEndWith(':'.$learners[0]->id);
    expect($before)->toHaveCount(3);
});

it('does nothing for a learner with no row in the month, and never breaks the action when the recompute fails', function (): void {
    [$month] = mrThreeRanked();
    $outsider = mrLearner();
    $admin = User::factory()->create();
    $moderation = app(LearnerModerationService::class);

    expect($moderation->block($outsider, $admin))->toBeTrue()
        ->and(mrScore($month, $outsider))->toBeNull();

    Exceptions::fake();
    DB::statement('drop table league_month_scores');
    $leader = Learner::query()->orderBy('id')->firstOrFail();
    expect($moderation->block($leader, $admin))->toBeTrue()
        ->and($leader->fresh()?->blocked_at)->not->toBeNull();
    Exceptions::assertReported(QueryException::class);
});
