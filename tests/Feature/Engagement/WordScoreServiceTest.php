<?php

declare(strict_types=1);

/*
 * The word points of one answer (docs/28, docs/32 §3.2–§3.4, task S4): WordScoreService through its contract, inside a transaction as
 * the answer calls it. The matrix: new once in a learner's history; a repetition by the scheduled due (on time, long, early, pulled,
 * paid for already, studied the same day); a failed / assisted / revealed / unchecked answer closes the attempt of the rating day; the
 * slots and the day cap of Free and Pro, the remainder under the cap, two counts with different categories; the rating day of Tashkent
 * across midnight; a plan change inside a period; off / shadow / live before the start; the game switch; the long marks. The races are
 * in WordScoreRaceTest (PostgreSQL), the statement budget in WordScoreQueryBudgetTest.
 */

use App\Domain\Billing\Enums\Plan;
use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Enums\ScoreKind;
use App\Domain\Engagement\Enums\ScoreSource;
use App\Domain\Learning\Contracts\AnswerScoreRecorder;
use App\Domain\Learning\DTO\CardStateBefore;
use App\Domain\Learning\DTO\ScoredAnswer;
use App\Domain\Learning\DTO\WordScoreOutcome;
use App\Domain\Learning\Enums\AnswerVerdict;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Enums\WordScoreReason;
use App\Models\Learner;
use App\Models\LearnerLexeme;
use App\Models\LearnerScoreDay;
use App\Models\LearnerScorePeriod;
use App\Models\Lexeme;
use App\Models\ScoreCredit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('leagues.timezone', 'Asia/Tashkent');
    config()->set('leagues.scoring.mode', 'live');
    config()->set('leagues.scoring.game_enabled', true);
    config()->set('leagues.scoring.versions.2.starts_on', '2027-02-01');
});

/** A moment of the given Tashkent date and time. */
function wssAt(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Asia/Tashkent')->utc();
}

/** A day-based card whose last pass was `$daysAgo` days (and an hour) before `$now`, with the interval `$interval`. */
function wssCard(CarbonImmutable $now, int $interval, ?float $daysAgo = null): CardStateBefore
{
    $daysAgo ??= (float) $interval;

    return new CardStateBefore(RepetitionState::Review, $interval, $now->subMinutes((int) round($daysAgo * 1440) + 60));
}

function wssRecord(
    int $learnerId,
    ?int $lexemeId,
    AnswerVerdict $verdict,
    CarbonImmutable $now,
    CardStateBefore|Closure|null $before = null,
    Plan|Closure $plan = Plan::Free,
    ScoreSource $source = ScoreSource::Training,
    int $wordId = 1,
    int $sourceId = 7,
): WordScoreOutcome {
    return DB::transaction(fn (): WordScoreOutcome => app(AnswerScoreRecorder::class)->record(
        new ScoredAnswer($learnerId, $lexemeId, $wordId, $source, $sourceId, $verdict, $before, $plan, $now),
    ));
}

/** @return list<int> `$count` fresh lexeme ids */
function wssLexemes(int $count): array
{
    return Lexeme::factory()->count($count)->create()->modelKeys();
}

function wssDay(int $learnerId, string $day): ?LearnerScoreDay
{
    return LearnerScoreDay::query()->where('learner_id', $learnerId)->whereDate('rating_day', $day)->first();
}

function wssLexeme(int $learnerId, int $lexemeId): ?LearnerLexeme
{
    return LearnerLexeme::query()->where('learner_id', $learnerId)->where('lexeme_id', $lexemeId)->first();
}

