<?php

declare(strict_types=1);

use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Enums\SchedulerKind;
use App\Domain\Learning\Services\FsrsScheduler;
use App\Domain\Learning\Services\SchedulerResolver;
use App\Domain\Learning\Services\Sm2Scheduler;
use App\Domain\Learning\Services\SpacedRepetitionEngine;
use App\Domain\Learning\Support\FsrsParameters;
use App\Domain\Learning\Support\LearningPolicy;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * FSRS-5 (docs/06 §9). The expected stabilities / difficulties / intervals below were computed
 * INDEPENDENTLY of the PHP code, by hand-coding the FSRS-5 formulas in a Python script with the
 * official default parameters (open-spaced-repetition fsrs-rs DEFAULT_PARAMETERS v1.x / ts-fsrs 4.x
 * default_w, see FsrsParameters::DEFAULT_WEIGHTS):
 *
 *   R(t,S) = (1 + 19/81 · t/S)^-0.5
 *   D0(G)  = w4 − e^(w5(G−1)) + 1
 *   D'     = w7·D0(4) + (1−w7)·(D − w6(G−3)(10−D)/9)
 *   S'ok   = S·(e^w8 (11−D) S^−w9 (e^(w10(1−R)) − 1) [w15 Hard] [w16 Easy] + 1)
 *   S'fail = min(S, w11 D^−w12 ((S+1)^w13 − 1) e^(w14(1−R)))
 *   I      = round(S'), because I(0.9, S) = S/FACTOR · (0.9^-2 − 1) = S
 */

const FSRS_DELTA = 1e-5;

function fsrsPolicy(int $maxIntervalDays = 3650): LearningPolicy
{
    return new LearningPolicy(
        learningStepsMinutes: [1, 10],
        relearningStepsMinutes: [10],
        graduatingIntervalDays: 1,
        easyIntervalDays: 4,
        learnAheadMinutes: 20,
        maxIntervalDays: $maxIntervalDays,
    );
}

function fsrs(?LearningPolicy $policy = null): FsrsScheduler
{
    return new FsrsScheduler($policy ?? fsrsPolicy(), FsrsParameters::defaults());
}

function fsrsCard(array $overrides = []): WordRepetition
{
    $learner = Learner::factory()->create();
    $stage = Stage::factory()->create();
    $lesson = Lesson::factory()->for($stage)->create();
    $word = Word::factory()->for($lesson)->create();

    return WordRepetition::factory()->create(array_merge([
        'learner_id' => $learner->id,
        'word_id' => $word->id,
        'repetitions' => 0,
        'interval_days' => 0,
        'easiness_factor' => SpacedRepetitionEngine::DEFAULT_EF,
        'last_quality' => null,
        'last_reviewed_at' => null,
        'is_hard' => false,
        'state' => RepetitionState::New->value,
        'learning_step' => 0,
        'lapses' => 0,
        'stability' => null,
        'difficulty' => null,
    ], $overrides));
}

// --- quality → rating, seeds -----------------------------------------------------

it('maps API quality 0..5 to FSRS ratings Again / Hard / Good / Easy', function (): void {
    expect(FsrsScheduler::ratingFor(0))->toBe(FsrsScheduler::RATING_AGAIN)
        ->and(FsrsScheduler::ratingFor(1))->toBe(FsrsScheduler::RATING_AGAIN)
        ->and(FsrsScheduler::ratingFor(2))->toBe(FsrsScheduler::RATING_AGAIN)
        ->and(FsrsScheduler::ratingFor(3))->toBe(FsrsScheduler::RATING_HARD)
        ->and(FsrsScheduler::ratingFor(4))->toBe(FsrsScheduler::RATING_GOOD)
        ->and(FsrsScheduler::ratingFor(5))->toBe(FsrsScheduler::RATING_EASY);
});

it('seeds stability and difficulty from an SM-2 card by the documented formulas', function (): void {
    expect(FsrsScheduler::stabilityFromSm2(0))->toBe(1.0)
        ->and(FsrsScheduler::stabilityFromSm2(1))->toBe(1.0)
        ->and(FsrsScheduler::stabilityFromSm2(17))->toBe(17.0)
        ->and(FsrsScheduler::difficultyFromEasinessFactor(2.5))->toBe(5.0)
        ->and(FsrsScheduler::difficultyFromEasinessFactor(1.3))->toEqualWithDelta(9.0, 1e-9)
        ->and(FsrsScheduler::difficultyFromEasinessFactor(2.0))->toEqualWithDelta(6.666666667, 1e-6)
        ->and(FsrsScheduler::difficultyFromEasinessFactor(3.7))->toBe(1.0)
        ->and(FsrsScheduler::difficultyFromEasinessFactor(0.5))->toBe(10.0);
});

it('has the official FSRS-5 initial stability and difficulty per rating', function (): void {
    $s = fsrs();

    expect($s->initialStability(1))->toBe(0.40255)
        ->and($s->initialStability(2))->toBe(1.18385)
        ->and($s->initialStability(3))->toBe(3.173)
        ->and($s->initialStability(4))->toBe(15.69105)
        ->and($s->initialDifficulty(1))->toEqualWithDelta(7.1949, FSRS_DELTA)
        ->and($s->initialDifficulty(2))->toEqualWithDelta(6.488305, FSRS_DELTA)
        ->and($s->initialDifficulty(3))->toEqualWithDelta(5.282434, FSRS_DELTA)
        ->and($s->initialDifficulty(4))->toEqualWithDelta(3.224502, FSRS_DELTA);
});

it('computes the forgetting curve and the interval for the desired retention (I(0.9, S) = S)', function (): void {
    $s = fsrs();

    expect($s->retrievability(0.0, 5.0))->toBe(1.0)
        ->and($s->retrievability(1.0, 3.173))->toEqualWithDelta(0.964968, FSRS_DELTA)
        ->and($s->retrievability(10.0, 10.0))->toEqualWithDelta(0.9, 1e-9)
        ->and($s->intervalFor(10.0))->toEqualWithDelta(10.0, 1e-9)
        ->and($s->intervalFor(10.0, 0.8))->toBeGreaterThan(10.0)
        ->and($s->intervalFor(10.0, 0.95))->toBeLessThan(10.0);
});

it('rejects a quality outside 0..5', function (): void {
    fsrs()->schedule(fsrsCard(), 6, CarbonImmutable::now());
})->throws(InvalidArgumentException::class);

// --- reference sequence A: fresh card under FSRS ------------------------------------

it('follows the reference sequence: learn → Good, Easy, Hard, Again (lapse), relearn → Good', function (): void {
    $s = fsrs();
    $t0 = CarbonImmutable::parse('2026-10-01 10:00:00');
    $card = fsrsCard();

    // A1: new card, Good → learning step 1 (10 min), no memory yet (shared steps).
    $s->schedule($card, 4, $t0);
    expect($card->state)->toBe(RepetitionState::Learning)
        ->and($card->learning_step)->toBe(1)
        ->and($card->stability)->toBeNull()
        ->and($card->difficulty)->toBeNull()
        ->and($card->scheduler)->toBe(SchedulerKind::Fsrs)
        ->and($card->next_review_at->equalTo($t0->addMinutes(10)))->toBeTrue();

    // A2: last step, Good → graduates with the SHARED graduating interval (1 day) and S0(Good) / D0(Good).
    $t = $t0->addMinutes(10);
    $s->schedule($card, 4, $t);
    expect($card->state)->toBe(RepetitionState::Review)
        ->and($card->repetitions)->toBe(1)
        ->and($card->interval_days)->toBe(1)
        ->and($card->scheduled_days)->toBe(1)
        ->and($card->stability)->toEqualWithDelta(3.173, FSRS_DELTA)
        ->and($card->difficulty)->toEqualWithDelta(5.282434, FSRS_DELTA)
        ->and($card->next_review_at->equalTo($t->addDay()))->toBeTrue();

    // A3: one day later, Good. R = 0.964968, S' = 5.869142, D' = 5.272968, I = 6.
    $t = $t->addDay();
    $s->schedule($card, 4, $t);
    expect($card->stability)->toEqualWithDelta(5.869142, FSRS_DELTA)
        ->and($card->difficulty)->toEqualWithDelta(5.272968, FSRS_DELTA)
        ->and($card->interval_days)->toBe(6)
        ->and($card->scheduled_days)->toBe(6)
        ->and($card->repetitions)->toBe(2)
        ->and($card->is_hard)->toBeFalse()
        ->and($card->next_review_at->equalTo($t->addDays(6)))->toBeTrue()
        ->and((float) $card->easiness_factor)->toBe(2.5);

    // A4: 6 days later, Easy. R = 0.898100, S' = 47.656750 (w16 bonus), D' = 4.500034, I = 48.
    $t = $t->addDays(6);
    $s->schedule($card, 5, $t);
    expect($card->stability)->toEqualWithDelta(47.656750, FSRS_DELTA)
        ->and($card->difficulty)->toEqualWithDelta(4.500034, FSRS_DELTA)
        ->and($card->interval_days)->toBe(48)
        ->and($card->repetitions)->toBe(3);

    // A5: 48 days later, Hard. R = 0.899385, S' = 70.579580 (w15 penalty), D' = 5.382522, I = 71.
    $t = $t->addDays(48);
    $s->schedule($card, 3, $t);
    expect($card->stability)->toEqualWithDelta(70.579580, FSRS_DELTA)
        ->and($card->difficulty)->toEqualWithDelta(5.382522, FSRS_DELTA)
        ->and($card->interval_days)->toBe(71)
        ->and($card->repetitions)->toBe(4);

    // A6: 71 days later, Again → lapse. S' = 5.144487 (< S), D' = 6.864227; shared relearning step (10 min).
    $t = $t->addDays(71);
    $s->schedule($card, 1, $t);
    expect($card->state)->toBe(RepetitionState::Relearning)
        ->and($card->learning_step)->toBe(0)
        ->and($card->stability)->toEqualWithDelta(5.144487, FSRS_DELTA)
        ->and($card->difficulty)->toEqualWithDelta(6.864227, FSRS_DELTA)
        ->and($card->lapses)->toBe(1)
        ->and($card->repetitions)->toBe(0)
        ->and($card->interval_days)->toBe(0)
        ->and($card->scheduled_days)->toBe(0)
        ->and($card->is_hard)->toBeTrue()
        ->and($card->next_review_at->equalTo($t->addMinutes(10)))->toBeTrue()
        ->and((float) $card->easiness_factor)->toBe(2.5);

    // A7: relearning step passed → graduates with the shared 1-day interval, post-lapse memory kept.
    $t = $t->addMinutes(10);
    $s->schedule($card, 4, $t);
    expect($card->state)->toBe(RepetitionState::Review)
        ->and($card->interval_days)->toBe(1)
        ->and($card->repetitions)->toBe(1)
        ->and($card->is_hard)->toBeTrue()
        ->and($card->stability)->toEqualWithDelta(5.144487, FSRS_DELTA)
        ->and($card->difficulty)->toEqualWithDelta(6.864227, FSRS_DELTA);

    // A8: a day later, Good. R = 0.977953, S' = 7.010581, D' = 6.847485, I = 7; is_hard cleared.
    $t = $t->addDay();
    $s->schedule($card, 4, $t);
    expect($card->stability)->toEqualWithDelta(7.010581, FSRS_DELTA)
        ->and($card->difficulty)->toEqualWithDelta(6.847485, FSRS_DELTA)
        ->and($card->interval_days)->toBe(7)
        ->and($card->is_hard)->toBeFalse()
        ->and($card->last_quality)->toBe(4)
        ->and($card->last_reviewed_at?->equalTo($t))->toBeTrue();

    // Everything above was persisted.
    // decimal(9,4): the stored value is rounded to 4 places.
    $fresh = $card->fresh();
    expect($fresh?->stability)->toEqualWithDelta(7.010581, 1e-4)
        ->and($fresh?->scheduler)->toBe(SchedulerKind::Fsrs)
        ->and($fresh?->interval_days)->toBe(7);
});

// --- reference B: a card scheduled by SM-2 so far ----------------------------------

it('initialises an SM-2 card at its first FSRS review from interval_days and EF (reference B)', function (): void {
    $t0 = CarbonImmutable::parse('2026-10-01 10:00:00');
    $card = fsrsCard([
        'state' => RepetitionState::Review->value,
        'repetitions' => 3,
        'interval_days' => 10,
        'easiness_factor' => 2.5,
        'last_reviewed_at' => $t0,
        'next_review_at' => $t0->addDays(10),
        'scheduler' => SchedulerKind::Sm2->value,
    ]);

    // Seeds: S = 10, D = 5; after exactly 10 days R = 0.9 → S' = 32.954264, D' = 4.991833, I = 33.
    fsrs()->schedule($card, 4, $t0->addDays(10));

    expect($card->stability)->toEqualWithDelta(32.954264, FSRS_DELTA)
        ->and($card->difficulty)->toEqualWithDelta(4.991833, FSRS_DELTA)
        ->and($card->interval_days)->toBe(33)
        ->and($card->repetitions)->toBe(4)
        ->and($card->scheduler)->toBe(SchedulerKind::Fsrs)
        ->and((float) $card->easiness_factor)->toBe(2.5);
});

it('uses the SM-2 seed for a card that lapsed under SM-2 and graduates from relearning under FSRS', function (): void {
    $t0 = CarbonImmutable::parse('2026-10-01 10:00:00');
    $card = fsrsCard([
        'state' => RepetitionState::Relearning->value,
        'learning_step' => 0,
        'lapses' => 1,
        'is_hard' => true,
        'easiness_factor' => 2.0,
        'next_review_at' => $t0,
    ]);

    fsrs()->schedule($card, 4, $t0);

    // Graduated with the shared 1-day interval → S = max(1, 1) = 1, D = 5 + (2.5 − 2.0) · 4/1.2 = 6.667.
    expect($card->state)->toBe(RepetitionState::Review)
        ->and($card->interval_days)->toBe(1)
        ->and($card->stability)->toBe(1.0)
        ->and($card->difficulty)->toEqualWithDelta(6.666667, FSRS_DELTA);
});

// --- edge cases ------------------------------------------------------------------

it('counts an overdue answer with the real elapsed time (lower retrievability, larger growth)', function (): void {
    $t0 = CarbonImmutable::parse('2026-10-01 10:00:00');
    $card = fsrsCard([
        'state' => RepetitionState::Review->value,
        'repetitions' => 2,
        'interval_days' => 5,
        'stability' => 5.0,
        'difficulty' => 5.0,
        'last_reviewed_at' => $t0,
        'next_review_at' => $t0->addDays(5),
    ]);

    // Answered 15 days after the last review: R = 0.766131, S' = 36.272146, I = 36.
    fsrs()->schedule($card, 4, $t0->addDays(15));

    expect($card->stability)->toEqualWithDelta(36.272146, FSRS_DELTA)
        ->and($card->interval_days)->toBe(36);
});

it('does not grow stability on an answer given at the same moment (R = 1)', function (): void {
    $t0 = CarbonImmutable::parse('2026-10-01 10:00:00');
    $card = fsrsCard([
        'state' => RepetitionState::Review->value,
        'repetitions' => 2,
        'interval_days' => 5,
        'stability' => 5.0,
        'difficulty' => 5.0,
        'last_reviewed_at' => $t0,
        'next_review_at' => $t0,
    ]);

    fsrs()->schedule($card, 4, $t0);

    expect($card->stability)->toEqualWithDelta(5.0, 1e-9)
        ->and($card->interval_days)->toBe(5);
});

it('caps the interval at max_interval_days while keeping the real stability', function (): void {
    $t0 = CarbonImmutable::parse('2026-10-01 10:00:00');
    $card = fsrsCard([
        'state' => RepetitionState::Review->value,
        'repetitions' => 9,
        'interval_days' => 3000,
        'stability' => 3000.0,
        'difficulty' => 3.0,
        'last_reviewed_at' => $t0,
        'next_review_at' => $t0->addDays(3000),
    ]);

    // Easy after 3000 days: S' = 16908.895602 → I would be 16909 days; the policy ceiling wins.
    fsrs(fsrsPolicy(maxIntervalDays: 3650))->schedule($card, 5, $t0->addDays(3000));

    expect($card->stability)->toEqualWithDelta(16908.895602, 1e-3)
        ->and($card->interval_days)->toBe(3650)
        ->and($card->scheduled_days)->toBe(3650)
        ->and($card->next_review_at->equalTo($t0->addDays(3000)->addDays(3650)))->toBeTrue();
});

it('never schedules less than one day for a review card and keeps difficulty within 1..10', function (): void {
    $s = fsrs();
    $t0 = CarbonImmutable::parse('2026-10-01 10:00:00');
    $card = fsrsCard([
        'state' => RepetitionState::Review->value,
        'repetitions' => 1,
        'interval_days' => 1,
        'stability' => 0.05,
        'difficulty' => 9.9,
        'last_reviewed_at' => $t0,
        'next_review_at' => $t0->addDay(),
    ]);

    $s->schedule($card, 3, $t0->addDay());
    expect($card->interval_days)->toBe(1)
        ->and($card->difficulty)->toBeLessThanOrEqual(10.0)
        ->and($card->difficulty)->toBeGreaterThanOrEqual(1.0);

    for ($i = 0; $i < 30; $i++) {
        $s->schedule($card, 5, $card->next_review_at);
    }
    expect($card->difficulty)->toBeGreaterThanOrEqual(1.0)
        ->and($card->stability)->toBeLessThanOrEqual(FsrsScheduler::MAX_STABILITY);
});

it('fails inside learning steps without touching the memory columns (shared machine)', function (): void {
    $t0 = CarbonImmutable::parse('2026-10-01 10:00:00');
    $card = fsrsCard();

    fsrs()->schedule($card, 2, $t0);

    expect($card->state)->toBe(RepetitionState::Learning)
        ->and($card->learning_step)->toBe(0)
        ->and($card->is_hard)->toBeTrue()
        ->and($card->stability)->toBeNull()
        ->and($card->difficulty)->toBeNull()
        ->and($card->next_review_at->equalTo($t0->addMinute()))->toBeTrue();

    // Easy inside learning: immediate graduation with the shared easy interval and S0 / D0 of Easy.
    fsrs()->schedule($card, 5, $t0->addMinute());
    expect($card->state)->toBe(RepetitionState::Review)
        ->and($card->interval_days)->toBe(4)
        ->and($card->stability)->toEqualWithDelta(15.69105, FSRS_DELTA)
        ->and($card->difficulty)->toEqualWithDelta(3.224502, FSRS_DELTA);
});

// --- SM-2 arm and parameters -------------------------------------------------------------

it('keeps the SM-2 arm bit-for-bit: Sm2Scheduler equals SpacedRepetitionEngine::applyReview', function (): void {
    $policy = fsrsPolicy();
    $engine = new SpacedRepetitionEngine($policy);
    $t0 = CarbonImmutable::parse('2026-10-01 10:00:00');

    $sequence = [[4, 0], [4, 10], [4, 1440], [3, 6 * 1440], [5, 14 * 1440], [1, 40 * 1440], [4, 10], [4, 1440]];
    $viaEngine = fsrsCard();
    $viaScheduler = fsrsCard();
    $t = $t0;
    foreach ($sequence as [$q, $minutes]) {
        $t = $t->addMinutes($minutes);
        $engine->applyReview($viaEngine, $q, $t);
        (new Sm2Scheduler($engine))->schedule($viaScheduler, $q, $t);

        $strip = fn (WordRepetition $r): array => collect($r->getAttributes())->except(['id', 'learner_id', 'word_id', 'created_at', 'updated_at'])->all();
        expect($strip($viaScheduler))->toBe($strip($viaEngine));
    }

    expect($viaEngine->scheduler)->toBe(SchedulerKind::Sm2)
        ->and($viaEngine->stability)->toBeNull()
        ->and($viaEngine->difficulty)->toBeNull();
});

it('validates the FSRS parameters (19 weights, retention in (0,1), rollout 0..100)', function (): void {
    expect(fn () => new FsrsParameters(array_fill(0, 18, 1.0)))->toThrow(InvalidArgumentException::class, '19 weights')
        ->and(fn () => new FsrsParameters(array_fill(0, 19, -1.0)))->toThrow(InvalidArgumentException::class, 'w[0]')
        ->and(fn () => new FsrsParameters(FsrsParameters::DEFAULT_WEIGHTS, 1.0))->toThrow(InvalidArgumentException::class, 'desired_retention')
        ->and(fn () => new FsrsParameters(FsrsParameters::DEFAULT_WEIGHTS, 0.9, 101))->toThrow(InvalidArgumentException::class, 'rollout_percent')
        ->and(fn () => FsrsParameters::fromConfig(['weights' => '1,2,x']))->toThrow(InvalidArgumentException::class);

    $fromString = FsrsParameters::fromConfig(['weights' => implode(',', FsrsParameters::DEFAULT_WEIGHTS), 'desired_retention' => '0.85', 'rollout_percent' => '15']);
    expect($fromString->weights)->toBe(FsrsParameters::DEFAULT_WEIGHTS)
        ->and($fromString->desiredRetention)->toBe(0.85)
        ->and($fromString->rolloutPercent)->toBe(15)
        ->and(FsrsParameters::fromConfig([])->weights)->toBe(FsrsParameters::DEFAULT_WEIGHTS)
        ->and(FsrsParameters::fromConfig(['weights' => ''])->weights)->toBe(FsrsParameters::DEFAULT_WEIGHTS)
        ->and(count(FsrsParameters::DEFAULT_WEIGHTS))->toBe(19);
});

// --- resolver / bucket --------------------------------------------------------------

function resolverWithRollout(int $percent): SchedulerResolver
{
    $policy = fsrsPolicy();
    $engine = new SpacedRepetitionEngine($policy);
    $params = new FsrsParameters(FsrsParameters::DEFAULT_WEIGHTS, 0.9, $percent);

    return new SchedulerResolver(new Sm2Scheduler($engine), new FsrsScheduler($policy, $params), $params);
}

it('buckets by a stable hash of the Telegram id: 0% → always SM-2, 100% → always FSRS, same id → same arm', function (): void {
    $ids = [1, 7, 42, 123456789, 987654321012, 5000000001];

    foreach ($ids as $id) {
        expect(resolverWithRollout(0)->bucketFor($id))->toBe(SchedulerKind::Sm2)
            ->and(resolverWithRollout(100)->bucketFor($id))->toBe(SchedulerKind::Fsrs);
    }

    $half = resolverWithRollout(50);
    $first = array_map(fn (int $id): SchedulerKind => $half->bucketFor($id), $ids);
    $second = array_map(fn (int $id): SchedulerKind => $half->bucketFor($id), $ids);
    expect($first)->toBe($second);

    // The bucket is crc32(id) % 100 < percent, and it is monotonic in the percentage.
    foreach ($ids as $id) {
        $slot = crc32((string) $id) % 100;
        expect(resolverWithRollout($slot)->bucketFor($id))->toBe(SchedulerKind::Sm2)
            ->and(resolverWithRollout($slot + 1)->bucketFor($id))->toBe(SchedulerKind::Fsrs);
    }
});

it('resolves the learner bucket, falling back to the config default and to SM-2 for an unknown value', function (): void {
    $resolver = resolverWithRollout(0);

    config(['learning.algorithm' => 'sm2']);
    expect($resolver->kindFor(new Learner(['scheduler' => SchedulerKind::Fsrs])))->toBe(SchedulerKind::Fsrs)
        ->and($resolver->for(new Learner(['scheduler' => SchedulerKind::Fsrs])))->toBeInstanceOf(FsrsScheduler::class)
        ->and($resolver->kindFor(new Learner))->toBe(SchedulerKind::Sm2)
        ->and($resolver->for(new Learner))->toBeInstanceOf(Sm2Scheduler::class);

    config(['learning.algorithm' => 'fsrs']);
    expect($resolver->kindFor(new Learner))->toBe(SchedulerKind::Fsrs)
        ->and($resolver->kindFor(new Learner(['scheduler' => SchedulerKind::Sm2])))->toBe(SchedulerKind::Sm2);

    config(['learning.algorithm' => 'bogus']);
    expect($resolver->defaultKind())->toBe(SchedulerKind::Sm2);
});
