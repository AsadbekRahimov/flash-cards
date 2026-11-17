<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\RetentionReportService;
use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Game\Services\Runs\GameSrsAdjuster;
use App\Domain\Learners\Services\LearnerClock;
use App\Domain\Learning\DTO\HardRoundAvailability;
use App\Domain\Learning\DTO\LearnerExerciseSettings;
use App\Domain\Learning\Enums\DoneReason;
use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Exceptions\HardRoundException;
use App\Domain\Learning\Services\CardPicker;
use App\Domain\Learning\Services\ExerciseSelector;
use App\Domain\Learning\Services\GapFillBuilder;
use App\Domain\Learning\Services\HardRoundService;
use App\Domain\Learning\Services\ReviewPull;
use App\Domain\Learning\Services\TrainingSessionService;
use App\Domain\Learning\Support\HardRoundPolicy;
use App\Domain\Learning\Support\LadderPolicy;
use App\Domain\Learning\Support\NewCardBudget;
use App\Domain\Learning\Support\TrainingScope;
use App\Models\Course;
use App\Models\Learner;
use App\Models\ProductEvent;
use App\Models\TrainingSession;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HotPathFixtures as Fx;

uses(RefreshDatabase::class);

/*
 * The round of hard words (`hard_review`, docs/06 §14): the policy, the candidates, the availability, the opening (the pull, the day's
 * limit, the stale round), the scope, the exercises without flash, the close of an expired round, the retention report. The flow over
 * HTTP is HardRoundFlowTest, the race is HardRoundConcurrencyTest.
 */

// 2026-11-16 10:00 in Tashkent: the learner's day began 2026-11-15 19:00 UTC and ends 2026-11-16 19:00 UTC.
const HARD_ROUND_NOW = '2026-11-16 05:00:00';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse(HARD_ROUND_NOW, 'UTC'));
    // The config as shipped, whatever a local .env says.
    config(['learning.hard_round' => ['enabled' => true, 'words' => 10, 'min_words' => 8, 'per_day' => 1, 'lapses_from' => 2]]);
});

/** @return array{learner: Learner, words: list<int>, course: Course} a Tashkent learner and a course of `$words` active words */
function hardRoundWorld(int $words = 30, array $learner = []): array
{
    ['course' => $course, 'words' => $ids] = Fx::course([1 => [1 => $words]]);

    return [
        'learner' => Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent', 'active_course_id' => $course->id, 'daily_goal' => 10, ...$learner]),
        'words' => $ids['1.1']['active'],
        'course' => $course,
    ];
}

/** A released card, hard by its lapses (2), due in five days, last graded three days ago; `$attributes` override. */
function hardRoundRow(Learner $learner, int $wordId, array $attributes = []): WordRepetition
{
    return WordRepetition::factory()->create(array_replace([
        'learner_id' => $learner->id, 'word_id' => $wordId, 'state' => RepetitionState::Review->value,
        'repetitions' => 4, 'interval_days' => 10, 'easiness_factor' => 2.5, 'lapses' => 2, 'is_hard' => false,
        'next_review_at' => now()->addDays(5), 'last_reviewed_at' => now()->subDays(3), 'last_quality' => 4,
        'introduced_mode' => 'daily_review', 'served_ahead_token' => null, 'meta' => [],
    ], $attributes));
}

/**
 * @param  list<int>  $wordIds
 * @param  array<string, mixed>  $attributes
 * @return list<WordRepetition>
 */
function hardRoundRows(Learner $learner, array $wordIds, int $count, array $attributes = []): array
{
    $rows = [];
    foreach (array_slice($wordIds, 0, $count) as $wordId) {
        $rows[] = hardRoundRow($learner, $wordId, $attributes);
    }

    return $rows;
}

function hardRoundService(): HardRoundService
{
    return app(HardRoundService::class);
}

function hardRoundWindowStart(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-11-15 19:00:00', 'UTC');
}

// --- the policy -------------------------------------------------------------------------------------------

it('reads the policy from the config as shipped', function (): void {
    $policy = HardRoundPolicy::fromConfig();

    expect($policy->enabled)->toBeTrue()->and($policy->words)->toBe(10)->and($policy->minWords)->toBe(8)
        ->and($policy->perDay)->toBe(1)->and($policy->lapsesFrom)->toBe(2);
});

