<?php

declare(strict_types=1);

/*
 * The new-word frontier of the daily review (docs/06 §4.2, docs/19 §2.2): a cached hint of the lesson where the search
 * for the learner's next NEW word of the course starts. The hint narrows the candidate statement to one lesson; the
 * decision - unseen, active, available, within the quota - stays with the database under the learner lock. Every
 * sequence of picks with the hint must equal the sequence the full statement (the legacy query, kept here as the
 * reference) gives, whatever the progress, the content changes and the state of the cache.
 */

use App\Domain\Content\Services\LearnerDeckService;
use App\Domain\Content\Services\LessonContentRefresher;
use App\Domain\Learning\DTO\CardPick;
use App\Domain\Learning\DTO\NewWordFrontierHint;
use App\Domain\Learning\Enums\DoneReason;
use App\Domain\Learning\Services\CardPicker;
use App\Domain\Learning\Services\NewWordFrontier;
use App\Domain\Learning\Support\NewCardBudget;
use App\Domain\Learning\Support\TrainingScope;
use App\Domain\Operations\Support\PureCache;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Word;
use App\Models\WordRepetition;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Tests\Support\HotPathFixtures as Fx;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

const NWF_NARROWED = '/from ["`]words["`] inner join ["`]lessons["`].*["`]lessons["`]\.["`]id["`] = \?/s';

const NWF_FULL = '/from ["`]words["`] inner join ["`]lessons["`](?!.*["`]lessons["`]\.["`]id["`] = \?)/s';

/**
 * The reference: the course branch of TrainingScope::newWordCandidates() as it was before the frontier - the full
 * anti-join in study order, nothing cached.
 */
function nwfLegacyFirstUnseen(int $learnerId, int $courseId): ?Word
{
    /** @var Word|null $word */
    $word = Word::query()
        ->select('words.*')
        ->join('lessons', 'lessons.id', '=', 'words.lesson_id')
        ->join('stages', 'stages.id', '=', 'lessons.stage_id')
        ->where('stages.course_id', $courseId)
        ->whereIn('stages.course_id', Course::query()->availableTo($learnerId)->select('courses.id'))
        ->where('words.is_active', true)
        ->whereNotExists(fn (QueryBuilder $q): QueryBuilder => $q
            ->from('word_repetitions')
            ->whereColumn('word_repetitions.word_id', 'words.id')
            ->where('word_repetitions.learner_id', $learnerId))
        ->orderBy('stages.number')
        ->orderBy('lessons.number')
        ->orderBy('words.id')
        ->first();

    return $word;
}

/** One new-card step of the daily review of the course; the card it introduced is pushed out of "due" so the next step introduces again. */
function nwfPick(Learner $learner, Course $course, ?NewCardBudget $budget = null): CardPick
{
    $pick = app(CardPicker::class)->pickNext((int) $learner->id, TrainingScope::daily((int) $course->id), $budget);

    if ($pick->repetition !== null) {
        WordRepetition::query()->whereKey($pick->repetition->id)->update(['next_review_at' => now()->addDays(3)]);
    }

    return $pick;
}

/** @return list<string> */
function nwfStatements(Learner $learner, Course $course, ?NewCardBudget $budget = null, ?CardPick &$pick = null): array
{
    return QueryLog::during(function () use ($learner, $course, $budget, &$pick): void {
        $pick = nwfPick($learner, $course, $budget);
    });
}

function nwfHint(Learner $learner, Course $course): ?int
{
    return app(NewWordFrontier::class)->read((int) $learner->id, (int) $course->id)?->lessonId;
}

function nwfPlant(Learner $learner, Course $course, int $lessonId): void
{
    $frontier = app(NewWordFrontier::class);
    $frontier->write(new NewWordFrontierHint($frontier->key((int) $learner->id, (int) $course->id), null), $lessonId);
}

/** A word added or switched in the admin panel: the panel's own UPDATE / INSERT, then LessonContentRefresher::wordChanged(). */
function nwfAddWord(Lesson $lesson, bool $active = true): Word
{
    $word = Word::factory()->create(['lesson_id' => $lesson->id, 'is_active' => $active]);
    app(LessonContentRefresher::class)->wordChanged($word);

    return $word;
}