it('records nothing with the word points off, without a lexeme or at the first meeting of a new card', function (): void {
    $learner = Learner::factory()->create();
    [$lexeme] = wssLexemes(1);
    $now = wssAt('2027-02-10 12:00');

    config()->set('leagues.scoring.mode', 'off');
    expect(wssRecord($learner->id, $lexeme, AnswerVerdict::Passed, $now)->reason)->toBe(WordScoreReason::Off);

    config()->set('leagues.scoring.mode', 'live');
    expect(wssRecord($learner->id, null, AnswerVerdict::Passed, $now)->reason)->toBe(WordScoreReason::NoCatalog)
        ->and(wssRecord($learner->id, $lexeme, AnswerVerdict::Introductory, $now)->reason)->toBe(WordScoreReason::Introductory)
        ->and(LearnerLexeme::query()->count())->toBe(0)
        ->and(LearnerScoreDay::query()->count())->toBe(0)
        ->and(ScoreCredit::query()->count())->toBe(0);

    // The meeting left the attempt open: the first check of the same day confirms the word.
    $outcome = wssRecord($learner->id, $lexeme, AnswerVerdict::Passed, $now->addMinutes(2));
    expect($outcome->reason)->toBe(WordScoreReason::Credited)
        ->and($outcome->kind)->toBe(ScoreKind::New)
        ->and($outcome->weekAdded)->toBe(2)
        ->and($outcome->monthAdded)->toBe(2);
});

it('credits a new word once in the learner\'s history: two copies of the lexeme, two days', function (): void {
    $learner = Learner::factory()->create();
    [$lexeme] = wssLexemes(1);
    $day1 = wssAt('2027-02-10 12:00');

    $first = wssRecord($learner->id, $lexeme, AnswerVerdict::Passed, $day1, wordId: 11);
    $copy = wssRecord($learner->id, $lexeme, AnswerVerdict::Passed, $day1->addMinutes(5), wordId: 22);

    expect($first->kind)->toBe(ScoreKind::New)
        ->and($first->base)->toBe(2)
        ->and($first->firstCreditOfWeek)->toBeTrue()
        ->and($first->visible)->toBeTrue()
        ->and($copy->reason)->toBe(WordScoreReason::AlreadyToday);

    $row = wssLexeme($learner->id, $lexeme);
    expect($row?->confirmed_on?->toDateString())->toBe('2027-02-10')
        ->and($row?->confirmed_source)->toBe('training')
        ->and($row?->last_attempt_on?->toDateString())->toBe('2027-02-10');

    // Next day the second copy is still in its minute steps: no "new" again, no repetition either.
    $next = wssRecord($learner->id, $lexeme, AnswerVerdict::Passed, $day1->addDay(), new CardStateBefore(RepetitionState::Learning, 0, $day1), wordId: 22);
    expect($next->reason)->toBe(WordScoreReason::NotDue)
        ->and(ScoreCredit::query()->count())->toBe(1);

    $credit = ScoreCredit::query()->sole();
    expect($credit->kind)->toBe(ScoreKind::New)
        ->and($credit->word_id)->toBe(11)
        ->and($credit->source)->toBe(ScoreSource::Training)
        ->and($credit->source_id)->toBe(7)
        ->and($credit->rating_day->toDateString())->toBe('2027-02-10')
        ->and($credit->week_points)->toBe(2)
        ->and($credit->month_points)->toBe(2);

    $day = wssDay($learner->id, '2027-02-10');
    expect($day?->week_category)->toBe(ScoreCategory::Free)
        ->and($day?->week_version)->toBe(2)
        ->and($day?->week_starts_on->toDateString())->toBe('2027-02-08')
        ->and($day?->month_starts_on->toDateString())->toBe('2027-02-01')
        ->and($day?->raw_points)->toBe(2)
        ->and($day?->new_count)->toBe(1)
        ->and($day?->week_new)->toBe(1)
        ->and($day?->month_new)->toBe(1)
        ->and($day?->week_points)->toBe(2)
        ->and(LearnerScorePeriod::query()->count())->toBe(2);
});