it('keeps the numbers of the policy sane: at least one word, min_words within 1..words, one round a day, one lapse', function (array $config, array $expected): void {
    config(['learning.hard_round' => $config]);
    $policy = HardRoundPolicy::fromConfig();

    expect([$policy->enabled, $policy->words, $policy->minWords, $policy->perDay, $policy->lapsesFrom])->toBe($expected);
})->with([
    'zeros' => [['enabled' => true, 'words' => 0, 'min_words' => 0, 'per_day' => 0, 'lapses_from' => 0], [true, 1, 1, 1, 1]],
    'negatives' => [['enabled' => false, 'words' => -5, 'min_words' => -1, 'per_day' => -2, 'lapses_from' => -3], [false, 1, 1, 1, 1]],
    'min above words' => [['enabled' => true, 'words' => 6, 'min_words' => 9, 'per_day' => 3, 'lapses_from' => 4], [true, 6, 6, 3, 4]],
    'empty config' => [[], [true, 10, 8, 1, 2]],
]);

// --- the candidates ---------------------------------------------------------------------------------------

it('takes released words that are hard or failed enough, and nothing else', function (): void {
    ['learner' => $learner, 'words' => $w] = hardRoundWorld();
    hardRoundRow($learner, $w[0]);                                                                            // review, 2 lapses: in
    hardRoundRow($learner, $w[1], ['state' => RepetitionState::Relearning->value, 'lapses' => 3]);           // relearning: in
    hardRoundRow($learner, $w[2], ['lapses' => 0, 'is_hard' => true]);                                        // hard without a lapse (the game's pull): in
    hardRoundRow($learner, $w[3], ['lapses' => 1]);                                                           // below lapses_from, not hard: out
    hardRoundRow($learner, $w[4], ['lapses' => 0]);                                                           // never failed: out
    hardRoundRow($learner, $w[5], ['state' => RepetitionState::New->value, 'lapses' => 0, 'is_hard' => true, 'repetitions' => 0]); // new: out
    hardRoundRow($learner, $w[6], ['state' => RepetitionState::Learning->value, 'lapses' => 3]);             // learning: out
    hardRoundRow($learner, $w[7], ['lapses' => 4]);                                                           // in
    Word::query()->whereKey($w[7])->update(['is_active' => false]);                                           // ... but withdrawn: out

    $ids = hardRoundService()->candidates((int) $learner->id, hardRoundWindowStart(), 50);

    expect($ids)->toEqualCanonicalizing([$w[0], $w[1], $w[2]]);
});

it('orders the candidates hardest first: is_hard, lapses, ease, the longest overdue, id; and honours the limit', function (): void {
    ['learner' => $learner, 'words' => $w] = hardRoundWorld();
    hardRoundRow($learner, $w[0], ['lapses' => 3, 'easiness_factor' => 1.8, 'next_review_at' => now()->addDays(2)]); // D
    hardRoundRow($learner, $w[1], ['lapses' => 3, 'easiness_factor' => 1.8, 'next_review_at' => now()->addDay()]);  // E: earlier than D
    hardRoundRow($learner, $w[2], ['lapses' => 3, 'easiness_factor' => 1.5]);                                      // C: the lowest ease
    hardRoundRow($learner, $w[3], ['lapses' => 5, 'easiness_factor' => 2.5]);                                      // B: the most lapses
    hardRoundRow($learner, $w[4], ['lapses' => 0, 'is_hard' => true]);                                             // A: hard first
    hardRoundRow($learner, $w[5], ['lapses' => 2, 'easiness_factor' => 1.3]);                                      // F: fewest lapses

    $service = hardRoundService();

    expect($service->candidates((int) $learner->id, hardRoundWindowStart(), 50))->toBe([$w[4], $w[3], $w[2], $w[1], $w[0], $w[5]])
        ->and($service->candidates((int) $learner->id, hardRoundWindowStart(), 3))->toBe([$w[4], $w[3], $w[2]]);
});

it('leaves out a word graded today and takes one graded a minute before the day began (Tashkent)', function (): void {
    ['learner' => $learner, 'words' => $w] = hardRoundWorld();
    hardRoundRow($learner, $w[0], ['last_reviewed_at' => '2026-11-15 18:59:00']); // 23:59 yesterday, local: in
    hardRoundRow($learner, $w[1], ['last_reviewed_at' => '2026-11-15 19:01:00']); // 00:01 today, local: out
    hardRoundRow($learner, $w[2], ['last_reviewed_at' => null]);                  // never graded: in
    hardRoundRow($learner, $w[3], ['last_reviewed_at' => '2026-11-16 04:00:00']); // this morning: out

    expect(hardRoundService()->candidates((int) $learner->id, hardRoundWindowStart(), 50))->toEqualCanonicalizing([$w[0], $w[2]]);
});