function nwfSwitchWord(Word $word, bool $active): void
{
    $word->update(['is_active' => $active]);
    app(LessonContentRefresher::class)->wordChanged($word);
}

/** @return list<Lesson> the lessons of the course in study order */
function nwfLessons(Course $course): array
{
    return Lesson::query()
        ->select('lessons.*')
        ->join('stages', 'stages.id', '=', 'lessons.stage_id')
        ->where('stages.course_id', $course->id)
        ->orderBy('stages.number')->orderBy('lessons.number')
        ->get()
        ->all();
}

// --- equivalence with the full statement ------------------------------------------------------------------------

it('introduces exactly the words the full statement would, in its order, over random courses, progress and changes', function (int $seed): void {
    $rng = Fx::rng($seed);

    // A course whose modules and lessons are created out of study order (ids do not follow the order), with lessons of
    // 0-4 active and 0-2 inactive words (some lessons have no active word at all).
    $layout = [];
    foreach (Fx::scramble(range(1, $rng(1, 3)), $rng) as $stageNumber) {
        $layout[$stageNumber] = [];
        foreach (Fx::scramble(range(1, $rng(1, 4)), $rng) as $lessonNumber) {
            $layout[$stageNumber][$lessonNumber] = [$rng(0, 4), $rng(0, 2)];
        }
    }
    // Enough words that the walk does not end in the first steps: the smallest draw gets six.
    if (array_sum(array_map(fn (array $lessons): int => array_sum(array_column($lessons, 0)), $layout)) < 6) {
        $layout[array_key_first($layout)][array_key_first($layout[array_key_first($layout)])][0] = 6;
    }
    $fx = Fx::course($layout);
    $course = $fx['course'];
    $learner = Learner::factory()->create(['active_course_id' => $course->id]);
    $learnerId = (int) $learner->id;
    $all = array_merge(...array_map(fn (array $w): array => Fx::all($w), array_values($fx['words'])));

    // Progress to start from: some words (active and inactive) served or answered, a lesson "jumped" into through
    // lesson mode, and the rows of another learner, which must not count.
    foreach ($all as $wordId) {
        if ($rng(1, 100) <= 30) {
            Fx::learn($learner, [$wordId], $rng(0, 1));
        }
    }
    $other = Learner::factory()->create();
    Fx::learn($other, array_filter($all, fn (): bool => $rng(1, 100) <= 50));

    $narrowed = 0;
    $full = 0;
    for ($step = 0; $step < 30; $step++) {
        // Between two steps, the world moves: a word is switched on or off in the panel (the version of the course
        // moves), or the learner studies a whole lesson in lesson mode (rows appear, the version does not move).
        $event = $rng(1, 100);
        if ($event <= 12) {
            $candidates = Word::query()->whereIn('id', $all)->where('is_active', false)->orderBy('id')->get();
            if ($candidates->isNotEmpty()) {
                nwfSwitchWord($candidates[$rng(0, $candidates->count() - 1)], true);
            }
        } elseif ($event <= 20) {
            $candidates = Word::query()->whereIn('id', $all)->where('is_active', true)->orderBy('id')->get();
            if ($candidates->isNotEmpty()) {
                nwfSwitchWord($candidates[$rng(0, $candidates->count() - 1)], false);
            }
        } elseif ($event <= 32) {
            $lessons = nwfLessons($course);
            $lesson = $lessons[$rng(0, count($lessons) - 1)];
            $unseen = Word::query()->where('lesson_id', $lesson->id)
                ->whereNotIn('id', WordRepetition::query()->where('learner_id', $learnerId)->select('word_id'))
                ->pluck('id')->map(fn ($id): int => (int) $id)->all();
            Fx::learn($learner, $unseen, 0);
        }

        $expected = nwfLegacyFirstUnseen($learnerId, (int) $course->id);
        $statements = nwfStatements($learner, $course, null, $pick);

        expect($pick->word?->id)->toBe($expected?->id, "seed {$seed}, step {$step}");
        if ($expected === null) {
            expect($pick->doneReason)->toBe(DoneReason::NothingDue);
        }

        $narrowed += count(QueryLog::matching($statements, NWF_NARROWED));
        $full += count(QueryLog::matching($statements, NWF_FULL));
    }

    // The hint did its work: most steps looked into one lesson only.
    expect($narrowed)->toBeGreaterThan(0)
        ->and($full)->toBeLessThan($narrowed + 10);

    // What the learner has is exactly what was introduced: every row once, no word twice.
    $rows = WordRepetition::query()->where('learner_id', $learnerId)->pluck('word_id');
    expect($rows->unique()->count())->toBe($rows->count());
})->with(range(1, 12));