it('credits a repetition by the scheduled due: 3 on time, 5 after an interval of a week, 0 before the due', function (int $interval, float $daysAgo, ?ScoreKind $kind, WordScoreReason $reason, int $points): void {
    $learner = Learner::factory()->create();
    [$lexeme] = wssLexemes(1);
    $now = wssAt('2027-02-10 12:00');
    LearnerLexeme::factory()->create(['learner_id' => $learner->id, 'lexeme_id' => $lexeme, 'confirmed_on' => '2027-01-20']);

    $outcome = wssRecord($learner->id, $lexeme, AnswerVerdict::Passed, $now, wssCard($now, $interval, $daysAgo));

    expect($outcome->kind)->toBe($kind)
        ->and($outcome->reason)->toBe($reason)
        ->and($outcome->weekAdded)->toBe($points)
        ->and(wssLexeme($learner->id, $lexeme)?->last_attempt_on?->toDateString())->toBe('2027-02-10');
})->with([
    'on time, interval 3' => [3, 3.0, ScoreKind::Review, WordScoreReason::Credited, 3],
    'overdue, interval 3 (no bonus for the delay)' => [3, 9.0, ScoreKind::Review, WordScoreReason::Credited, 3],
    'interval 6' => [6, 6.0, ScoreKind::Review, WordScoreReason::Credited, 3],
    'long, interval 7' => [7, 7.0, ScoreKind::Long, WordScoreReason::Credited, 5],
    'long, interval 30, overdue' => [30, 40.0, ScoreKind::Long, WordScoreReason::Credited, 5],
    'due tomorrow (pulled to now by the game or the round of hard words)' => [7, 6.0, null, WordScoreReason::Early, 0],
]);

it('counts the due with a minute of slack, and never a card studied earlier the same rating day', function (): void {
    $learner = Learner::factory()->create();
    [$slack, $sameDay] = wssLexemes(2);
    $now = wssAt('2027-02-10 12:00');
    LearnerLexeme::factory()->create(['learner_id' => $learner->id, 'lexeme_id' => $slack, 'confirmed_on' => '2027-01-20']);
    LearnerLexeme::factory()->create(['learner_id' => $learner->id, 'lexeme_id' => $sameDay, 'confirmed_on' => '2027-01-20']);

    // Due in 40 seconds: on time.
    $due = new CardStateBefore(RepetitionState::Review, 2, $now->subDays(2)->addSeconds(40));
    expect(wssRecord($learner->id, $slack, AnswerVerdict::Passed, $now, $due)->kind)->toBe(ScoreKind::Review);

    // A review card with interval 0 graded this morning (Tashkent): due, but the same rating day.
    $morning = new CardStateBefore(RepetitionState::Review, 0, wssAt('2027-02-10 08:00'));
    expect(wssRecord($learner->id, $sameDay, AnswerVerdict::Passed, $now, $morning)->reason)->toBe(WordScoreReason::Early);
});

it('pays a scheduled due once: a game credit makes the next training answer of the same cycle worth nothing', function (): void {
    $learner = Learner::factory()->create();
    [$lexeme] = wssLexemes(1);
    $day1 = wssAt('2027-02-10 12:00');
    LearnerLexeme::factory()->create(['learner_id' => $learner->id, 'lexeme_id' => $lexeme, 'confirmed_on' => '2027-01-20']);
    // The game does not move the schedule: the card stands as it was on both days.
    $card = wssCard($day1, 7);

    $game = wssRecord($learner->id, $lexeme, AnswerVerdict::Passed, $day1, $card, source: ScoreSource::Game);
    $training = wssRecord($learner->id, $lexeme, AnswerVerdict::Passed, $day1->addDay(), $card);

    expect($game->kind)->toBe(ScoreKind::Long)
        ->and($training->reason)->toBe(WordScoreReason::SameCycle)
        ->and(wssLexeme($learner->id, $lexeme)?->last_credit_due_at?->toIso8601String())->toBe($card->nominalDue()?->toIso8601String());

    // The training moved the schedule (new pass today, interval 16): the next due is a new cycle.
    $later = $day1->addDays(18);
    $moved = new CardStateBefore(RepetitionState::Review, 16, $day1->addDay());
    expect(wssRecord($learner->id, $lexeme, AnswerVerdict::Passed, $later, $moved)->kind)->toBe(ScoreKind::Long);
});

