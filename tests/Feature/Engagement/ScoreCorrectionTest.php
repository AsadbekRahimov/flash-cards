<?php

declare(strict_types=1);

/*
 * The correction of a rating day (docs/32 §6.4, task S11): credits voided / restored, the day recounted by the very rule of the answer
 * (ScoreCount) - "60 → 45", a repeat subtracts nothing, the slots and the cap refill, the long marks of the lexemes follow the journal,
 * a final week keeps its count, the month of the day is recounted after the commit, the audit row. The races (a correction during an
 * answer) are in LeagueV2RaceTest (PostgreSQL).
 */

use App\Domain\Billing\Enums\Plan;
use App\Domain\Engagement\Enums\ScoreSource;
use App\Domain\Engagement\Exceptions\ScoreCorrectionException;
use App\Domain\Engagement\Services\LeagueService;
use App\Domain\Engagement\Services\MonthlyRatingService;
use App\Domain\Engagement\Services\Scoring\ScoreCorrectionService;
use App\Domain\Learning\Contracts\AnswerScoreRecorder;
use App\Domain\Learning\DTO\CardStateBefore;
use App\Domain\Learning\DTO\ScoredAnswer;
use App\Domain\Learning\DTO\WordScoreOutcome;
use App\Domain\Learning\Enums\AnswerVerdict;
use App\Domain\Learning\Enums\RepetitionState;
use App\Models\AuditLog;
use App\Models\LeagueMember;
use App\Models\LeagueMonthScore;
use App\Models\Learner;
use App\Models\LearnerLexeme;
use App\Models\LearnerScoreDay;
use App\Models\Lexeme;
use App\Models\ScoreCredit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    config()->set('leagues.timezone', 'Asia/Tashkent');
    config()->set('leagues.scoring.mode', 'live');
    config()->set('leagues.scoring.versions.2.starts_on', '2027-02-01');
});

function scAt(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Asia/Tashkent')->utc();
}

/** A repetition credit (or a long one, interval ≥ 7) of a fresh confirmed lexeme of the learner at the moment. */
function scRepeat(Learner $learner, CarbonImmutable $now, int $interval = 3, ?int $lexeme = null, Plan $plan = Plan::Free): WordScoreOutcome
{
    $lexeme ??= Lexeme::factory()->create()->id;
    LearnerLexeme::query()->firstOrCreate(['learner_id' => $learner->id, 'lexeme_id' => $lexeme], ['confirmed_on' => '2027-01-10']);
    $before = new CardStateBefore(RepetitionState::Review, $interval, $now->subDays($interval)->subHour());

    return DB::transaction(fn (): WordScoreOutcome => app(AnswerScoreRecorder::class)->record(
        new ScoredAnswer($learner->id, $lexeme, 1, ScoreSource::Training, 7, AnswerVerdict::Passed, $before, $plan, $now),
    ));
}

function scAdmin(): User
{
    return User::factory()->create(['is_active' => true]);
}

function scDay(Learner $learner, string $day): LearnerScoreDay
{
    return LearnerScoreDay::query()->where('learner_id', $learner->id)->whereDate('rating_day', $day)->sole();
}

/** @return list<int> the credit ids of the learner's day, oldest first */
function scCredits(Learner $learner, string $day): array
{
    return ScoreCredit::query()->where('learner_id', $learner->id)->whereDate('rating_day', $day)->orderBy('id')->pluck('id')->all();
}