// --- the shape of the statements ---------------------------------------------------------------------------------

it('narrows the candidates to the hinted lesson, walks the course once per lesson boundary, and stops querying at the end', function (): void {
    $fx = Fx::course([1 => [1 => 3, 2 => 2]]);
    $course = $fx['course'];
    $learner = Learner::factory()->create(['active_course_id' => $course->id]);
    [$lesson1, $lesson2] = nwfLessons($course);

    // Cold: the full statement once, nothing narrowed; the hint is the lesson it found.
    $cold = nwfStatements($learner, $course, null, $pick);
    expect($pick->word?->id)->toBe($fx['words']['1.1']['active'][0])
        ->and(QueryLog::matching($cold, NWF_FULL))->toHaveCount(1)
        ->and(QueryLog::matching($cold, NWF_NARROWED))->toBe([])
        ->and(nwfHint($learner, $course))->toBe((int) $lesson1->id);

    // Warm: the narrowed statement only.
    foreach ([1, 2] as $i) {
        $warm = nwfStatements($learner, $course, null, $pick);
        expect($pick->word?->id)->toBe($fx['words']['1.1']['active'][$i])
            ->and(QueryLog::matching($warm, NWF_NARROWED))->toHaveCount(1)
            ->and(QueryLog::matching($warm, NWF_FULL))->toBe([]);
    }

    // The lesson is finished: the narrowed statement finds nothing, the full one runs - this once - and moves the hint.
    $boundary = nwfStatements($learner, $course, null, $pick);
    expect($pick->word?->id)->toBe($fx['words']['1.2']['active'][0])
        ->and(QueryLog::matching($boundary, NWF_NARROWED))->toHaveCount(1)
        ->and(QueryLog::matching($boundary, NWF_FULL))->toHaveCount(1)
        ->and(nwfHint($learner, $course))->toBe((int) $lesson2->id);

    $again = nwfStatements($learner, $course, null, $pick);
    expect($pick->word?->id)->toBe($fx['words']['1.2']['active'][1])
        ->and(QueryLog::matching($again, NWF_NARROWED))->toHaveCount(1)
        ->and(QueryLog::matching($again, NWF_FULL))->toBe([]);

    // The course is finished: the full statement finds nothing and "nothing left" is cached ...
    $end = nwfStatements($learner, $course, null, $pick);
    expect($pick->word)->toBeNull()
        ->and($pick->doneReason)->toBe(DoneReason::NothingDue)
        ->and(QueryLog::matching($end, NWF_FULL))->toHaveCount(1)
        ->and(nwfHint($learner, $course))->toBe(NewWordFrontier::NONE_LEFT);

    // ... so the next step neither locks the learner nor looks at the course: no transaction, no statement on it.
    $after = nwfStatements($learner, $course, null, $pick);
    expect($pick->word)->toBeNull()
        ->and($pick->doneReason)->toBe(DoneReason::NothingDue)
        ->and(QueryLog::matching($after, '/from ["`]words["`]|["`](lessons|stages|courses)["`]/'))->toBe([])
        ->and(QueryLog::matching($after, '/from ["`]learners["`]/'))->toBe([]);
    if (DB::getDriverName() === 'pgsql') {
        expect(QueryLog::matching($after, '/for (no key )?update/'))->toBe([]);
    }
});