it('reads "today" over the window a change of zone carries over, not the new zone\'s own midnight', function (): void {
    ['learner' => $learner, 'words' => $w] = hardRoundWorld();
    // Graded at 14:00 UTC on the 15th: 19:00 yesterday in Tashkent, 03:00 of the 16th in Auckland.
    hardRoundRow($learner, $w[0], ['last_reviewed_at' => '2026-11-15 14:00:00']);
    hardRoundRow($learner, $w[1], ['last_reviewed_at' => '2026-11-15 20:00:00']); // 01:00 of the 16th in Tashkent: today there too

    app(LearnerClock::class)->carryDayAcrossTimezoneChange($learner);
    $learner->timezone = 'Pacific/Auckland';
    $learner->save();

    $window = hardRoundService()->window($learner->fresh() ?? $learner, now()->toImmutable());
    expect($window->startUtc->toDateTimeString())->toBe('2026-11-15 19:00:00')
        ->and(hardRoundService()->candidates((int) $learner->id, $window->startUtc, 50))->toBe([$w[0]]);
});

// --- the availability -------------------------------------------------------------------------------------

it('is not enough with seven candidates and available with eight, the size being what opens', function (): void {
    ['learner' => $learner, 'words' => $w] = hardRoundWorld();
    hardRoundRows($learner, $w, 7);

    $seven = hardRoundService()->availability($learner);
    expect($seven->available)->toBeFalse()->and($seven->reason)->toBe(HardRoundAvailability::REASON_NOT_ENOUGH)->and($seven->words)->toBe(0);

    hardRoundRow($learner, $w[7]);
    $eight = hardRoundService()->availability($learner);
    expect($eight->available)->toBeTrue()->and($eight->reason)->toBeNull()->and($eight->words)->toBe(8)->and($eight->openSessionId)->toBeNull();

    hardRoundRows($learner, array_slice($w, 8), 6);
    expect(hardRoundService()->availability($learner)->words)->toBe(10);
});

it('says played_today after the round was opened and after it was closed, with the end of the day window', function (): void {
    ['learner' => $learner, 'words' => $w] = hardRoundWorld();
    hardRoundRows($learner, $w, 10);

    $session = hardRoundService()->open($learner, 'self');
    $live = hardRoundService()->availability($learner);
    expect($live->available)->toBeTrue()->and($live->openSessionId)->toBe((int) $session->id)->and($live->words)->toBe(10);

    $session->update(['status' => 'closed', 'ended_at' => now(), 'done_reason' => 'finished']);
    $closed = hardRoundService()->availability($learner);

    expect($closed->available)->toBeFalse()->and($closed->reason)->toBe(HardRoundAvailability::REASON_PLAYED_TODAY)
        ->and($closed->resetsAt?->utc()->toDateTimeString())->toBe('2026-11-16 19:00:00')
        ->and($closed->toArray()['resets_at'])->toBe('2026-11-16T19:00:00.000Z');
});

it('counts a round begun a minute before the day only as yesterday\'s, one begun a minute after as today\'s', function (): void {
    ['learner' => $learner, 'words' => $w] = hardRoundWorld();
    hardRoundRows($learner, $w, 10);
    $make = fn (string $startedAt) => TrainingSession::factory()->personal($learner, TrainingSession::MODE_HARD_REVIEW)->closed()->create([
        'lesson_id' => null, 'started_at' => $startedAt, 'ended_at' => $startedAt, 'expires_at' => null, 'meta' => ['hard_words' => []],
    ]);

    $yesterday = $make('2026-11-15 18:59:00');
    expect(hardRoundService()->availability($learner)->available)->toBeTrue();

    $yesterday->delete();
    $make('2026-11-15 19:01:00');
    expect(hardRoundService()->availability($learner)->reason)->toBe(HardRoundAvailability::REASON_PLAYED_TODAY);
});

it('is not available while the learner switched meaning and reverse off: it would come down to flash cards', function (): void {
    ['learner' => $learner, 'words' => $w] = hardRoundWorld(learner: ['meta' => ['exercises' => ['meaning' => false, 'reverse' => false]]]);
    hardRoundRows($learner, $w, 10);

    expect(hardRoundService()->availability($learner)->reason)->toBe(HardRoundAvailability::REASON_NO_OBJECTIVE_EXERCISE);

    // One of the two is enough.
    $learner->update(['meta' => ['exercises' => ['meaning' => false, 'reverse' => true]]]);
    expect(hardRoundService()->availability($learner->fresh() ?? $learner)->available)->toBeTrue();
});

it('is switched off by the flag: disabled, no opening, an open round is still reachable through its own session', function (): void {
    ['learner' => $learner, 'words' => $w] = hardRoundWorld();
    hardRoundRows($learner, $w, 10);
    $session = hardRoundService()->open($learner, 'self');

    config(['learning.hard_round.enabled' => false]);
    $off = hardRoundService()->availability($learner);

    expect(hardRoundService()->enabled())->toBeFalse()
        ->and($off->available)->toBeFalse()->and($off->reason)->toBe(HardRoundAvailability::REASON_DISABLED);

    $session->update(['status' => 'closed', 'ended_at' => now(), 'done_reason' => 'expired']);
    try {
        hardRoundService()->open($learner, 'self');
        $this->fail('The round must not open while it is switched off.');
    } catch (HardRoundException $e) {
        expect($e->reason())->toBe('disabled')->and($e->details())->toBe(['reason' => 'disabled', 'resets_at' => null]);
    }
});

