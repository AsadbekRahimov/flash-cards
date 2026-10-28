<?php

declare(strict_types=1);

use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Services\LearnerStatsService;
use App\Domain\Learning\Support\DueRepetitionCriteria;
use App\Domain\Learning\Support\LearningPolicy;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/*
 * DueRepetitionCriteria (docs/06 §6) is phrased as an anti-join — `NOT EXISTS (words … is_active = false)`,
 * served by the partial index `words_inactive_idx` (docs/03 §2.7) — instead of `EXISTS (words … is_active = true)`.
 * These tests pin the count to the rule of docs/06 §6 evaluated row by row in PHP, on every shape of data
 * the two SQL forms could possibly differ on: inactive words in each state, deck and archived-course words,
 * shared words of several learners, deactivation / reactivation / deletion of a word.
 */

uses(RefreshDatabase::class);

function drcNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-28 12:00:00', 'UTC');
}

function drcDueCount(Learner $learner): int
{
    return app(LearnerStatsService::class)->dueCount($learner, drcNow());
}

/**
 * docs/06 §6 applied to every row of the learner in PHP — independent of how the SQL is phrased:
 * active word AND (review due now OR (re)learning step elapsed more than `abandoned_after_hours` ago).
 */
function drcExpected(Learner $learner): int
{
    $now = drcNow();
    $abandonedBefore = $now->subHours(app(LearningPolicy::class)->abandonedAfterHours);

    return WordRepetition::query()
        ->where('learner_id', $learner->id)
        ->with('word')
        ->get()
        ->filter(function (WordRepetition $rep) use ($now, $abandonedBefore): bool {
            if ($rep->word === null || ! $rep->word->is_active) {
                return false;
            }

            return match ($rep->state) {
                RepetitionState::Review => $rep->next_review_at->lessThanOrEqualTo($now),
                RepetitionState::Learning, RepetitionState::Relearning => $rep->next_review_at->lessThanOrEqualTo($abandonedBefore),
                RepetitionState::New => false,
            };
        })
        ->count();
}

function drcWord(?Course $course = null, bool $active = true): Word
{
    $course ??= Course::factory()->published()->create();
    $stage = Stage::factory()->create(['course_id' => $course->id]);
    $lesson = Lesson::factory()->create(['stage_id' => $stage->id]);

    return Word::factory()->create(['lesson_id' => $lesson->id, 'is_active' => $active]);
}

function drcRep(Learner $learner, Word $word, RepetitionState $state, CarbonImmutable $nextReviewAt): WordRepetition
{
    return WordRepetition::factory()->create([
        'learner_id' => $learner->id,
        'word_id' => $word->id,
        'state' => $state->value,
        'repetitions' => $state === RepetitionState::Review ? 3 : 0,
        'interval_days' => $state === RepetitionState::Review ? 6 : 0,
        'last_quality' => $state === RepetitionState::New ? null : 3,
        'is_hard' => $state === RepetitionState::Relearning,
        'next_review_at' => $nextReviewAt,
    ]);
}

it('counts exactly the cards docs/06 §6 defines and skips inactive words in every state', function (): void {
    $now = drcNow();
    $abandoned = $now->subHours(app(LearningPolicy::class)->abandonedAfterHours);
    $learner = Learner::factory()->create();

    // Counted: 2 overdue review cards, 1 due this very second, 1 abandoned learning, 1 relearning exactly on the line.
    drcRep($learner, drcWord(), RepetitionState::Review, $now->subDays(3));
    drcRep($learner, drcWord(), RepetitionState::Review, $now->subMinute());
    drcRep($learner, drcWord(), RepetitionState::Review, $now);
    drcRep($learner, drcWord(), RepetitionState::Learning, $abandoned->subHour());
    drcRep($learner, drcWord(), RepetitionState::Relearning, $abandoned);

    // Not counted: the same shapes on inactive words …
    drcRep($learner, drcWord(active: false), RepetitionState::Review, $now->subDays(3));
    drcRep($learner, drcWord(active: false), RepetitionState::Learning, $abandoned->subHour());
    drcRep($learner, drcWord(active: false), RepetitionState::Relearning, $abandoned->subDay());
    // … and cards that are not due by the rule itself.
    drcRep($learner, drcWord(), RepetitionState::Review, $now->addSecond());
    drcRep($learner, drcWord(), RepetitionState::Learning, $now->subMinutes(5));
    drcRep($learner, drcWord(), RepetitionState::Relearning, $abandoned->addSecond());
    drcRep($learner, drcWord(), RepetitionState::New, $now->subDays(2));
    drcRep($learner, drcWord(active: false), RepetitionState::New, $now->subDays(2));

    expect(drcDueCount($learner))->toBe(5)
        ->and(drcExpected($learner))->toBe(5);
});

it('ignores the course a word belongs to (decks, archived and draft courses) and looks only at words.is_active', function (): void {
    $now = drcNow();
    $learner = Learner::factory()->create();

    // The learner's own deck (private, owner learner): a due word counts, a word removed from the deck
    // (`LearnerDeckService::removeWord` sets is_active = false) does not.
    $deck = Course::factory()->deckOf($learner->id)->create();
    drcRep($learner, drcWord($deck), RepetitionState::Review, $now->subDay());
    drcRep($learner, drcWord($deck, active: false), RepetitionState::Review, $now->subDay());

    // Archived and draft platform courses: their cards are still the learner's progress and still count.
    $archived = Course::factory()->create(['status' => 'archived']);
    drcRep($learner, drcWord($archived), RepetitionState::Review, $now->subDay());
    $draft = Course::factory()->create(['status' => 'draft']);
    drcRep($learner, drcWord($draft), RepetitionState::Relearning, $now->subDays(2));

    // Somebody else's private deck: not reachable through the app, but the rule does not care either.
    $other = Learner::factory()->create();
    drcRep($learner, drcWord(Course::factory()->deckOf($other->id)->create()), RepetitionState::Review, $now->subDay());

    expect(drcDueCount($learner))->toBe(4)
        ->and(drcExpected($learner))->toBe(4);
});