it('does not touch the lesson scope or a daily review without a course', function (): void {
    $fx = Fx::course([1 => [1 => 2]]);
    $learner = Learner::factory()->create();
    $lessonId = (int) $fx['lessons']['1.1']->id;

    $lesson = QueryLog::during(fn () => app(CardPicker::class)->pickNext((int) $learner->id, TrainingScope::lesson($lessonId)));
    expect(QueryLog::matching($lesson, '/inner join ["`]lessons["`]/'))->toBe([])
        ->and(QueryLog::matching($lesson, '/from ["`]words["`] where/'))->toHaveCount(1)
        ->and(PureCache::store()->get(app(NewWordFrontier::class)->key((int) $learner->id, (int) $fx['course']->id)))->toBeNull();

    $none = app(CardPicker::class)->pickNext((int) Learner::factory()->create()->id, TrainingScope::daily(null));
    expect($none->doneReason)->toBe(DoneReason::NothingDue)
        ->and(TrainingScope::daily(null)->frontierCourseId())->toBeNull()
        ->and(TrainingScope::lesson($lessonId)->frontierCourseId())->toBeNull()
        ->and(TrainingScope::daily(7)->frontierCourseId())->toBe(7)
        ->and(fn () => TrainingScope::lesson($lessonId)->narrowCandidatesToLesson(Word::query(), $lessonId))->toThrow(LogicException::class);
});

// --- content changes: real write paths, no manual cache reset ------------------------------------------------------

it('finds a word added to a finished course at once: the write moved the version, "nothing left" is not read any more', function (): void {
    $fx = Fx::course([1 => [1 => 1]]);
    $course = $fx['course'];
    $learner = Learner::factory()->create(['active_course_id' => $course->id]);

    nwfPick($learner, $course);
    expect(nwfPick($learner, $course)->doneReason)->toBe(DoneReason::NothingDue)
        ->and(nwfHint($learner, $course))->toBe(NewWordFrontier::NONE_LEFT);

    // A word is added on the lesson screen of the panel.
    $added = nwfAddWord($fx['lessons']['1.1']);
    expect(nwfPick($learner, $course)->word?->id)->toBe($added->id);
    expect(nwfPick($learner, $course)->doneReason)->toBe(DoneReason::NothingDue);

    // An inactive word is switched on.
    $off = nwfAddWord($fx['lessons']['1.1'], active: false);
    expect(nwfPick($learner, $course)->doneReason)->toBe(DoneReason::NothingDue);
    nwfSwitchWord($off, true);
    expect(nwfPick($learner, $course)->word?->id)->toBe($off->id);
});

it('serves a word written into an EARLIER lesson before the words of the hinted lesson', function (): void {
    $fx = Fx::course([1 => [1 => 1, 2 => 2]]);
    $course = $fx['course'];
    $learner = Learner::factory()->create(['active_course_id' => $course->id]);
    [$lesson1, $lesson2] = nwfLessons($course);

    nwfPick($learner, $course); // lesson 1 done
    nwfPick($learner, $course); // the first of lesson 2: the hint now says lesson 2
    expect(nwfHint($learner, $course))->toBe((int) $lesson2->id);

    $early = nwfAddWord($lesson1);
    $statements = nwfStatements($learner, $course, null, $pick);
    expect($pick->word?->id)->toBe($early->id)
        ->and(QueryLog::matching($statements, NWF_NARROWED))->toBe([], 'a new version: no hint, the full statement');

    expect(nwfPick($learner, $course)->word?->id)->toBe($fx['words']['1.2']['active'][1]);
});

it('moves the version when a lesson or a module is renumbered, so the order of introduction follows', function (): void {
    $fx = Fx::course([1 => [1 => 1, 2 => 1, 3 => 1]]);
    $course = $fx['course'];
    $learner = Learner::factory()->create(['active_course_id' => $course->id]);

    nwfPick($learner, $course); // 1.1 done, the hint says lesson 1.1
    nwfPick($learner, $course); // 1.2: the hint says lesson 1.2

    // Lesson 3 is moved in front of lesson 2 (the panel saves the model: CourseTreeObserver).
    $fx['lessons']['1.3']->update(['number' => 0]);
    expect(nwfPick($learner, $course)->word?->id)->toBe($fx['words']['1.3']['active'][0]);
});

// --- stale and garbage hints -------------------------------------------------------------------------------------

