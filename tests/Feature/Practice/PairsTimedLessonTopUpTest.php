<?php

declare(strict_types=1);

use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Services\Modes\PairsTimedMode;
use App\Domain\Practice\Services\PracticeSnapshotBuilder;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PracticeSession;
use App\Models\Stage;
use App\Models\Word;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Practice\PracticeKit;

/*
 * "Pairs against the clock" on ONE lesson short of words (docs/16 §5.5a, docs/31 §5б, step 3 of the day plan): the words of the lesson
 * all stay in, the board is filled up to `min_words` with words of the same course the learner has LEARNED, chosen by the seed of the
 * session; with too few learned words in the course - the old refusal.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-25 10:00:00');
    PracticeKit::openAccess();
    config(['practice.modes.pairs_timed' => true, 'practice.solo.pairs_timed.min_words' => 8, 'practice.solo.pairs_timed.per_round' => 4]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * A course with the lesson of today (5 words met) and an older lesson of 12 words: `$learned` of them learned (review), 2 only met (new).
 *
 * @return array{0: Learner, 1: Course, 2: Lesson, 3: list<Word>, 4: list<Word>, 5: list<Word>}
 */
function pairsTopUpWorld(int $learned = 6): array
{
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create();
    $older = Lesson::factory()->for(Stage::query()->where('course_id', $course->id)->firstOrFail())->create(['number' => 2]);

    $todays = PracticeKit::words($lesson, 5, $learner, state: 'learning');
    $known = PracticeKit::words($older, $learned, $learner, state: 'review');
    $unlearned = PracticeKit::words($older, 2, $learner, state: 'new');
    foreach ([...$todays, ...$known, ...$unlearned] as $i => $word) {
        $word->forceFill(['translation' => "перевод{$i}x{$word->id}"])->save();
    }

    return [$learner, $course, $lesson, $todays, $known, $unlearned];
}

/** @return list<int> every word id on the board */
function pairsTopUpBoard(Learner $learner, Course $course, Lesson $lesson, string $seed): array
{
    $elements = app(PracticeSnapshotBuilder::class)
        ->build(PracticeMode::PairsTimed, new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'), 10, false, $seed)
        ->toArray()['elements'];

    return array_merge(...array_map(fn (array $e): array => array_column($e['left'], 'word_id'), $elements));
}

it('fills a lesson of 5 words up to 8 with learned words of the course, every word of the lesson on the board', function (): void {
    [$learner, $course, $lesson, $todays, $known, $unlearned] = pairsTopUpWorld();

    $board = array_values(array_unique(pairsTopUpBoard($learner, $course, $lesson, 'seed-a')));
    $ids = fn (array $words): array => array_map(fn (Word $w): int => $w->id, $words);

    expect($board)->toHaveCount(8)
        ->and(array_diff($ids($todays), $board))->toBe([])
        ->and(array_diff($board, [...$ids($todays), ...$ids($known)]))->toBe([]) // only learned words fill the board
        ->and(array_intersect($board, $ids($unlearned)))->toBe([])
        ->and(app(PairsTimedMode::class)->availability(new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'))->toArray())
        ->toBe(['available' => 8, 'required' => 8, 'reason' => null]);
});

it('chooses the same filling for one seed and may choose another for another', function (): void {
    [$learner, $course, $lesson] = pairsTopUpWorld(learned: 10);

    $a = array_values(array_unique(pairsTopUpBoard($learner, $course, $lesson, 'seed-a')));
    sort($a);
    $again = array_values(array_unique(pairsTopUpBoard($learner, $course, $lesson, 'seed-a')));
    sort($again);
    $fillings = [];
    foreach (['s1', 's2', 's3', 's4', 's5', 's6'] as $seed) {
        $board = array_values(array_unique(pairsTopUpBoard($learner, $course, $lesson, $seed)));
        sort($board);
        $fillings[implode(',', $board)] = true;
    }

    expect($again)->toBe($a)->and(count($fillings))->toBeGreaterThan(1);
});

it('keeps the refusal when the course has too few learned words to fill the board', function (): void {
    [$learner, $course, $lesson] = pairsTopUpWorld(learned: 2);

    expect(app(PairsTimedMode::class)->availability(new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'))->toArray())
        ->toBe(['available' => 7, 'required' => 8, 'reason' => 'no_words']);

    $this->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/practice/sessions', [
        'mode' => 'pairs_timed', 'course' => $course->slug, 'lesson_id' => $lesson->id, 'source' => PracticeSession::SOURCE_DEEPLINK,
    ])->assertStatus(422)->assertJsonPath('error.code', 'practice_not_enough_material');
});

it('does not fill a lesson the learner has not met at all, nor a game of the whole course', function (): void {
    [$learner, $course, $lesson, $todays] = pairsTopUpWorld(learned: 10);
    $fresh = Lesson::factory()->for(Stage::query()->where('course_id', $course->id)->firstOrFail())->create(['number' => 3]);
    PracticeKit::words($fresh, 3);

    expect(app(PairsTimedMode::class)->availability(new MaterialScope($course, $fresh), $learner, LocaleSet::of('ru'))->toArray()['reason'])->toBe('no_words')
        // The whole course: the learner's own words as before (5 + 10 + 2 met), nothing "filled".
        ->and(app(PairsTimedMode::class)->availability(new MaterialScope($course), $learner, LocaleSet::of('ru'))->toArray()['available'])->toBe(17)
        ->and($todays)->toHaveCount(5);
});

it('opens the game of the day plan over HTTP with the words of the lesson and the filling', function (): void {
    [$learner, $course, $lesson, $todays] = pairsTopUpWorld();

    $id = $this->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/practice/sessions', [
        'mode' => 'pairs_timed', 'course' => $course->slug, 'lesson_id' => $lesson->id, 'source' => PracticeSession::SOURCE_DEEPLINK,
    ])->assertSuccessful()->json('session_id') ?? PracticeSession::query()->latest('id')->value('id');

    $session = PracticeSession::query()->findOrFail($id);
    $board = array_values(array_unique(array_merge(...array_map(fn (array $e): array => array_column($e['left'], 'word_id'), $session->snapshot['elements']))));

    expect($session->lesson_id)->toBe($lesson->id)
        ->and($session->source)->toBe(PracticeSession::SOURCE_DEEPLINK)
        ->and($board)->toHaveCount(8)
        ->and(array_diff(array_map(fn (Word $w): int => $w->id, $todays), $board))->toBe([]);
});