// --- the opening ------------------------------------------------------------------------------------------

it('fixes the first ten of twelve in meta, pulls only the rows due later and leaves is_hard as it was', function (): void {
    ['learner' => $learner, 'words' => $w] = hardRoundWorld();
    // Lapses 2..13: the order is by lapses, descending - the last two words are the two left out.
    foreach (array_slice($w, 0, 12) as $i => $wordId) {
        hardRoundRow($learner, $wordId, ['lapses' => 2 + $i]);
    }
    hardRoundRow($learner, $w[12], ['lapses' => 99, 'next_review_at' => now()->subDay()]); // already due: stays where it is, still the first

    $before = WordRepetition::query()->where('learner_id', $learner->id)->pluck('next_review_at', 'word_id');
    $session = hardRoundService()->open($learner, 'finish');

    $set = array_slice(array_reverse(array_slice($w, 0, 12)), 0, 9);   // lapses 13..5
    $expected = [$w[12], ...$set];
    expect($session->meta)->toBe(['hard_words' => $expected])
        ->and($session->mode)->toBe('hard_review')->and($session->status)->toBe('open')->and($session->source)->toBe('finish')
        ->and($session->lesson_id)->toBeNull()->and($session->course_id)->toBeNull()
        ->and($session->started_at->equalTo(now()))->toBeTrue()
        ->and($session->expires_at?->equalTo(now()->addHours(12)))->toBeTrue();

    $after = WordRepetition::query()->where('learner_id', $learner->id)->get()->keyBy('word_id');
    foreach ($expected as $wordId) {
        // Due now (a row that was due earlier keeps its time), nothing else changed.
        expect($after[$wordId]->next_review_at->lte(now()))->toBeTrue()->and($after[$wordId]->is_hard)->toBeFalse();
    }
    foreach ([$w[0], $w[1]] as $left) {
        expect($after[$left]->next_review_at->equalTo($before[$left] instanceof DateTimeInterface ? CarbonImmutable::instance($before[$left]) : CarbonImmutable::parse((string) $before[$left])))->toBeTrue();
    }
    expect($after[$w[12]]->next_review_at->equalTo(CarbonImmutable::parse($before[$w[12]] instanceof DateTimeInterface ? $before[$w[12]]->format('Y-m-d H:i:s') : (string) $before[$w[12]], 'UTC')))->toBeTrue();
});

it('changes nothing of the schedule but the time: state, repetitions, interval, ease, lapses, last review', function (): void {
    ['learner' => $learner, 'words' => $w] = hardRoundWorld();
    $rows = hardRoundRows($learner, $w, 8, ['repetitions' => 5, 'interval_days' => 12, 'easiness_factor' => 2.1, 'lapses' => 3, 'last_quality' => 3]);

    hardRoundService()->open($learner, 'self');

    foreach ($rows as $row) {
        $fresh = $row->fresh();
        expect($fresh?->state)->toBe(RepetitionState::Review)->and($fresh?->repetitions)->toBe(5)->and($fresh?->interval_days)->toBe(12)
            ->and((float) $fresh?->easiness_factor)->toBe(2.1)->and($fresh?->lapses)->toBe(3)->and($fresh?->last_quality)->toBe(3)
            ->and($fresh?->last_reviewed_at?->equalTo($row->last_reviewed_at))->toBeTrue();
    }
    expect(DB::table('training_reviews')->count())->toBe(0);
});

it('hands the same session back on a repeat: one session, one pull', function (): void {
    ['learner' => $learner, 'words' => $w] = hardRoundWorld();
    hardRoundRows($learner, $w, 9);

    $first = hardRoundService()->open($learner, 'self');
    // A card graded between the two opens must not be pulled again: put the pulled rows back into the future and see nobody moves them.
    WordRepetition::query()->where('learner_id', $learner->id)->update(['next_review_at' => now()->addDays(9)]);
    $second = hardRoundService()->open($learner, 'finish');

    expect($second->id)->toBe($first->id)->and($second->source)->toBe('self')
        ->and(TrainingSession::query()->where('learner_id', $learner->id)->count())->toBe(1)
        ->and(WordRepetition::query()->where('learner_id', $learner->id)->where('next_review_at', '<=', now())->count())->toBe(0);
});

