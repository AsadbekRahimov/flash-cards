<?php

declare(strict_types=1);

/*
 * The switch to the word points v2 (docs/32 §12.6, owner's decision В1): the open week and month finish and close by v1, the first
 * week / month created on or after LEAGUES_SCORING_V2_STARTS_ON counts by v2; `leagues:scoring-cutover` shows the plan and with
 * --apply closes what is due and fixes the rows of the current periods. The game finish reads the version of the periods' rows.
 */

use App\Domain\Billing\Enums\Plan;
use App\Domain\Engagement\Enums\ScoreSource;
use App\Domain\Engagement\Services\LeagueService;
use App\Domain\Engagement\Services\MonthlyRatingService;
use App\Domain\Game\Services\Runs\RunFinisher;
use App\Domain\Learning\Contracts\AnswerScoreRecorder;
use App\Domain\Learning\DTO\ScoredAnswer;
use App\Domain\Learning\DTO\WordScoreOutcome;
use App\Domain\Learning\Enums\AnswerVerdict;
use App\Models\LeagueMember;
use App\Models\LeagueMonth;
use App\Models\LeagueMonthScore;
use App\Models\LeagueWeek;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Lexeme;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    config()->set('leagues.timezone', 'Asia/Tashkent');
    config()->set('leagues.monthly.close_delay_minutes', 180);
});

function cutAt(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Asia/Tashkent')->utc();
}

it('finishes the open week and month by v1, then counts the next ones by v2', function (): void {
    // Before the switch: off, a v1 week and month with a learner's v1 points.
    config()->set('leagues.scoring.mode', 'off');
    CarbonImmutable::setTestNow(cutAt('2027-01-27 12:00'));
    $learner = Learner::factory()->create();
    LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => '2027-01-27', 'reviews_count' => 10, 'correct_count' => 8, 'new_words_count' => 2]);
    $leagues = app(LeagueService::class);
    $months = app(MonthlyRatingService::class);
    $member = $leagues->place($learner, CarbonImmutable::now());
    $months->refresh($months->currentMonth(CarbonImmutable::now()), CarbonImmutable::now(), full: true);
    expect($member?->week?->rules_version)->toBe(1);

    // The switch on Wednesday: live, v2 from Monday 1 February (also the 1st). The open periods keep v1.
    config()->set('leagues.scoring.mode', 'live');
    config()->set('leagues.scoring.versions.2.starts_on', '2027-02-01');
    $this->artisan('leagues:scoring-cutover')->assertSuccessful();
    expect(LeagueWeek::query()->sole()->rules_version)->toBe(1);

    // A credit this week is recorded but counts by v1 (its week and month are v1): no v2 placement, the v1 one stays.
    $lexeme = Lexeme::factory()->create()->id;
    $outcome = DB::transaction(fn (): WordScoreOutcome => app(AnswerScoreRecorder::class)->record(
        new ScoredAnswer($learner->id, $lexeme, 1, ScoreSource::Training, 7, AnswerVerdict::Passed, null, Plan::Pro, CarbonImmutable::now()),
    ));
    expect([$outcome->weekAdded, $outcome->monthAdded])->toBe([0, 0])
        ->and(LeagueMember::query()->count())->toBe(1);

    // Monday 03:30: the cutover closes the ended week and month by v1 and creates the v2 rows.
    CarbonImmutable::setTestNow(cutAt('2027-02-01 03:30'));
    $this->artisan('leagues:scoring-cutover', ['--apply' => true])->assertSuccessful();

    expect(LeagueMember::query()->sole()->final_points)->toBe(22) // v1: 10 reviews + 8 correct + 2 new × 2, no goal
        ->and(LeagueMonth::query()->whereDate('starts_on', '2027-01-01')->sole()->status)->toBe('pending_review')
        ->and(LeagueMonthScore::query()->sole()->category)->toBeNull()
        ->and(LeagueWeek::query()->orderByDesc('starts_on')->first()?->rules_version)->toBe(2)
        ->and(LeagueMonth::query()->whereDate('starts_on', '2027-02-01')->sole()->rules_version)->toBe(2);

    // The first credit of the v2 week seats the learner in a group of the category of the week (Pro).
    $outcome = DB::transaction(fn (): WordScoreOutcome => app(AnswerScoreRecorder::class)->record(
        new ScoredAnswer($learner->id, Lexeme::factory()->create()->id, 2, ScoreSource::Training, 8, AnswerVerdict::Passed, null, Plan::Pro, CarbonImmutable::now()),
    ));
    expect([$outcome->weekAdded, $outcome->monthAdded])->toBe([2, 2])
        ->and(LeagueMember::query()->orderByDesc('id')->first()?->group?->category)->toBe('pro');

    CarbonImmutable::setTestNow();
});

it('refuses --apply unless the mode is live with a start date, and warns about a date that is not a Monday or the 1st', function (): void {
    CarbonImmutable::setTestNow(cutAt('2027-01-27 12:00'));
    config()->set('leagues.scoring.mode', 'off');
    $this->artisan('leagues:scoring-cutover', ['--apply' => true])->assertFailed();

    config()->set('leagues.scoring.mode', 'live');
    config()->set('leagues.scoring.versions.2.starts_on', '2027-02-03');
    $this->artisan('leagues:scoring-cutover')
        ->expectsOutputToContain('the first v2 week starts on 2027-02-08')
        ->expectsOutputToContain('the first v2 month starts on 2027-03-01')
        ->assertSuccessful();

    CarbonImmutable::setTestNow();
});

it('makes the game finish read the version of the period rows, not the configuration', function (): void {
    config()->set('leagues.scoring.mode', 'live');
    config()->set('leagues.scoring.versions.2.starts_on', '2027-01-01');
    $now = cutAt('2027-02-03 12:00');
    $v2Periods = new ReflectionMethod(RunFinisher::class, 'v2Periods');
    $finisher = app(RunFinisher::class);

    // No rows yet: what the rows will get (v2 both).
    expect($v2Periods->invoke($finisher, $now))->toBe([true, true]);

    // A week row that counts by v1 (created before the date moved): the finish follows the row.
    LeagueWeek::factory()->create(['starts_on' => '2027-02-01', 'ends_on' => '2027-02-07', 'rules_version' => 1]);
    LeagueMonth::factory()->startingOn('2027-02-01')->create(['rules_version' => 2]);
    expect($v2Periods->invoke($finisher, $now))->toBe([false, true]);

    config()->set('leagues.scoring.mode', 'off');
    expect($v2Periods->invoke($finisher, $now))->toBe([false, false]);
});
