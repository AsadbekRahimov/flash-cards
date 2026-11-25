<?php

declare(strict_types=1);

/*
 * The statement budget of the tables of the word points v2 (S8, S9, S11; the owner's requirement: the week and the month are read
 * often). Each read is a constant number of statements whatever the size of the table - the plans of those statements on 50 000
 * learners / 1 million rating days are in docs/32 §12.6 (EXPLAIN ANALYZE on PostgreSQL). Nothing here grows with the members or rows.
 */

use App\Domain\Billing\Enums\Plan;
use App\Domain\Engagement\DTO\BoardCursor;
use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Enums\ScoreSource;
use App\Domain\Engagement\Services\LeagueService;
use App\Domain\Engagement\Services\MonthlyRatingService;
use App\Domain\Engagement\Services\Scoring\ScoreCorrectionService;
use App\Domain\Learning\Contracts\AnswerScoreRecorder;
use App\Domain\Learning\DTO\ScoredAnswer;
use App\Domain\Learning\DTO\WordScoreOutcome;
use App\Domain\Learning\Enums\AnswerVerdict;
use App\Domain\Operations\Support\PureCache;
use App\Models\LeagueGroup;
use App\Models\LeagueMember;
use App\Models\Learner;
use App\Models\LearnerScoreDay;
use App\Models\Lexeme;
use App\Models\ScoreCredit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    config()->set('leagues.timezone', 'Asia/Tashkent');
    config()->set('leagues.scoring.mode', 'live');
    config()->set('leagues.scoring.versions.2.starts_on', '2027-02-01');
});

function lbNow(string $local = '2027-02-03 12:00'): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Asia/Tashkent')->utc();
}

function lbCredit(int $learnerId, CarbonImmutable $now): void
{
    $lexeme = Lexeme::factory()->create()->id;
    DB::transaction(fn (): WordScoreOutcome => app(AnswerScoreRecorder::class)->record(
        new ScoredAnswer($learnerId, $lexeme, 1, ScoreSource::Training, 7, AnswerVerdict::Passed, null, Plan::Free, $now),
    ));
}

/** A v2 group of `$size` members with three rating days each. */
function lbGroup(int $size): LeagueGroup
{
    $week = app(LeagueService::class)->currentWeek(lbNow());
    $group = LeagueGroup::factory()->create(['league_week_id' => $week->id, 'tier' => 0, 'category' => 'free', 'members_count' => $size]);
    foreach (Learner::factory()->count($size)->create() as $i => $learner) {
        LeagueMember::factory()->create(['league_group_id' => $group->id, 'league_week_id' => $week->id, 'learner_id' => $learner->id, 'tier_before' => 0, 'category' => 'free']);
        foreach (['2027-02-01', '2027-02-02', '2027-02-03'] as $d => $day) {
            LearnerScoreDay::factory()->create(['learner_id' => $learner->id, 'rating_day' => $day, 'week_starts_on' => '2027-02-01', 'month_starts_on' => '2027-02-01', 'week_points' => 10 * $i + $d, 'month_points' => 10 * $i + $d]);
        }
    }

    return $group->fresh() ?? $group;
}

it('reads the standings of a v2 group in one statement whatever its size, none from the cache; closes a group in five', function (): void {
    $leagues = app(LeagueService::class);
    foreach ([3, 30] as $size) {
        $group = lbGroup($size);
        $group->load('week');

        $miss = QueryLog::during(fn () => $leagues->standings($group, lbNow()));
        $hit = QueryLog::during(fn () => $leagues->standings($group, lbNow()));
        expect($miss)->toHaveCount(1)
            ->and($miss[0])->toContain('row_number() over (partition by s.learner_id')
            ->and($hit)->toBe([]);

        // The close of the group: the lock, its week, the standings, ONE update of every member, the stamp.
        $close = QueryLog::during(fn () => $leagues->closeGroup($group, lbNow('2027-02-08 00:01')));
        expect($close)->toHaveCount(5)
            ->and(QueryLog::matching($close, '/^update ["`]?league_members/'))->toHaveCount(1);
    }
});