it('closes yesterday\'s round left open as expired and opens today\'s', function (): void {
    ['learner' => $learner, 'words' => $w] = hardRoundWorld();
    hardRoundRows($learner, $w, 9);
    $old = TrainingSession::factory()->personal($learner, TrainingSession::MODE_HARD_REVIEW)->create([
        'lesson_id' => null, 'started_at' => now()->subHours(20), 'expires_at' => now()->addHours(2), 'meta' => ['hard_words' => array_slice($w, 0, 9)],
    ]);

    $new = hardRoundService()->open($learner, 'self');

    expect($new->id)->not->toBe($old->id)
        ->and($old->fresh()?->status)->toBe('closed')->and($old->fresh()?->done_reason)->toBe(TrainingSession::DONE_EXPIRED)
        ->and(TrainingSession::query()->where('learner_id', $learner->id)->where('status', 'open')->count())->toBe(1);
});

it('allows a second round when per_day is two and a third not; a round played to the end is closed as finished first', function (): void {
    config(['learning.hard_round.per_day' => 2]);
    ['learner' => $learner, 'words' => $w] = hardRoundWorld();
    hardRoundRows($learner, $w, 9);

    $first = hardRoundService()->open($learner, 'self');
    $first->update(['completed_at' => now()]); // played to the end, never closed

    $second = hardRoundService()->open($learner, 'self');
    expect($second->id)->not->toBe($first->id)
        ->and($first->fresh()?->done_reason)->toBe(TrainingSession::DONE_FINISHED)
        ->and(ProductEvent::query()->where('name', ProductEventName::TrainingSessionFinished->value)->count())->toBe(1);

    $second->update(['status' => 'closed', 'ended_at' => now(), 'done_reason' => 'expired']);
    expect(fn () => hardRoundService()->open($learner, 'self'))->toThrow(HardRoundException::class);
});

it('refuses with the reason and records hard_round.refused', function (): void {
    ['learner' => $learner, 'words' => $w] = hardRoundWorld();
    hardRoundRows($learner, $w, 7);

    try {
        hardRoundService()->open($learner, 'finish');
        $this->fail('Seven words are not enough.');
    } catch (HardRoundException $e) {
        expect($e->reason())->toBe('not_enough')->and($e->details())->toBe(['reason' => 'not_enough', 'resets_at' => null]);
    }
    $event = ProductEvent::query()->where('name', ProductEventName::HardRoundRefused->value)->sole();
    expect($event->props)->toBe(['reason' => 'not_enough', 'source' => 'finish'])
        ->and(TrainingSession::query()->count())->toBe(0);

    hardRoundRow($learner, $w[7]);
    $session = hardRoundService()->open($learner, 'self');
    $session->update(['status' => 'closed', 'ended_at' => now(), 'done_reason' => 'finished']);
    try {
        hardRoundService()->open($learner, 'self');
        $this->fail('One round a day.');
    } catch (HardRoundException $e) {
        expect($e->reason())->toBe('played_today')->and($e->details()['resets_at'])->toBe('2026-11-16T19:00:00.000Z');
    }
});

it('refuses a learner without an objective exercise', function (): void {
    ['learner' => $learner, 'words' => $w] = hardRoundWorld(learner: ['meta' => ['exercises' => ['meaning' => false, 'reverse' => false]]]);
    hardRoundRows($learner, $w, 10);

    expect(fn () => hardRoundService()->open($learner, 'self'))->toThrow(HardRoundException::class, 'no_objective_exercise')
        ->and(WordRepetition::query()->where('learner_id', $learner->id)->where('next_review_at', '<=', now())->count())->toBe(0);
});

// --- the pull ---------------------------------------------------------------------------------------------

it('brings only the rows due later to now, marks them hard on demand and never pushes one away', function (): void {
    ['learner' => $learner, 'words' => $w] = hardRoundWorld();
    $later = hardRoundRow($learner, $w[0], ['next_review_at' => now()->addDays(3)]);
    $past = hardRoundRow($learner, $w[1], ['next_review_at' => now()->subDays(2)]);
    $other = hardRoundRow(Learner::factory()->create(), $w[0], ['next_review_at' => now()->addDays(3)]);
    $pull = app(ReviewPull::class);

    expect($pull->toNow((int) $learner->id, [$w[0], $w[1], $w[0]], now()->toImmutable(), markHard: false))->toBe([$w[0]])
        ->and($later->fresh()?->next_review_at->equalTo(now()))->toBeTrue()->and($later->fresh()?->is_hard)->toBeFalse()
        ->and($past->fresh()?->next_review_at->equalTo($past->next_review_at))->toBeTrue()
        ->and($other->fresh()?->next_review_at->equalTo($other->next_review_at))->toBeTrue()
        ->and($pull->toNow((int) $learner->id, [$w[0]], now()->toImmutable(), markHard: false))->toBe([])
        ->and($pull->toNow((int) $learner->id, [], now()->toImmutable(), markHard: true))->toBe([]);

    $again = hardRoundRow($learner, $w[2], ['next_review_at' => now()->addDay()]);
    expect($pull->toNow((int) $learner->id, [$w[2]], now()->toImmutable(), markHard: true))->toBe([$w[2]])
        ->and($again->fresh()?->is_hard)->toBeTrue();
});