it('pays the due cycle with the confirmation of a new word on a due card', function (): void {
    $learner = Learner::factory()->create();
    [$lexeme] = wssLexemes(1);
    $now = wssAt('2027-02-10 12:00');
    $card = wssCard($now, 3);

    expect(wssRecord($learner->id, $lexeme, AnswerVerdict::Passed, $now, $card)->kind)->toBe(ScoreKind::New)
        ->and(wssLexeme($learner->id, $lexeme)?->last_credit_due_at?->toIso8601String())->toBe($card->nominalDue()?->toIso8601String())
        ->and(wssRecord($learner->id, $lexeme, AnswerVerdict::Passed, $now->addDay(), $card)->reason)->toBe(WordScoreReason::SameCycle);
});

it('closes the attempt of the rating day with a failed, assisted, revealed or unchecked answer', function (AnswerVerdict $verdict): void {
    $learner = Learner::factory()->create();
    [$fresh, $known] = wssLexemes(2);
    $now = wssAt('2027-02-10 12:00');
    LearnerLexeme::factory()->create(['learner_id' => $learner->id, 'lexeme_id' => $known, 'confirmed_on' => '2027-01-20']);

    expect(wssRecord($learner->id, $fresh, $verdict, $now)->reason)->toBe(WordScoreReason::Closed)
        ->and(wssRecord($learner->id, $known, $verdict, $now, wssCard($now, 7))->reason)->toBe(WordScoreReason::Closed)
        ->and(wssRecord($learner->id, $fresh, AnswerVerdict::Passed, $now->addMinute())->reason)->toBe(WordScoreReason::AlreadyToday)
        ->and(wssRecord($learner->id, $known, AnswerVerdict::Passed, $now->addMinute(), wssCard($now, 7))->reason)->toBe(WordScoreReason::AlreadyToday)
        ->and(LearnerScoreDay::query()->count())->toBe(0)
        ->and(ScoreCredit::query()->count())->toBe(0)
        ->and(wssLexeme($learner->id, $fresh)?->confirmed_on)->toBeNull();

    // The next rating day the attempt is open again.
    expect(wssRecord($learner->id, $fresh, AnswerVerdict::Passed, $now->addDay())->kind)->toBe(ScoreKind::New);
})->with([AnswerVerdict::Failed, AnswerVerdict::Assisted, AnswerVerdict::Revealed, AnswerVerdict::NotChecked]);

it('spends the slots of Free: the 21st new word and the 21st repetition of a day are credited with 0 and keep the slot at the limit', function (): void {
    $learner = Learner::factory()->create();
    $lexemes = wssLexemes(42);
    $now = wssAt('2027-02-10 09:00');

    foreach (array_slice($lexemes, 0, 21) as $i => $lexeme) {
        $outcome = wssRecord($learner->id, $lexeme, AnswerVerdict::Passed, $now->addMinutes($i));
    }
    expect($outcome->reason)->toBe(WordScoreReason::Capped)
        ->and($outcome->kind)->toBe(ScoreKind::New)
        ->and($outcome->weekAdded)->toBe(0)
        ->and($outcome->week?->newUsed)->toBe(20)
        ->and($outcome->week?->newLimit)->toBe(20);

    // The repetitions have their own 20 slots.
    foreach (array_slice($lexemes, 21, 21) as $i => $lexeme) {
        LearnerLexeme::factory()->create(['learner_id' => $learner->id, 'lexeme_id' => $lexeme, 'confirmed_on' => '2027-01-20']);
        $outcome = wssRecord($learner->id, $lexeme, AnswerVerdict::Passed, $now->addHour()->addMinutes($i), wssCard($now, 3));
        if ($i === 0) {
            expect($outcome->weekAdded)->toBe(3);
        }
    }
    expect($outcome->reason)->toBe(WordScoreReason::Capped);

    $day = wssDay($learner->id, '2027-02-10');
    expect($day?->week_new)->toBe(20)
        ->and($day?->week_review)->toBe(20)
        ->and($day?->week_points)->toBe(20 * 2 + 20 * 3)
        ->and($day?->week_capped_at)->not->toBeNull() // 100 = the cap of Free
        ->and($day?->new_count)->toBe(21)
        ->and($day?->review_count)->toBe(21)
        ->and($day?->raw_points)->toBe(21 * 2 + 21 * 3)
        ->and(ScoreCredit::query()->count())->toBe(42)
        ->and(ScoreCredit::query()->where('week_points', 0)->count())->toBe(2);
});