it('seats at the first credit of a rating day after the commit: the read of the learner, the week and the membership, then the seat', function (): void {
    $learner = Learner::factory()->create();
    $first = QueryLog::during(fn () => lbCredit($learner->id, lbNow()));
    // (the two inserts of the learner's periods name `league_weeks` / `league_months` in their scalar subquery: not the league's)
    $league = array_values(array_filter(QueryLog::matching($first, '/league_/'), fn (string $sql): bool => ! str_contains($sql, 'learner_score_periods')));
    // week (create: read + insert), membership, last membership, week FOR SHARE, group seat (PostgreSQL: SKIP LOCKED, then under the
    // advisory lock FOR UPDATE), group insert, member insert, increment
    expect($league)->toHaveCount(DB::getDriverName() === 'pgsql' ? 10 : 9);

    // The first credit of the next day: three reads after the commit, nothing written.
    $next = QueryLog::during(fn () => lbCredit($learner->id, lbNow('2027-02-04 09:00')));
    expect(QueryLog::matching($next, '/league_/'))->toHaveCount(2)
        ->and(QueryLog::matching($next, '/^(insert|update) .*league_/'))->toBe([]);

    // Any other credit of the day: no statement of the league.
    $other = QueryLog::during(fn () => lbCredit($learner->id, lbNow('2027-02-04 09:05')));
    expect(QueryLog::matching($other, '/league_|from ["`]learners["`]/'))->toBe([]);
});

it('reads a v2 month in a constant number of statements: top 2 (with the emoji of Pro), rank 1 then 0 from the cache, a page 1 or 3, the neighbours and the gap 6, a refresh 2', function (): void {
    $months = app(MonthlyRatingService::class);
    $month = $months->currentMonth(lbNow());
    foreach (Learner::factory()->count(12)->create() as $i => $learner) {
        LearnerScoreDay::factory()->create(['learner_id' => $learner->id, 'rating_day' => '2027-02-02', 'week_starts_on' => '2027-02-01', 'month_starts_on' => '2027-02-01', 'month_points' => 5 * ($i % 6)]);
    }
    $months->refresh($month, lbNow(), full: true);
    PureCache::forgetMany(MonthlyRatingService::topKeys($month->id));
    $score = $months->scoreOf($month, (int) DB::table('league_month_scores')->orderBy('id')->skip(2)->value('learner_id')); // 10 points: rows above and below
    expect($score)->not->toBeNull();
    $learner = $score->learner;

    // the top and the emoji of Pro of its rows (docs/31 §5а) - both cached together
    expect(QueryLog::during(fn () => $months->top($month, ScoreCategory::Free)))->toHaveCount(2)
        ->and(QueryLog::during(fn () => $months->top($month, ScoreCategory::Free)))->toBe([])
        ->and(QueryLog::during(fn () => $months->rankOf($month, $score)))->toHaveCount(1)
        // the own place is cached a minute by the tuple (S12)
        ->and(QueryLog::during(fn () => $months->rankOf($month, $score)))->toBe([])
        ->and(QueryLog::during(fn () => $months->board($month, ScoreCategory::Free, null, 5)))->toHaveCount(1)
        ->and(QueryLog::during(fn () => $months->board($month, ScoreCategory::Free, new BoardCursor(15, 0, 1, 1), 5)))->toHaveCount(3)
        ->and(QueryLog::during(fn () => $months->neighbours($month, $score)))->toHaveCount(6)
        ->and(QueryLog::during(fn () => $months->refreshLearner($month, $learner, lbNow())))->toHaveCount(2);
});

it('corrects a day in a constant number of statements plus one update per credit whose points changed', function (): void {
    $learner = Learner::factory()->create();
    foreach (range(1, 6) as $i) {
        lbCredit($learner->id, lbNow()->addMinutes($i));
    }
    $id = (int) ScoreCredit::query()->orderBy('id')->value('id');
    $admin = User::factory()->create();

    $log = QueryLog::during(fn () => app(ScoreCorrectionService::class)->void($learner, '2027-02-03', [$id], 'test', $admin, now: lbNow()));
    // day read, membership, group FOR SHARE, lexemes of the day, lexemes FOR UPDATE, day FOR UPDATE, credits, void, day update,
    // audit; after the commit: the month (find) - no month row here.
    expect($log)->toHaveCount(11);
});