it('keeps the game\'s rule on the shared formula: due now and hard, the misses counted', function (): void {
    ['learner' => $learner, 'words' => $w] = hardRoundWorld();
    $row = hardRoundRow($learner, $w[0], ['next_review_at' => now()->addDays(3)]);

    expect(app(GameSrsAdjuster::class)->pull((int) $learner->id, [$w[0]], now()->toImmutable()))->toBe(1)
        ->and($row->fresh()?->is_hard)->toBeTrue()->and($row->fresh()?->next_review_at->equalTo(now()))->toBeTrue()
        ->and(DB::table('game_word_misses')->where('learner_id', $learner->id)->value('misses'))->toBe(1);
});

// --- the scope and the picker -----------------------------------------------------------------------------

it('builds the hard scope from meta, never serves a new word and never accepts a word outside the set', function (): void {
    ['learner' => $learner, 'words' => $w] = hardRoundWorld();
    $session = TrainingSession::factory()->personal($learner, TrainingSession::MODE_HARD_REVIEW)->create(['lesson_id' => null, 'meta' => ['hard_words' => [$w[1], $w[0], $w[1]]]]);

    $scope = TrainingScope::forSession($session);
    $inactive = Word::query()->findOrFail($w[2]);
    $inactive->is_active = false;

    expect($scope->isHard())->toBeTrue()->and($scope->isDaily())->toBeFalse()->and($scope->isLesson())->toBeFalse()
        ->and($scope->wordIds)->toBe([$w[1], $w[0]])
        ->and($scope->sessionMode)->toBe('hard_review')
        ->and($scope->includesNew())->toBeFalse()->and($scope->newWordCandidates((int) $learner->id))->toBeNull()
        ->and($scope->frontierCourseId())->toBeNull()
        ->and($scope->exhaustedReason())->toBe(DoneReason::HardRoundComplete)
        ->and($scope->contains(Word::query()->findOrFail($w[0])))->toBeTrue()
        ->and($scope->contains(Word::query()->findOrFail($w[5])))->toBeFalse()
        ->and(TrainingScope::hard([$w[2]])->contains($inactive))->toBeFalse();
});

it('has no hard scope without a set', function (mixed $meta): void {
    ['learner' => $learner] = hardRoundWorld(1);
    $session = TrainingSession::factory()->personal($learner, TrainingSession::MODE_HARD_REVIEW)->create(['lesson_id' => null, 'meta' => $meta]);

    expect(fn () => TrainingScope::forSession($session))->toThrow(LogicException::class);
})->with([[[]], [['hard_words' => []]], [['hard_words' => 'x']]]);

it('serves only the words of the set, hardest and earliest first, and ends with hard_round_complete without can_continue', function (): void {
    ['learner' => $learner, 'words' => $w] = hardRoundWorld();
    hardRoundRow($learner, $w[0], ['next_review_at' => now()->subDay()]);
    hardRoundRow($learner, $w[1], ['next_review_at' => now()->subHours(3), 'is_hard' => true]);
    hardRoundRow($learner, $w[2], ['next_review_at' => now()->subDays(3)]);   // due and overdue most, but outside the set
    $scope = TrainingScope::hard([$w[0], $w[1]]);
    $picker = app(CardPicker::class);
    $rowsBefore = WordRepetition::query()->count();

    $first = $picker->pickNext((int) $learner->id, $scope, NewCardBudget::unlimited(), now()->toImmutable());
    expect($first->word?->id)->toBe($w[1]);                      // hard first

    WordRepetition::query()->where('word_id', $w[1])->update(['next_review_at' => now()->addDays(8)]);
    $second = $picker->pickNext((int) $learner->id, $scope, NewCardBudget::unlimited(), now()->toImmutable());
    expect($second->word?->id)->toBe($w[0]);

    WordRepetition::query()->where('word_id', $w[0])->update(['next_review_at' => now()->addDays(8)]);
    $done = $picker->pickNext((int) $learner->id, $scope, NewCardBudget::unlimited(), now()->toImmutable());
    expect($done->word)->toBeNull()->and($done->doneReason)->toBe(DoneReason::HardRoundComplete)->and($done->canContinue)->toBeFalse()
        ->and(WordRepetition::query()->count())->toBe($rowsBefore);   // a new word of the course was never introduced
});