it('gives the remainder under the day cap: 98 of 100 and a long repetition give 2, then 0 with the slot still spent', function (): void {
    $learner = Learner::factory()->create();
    [$opener, $long, $after] = wssLexemes(3);
    $now = wssAt('2027-02-10 09:00');

    wssRecord($learner->id, $opener, AnswerVerdict::Passed, $now); // creates the day: 2 points
    LearnerScoreDay::query()->where('learner_id', $learner->id)->update(['week_points' => 98, 'month_points' => 98]);
    foreach ([$long, $after] as $lexeme) {
        LearnerLexeme::factory()->create(['learner_id' => $learner->id, 'lexeme_id' => $lexeme, 'confirmed_on' => '2027-01-20']);
    }

    $two = wssRecord($learner->id, $long, AnswerVerdict::Passed, $now->addMinute(), wssCard($now, 10));
    $zero = wssRecord($learner->id, $after, AnswerVerdict::Passed, $now->addMinutes(2), wssCard($now, 10));

    expect($two->kind)->toBe(ScoreKind::Long)
        ->and($two->base)->toBe(5)
        ->and($two->weekAdded)->toBe(2)
        ->and($two->monthAdded)->toBe(2)
        ->and($two->reason)->toBe(WordScoreReason::Credited)
        ->and($zero->weekAdded)->toBe(0)
        ->and($zero->reason)->toBe(WordScoreReason::Capped);

    $day = wssDay($learner->id, '2027-02-10');
    expect($day?->week_points)->toBe(100)
        ->and($day?->week_review)->toBe(2)
        ->and($day?->week_long)->toBe(1) // only the long repetition that added points
        ->and($day?->long_count)->toBe(2)
        ->and($day?->week_capped_at?->toIso8601String())->toBe($now->addMinute()->toIso8601String())
        ->and($day?->month_capped_at)->not->toBeNull();

    // The long marks follow the month: the lexeme that added points has them, the one cut to 0 does not.
    expect(wssLexeme($learner->id, $long)?->long_free_on?->toDateString())->toBe('2027-02-10')
        ->and(wssLexeme($learner->id, $long)?->first_long_on?->toDateString())->toBe('2027-02-10')
        ->and($two->newLongLexeme)->toBeTrue()
        ->and(wssLexeme($learner->id, $after)?->first_long_on)->toBeNull();
});

it('applies the limits of Pro: 30 new words and a cap of 150', function (): void {
    $learner = Learner::factory()->create();
    $lexemes = wssLexemes(31);
    $now = wssAt('2027-02-10 09:00');

    foreach ($lexemes as $i => $lexeme) {
        $outcome = wssRecord($learner->id, $lexeme, AnswerVerdict::Passed, $now->addMinutes($i), plan: Plan::Pro);
    }

    expect($outcome->weekAdded)->toBe(0)
        ->and($outcome->week?->category)->toBe(ScoreCategory::Pro)
        ->and($outcome->week?->cap)->toBe(150)
        ->and($outcome->week?->newLimit)->toBe(30)
        ->and(wssDay($learner->id, '2027-02-10')?->week_points)->toBe(60);
});

it('counts one answer in a Free week and a Pro month by the limits of each', function (): void {
    $learner = Learner::factory()->create();
    $lexemes = wssLexemes(22);

    // Monday 29 March in Free fixes the week; Thursday 1 April in Pro fixes April.
    wssRecord($learner->id, $lexemes[0], AnswerVerdict::Passed, wssAt('2027-03-29 10:00'), plan: Plan::Free);
    $thursday = wssAt('2027-04-01 10:00');
    foreach (array_slice($lexemes, 1) as $i => $lexeme) {
        $outcome = wssRecord($learner->id, $lexeme, AnswerVerdict::Passed, $thursday->addMinutes($i), plan: Plan::Pro);
    }

    // The 21st new word of Thursday: 0 in the Free week, 2 in the Pro month.
    expect($outcome->week?->category)->toBe(ScoreCategory::Free)
        ->and($outcome->month?->category)->toBe(ScoreCategory::Pro)
        ->and($outcome->weekAdded)->toBe(0)
        ->and($outcome->monthAdded)->toBe(2)
        ->and($outcome->reason)->toBe(WordScoreReason::Credited)
        ->and($outcome->toApi()['today_month'] ?? null)->not->toBeNull();

    $day = wssDay($learner->id, '2027-04-01');
    expect($day?->week_points)->toBe(40)
        ->and($day?->month_points)->toBe(42)
        ->and($day?->week_new)->toBe(20)
        ->and($day?->month_new)->toBe(21);
});