it('voids credits worth 15 of a day of 60 (→ 45); the same void again subtracts nothing; the restore gives the 60 back', function (): void {
    $learner = Learner::factory()->create();
    foreach (range(1, 20) as $i) {
        scRepeat($learner, scAt('2027-02-03 10:00')->addMinutes($i));
    }
    expect(scDay($learner, '2027-02-03')->week_points)->toBe(60);

    $five = array_slice(scCredits($learner, '2027-02-03'), 0, 5);
    $service = app(ScoreCorrectionService::class);
    $admin = scAdmin();

    $result = $service->void($learner, '2027-02-03', $five, 'shared device', $admin, now: scAt('2027-02-03 18:00'));
    $day = scDay($learner, '2027-02-03');
    expect($result->changedCreditIds)->toBe($five)
        ->and([$result->weekPointsBefore, $result->weekPointsAfter, $result->monthPointsBefore, $result->monthPointsAfter])->toBe([60, 45, 60, 45])
        ->and([$day->week_points, $day->month_points, $day->week_review, $day->review_count, $day->raw_points, $day->revision])->toBe([45, 45, 15, 15, 45, 1])
        ->and(ScoreCredit::query()->whereIn('id', $five)->whereNotNull('voided_at')->where('void_reason', 'shared device')->where('voided_by_user_id', $admin->id)->count())->toBe(5)
        ->and(AuditLog::query()->where('action', ScoreCorrectionService::ACTION_VOIDED)->sole()->meta['week'])->toBe([60, 45]);

    // A repeat (a double click): nothing changes, nothing is written.
    $again = $service->void($learner, '2027-02-03', $five, 'shared device', $admin, now: scAt('2027-02-03 18:01'));
    expect($again->changed())->toBeFalse()
        ->and(scDay($learner, '2027-02-03')->week_points)->toBe(45)
        ->and(scDay($learner, '2027-02-03')->revision)->toBe(1)
        ->and(AuditLog::query()->count())->toBe(1);

    $restored = $service->restore($learner, '2027-02-03', null, $admin, now: scAt('2027-02-03 18:02'));
    expect($restored->changedCreditIds)->toBe($five)
        ->and(scDay($learner, '2027-02-03')->week_points)->toBe(60)
        ->and(scDay($learner, '2027-02-03')->revision)->toBe(2)
        ->and(ScoreCredit::query()->whereNotNull('voided_at')->count())->toBe(0);
});

it('refills the slots and the cap by the replay: a credit cut by the limit gets its points when an earlier one is voided', function (): void {
    $learner = Learner::factory()->create();
    // 22 repetitions, Free: 20 slots - the last two are journaled with 0.
    foreach (range(1, 22) as $i) {
        scRepeat($learner, scAt('2027-02-03 10:00')->addMinutes($i));
    }
    $ids = scCredits($learner, '2027-02-03');
    expect(ScoreCredit::query()->whereIn('id', array_slice($ids, 20))->pluck('week_points')->all())->toBe([0, 0]);

    app(ScoreCorrectionService::class)->void($learner, '2027-02-03', [$ids[0]], 'test', scAdmin(), now: scAt('2027-02-03 18:00'));

    // 21 active: the first 20 of them fill the slots - the 21st (the 22nd overall) still gets 0, the 21st overall gets its 3.
    expect(ScoreCredit::query()->whereIn('id', array_slice($ids, 20))->orderBy('id')->pluck('week_points')->all())->toBe([3, 0])
        ->and(scDay($learner, '2027-02-03')->week_points)->toBe(60)
        ->and(scDay($learner, '2027-02-03')->week_review)->toBe(20);
});

it('recomputes the long marks of the lexemes from the journal: voided → the next long day or nothing; restored → the day again', function (): void {
    $learner = Learner::factory()->create();
    $lexeme = Lexeme::factory()->create()->id;
    scRepeat($learner, scAt('2027-02-03 10:00'), interval: 8, lexeme: $lexeme);
    $other = Lexeme::factory()->create()->id;
    scRepeat($learner, scAt('2027-02-03 10:05'), interval: 8, lexeme: $other);
    // The same lexeme, a long repetition again 17 days later (a new cycle).
    scRepeat($learner, scAt('2027-02-20 10:00'), interval: 17, lexeme: $other);

    $marks = fn (int $id): array => (fn (LearnerLexeme $l): array => [$l->first_long_on?->toDateString(), $l->long_free_on?->toDateString()])(
        LearnerLexeme::query()->where('learner_id', $learner->id)->where('lexeme_id', $id)->sole(),
    );
    expect($marks($lexeme))->toBe(['2027-02-03', '2027-02-03'])
        ->and($marks($other))->toBe(['2027-02-03', '2027-02-03']);

    $service = app(ScoreCorrectionService::class);
    $service->void($learner, '2027-02-03', null, 'test', scAdmin(), now: scAt('2027-02-21 10:00'));
    expect($marks($lexeme))->toBe([null, null])
        ->and($marks($other))->toBe(['2027-02-20', '2027-02-20']);

    $service->restore($learner, '2027-02-03', null, scAdmin(), now: scAt('2027-02-21 10:05'));
    expect($marks($lexeme))->toBe(['2027-02-03', '2027-02-03'])
        ->and($marks($other))->toBe(['2027-02-03', '2027-02-03'])
        // confirmed_on / last_attempt_on / the paid due are never rolled back
        ->and(LearnerLexeme::query()->where('learner_id', $learner->id)->where('lexeme_id', $lexeme)->sole()->last_credit_due_at)->not->toBeNull();
});