it('serves a failed card of the set again through learn-ahead and skips a word that became inactive', function (): void {
    ['learner' => $learner, 'words' => $w] = hardRoundWorld();
    hardRoundRow($learner, $w[0], ['state' => RepetitionState::Relearning->value, 'next_review_at' => now()->addMinutes(10), 'repetitions' => 0, 'interval_days' => 0]);
    hardRoundRow($learner, $w[1]);
    Word::query()->whereKey($w[1])->update(['is_active' => false]);
    $scope = TrainingScope::hard([$w[0], $w[1]]);

    $pick = app(CardPicker::class)->pickNext((int) $learner->id, $scope, NewCardBudget::unlimited(), now()->toImmutable());

    expect($pick->word?->id)->toBe($w[0])->and($pick->ahead)->toBeTrue()->and($pick->kind)->toBe('learning');
});

// --- the exercises ----------------------------------------------------------------------------------------

/** @return array<string, array<string, mixed>> */
function hardRoundCards(): array
{
    return [
        'rung 0 review' => ['repetitions' => 1, 'interval_days' => 1, 'state' => RepetitionState::Review->value],
        'rung 1' => ['repetitions' => 2, 'interval_days' => 2, 'state' => RepetitionState::Review->value],
        'rung 2' => ['repetitions' => 4, 'interval_days' => 5, 'state' => RepetitionState::Review->value],
        'rung 3' => ['repetitions' => 6, 'interval_days' => 30, 'state' => RepetitionState::Review->value],
        'relearning' => ['repetitions' => 0, 'interval_days' => 0, 'state' => RepetitionState::Relearning->value, 'lapses' => 2],
    ];
}

it('never serves a flash card in the round, whatever the rung, the plan, the settings, the audio or the ladder', function (): void {
    $word = new Word(['word' => 'walk', 'example' => 'They walked home.']);
    $word->id = 7;
    $plans = ['free' => Entitlement::fromPlan(Plan::Free, Entitlement::SOURCE_FREE, null, 1), 'pro' => Entitlement::fromPlan(Plan::Pro, Entitlement::SOURCE_SUBSCRIPTION, null, 1)];
    $settings = [
        'all on' => new LearnerExerciseSettings,
        'no audio' => new LearnerExerciseSettings(audio: false),
        'no reverse' => new LearnerExerciseSettings(reverse: false),
        'no meaning' => new LearnerExerciseSettings(meaning: false),
        'meaning only' => new LearnerExerciseSettings(reverse: false, audio: false, spelling: false, gapFill: false),
        'reverse only' => new LearnerExerciseSettings(meaning: false, audio: false, spelling: false, gapFill: false),
    ];
    $ladders = ['early' => ['early' => ['enabled' => true]], 'no early' => ['early' => ['enabled' => false]], 'no ladder' => ['enabled' => false, 'early' => ['enabled' => false]]];
    $served = [];

    foreach ($ladders as $ladderName => $ladder) {
        config(['learning.ladder' => array_replace(['enabled' => true, 'meaning_enabled' => true, 'typed_from_interval_days' => 21], $ladder)]);
        $selector = new ExerciseSelector(new GapFillBuilder, LadderPolicy::fromConfig());

        foreach (hardRoundCards() as $cardName => $card) {
            foreach ($plans as $planName => $plan) {
                foreach ($settings as $settingName => $setting) {
                    foreach ([false, true] as $hasAudio) {
                        foreach ([null, 'flash', 'meaning', 'reverse'] as $last) {
                            $row = new WordRepetition([...$card, 'meta' => $last === null ? [] : [LadderPolicy::LAST_EXERCISE_META => $last]]);
                            $type = $selector->select($row, $word, $plan, $setting, TrainingSession::MODE_HARD_REVIEW, 'They walked home.', $hasAudio);

                            expect($type)->not->toBe(ExerciseType::Flash, "{$ladderName} / {$cardName} / {$planName} / {$settingName} / audio {$hasAudio} / last {$last}")
                                ->and($type)->not->toBe(ExerciseType::Pretest);
                            // Pure: the grade recomputes the type of the serve from the same inputs.
                            expect($selector->select($row, $word, $plan, $setting, TrainingSession::MODE_HARD_REVIEW, 'They walked home.', $hasAudio))->toBe($type);
                            $served[$type->value] = true;
                        }
                    }
                }
            }
        }
    }

    // The round is not a monoculture of one exercise.
    expect(array_keys($served))->toContain('meaning', 'reverse');
});