it('falls back to the full statement on a hint that is stale or garbage, and serves what it would have served without one', function (): void {
    $fx = Fx::course([1 => [1 => 1, 2 => 1]]);
    $course = $fx['course'];
    $elsewhere = Fx::course([1 => [1 => 2]]);
    $learner = Learner::factory()->create(['active_course_id' => $course->id]);
    [$lesson1, $lesson2] = nwfLessons($course);
    Fx::learn($learner, $fx['words']['1.1']['active']);

    foreach ([
        'a finished lesson' => (int) $lesson1->id,
        'a lesson that does not exist' => 999_999,
        'a lesson of another course' => (int) $elsewhere['lessons']['1.1']->id,
    ] as $case => $planted) {
        WordRepetition::query()->where('learner_id', $learner->id)->whereIn('word_id', $fx['words']['1.2']['active'])->delete();
        nwfPlant($learner, $course, $planted);

        $expected = nwfLegacyFirstUnseen((int) $learner->id, (int) $course->id);
        $statements = nwfStatements($learner, $course, null, $pick);

        expect($pick->word?->id)->toBe($expected?->id, $case)
            ->and($pick->word?->id)->toBe($fx['words']['1.2']['active'][0], $case)
            ->and(QueryLog::matching($statements, NWF_NARROWED))->toHaveCount(1, $case)
            ->and(QueryLog::matching($statements, NWF_FULL))->toHaveCount(1, $case)
            ->and(nwfHint($learner, $course))->toBe((int) $lesson2->id, $case);
    }

    // A planted "nothing left" is believed until it expires: five minutes, not thirty.
    nwfPlant($learner, $course, NewWordFrontier::NONE_LEFT);
    WordRepetition::query()->where('learner_id', $learner->id)->delete();
    expect(nwfPick($learner, $course)->doneReason)->toBe(DoneReason::NothingDue);
    $this->travel(NewWordFrontier::TTL_NONE_LEFT + 1)->seconds();
    expect(nwfPick($learner, $course)->word?->id)->toBe($fx['words']['1.1']['active'][0]);

    // A lesson hint outlives that: still narrowed after five minutes, gone after thirty.
    expect(nwfHint($learner, $course))->toBe((int) $lesson1->id);
    $this->travel(NewWordFrontier::TTL_NONE_LEFT + 1)->seconds();
    $statements = nwfStatements($learner, $course, null, $pick);
    expect(QueryLog::matching($statements, NWF_NARROWED))->toHaveCount(1);
    $this->travel(NewWordFrontier::TTL + 1)->seconds();
    expect(nwfHint($learner, $course))->toBeNull();
});

it('degrades to the full statement when the pure cache throws, and reports it', function (): void {
    Exceptions::fake();
    $fx = Fx::course([1 => [1 => 2]]);
    $course = $fx['course'];
    $learner = Learner::factory()->create(['active_course_id' => $course->id]);

    app()->instance(PureCache::CONTAINER_KEY, new class(new ArrayStore) extends Repository
    {
        public function get($key, $default = null): mixed
        {
            throw new RuntimeException('redis-cache is down');
        }

        public function put($key, $value, $ttl = null): bool
        {
            throw new RuntimeException('redis-cache is down');
        }
    });

    $statements = nwfStatements($learner, $course, null, $pick);
    expect($pick->word?->id)->toBe($fx['words']['1.1']['active'][0])
        ->and(QueryLog::matching($statements, NWF_FULL))->toHaveCount(1)
        ->and(QueryLog::matching($statements, NWF_NARROWED))->toBe([])
        ->and(nwfPick($learner, $course)->word?->id)->toBe($fx['words']['1.1']['active'][1]);

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'redis-cache is down');
});

// --- the quota ---------------------------------------------------------------------------------------------------