it('keeps the category of the week after a plan change inside it', function (): void {
    $learner = Learner::factory()->create();
    [$a, $b] = wssLexemes(2);

    wssRecord($learner->id, $a, AnswerVerdict::Passed, wssAt('2027-02-10 10:00'), plan: Plan::Free);
    $pro = wssRecord($learner->id, $b, AnswerVerdict::Passed, wssAt('2027-02-11 10:00'), plan: Plan::Pro);

    expect($pro->week?->category)->toBe(ScoreCategory::Free)
        ->and($pro->week?->cap)->toBe(100)
        ->and($pro->month?->category)->toBe(ScoreCategory::Free)
        ->and($pro->firstCreditOfWeek)->toBeFalse();
});

it('takes the rating day of Tashkent at the moment of the answer, across midnight', function (): void {
    $learner = Learner::factory()->create();
    [$lexeme] = wssLexemes(1);

    // 23:59:30 Tashkent on the 10th: a wrong answer closes the 10th only.
    expect(wssRecord($learner->id, $lexeme, AnswerVerdict::Failed, wssAt('2027-02-10 23:59:30'))->reason)->toBe(WordScoreReason::Closed);
    // 00:00:30 Tashkent on the 11th is still the 10th in UTC (19:00:30): a new rating day.
    $after = wssAt('2027-02-11 00:00:30');
    expect($after->toDateString())->toBe('2027-02-10');

    $outcome = wssRecord($learner->id, $lexeme, AnswerVerdict::Passed, $after);
    expect($outcome->kind)->toBe(ScoreKind::New)
        ->and(ScoreCredit::query()->sole()->rating_day->toDateString())->toBe('2027-02-11')
        ->and(wssDay($learner->id, '2027-02-11'))->not->toBeNull();
});

it('records without counting in shadow mode and in live before the start of the rules', function (string $mode, string $startsOn): void {
    config()->set('leagues.scoring.mode', $mode);
    config()->set('leagues.scoring.versions.2.starts_on', $startsOn);
    $learner = Learner::factory()->create();
    [$new, $old] = wssLexemes(2);
    $now = wssAt('2027-02-10 12:00');
    LearnerLexeme::factory()->create(['learner_id' => $learner->id, 'lexeme_id' => $old, 'confirmed_on' => '2027-01-20']);

    $outcome = wssRecord($learner->id, $new, AnswerVerdict::Passed, $now);
    $long = wssRecord($learner->id, $old, AnswerVerdict::Passed, $now, wssCard($now, 8));

    expect($outcome->reason)->toBe(WordScoreReason::Credited)
        ->and($outcome->kind)->toBe(ScoreKind::New)
        ->and($outcome->weekAdded)->toBe(0)
        ->and($outcome->visible)->toBeFalse()
        ->and($outcome->toApi())->toBeNull()
        ->and($long->kind)->toBe(ScoreKind::Long)
        ->and(wssRecord($learner->id, $new, AnswerVerdict::Passed, $now)->toApi())->toBeNull();

    $day = wssDay($learner->id, '2027-02-10');
    expect($day?->week_version)->toBe(1)
        ->and($day?->raw_points)->toBe(7)
        ->and($day?->new_count)->toBe(1)
        ->and($day?->long_count)->toBe(1)
        ->and($day?->week_points)->toBe(0)
        ->and($day?->month_points)->toBe(0)
        ->and(ScoreCredit::query()->count())->toBe(2)
        ->and(LearnerScorePeriod::query()->pluck('rules_version')->unique()->all())->toBe([1])
        ->and(wssLexeme($learner->id, $old)?->first_long_on)->toBeNull(); // nothing counted: no achievement progress
})->with([
    'shadow' => ['shadow', '2027-02-01'],
    'live before the start' => ['live', '2027-03-01'],
]);