it('leaves the other modes with their flash cards', function (): void {
    config(['learning.ladder.early' => ['enabled' => true]]);
    $selector = new ExerciseSelector(new GapFillBuilder, LadderPolicy::fromConfig());
    $word = new Word(['word' => 'walk', 'example' => 'They walked home.']);
    $word->id = 7;
    $new = new WordRepetition(['repetitions' => 0, 'interval_days' => 0, 'state' => RepetitionState::New->value, 'meta' => []]);

    expect($selector->select($new, $word, Entitlement::fromPlan(Plan::Free, Entitlement::SOURCE_FREE, null, 1), new LearnerExerciseSettings, TrainingSession::MODE_DAILY_REVIEW))->toBe(ExerciseType::Flash);
});

// --- the close of an expired round and the retention report -----------------------------------------------

it('closes an expired round of hard words as expired, a completed one as finished, and leaves a live one', function (): void {
    $learner = Learner::factory()->create();
    $make = fn (array $attributes) => TrainingSession::factory()->personal($learner, TrainingSession::MODE_HARD_REVIEW)->create([
        'lesson_id' => null, 'started_at' => now()->subDay(), 'meta' => ['hard_words' => [1]], ...$attributes,
    ]);
    $abandoned = $make(['expires_at' => now()->subHour()]);
    $other = Learner::factory()->create();
    $completed = TrainingSession::factory()->personal($other, TrainingSession::MODE_HARD_REVIEW)->create([
        'lesson_id' => null, 'started_at' => now()->subDay(), 'expires_at' => now()->subHour(), 'completed_at' => now()->subHours(3), 'meta' => ['hard_words' => [1]],
    ]);
    $third = Learner::factory()->create();
    $live = TrainingSession::factory()->personal($third, TrainingSession::MODE_HARD_REVIEW)->create(['lesson_id' => null, 'started_at' => now()->subHour(), 'expires_at' => now()->addHours(6), 'meta' => ['hard_words' => [1]]]);

    $closed = app(TrainingSessionService::class)->closeExpired();

    expect($closed)->toBe(2)
        ->and($abandoned->fresh()?->status)->toBe('closed')->and($abandoned->fresh()?->done_reason)->toBe(TrainingSession::DONE_EXPIRED)
        ->and($completed->fresh()?->done_reason)->toBe(TrainingSession::DONE_FINISHED)
        ->and($live->fresh()?->status)->toBe('open');
});

it('does not take a round of hard words for the first or the second session of the retention report', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'UTC'));
    $session = fn (Learner $l, string $mode, string $startedAt, string $reason) => TrainingSession::factory()->personal($l, $mode)->create([
        'lesson_id' => $mode === TrainingSession::MODE_LESSON ? null : null, 'started_at' => $startedAt, 'status' => 'closed', 'ended_at' => $startedAt,
        'done_reason' => $reason, 'expires_at' => null, 'meta' => $mode === TrainingSession::MODE_HARD_REVIEW ? ['hard_words' => [1]] : [],
    ]);
    // L1: a hard round came first (finished), the daily round twelve hours later (expired): the first session is the daily one -> abandoned.
    $l1 = Learner::factory()->create(['created_at' => '2026-11-05 08:00:00', 'updated_at' => '2026-11-05 08:00:00']);
    $session($l1, TrainingSession::MODE_HARD_REVIEW, '2026-11-08 08:00:00', TrainingSession::DONE_FINISHED);
    $session($l1, TrainingSession::MODE_DAILY_REVIEW, '2026-11-08 20:00:00', TrainingSession::DONE_EXPIRED);
    // L2: the daily round first (finished), a hard round ten hours later: not "came back within 24 hours".
    $l2 = Learner::factory()->create(['created_at' => '2026-11-05 08:00:00', 'updated_at' => '2026-11-05 08:00:00']);
    $session($l2, TrainingSession::MODE_DAILY_REVIEW, '2026-11-08 08:00:00', TrainingSession::DONE_FINISHED);
    $session($l2, TrainingSession::MODE_HARD_REVIEW, '2026-11-08 18:00:00', TrainingSession::DONE_FINISHED);
    // L3: only a hard round: no first session at all.
    $l3 = Learner::factory()->create(['created_at' => '2026-11-05 08:00:00', 'updated_at' => '2026-11-05 08:00:00']);
    $session($l3, TrainingSession::MODE_HARD_REVIEW, '2026-11-08 09:00:00', TrainingSession::DONE_FINISHED);

    $first = app(RetentionReportService::class)->compute(CarbonImmutable::parse('2026-11-18 10:00:00', 'UTC'))['first_session'];

    expect($first['registered'])->toBe(3)
        ->and($first['opened'])->toBe(['count' => 2, 'of' => 3, 'pct' => 66.7])
        ->and($first['finished'])->toBe(['count' => 1, 'of' => 2, 'pct' => 50.0])
        ->and($first['abandoned'])->toBe(['count' => 1, 'of' => 2, 'pct' => 50.0])
        ->and($first['second_24h']['count'])->toBe(0)
        ->and($first['second_7d']['count'])->toBe(0);
});
