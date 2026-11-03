<?php

declare(strict_types=1);

use App\Domain\Game\Enums\RunEvent;
use App\Domain\Game\Services\Runs\GameSrsAdjuster;
use App\Domain\Game\Services\Runs\RunService;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Enums\SchedulerKind;
use App\Domain\Learning\Services\SchedulerResolver;
use App\Models\GameRunAttempt;
use App\Models\GameWallet;
use App\Models\GameWordMiss;
use App\Models\Learner;
use App\Models\TrainingReview;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Game\GameKit;

/*
 * docs/18 §5, §12.4; docs/06 §5a: the game only brings a repetition closer. After a mistake `next_review_at = now` and `is_hard = true`
 * and every other column is byte for byte what it was - for a learner on SM-2 and one on FSRS, for a card in `new`, `learning`,
 * `review` and `relearning`; a correct answer and a typo change nothing; an answer after a hint does; a repeated request does not
 * count twice; the usual grade after the pull is the one of docs/06 (reference numbers); `training_reviews` stays as it was.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    GameKit::openAccess();
    GameKit::noEvents();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function srsNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-11-05 10:00:00');
}

/** Every column of the row but the three the game may write. @return array<string, mixed> */
function srsUntouchable(WordRepetition $rep): array
{
    $row = (array) DB::table('word_repetitions')->where('id', $rep->id)->first();
    unset($row['next_review_at'], $row['is_hard'], $row['updated_at']);

    return $row;
}

it('brings a review card of the future to now and marks it hard, for SM-2 and FSRS alike, and touches nothing else', function (SchedulerKind $kind): void {
    $learner = Learner::factory()->create(['scheduler' => $kind]);
    $word = Word::factory()->create();
    $rep = WordRepetition::factory()->create([
        'learner_id' => $learner->id, 'word_id' => $word->id, 'state' => RepetitionState::Review->value, 'repetitions' => 3, 'interval_days' => 10,
        'easiness_factor' => 2.5, 'last_quality' => 4, 'last_reviewed_at' => srsNow()->subDays(5), 'next_review_at' => srsNow()->addDays(5), 'is_hard' => false,
        'lapses' => 1, 'stability' => $kind === SchedulerKind::Fsrs ? 12.5 : null, 'difficulty' => $kind === SchedulerKind::Fsrs ? 4.2 : null, 'scheduler' => $kind,
        'scheduled_days' => 10, 'served_ahead_at' => srsNow()->subMinute(), 'served_ahead_token' => 'ticket', 'meta' => ['x' => 1],
    ]);
    $before = srsUntouchable($rep);

    $moved = app(GameSrsAdjuster::class)->pull($learner->id, [$word->id], srsNow());
    $rep->refresh();

    expect($moved)->toBe(1)
        ->and($rep->next_review_at->toDateTimeString())->toBe('2026-11-05 10:00:00')
        ->and($rep->is_hard)->toBeTrue()
        ->and(srsUntouchable($rep))->toBe($before)
        ->and(TrainingReview::query()->count())->toBe(0);

    $miss = GameWordMiss::query()->where('learner_id', $learner->id)->where('word_id', $word->id)->first();
    expect($miss)->not->toBeNull()->and($miss?->misses)->toBe(1)->and($miss?->last_pulled_at?->toDateTimeString())->toBe('2026-11-05 10:00:00');

    // Again: nothing moves, the miss counts once more, `last_pulled_at` stays (the card is due already).
    expect(app(GameSrsAdjuster::class)->pull($learner->id, [$word->id], srsNow()->addMinute()))->toBe(0)
        ->and($rep->refresh()->next_review_at->toDateTimeString())->toBe('2026-11-05 10:00:00')
        ->and(GameWordMiss::query()->where('word_id', $word->id)->value('misses'))->toBe(2)
        ->and(GameWordMiss::query()->where('word_id', $word->id)->first()?->last_pulled_at?->toDateTimeString())->toBe('2026-11-05 10:00:00');
})->with(['SM-2' => [SchedulerKind::Sm2], 'FSRS' => [SchedulerKind::Fsrs]]);