it('takes the game out with the switch, and nothing else', function (): void {
    config()->set('leagues.scoring.game_enabled', false);
    $learner = Learner::factory()->create();
    [$lexeme] = wssLexemes(1);
    $now = wssAt('2027-02-10 12:00');

    expect(app(AnswerScoreRecorder::class)->recordsGame())->toBeFalse()
        ->and(app(AnswerScoreRecorder::class)->records())->toBeTrue()
        ->and(wssRecord($learner->id, $lexeme, AnswerVerdict::Failed, $now, source: ScoreSource::Game)->reason)->toBe(WordScoreReason::Off)
        ->and(LearnerLexeme::query()->count())->toBe(0)
        ->and(wssRecord($learner->id, $lexeme, AnswerVerdict::Passed, $now)->kind)->toBe(ScoreKind::New);
});

it('confirms a word from the game with the source of the game', function (): void {
    $learner = Learner::factory()->create();
    [$lexeme] = wssLexemes(1);

    wssRecord($learner->id, $lexeme, AnswerVerdict::Passed, wssAt('2027-02-10 12:00'), source: ScoreSource::Game, sourceId: 55);

    expect(wssLexeme($learner->id, $lexeme)?->confirmed_source)->toBe('game')
        ->and(ScoreCredit::query()->sole()->source)->toBe(ScoreSource::Game)
        ->and(ScoreCredit::query()->sole()->source_id)->toBe(55);
});

it('reads the card and the plan only when the decision needs them', function (): void {
    $learner = Learner::factory()->create();
    [$lexeme, $other] = wssLexemes(2);
    $now = wssAt('2027-02-10 12:00');
    LearnerLexeme::factory()->create(['learner_id' => $learner->id, 'lexeme_id' => $lexeme, 'confirmed_on' => '2027-01-20']);
    $calls = ['before' => 0, 'plan' => 0];
    $before = function () use (&$calls, $now): CardStateBefore {
        $calls['before']++;

        return wssCard($now, 3);
    };
    $plan = function () use (&$calls): Plan {
        $calls['plan']++;

        return Plan::Free;
    };

    wssRecord($learner->id, $lexeme, AnswerVerdict::Failed, $now, $before, $plan);
    expect($calls)->toBe(['before' => 0, 'plan' => 0]);

    wssRecord($learner->id, $other, AnswerVerdict::Passed, $now, $before, $plan); // the first credit of the day: the plan
    expect($calls)->toBe(['before' => 1, 'plan' => 1]);

    wssRecord($learner->id, $other, AnswerVerdict::Passed, $now, $before, $plan); // spent: nothing read
    expect($calls)->toBe(['before' => 1, 'plan' => 1]);
});

it('gives the score block of the API only for a visible answer', function (): void {
    $learner = Learner::factory()->create();
    [$lexeme] = wssLexemes(1);

    $outcome = wssRecord($learner->id, $lexeme, AnswerVerdict::Passed, wssAt('2027-02-10 12:00'));

    expect($outcome->toApi())->toBe([
        'kind' => 'new',
        'reason' => 'credited',
        'base' => 2,
        'week' => 2,
        'month' => 2,
        'today' => ['points' => 2, 'cap' => 100, 'category' => 'free', 'new' => [1, 20], 'review' => [0, 20]],
        'today_month' => null,
    ])->and(wssRecord($learner->id, $lexeme, AnswerVerdict::Passed, wssAt('2027-02-10 12:05'))->toApi())->toBe([
        'kind' => null,
        'reason' => 'already_today',
        'base' => 0,
        'week' => 0,
        'month' => 0,
        'today' => null,
        'today_month' => null,
    ]);
});