it('keeps the quota decision in the database: a blocked pick creates no row, names the lesson, and the narrowed statement carries the count', function (): void {
    $fx = Fx::course([1 => [1 => 1, 2 => 3]]);
    $course = $fx['course'];
    $learner = Learner::factory()->create(['active_course_id' => $course->id]);
    [$lesson1, $lesson2] = nwfLessons($course);
    Fx::learn($learner, $fx['words']['1.1']['active']);

    $counted = fn (int $limit): NewCardBudget => NewCardBudget::counted(
        $limit,
        fn (int $learnerId): QueryBuilder => WordRepetition::query()->where('learner_id', $learnerId)->whereIn('word_id', $fx['words']['1.2']['active'])->toBase(),
    );

    // Nothing left today: the word is found (and the lesson remembered), not served.
    $blocked = nwfStatements($learner, $course, $counted(0), $pick);
    expect($pick->word)->toBeNull()
        ->and($pick->doneReason)->toBe(DoneReason::NewQuotaExhausted)
        ->and(WordRepetition::query()->where('learner_id', $learner->id)->whereIn('word_id', $fx['words']['1.2']['active'])->exists())->toBeFalse()
        ->and(QueryLog::matching($blocked, NWF_FULL))->toHaveCount(1)
        ->and(nwfHint($learner, $course))->toBe((int) $lesson2->id);

    // Still blocked: now the narrowed statement, with the quota count projected on it; nothing walks the course.
    $again = nwfStatements($learner, $course, $counted(0), $pick);
    expect($pick->doneReason)->toBe(DoneReason::NewQuotaExhausted)
        ->and(QueryLog::matching($again, NWF_NARROWED))->toHaveCount(1)
        ->and(QueryLog::matching($again, NWF_FULL))->toBe([])
        ->and(QueryLog::matching($again, '/quota_used/'))->toHaveCount(1);

    // Two today: two cards through the narrowed statement, the third stopped by the count it carries.
    expect(nwfPick($learner, $course, $counted(2))->word?->id)->toBe($fx['words']['1.2']['active'][0])
        ->and(nwfPick($learner, $course, $counted(2))->word?->id)->toBe($fx['words']['1.2']['active'][1])
        ->and(nwfPick($learner, $course, $counted(2))->doneReason)->toBe(DoneReason::NewQuotaExhausted)
        ->and(WordRepetition::query()->where('learner_id', $learner->id)->count())->toBe(3);
});

// --- availability ------------------------------------------------------------------------------------------------

it('serves nothing while the course is private or archived and finds the words again when it comes back', function (): void {
    $fx = Fx::course([1 => [1 => 2]]);
    $course = $fx['course'];
    $learner = Learner::factory()->create(['active_course_id' => $course->id]);
    $frontier = app(NewWordFrontier::class);
    $key = fn (): string => $frontier->key((int) $learner->id, (int) $course->id);

    expect(nwfPick($learner, $course)->word?->id)->toBe($fx['words']['1.1']['active'][0]);

    foreach ([['visibility' => 'private'], ['status' => 'archived']] as $away) {
        $back = array_map(fn (string $column): string => $column === 'visibility' ? 'public' : 'published', array_keys($away));
        $before = $key();

        $course->update($away);
        expect($key())->not->toBe($before, 'the availability moved the version')
            ->and(nwfPick($learner, $course)->doneReason)->toBe(DoneReason::NothingDue)
            ->and(nwfHint($learner, $course))->toBe(NewWordFrontier::NONE_LEFT);

        $gone = $key();
        $course->update(array_combine(array_keys($away), $back));
        expect($key())->not->toBe($gone)
            ->and(nwfPick($learner, $course)->word?->id)->toBe($fx['words']['1.1']['active'][1], json_encode($away));

        WordRepetition::query()->where('learner_id', $learner->id)->where('word_id', $fx['words']['1.1']['active'][1])->delete();
    }

    // A rename is no availability change: the version stays.
    $before = $key();
    $course->update(['title' => ['ru' => 'Другое имя', 'uz' => 'Boshqa nom'], 'position' => 5]);
    expect($key())->toBe($before);
});