it('touches a new, learning or relearning card only when its step is still ahead, and leaves the step and the learn-ahead ticket alone', function (): void {
    $learner = Learner::factory()->create();
    $ahead = WordRepetition::factory()->learning(1)->create(['learner_id' => $learner->id, 'next_review_at' => srsNow()->addMinutes(9), 'served_ahead_at' => srsNow(), 'served_ahead_token' => 'ticket', 'is_hard' => false]);
    $due = WordRepetition::factory()->relearning()->create(['learner_id' => $learner->id, 'next_review_at' => srsNow()->subMinute(), 'is_hard' => false]);
    $new = WordRepetition::factory()->create(['learner_id' => $learner->id, 'state' => RepetitionState::New->value, 'repetitions' => 0, 'next_review_at' => srsNow()->addDay(), 'is_hard' => false]);
    $untouchable = [srsUntouchable($ahead), srsUntouchable($due), srsUntouchable($new)];

    $moved = app(GameSrsAdjuster::class)->pull($learner->id, [$ahead->word_id, $due->word_id, $new->word_id], srsNow());

    expect($moved)->toBe(2)
        ->and($ahead->refresh()->next_review_at->toDateTimeString())->toBe('2026-11-05 10:00:00')
        ->and($ahead->is_hard)->toBeTrue()->and($ahead->learning_step)->toBe(1)->and($ahead->state)->toBe(RepetitionState::Learning)->and($ahead->served_ahead_token)->toBe('ticket')
        ->and($due->refresh()->next_review_at->toDateTimeString())->toBe('2026-11-05 09:59:00')
        ->and($due->is_hard)->toBeFalse()
        ->and($new->refresh()->next_review_at->toDateTimeString())->toBe('2026-11-05 10:00:00')
        ->and($new->state)->toBe(RepetitionState::New)
        ->and([srsUntouchable($ahead), srsUntouchable($due), srsUntouchable($new)])->toBe($untouchable)
        ->and(GameWordMiss::query()->where('learner_id', $learner->id)->count())->toBe(3)
        ->and(GameWordMiss::query()->where('word_id', $due->word_id)->value('last_pulled_at'))->toBeNull();
});

it('does not touch the words of another learner, nor words it was not told about', function (): void {
    $learner = Learner::factory()->create();
    $other = Learner::factory()->create();
    $word = Word::factory()->create();
    $mine = WordRepetition::factory()->create(['learner_id' => $learner->id, 'word_id' => $word->id, 'next_review_at' => srsNow()->addDay()]);
    $theirs = WordRepetition::factory()->create(['learner_id' => $other->id, 'word_id' => $word->id, 'next_review_at' => srsNow()->addDay()]);
    $another = WordRepetition::factory()->create(['learner_id' => $learner->id, 'next_review_at' => srsNow()->addDay()]);

    expect(app(GameSrsAdjuster::class)->pull($learner->id, [$word->id], srsNow()))->toBe(1)
        ->and($mine->refresh()->next_review_at->toDateTimeString())->toBe('2026-11-05 10:00:00')
        ->and($theirs->refresh()->next_review_at->toDateTimeString())->toBe('2026-11-06 10:00:00')
        ->and($another->refresh()->next_review_at->toDateTimeString())->toBe('2026-11-06 10:00:00')
        ->and(app(GameSrsAdjuster::class)->pull($learner->id, [], srsNow()))->toBe(0);
});

it('pulls the words of a wrong, a skipped and a hinted answer of a run, not of a correct one or a typo, once per accepted request', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    WordRepetition::query()->where('learner_id', $learner->id)->update(['next_review_at' => srsNow()->addDays(3), 'is_hard' => false]);
    GameWallet::factory()->withBalance(10)->create(['learner_id' => $learner->id]);
    $runs = app(RunService::class);
    $run = $runs->start($learner, $course->slug, $lesson->id);
    $elements = $run->snapshot['elements'];
    $wordOf = static fn (array $element): int => (int) $element['word_id'];
    $nextOf = static fn (int $wordId): string => WordRepetition::query()->where('learner_id', $learner->id)->where('word_id', $wordId)->firstOrFail()->next_review_at->toDateTimeString();
    $before = [];
    foreach ($elements as $element) {
        $before[$wordOf($element)] = srsUntouchable(WordRepetition::query()->where('learner_id', $learner->id)->where('word_id', $wordOf($element))->firstOrFail());
    }

    // 0: correct - nothing; 1: wrong - pulled; 2: skipped - pulled; 3: correct after a hint - pulled; 4: a typo (spell / gap) - nothing.
    $runs->answer($run, $learner, (string) Str::uuid(), $elements[0]['id'], GameKit::rightAnswers($elements[0])[0]);
    expect($nextOf($wordOf($elements[0])))->toContain('2026-11-08');

    $wrongId = (string) Str::uuid();
    $runs->answer($run, $learner, $wrongId, $elements[1]['id'], GameKit::wrongAnswer($elements[1]));
    expect($nextOf($wordOf($elements[1])))->toContain('2026-11-05 10:00:00')
        ->and(GameWordMiss::query()->where('word_id', $wordOf($elements[1]))->value('misses'))->toBe(1);
    // The same request again: the journal answers, the hook does not run twice.
    $runs->answer($run, $learner, $wrongId, $elements[1]['id'], GameKit::wrongAnswer($elements[1]));
    expect(GameWordMiss::query()->where('word_id', $wordOf($elements[1]))->value('misses'))->toBe(1)
        ->and(GameRunAttempt::query()->where('run_id', $run->id)->count())->toBe(2);

    $runs->answer($run, $learner, (string) Str::uuid(), $elements[2]['id'], ['skip' => true]);
    expect($nextOf($wordOf($elements[2])))->toContain('2026-11-05 10:00:00');

    $run->refresh();
    $run->forceFill(['snapshot' => [...$run->snapshot, 'events' => [['at' => 3, 'type' => RunEvent::Bazaar->value]]]])->save();
    $runs->hint($run, $learner, (string) Str::uuid(), $elements[3]['id']);
    $runs->answer($run, $learner, (string) Str::uuid(), $elements[3]['id'], GameKit::rightAnswers($elements[3])[0]);
    expect($nextOf($wordOf($elements[3])))->toContain('2026-11-05 10:00:00')
        ->and(GameWordMiss::query()->where('word_id', $wordOf($elements[3]))->value('misses'))->toBe(1);

    if (in_array($elements[4]['kind'], ['spell', 'gap'], true)) {
        $expected = $elements[4]['kind'] === 'spell' ? $elements[4]['expected'] : $elements[4]['expected'][0];
        $typo = substr($expected, 0, -1).($expected[-1] === 'x' ? 'y' : 'x');
        $outcome = $runs->answer($run, $learner, (string) Str::uuid(), $elements[4]['id'], ['text' => $typo]);
        expect($outcome->body['verdict'])->toBe('typo')
            ->and($nextOf($wordOf($elements[4])))->toContain('2026-11-08')
            ->and(GameWordMiss::query()->where('word_id', $wordOf($elements[4]))->exists())->toBeFalse();
    }

    $run->refresh();
    expect($run->srs_pulled)->toBe(3)
        ->and($run->state['srs_pulled'])->toEqualCanonicalizing([$wordOf($elements[1]), $wordOf($elements[2]), $wordOf($elements[3])]);
    foreach ($elements as $element) {
        expect(srsUntouchable(WordRepetition::query()->where('learner_id', $learner->id)->where('word_id', $wordOf($element))->firstOrFail()))->toBe($before[$wordOf($element)]);
    }
    expect(TrainingReview::query()->count())->toBe(0);
});

