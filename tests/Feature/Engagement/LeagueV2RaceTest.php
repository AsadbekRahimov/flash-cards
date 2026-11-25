<?php

declare(strict_types=1);

/*
 * Real races of the tables v2 on PostgreSQL (S8, S11): forked workers, each with its own connection and transaction, released at the
 * same instant (Tests\Support\Game\GameRace: lock_timeout 8 s - a deadlock or an endless wait is a crash, not a hang).
 *
 *   - the placement: many first credits of the week at once - every learner seated once, no group above its capacity, the groups of
 *     a category hold only that category; two first credits of one learner at once - one seat;
 *   - the close during the credits of the next week: the ended week closes group by group while the credits of Monday seat the same
 *     learners (whose tier needs the verdict of their old group): every group final once, every learner seated once with its verdict;
 *   - the correction during the answers: an administrator voids the credits of a day while the learner keeps answering on it - no
 *     deadlock, and the day always equals the replay of its journal (ScoreCount), nothing subtracted twice.
 * Committed rows: DatabaseTruncation. Skipped on sqlite.
 */

use App\Domain\Billing\Enums\Plan;
use App\Domain\Engagement\Enums\LeagueWeekStatus;
use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Enums\ScoreKind;
use App\Domain\Engagement\Enums\ScoreSource;
use App\Domain\Engagement\Services\LeagueService;
use App\Domain\Engagement\Services\Scoring\ScoreCorrectionService;
use App\Domain\Engagement\Services\Scoring\ScoreRules;
use App\Domain\Engagement\Support\ScoreCount;
use App\Domain\Learning\Contracts\AnswerScoreRecorder;
use App\Domain\Learning\DTO\CardStateBefore;
use App\Domain\Learning\DTO\ScoredAnswer;
use App\Domain\Learning\Enums\AnswerVerdict;
use App\Domain\Learning\Enums\RepetitionState;
use App\Models\LeagueGroup;
use App\Models\LeagueMember;
use App\Models\LeagueWeek;
use App\Models\Learner;
use App\Models\LearnerLexeme;
use App\Models\LearnerScoreDay;
use App\Models\Lexeme;
use App\Models\ScoreCredit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Game\GameRace;