it('treats a learner\'s own deck like any course: the hint applies and every deck write moves the version', function (): void {
    $learner = Learner::factory()->create();
    $decks = app(LearnerDeckService::class);
    $deck = $decks->create($learner, 'Мои слова');
    $decks->importText($learner, $deck, "alpha — альфа\nbeta — бета", 'list');
    $learner->update(['active_course_id' => $deck->id]);
    $words = Word::query()->whereIn('lesson_id', Lesson::query()->whereIn('stage_id', $deck->stages()->select('id'))->select('id'))->orderBy('id')->get();

    expect($words)->toHaveCount(2);

    $first = nwfStatements($learner, $deck, null, $pick);
    expect($pick->word?->id)->toBe($words[0]->id)
        ->and(QueryLog::matching($first, NWF_FULL))->toHaveCount(1);

    $second = nwfStatements($learner, $deck, null, $pick);
    expect($pick->word?->id)->toBe($words[1]->id)
        ->and(QueryLog::matching($second, NWF_NARROWED))->toHaveCount(1)
        ->and(nwfPick($learner, $deck)->doneReason)->toBe(DoneReason::NothingDue)
        ->and(nwfHint($learner, $deck))->toBe(NewWordFrontier::NONE_LEFT);

    // Words added to the deck are found at once (addWords ends in CoursePublisher::recount(), which moves the version).
    $decks->importText($learner, $deck, 'gamma — гамма', 'list');
    $gamma = Word::query()->where('word', 'gamma')->firstOrFail();
    expect(nwfPick($learner, $deck)->word?->id)->toBe($gamma->id);

    // A removed (deactivated) word is no candidate; a re-added one is again.
    $decks->removeWord($learner, $deck, (int) $words[0]->id);
    WordRepetition::query()->where('learner_id', $learner->id)->delete();
    expect(nwfPick($learner, $deck)->word?->id)->toBe($words[1]->id);
    $decks->importText($learner, $deck, 'alpha — альфа', 'list');
    expect(nwfPick($learner, $deck)->word?->id)->toBe($words[0]->id);
});

// --- the hint of one learner is nobody else's ---------------------------------------------------------------------

it('keeps one hint per learner and course, untouched by other learners, lesson-mode progress and the other courses', function (): void {
    $fx = Fx::course([1 => [1 => 1, 2 => 1, 3 => 1]]);
    $course = $fx['course'];
    $other = Fx::course([1 => [1 => 1]]);
    $learner = Learner::factory()->create(['active_course_id' => $course->id]);
    $friend = Learner::factory()->create(['active_course_id' => $course->id]);
    [$lesson1, $lesson2, $lesson3] = nwfLessons($course);

    nwfPick($learner, $course);
    nwfPick($learner, $course);
    expect(nwfHint($learner, $course))->toBe((int) $lesson2->id)
        ->and(nwfHint($friend, $course))->toBeNull()
        ->and(nwfHint($learner, $other['course']))->toBeNull();

    // The friend walks the course from the start, with their own hint.
    expect(nwfPick($friend, $course)->word?->id)->toBe($fx['words']['1.1']['active'][0])
        ->and(nwfHint($friend, $course))->toBe((int) $lesson1->id)
        ->and(nwfHint($learner, $course))->toBe((int) $lesson2->id);

    // The learner studies lesson 3 in lesson mode (rows, no version change): the hint is a lower bound, still right.
    $inLesson = app(CardPicker::class)->pickNext((int) $learner->id, TrainingScope::lesson((int) $lesson3->id));
    expect($inLesson->word?->id)->toBe($fx['words']['1.3']['active'][0]);
    WordRepetition::query()->whereKey($inLesson->repetition?->id)->update(['next_review_at' => now()->addDays(3)]);
    expect(nwfPick($learner, $course)->doneReason)->toBe(DoneReason::NothingDue)
        ->and(nwfHint($learner, $course))->toBe(NewWordFrontier::NONE_LEFT)
        ->and(nwfHint($friend, $course))->toBe((int) $lesson1->id);
});

it('writes nothing when the pick fails', function (): void {
    $fx = Fx::course([1 => [1 => 1]]);
    $course = $fx['course'];
    $learner = Learner::factory()->create(['active_course_id' => $course->id]);

    WordRepetition::creating(function (): never {
        throw new RuntimeException('insert failed');
    });

    expect(fn () => nwfPick($learner, $course))->toThrow(RuntimeException::class)
        ->and(nwfHint($learner, $course))->toBeNull()
        ->and(WordRepetition::query()->where('learner_id', $learner->id)->exists())->toBeFalse();
});