it('leaves the weekly count of a closed week as it is and still corrects the month of the day', function (): void {
    app(MonthlyRatingService::class)->currentMonth(scAt('2027-02-01 09:00')); // the scheduler's refresh made the month's row
    $learner = Learner::factory()->create();
    foreach (range(1, 4) as $i) {
        scRepeat($learner, scAt('2027-02-03 10:00')->addMinutes($i));
    }
    $leagues = app(LeagueService::class);
    $member = LeagueMember::query()->where('learner_id', $learner->id)->sole(); // seated by the first credit of the day
    $leagues->closeWeek($member->week, scAt('2027-02-08 00:01'));
    expect($member->fresh()?->final_points)->toBe(12);

    $ids = scCredits($learner, '2027-02-03');
    $result = app(ScoreCorrectionService::class)->void($learner, '2027-02-03', [$ids[0]], 'test', scAdmin(), now: scAt('2027-02-09 10:00'));

    $day = scDay($learner, '2027-02-03');
    expect($result->weekFrozen)->toBeTrue()
        ->and([$day->week_points, $day->month_points])->toBe([12, 9])
        ->and(ScoreCredit::query()->find($ids[0])?->week_points)->toBe(3) // the voided credit keeps its record
        ->and($member->fresh()?->final_points)->toBe(12);

    // The open month of the day was recounted after the commit.
    $month = app(MonthlyRatingService::class)->findMonthStartingOn('2027-02-01');
    expect($month)->not->toBeNull()
        ->and(LeagueMonthScore::query()->where('league_month_id', $month?->id)->where('learner_id', $learner->id)->value('points'))->toBe(9);
});

it('corrects the weekly count of an open week and drops the cached standings of the group', function (): void {
    $learner = Learner::factory()->create();
    foreach (range(1, 3) as $i) {
        scRepeat($learner, scAt('2027-02-03 10:00')->addMinutes($i));
    }
    $leagues = app(LeagueService::class);
    $group = LeagueMember::query()->where('learner_id', $learner->id)->sole()->group;
    expect($leagues->standings($group, scAt('2027-02-03 12:00'))->first()?->points)->toBe(9);

    app(ScoreCorrectionService::class)->void($learner, '2027-02-03', [scCredits($learner, '2027-02-03')[0]], 'test', scAdmin(), now: scAt('2027-02-03 12:00'));

    expect($leagues->standings($group, scAt('2027-02-03 12:01'))->first()?->points)->toBe(6);
});

it('refuses a credit of another day or learner, an empty reason and a day without credits', function (): void {
    $learner = Learner::factory()->create();
    scRepeat($learner, scAt('2027-02-03 10:00'));
    scRepeat($learner, scAt('2027-02-04 10:00'));
    $service = app(ScoreCorrectionService::class);

    expect(fn () => $service->void($learner, '2027-02-03', scCredits($learner, '2027-02-04'), 'x', scAdmin()))
        ->toThrow(fn (ScoreCorrectionException $e) => expect($e->errorCode)->toBe(ScoreCorrectionException::CREDIT_NOT_FOUND))
        ->and(fn () => $service->void($learner, '2027-02-03', null, '  ', scAdmin()))
        ->toThrow(fn (ScoreCorrectionException $e) => expect($e->errorCode)->toBe(ScoreCorrectionException::REASON_INVALID))
        ->and(fn () => $service->void($learner, '2027-02-10', null, 'x', scAdmin()))
        ->toThrow(fn (ScoreCorrectionException $e) => expect($e->errorCode)->toBe(ScoreCorrectionException::DAY_NOT_FOUND))
        ->and(ScoreCredit::query()->whereNotNull('voided_at')->count())->toBe(0);
});