$onPostgres = (getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql';

if (! $onPostgres) {
    it('races the tables v2 on PostgreSQL', function (): void {})
        ->skip('Requires PostgreSQL (DB_CONNECTION=pgsql): real parallel transactions need committed rows and row locks.');

    return;
}

uses(DatabaseTruncation::class);

beforeEach(function (): void {
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Needs the pcntl and posix extensions to fork workers.');
    }
    Queue::fake();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-02-03 12:00', 'Asia/Tashkent')->utc());
    config([
        'leagues.timezone' => 'Asia/Tashkent',
        'leagues.scoring.mode' => 'live',
        'leagues.scoring.versions.2.starts_on' => '2027-02-01',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    DB::reconnect();
    $this->truncateTablesForAllConnections();
});

/** One answer of the learner on the lexeme, in its own transaction: a new word, or a due repetition when `$interval` is given. */
function lvrAnswer(int $learnerId, int $lexemeId, Plan $plan, CarbonImmutable $now, ?int $interval = null): string
{
    $before = $interval === null ? null : new CardStateBefore(RepetitionState::Review, $interval, $now->subDays($interval)->subHour());

    return DB::transaction(fn (): string => app(AnswerScoreRecorder::class)->record(
        new ScoredAnswer($learnerId, $lexemeId, 1, ScoreSource::Training, 7, AnswerVerdict::Passed, $before, $plan, $now),
    )->reason->value);
}

/** The day as its journal gives it: the replay of the active credits must equal the stored counts. */
function lvrReplayed(int $learnerId, string $day): array
{
    $rules = app(ScoreRules::class)->version(2);
    $credits = ScoreCredit::query()->where('learner_id', $learnerId)->whereDate('rating_day', $day)->whereNull('voided_at')->orderBy('id')->get();
    [, $count] = ScoreCount::replay($credits->map(fn (ScoreCredit $c): array => ['kind' => $c->kind, 'at' => (string) $c->created_at])->all(), $rules, ScoreCategory::Free);

    return [$count->points, $count->new, $count->review];
}

it('seats twelve learners whose first credits of the week come at once: once each, never above the capacity, by category', function (): void {
    config(['leagues.capacity' => 3]);
    $learners = Learner::factory()->count(12)->create()->modelKeys();
    $lexeme = Lexeme::factory()->create()->id;
    app(LeagueService::class)->currentWeek(CarbonImmutable::now()); // the week row exists: the race is on the seats

    $tasks = [];
    foreach ($learners as $i => $learnerId) {
        $tasks[] = fn (): string => lvrAnswer($learnerId, $lexeme, $i % 3 === 0 ? Plan::Pro : Plan::Free, CarbonImmutable::now());
    }
    $results = GameRace::run($tasks);

    expect(GameRace::crashes($results))->toBe([])
        ->and(LeagueMember::query()->count())->toBe(12)
        ->and(LeagueMember::query()->distinct()->count('learner_id'))->toBe(12)
        ->and(LeagueGroup::query()->where('members_count', '>', 3)->count())->toBe(0)
        ->and((int) LeagueGroup::query()->sum('members_count'))->toBe(12);

    foreach (LeagueGroup::query()->get() as $group) {
        expect(LeagueMember::query()->where('league_group_id', $group->id)->count())->toBe($group->members_count)
            ->and(LeagueMember::query()->where('league_group_id', $group->id)->where('category', '<>', $group->category)->count())->toBe(0);
    }
    expect(LeagueGroup::query()->where('category', 'pro')->sum('members_count'))->toEqual(4);
});

it('seats a learner once when two first credits of the week (two lexemes, two devices) come at once', function (): void {
    $learner = Learner::factory()->create()->id;
    [$a, $b] = Lexeme::factory()->count(2)->create()->modelKeys();

    $results = GameRace::run([
        fn (): string => lvrAnswer($learner, $a, Plan::Free, CarbonImmutable::now()),
        fn (): string => lvrAnswer($learner, $b, Plan::Pro, CarbonImmutable::now()),
    ]);

    expect(GameRace::crashes($results))->toBe([])
        ->and(LeagueMember::query()->where('learner_id', $learner)->count())->toBe(1)
        ->and(LeagueGroup::query()->sum('members_count'))->toEqual(1)
        // the category of the week is the one of whichever fixed it first - one for the day, the week and the group
        ->and(LearnerScoreDay::query()->where('learner_id', $learner)->sole()->week_category->value)->toBe(LeagueMember::query()->where('learner_id', $learner)->sole()->category);
});

it('closes the ended week while the first credits of Monday seat its members: every group final once, every member seated once', function (): void {
    config(['leagues.capacity' => 3, 'leagues.promote' => 1, 'leagues.demote' => 1]);
    $leagues = app(LeagueService::class);
    // Last week (v2): two groups of three, tier 1, with points.
    $week = $leagues->currentWeek(CarbonImmutable::now());
    $learners = [];
    foreach (range(0, 1) as $g) {
        $group = LeagueGroup::factory()->create(['league_week_id' => $week->id, 'tier' => 1, 'category' => 'free', 'members_count' => 3]);
        foreach (range(0, 2) as $m) {
            $learner = Learner::factory()->create();
            LeagueMember::factory()->create(['league_group_id' => $group->id, 'league_week_id' => $week->id, 'learner_id' => $learner->id, 'tier_before' => 1, 'category' => 'free']);
            LearnerScoreDay::factory()->create(['learner_id' => $learner->id, 'rating_day' => '2027-02-02', 'week_starts_on' => '2027-02-01', 'month_starts_on' => '2027-02-01', 'week_points' => 10 * ($m + 1)]);
            $learners[] = $learner->id;
        }
    }
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-02-08 00:00:30', 'Asia/Tashkent')->utc());
    $lexeme = Lexeme::factory()->create()->id;

    $tasks = [fn (): bool => app(LeagueService::class)->closeWeek(LeagueWeek::query()->findOrFail($week->id), CarbonImmutable::now())];
    foreach ($learners as $learnerId) {
        $tasks[] = fn (): string => lvrAnswer($learnerId, $lexeme, Plan::Free, CarbonImmutable::now());
    }
    $results = GameRace::run($tasks);

    expect(GameRace::crashes($results))->toBe([])
        ->and(LeagueWeek::query()->findOrFail($week->id)->status)->toBe(LeagueWeekStatus::Closed->value)
        ->and(LeagueMember::query()->where('league_week_id', $week->id)->whereNull('result')->count())->toBe(0)
        ->and(LeagueMember::query()->where('league_week_id', '<>', $week->id)->count())->toBe(6);

    // Every new seat follows the verdict of the old group: the best of each group up (tier 2), the worst down (tier 0).
    $old = LeagueMember::query()->where('league_week_id', $week->id)->get()->keyBy('learner_id');
    foreach (LeagueMember::query()->where('league_week_id', '<>', $week->id)->get() as $member) {
        expect($member->tier_before)->toBe($old[$member->learner_id]->tierAfter());
    }
});

it('corrects a day while the learner keeps answering on it: no deadlock, the day is always the replay of its journal', function (): void {
    $learner = Learner::factory()->create();
    $lexemes = Lexeme::factory()->count(12)->create()->modelKeys();
    foreach ($lexemes as $lexeme) {
        LearnerLexeme::factory()->create(['learner_id' => $learner->id, 'lexeme_id' => $lexeme, 'confirmed_on' => '2027-01-10']);
    }
    // Eight repetitions credited before; four more come during the corrections.
    foreach (array_slice($lexemes, 0, 8) as $i => $lexeme) {
        lvrAnswer($learner->id, $lexeme, Plan::Free, CarbonImmutable::now()->addSeconds($i), interval: 3);
    }
    $admin = User::factory()->create();
    $first = ScoreCredit::query()->orderBy('id')->limit(3)->pluck('id')->all();

    $tasks = [
        fn (): array => app(ScoreCorrectionService::class)->void(Learner::query()->findOrFail($learner->id), '2027-02-03', $first, 'race', User::query()->findOrFail($admin->id))->changedCreditIds,
        fn (): array => app(ScoreCorrectionService::class)->void(Learner::query()->findOrFail($learner->id), '2027-02-03', $first, 'race', User::query()->findOrFail($admin->id))->changedCreditIds,
    ];
    foreach (array_slice($lexemes, 8) as $i => $lexeme) {
        $tasks[] = fn (): string => lvrAnswer($learner->id, $lexeme, Plan::Free, CarbonImmutable::now()->addMinutes($i + 1), interval: 3);
    }
    $results = GameRace::run($tasks);

    expect(GameRace::crashes($results))->toBe([])
        // one of the two corrections voided the three, the other found them voided: nothing twice
        ->and(collect([$results[0]['value'], $results[1]['value']])->map(fn (array $ids): int => count($ids))->sort()->values()->all())->toBe([0, 3])
        ->and(ScoreCredit::query()->whereNotNull('voided_at')->count())->toBe(3)
        ->and(ScoreCredit::query()->count())->toBe(12);

    $day = LearnerScoreDay::query()->where('learner_id', $learner->id)->sole();
    expect([$day->week_points, $day->week_new, $day->week_review])->toBe(lvrReplayed($learner->id, '2027-02-03'))
        ->and([$day->month_points, $day->month_new, $day->month_review])->toBe(lvrReplayed($learner->id, '2027-02-03'))
        ->and($day->week_points)->toBe(27)                     // 9 active repetitions × 3
        ->and(ScoreCredit::query()->whereNull('voided_at')->sum('week_points'))->toEqual(27)
        ->and(ScoreCredit::query()->where('kind', ScoreKind::Review->value)->count())->toBe(12);
});