it('lets the usual grade of SM-2 after the pull follow docs/06 §3.3 from the stored interval: 10 days, EF 2.5, quality 4 -> 25 days', function (): void {
    $learner = Learner::factory()->create(['scheduler' => SchedulerKind::Sm2]);
    $rep = WordRepetition::factory()->create([
        'learner_id' => $learner->id, 'state' => RepetitionState::Review->value, 'repetitions' => 3, 'interval_days' => 10, 'easiness_factor' => 2.5,
        'last_quality' => 4, 'last_reviewed_at' => srsNow()->subDays(5), 'next_review_at' => srsNow()->addDays(5), 'is_hard' => false, 'lapses' => 0,
    ]);

    app(GameSrsAdjuster::class)->pull($learner->id, [$rep->word_id], srsNow());
    app(SchedulerResolver::class)->for($learner)->schedule($rep->refresh(), 4, srsNow());
    $rep->refresh();

    // EF' = 2.5 + (0.1 - 1 * (0.08 + 0.02)) = 2.5; interval = round(10 * 2.5) = 25; the pull did not touch the formula.
    expect((float) $rep->easiness_factor)->toBe(2.5)
        ->and($rep->interval_days)->toBe(25)
        ->and($rep->repetitions)->toBe(4)
        ->and($rep->next_review_at->toDateTimeString())->toBe('2026-11-30 10:00:00')
        ->and($rep->is_hard)->toBeFalse()
        ->and($rep->lapses)->toBe(0);
});

it('gives a pulled FSRS card the very grade of its twin that was due by itself: the same stability, difficulty and interval', function (): void {
    $learner = Learner::factory()->create(['scheduler' => SchedulerKind::Fsrs]);
    $fields = [
        'learner_id' => $learner->id, 'state' => RepetitionState::Review->value, 'repetitions' => 4, 'interval_days' => 12, 'easiness_factor' => 2.3,
        'last_quality' => 4, 'last_reviewed_at' => srsNow()->subDays(7), 'is_hard' => false, 'lapses' => 0, 'stability' => 12.0, 'difficulty' => 5.5,
        'scheduler' => SchedulerKind::Fsrs, 'scheduled_days' => 12,
    ];
    $pulled = WordRepetition::factory()->create([...$fields, 'next_review_at' => srsNow()->addDays(5)]);
    $twin = WordRepetition::factory()->create([...$fields, 'next_review_at' => srsNow()]);

    app(GameSrsAdjuster::class)->pull($learner->id, [$pulled->word_id], srsNow());
    $scheduler = app(SchedulerResolver::class)->for($learner);
    $scheduler->schedule($pulled->refresh(), 4, srsNow());
    $scheduler->schedule($twin->refresh(), 4, srsNow());
    $pulled->refresh();
    $twin->refresh();

    expect($pulled->stability)->toBe($twin->stability)
        ->and($pulled->difficulty)->toBe($twin->difficulty)
        ->and($pulled->interval_days)->toBe($twin->interval_days)
        ->and($pulled->next_review_at->toDateTimeString())->toBe($twin->next_review_at->toDateTimeString())
        ->and($pulled->is_hard)->toBeFalse()
        // The pull did not add a lapse: the grade after it is a recall, not a relearning.
        ->and($pulled->lapses)->toBe(0)
        ->and($pulled->interval_days)->toBeGreaterThan(12);
});