it('keeps the counts of learners sharing the same words apart, also inside whereHas and withCount', function (): void {
    $now = drcNow();
    [$a, $b, $c] = Learner::factory()->count(3)->create()->all();
    [$w1, $w2, $w3] = [drcWord(), drcWord(), drcWord()];
    $w4 = drcWord(active: false);

    drcRep($a, $w1, RepetitionState::Review, $now->subDay());
    drcRep($a, $w2, RepetitionState::Review, $now->subDay());
    drcRep($a, $w3, RepetitionState::Review, $now->addDay());
    drcRep($b, $w1, RepetitionState::Review, $now->addDay());
    drcRep($b, $w2, RepetitionState::Learning, $now->subDay());
    drcRep($b, $w3, RepetitionState::Review, $now->subHour());
    drcRep($b, $w4, RepetitionState::Review, $now->subHour());
    drcRep($c, $w4, RepetitionState::Review, $now->subHour()); // only an inactive word: nothing due

    expect(drcDueCount($a))->toBe(2)->and(drcExpected($a))->toBe(2)
        ->and(drcDueCount($b))->toBe(2)->and(drcExpected($b))->toBe(2)
        ->and(drcDueCount($c))->toBe(0)->and(drcExpected($c))->toBe(0);

    // The criterion is documented as usable inside a relation query (docs/06 §6): the correlation to `words`
    // must survive nesting under `learners`.
    $criteria = app(DueRepetitionCriteria::class);
    $apply = fn (Builder $q): Builder => $criteria->apply($q, $now);

    expect(Learner::query()->whereHas('repetitions', $apply)->orderBy('id')->pluck('id')->all())
        ->toBe([$a->id, $b->id]);

    $counts = Learner::query()->withCount(['repetitions as due_count' => $apply])->orderBy('id')->pluck('due_count', 'id');
    expect($counts->map(fn (mixed $n): int => (int) $n)->all())->toBe([$a->id => 2, $b->id => 2, $c->id => 0]);
});

it('follows a word through deactivation, reactivation and deletion', function (): void {
    $now = drcNow();
    $learner = Learner::factory()->create();
    $word = drcWord();
    drcRep($learner, $word, RepetitionState::Review, $now->subDay());

    expect(drcDueCount($learner))->toBe(1);

    $word->forceFill(['is_active' => false])->save();
    expect(drcDueCount($learner))->toBe(0)->and(drcExpected($learner))->toBe(0);

    $word->forceFill(['is_active' => true])->save();
    expect(drcDueCount($learner))->toBe(1)->and(drcExpected($learner))->toBe(1);

    // Hard deletion is forbidden by docs/03 §8, but if it happens the FK cascade removes the row,
    // so the anti-join cannot resurrect an orphan.
    $word->delete();
    expect(WordRepetition::query()->where('learner_id', $learner->id)->exists())->toBeFalse()
        ->and(drcDueCount($learner))->toBe(0);
});

it('matches the row-by-row rule on a randomised mix of states, dates, active flags and learners', function (): void {
    mt_srand(20261028);
    $now = drcNow();
    $learners = Learner::factory()->count(3)->create();
    $courses = [Course::factory()->published()->create(), Course::factory()->create(['status' => 'archived'])];

    $words = [];
    for ($i = 0; $i < 40; $i++) {
        $words[] = drcWord($courses[$i % 2], active: mt_rand(1, 100) > 20);
    }

    $states = RepetitionState::cases();
    foreach ($learners as $learner) {
        foreach ($words as $word) {
            if (mt_rand(0, 3) === 0) {
                continue; // not every learner has seen every word
            }
            $state = $states[mt_rand(0, count($states) - 1)];
            // Spread around both thresholds (now and now - abandoned_after_hours).
            $nextReviewAt = $now->addMinutes(mt_rand(-48 * 60, 24 * 60));
            drcRep($learner, $word, $state, $nextReviewAt);
        }
    }

    $total = 0;
    foreach ($learners as $learner) {
        $expected = drcExpected($learner);
        expect(drcDueCount($learner))->toBe($expected);
        $total += $expected;
    }
    // The fixture is only meaningful when both sides of the rule actually occur.
    expect($total)->toBeGreaterThan(0)
        ->and($total)->toBeLessThan(WordRepetition::query()->count());
});

it('relies on NOT NULL word_id and words.is_active, and is phrased as the anti-join the partial index serves', function (): void {
    $isActive = collect(Schema::getColumns('words'))->firstWhere('name', 'is_active');
    $wordId = collect(Schema::getColumns('word_repetitions'))->firstWhere('name', 'word_id');

    expect($isActive)->not->toBeNull()->and($isActive['nullable'])->toBeFalse()
        ->and($wordId)->not->toBeNull()->and($wordId['nullable'])->toBeFalse();

    $query = app(DueRepetitionCriteria::class)->apply(WordRepetition::query(), drcNow());
    expect($query->toSql())
        ->toContain('not exists (select * from "words" where "word_repetitions"."word_id" = "words"."id" and "is_active" = ?)')
        ->and($query->getBindings()[0])->toBeFalse();
});
