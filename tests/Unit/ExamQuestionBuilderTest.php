<?php

declare(strict_types=1);

use App\Domain\Learning\Exceptions\ExamSessionException;
use App\Domain\Learning\Services\ExamQuestionBuilder;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Word;
use App\Models\WordTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function seedLessonForBuilder(int $wordsInTarget = 10, int $distractors = 30): Lesson
{
    $stage = Stage::factory()->create();
    $target = Lesson::factory()->for($stage)->create();
    Word::factory()->count($wordsInTarget)->for($target)->sequence(
        ...array_map(fn (int $i): array => [
            'word' => "w{$i}",
            'translation' => "tr{$i}",
        ], range(0, $wordsInTarget - 1)),
    )->create();

    $sameStageOther = Lesson::factory()->for($stage)->create();
    Word::factory()->count($distractors)->for($sameStageOther)->sequence(
        ...array_map(fn (int $i): array => ['translation' => "d{$i}"], range(0, max(0, $distractors - 1))),
    )->create();

    return $target;
}

function assertWellFormedQuestion(array $q): void
{
    expect($q)->toHaveKeys(['word_id', 'word', 'correct_translation', 'options'])
        ->and($q)->not->toHaveKey('correct_index')
        ->and(count($q['options']))->toBeGreaterThanOrEqual(ExamQuestionBuilder::MIN_OPTIONS_PER_QUESTION)
        ->and(count($q['options']))->toBeLessThanOrEqual(ExamQuestionBuilder::OPTIONS_PER_QUESTION);

    // Options unique (case/whitespace-insensitively) and the correct answer present exactly once.
    $normalized = array_map(fn (string $o): string => mb_strtolower(trim($o)), $q['options']);
    expect(count(array_unique($normalized)))->toBe(count($q['options']));
    expect(array_keys($q['options'], $q['correct_translation'], true))->toHaveCount(1);

    foreach ($q['options'] as $option) {
        expect($option)->not->toContain('filler');
    }
}

it('builds N questions with 4 distinct real options, correct answer present exactly once', function (): void {
    $lesson = seedLessonForBuilder(10, 30);

    $questions = app(ExamQuestionBuilder::class)->build($lesson, totalQuestions: 5, locale: 'ru');

    expect($questions)->toHaveCount(5);

    foreach ($questions as $q) {
        assertWellFormedQuestion($q);
        expect($q['options'])->toHaveCount(ExamQuestionBuilder::OPTIONS_PER_QUESTION);
    }
});

it('picks each word at most once within a session', function (): void {
    $lesson = seedLessonForBuilder(10, 20);

    $questions = app(ExamQuestionBuilder::class)->build($lesson, totalQuestions: 10, locale: 'ru');

    $wordIds = array_column($questions, 'word_id');
    expect(count($wordIds))->toBe(count(array_unique($wordIds)));
});

it('clamps totalQuestions to the words available', function (): void {
    $lesson = seedLessonForBuilder(5, 20);

    $questions = app(ExamQuestionBuilder::class)->build($lesson, totalQuestions: 100, locale: 'ru');

    expect(count($questions))->toBeGreaterThanOrEqual(ExamQuestionBuilder::MIN_QUESTIONS)
        ->and(count($questions))->toBeLessThanOrEqual(5);
});

it('returns empty array when the lesson has fewer than MIN_QUESTIONS words', function (): void {
    $stage = Stage::factory()->create();
    $lesson = Lesson::factory()->for($stage)->create();
    Word::factory()->count(2)->for($lesson)->create();

    $questions = app(ExamQuestionBuilder::class)->build($lesson, totalQuestions: 10, locale: 'ru');

    expect($questions)->toBeEmpty();
});

it('never emits filler options: falls back to same-lesson words when the library is tiny', function (): void {
    $stage = Stage::factory()->create();
    $target = Lesson::factory()->for($stage)->create();
    Word::factory()->count(5)->for($target)->sequence(
        ['translation' => 'aa'],
        ['translation' => 'bb'],
        ['translation' => 'cc'],
        ['translation' => 'dd'],
        ['translation' => 'ee'],
    )->create();

    // Only one other word in the whole DB → the same lesson must supply the rest.
    $other = Lesson::factory()->for($stage)->create();
    Word::factory()->for($other)->create(['translation' => 'only-one']);

    $questions = app(ExamQuestionBuilder::class)->build($target, totalQuestions: 4, locale: 'ru');

    expect($questions)->toHaveCount(4);
    foreach ($questions as $q) {
        assertWellFormedQuestion($q);
        expect($q['options'])->toHaveCount(4);
    }
});

it('prefers distractors from the same stage and the same part of speech', function (): void {
    $stage = Stage::factory()->create();
    $target = Lesson::factory()->for($stage)->create();
    Word::factory()->count(4)->for($target)->sequence(
        ...array_map(fn (int $i): array => [
            'translation' => "t{$i}",
            'part_of_speech' => 'verb',
        ], range(0, 3)),
    )->create();

    $sameStage = Lesson::factory()->for($stage)->create();
    Word::factory()->count(3)->for($sameStage)->sequence(
        ...array_map(fn (int $i): array => [
            'translation' => "same-stage-verb-{$i}",
            'part_of_speech' => 'verb',
        ], range(0, 2)),
    )->create();
    Word::factory()->count(10)->for($sameStage)->sequence(
        ...array_map(fn (int $i): array => [
            'translation' => "same-stage-noun-{$i}",
            'part_of_speech' => 'noun',
        ], range(0, 9)),
    )->create();

    $otherStage = Stage::factory()->create();
    $otherLesson = Lesson::factory()->for($otherStage)->create();
    Word::factory()->count(10)->for($otherLesson)->sequence(
        ...array_map(fn (int $i): array => [
            'translation' => "other-stage-verb-{$i}",
            'part_of_speech' => 'verb',
        ], range(0, 9)),
    )->create();

    $questions = app(ExamQuestionBuilder::class)->build($target, totalQuestions: 4, locale: 'ru');

    foreach ($questions as $q) {
        $distractors = array_values(array_filter($q['options'], fn (string $o): bool => $o !== $q['correct_translation']));
        expect($distractors)->toHaveCount(3);
        foreach ($distractors as $d) {
            expect($d)->toStartWith('same-stage-verb-');
        }
    }
});

it('de-duplicates distractors case- and whitespace-insensitively and excludes the correct answer', function (): void {
    $stage = Stage::factory()->create();
    $target = Lesson::factory()->for($stage)->create();
    Word::factory()->count(4)->for($target)->sequence(
        ['translation' => 'Дом'],
        ['translation' => 'Кот'],
        ['translation' => 'Сад'],
        ['translation' => 'Мир'],
    )->create();

    // Noisy pool: variants of the correct answers + duplicates differing only by case/spaces.
    $other = Lesson::factory()->for($stage)->create();
    Word::factory()->count(8)->for($other)->sequence(
        ['translation' => 'дом'],
        ['translation' => ' Дом '],
        ['translation' => 'кот'],
        ['translation' => 'Река'],
        ['translation' => 'река'],
        ['translation' => 'РЕКА'],
        ['translation' => 'Лес'],
        ['translation' => 'лес  '],
    )->create();

    $questions = app(ExamQuestionBuilder::class)->build($target, totalQuestions: 4, locale: 'ru');

    foreach ($questions as $q) {
        assertWellFormedQuestion($q);
        $norm = array_map(fn (string $o): string => mb_strtolower(preg_replace('/\s+/u', ' ', trim($o)) ?? $o), $q['options']);
        expect(count(array_unique($norm)))->toBe(count($q['options']));
    }
});

it('emits fewer options (but at least 2) when the whole library cannot supply 3 real distractors', function (): void {
    $stage = Stage::factory()->create();
    $target = Lesson::factory()->for($stage)->create();
    // 4 words, but only 2 distinct translations overall → each word has exactly 1 real distractor.
    Word::factory()->count(4)->for($target)->sequence(
        ['translation' => 'one'],
        ['translation' => 'ONE'],
        ['translation' => 'two'],
        ['translation' => 'Two '],
    )->create();

    $questions = app(ExamQuestionBuilder::class)->build($target, totalQuestions: 4, locale: 'ru');

    expect($questions)->toHaveCount(4);
    foreach ($questions as $q) {
        assertWellFormedQuestion($q);
        expect($q['options'])->toHaveCount(2);
    }
});

it('refuses to build when a word has no real distractor at all', function (): void {
    $stage = Stage::factory()->create();
    $target = Lesson::factory()->for($stage)->create();
    Word::factory()->count(4)->for($target)->create(['translation' => 'same']);

    expect(fn () => app(ExamQuestionBuilder::class)->build($target, totalQuestions: 4, locale: 'ru'))
        ->toThrow(ExamSessionException::class, 'distractor');
});

// ---------------------------------------------------------------------------
// Locale of the group (docs/12 §1.7): translations by locale, ru fallback
// ---------------------------------------------------------------------------

/**
 * A target lesson with $targets words and a sibling lesson (same stage) with $others words. Every word
 * gets a ru row (`ru-<name>`); words listed in $withUz also get a uz row (`uz-<name>`).
 *
 * @param  list<string>  $withUz  word names that have a uz translation
 */
function localizedLessons(int $targets, int $others, array $withUz): Lesson
{
    $stage = Stage::factory()->create();
    $target = Lesson::factory()->for($stage)->create();
    $sibling = Lesson::factory()->for($stage)->create();

    $make = function (Lesson $lesson, string $prefix, int $count) use ($withUz): void {
        for ($i = 0; $i < $count; $i++) {
            $name = "{$prefix}{$i}";
            $word = Word::factory()->for($lesson)->create(['word' => $name, 'translation' => "ru-{$name}", 'part_of_speech' => 'noun']);
            WordTranslation::factory()->create(['word_id' => $word->id, 'locale' => 'ru', 'translation' => "ru-{$name}"]);
            if (in_array($name, $withUz, true)) {
                WordTranslation::factory()->uz()->create(['word_id' => $word->id, 'translation' => "uz-{$name}"]);
            }
        }
    };
    $make($target, 't', $targets);
    $make($sibling, 'o', $others);

    return $target;
}

it('builds the question in the locale of the group when every word has a translation in it', function (): void {
    $lesson = localizedLessons(5, 12, array_merge(
        array_map(fn (int $i): string => "t{$i}", range(0, 4)),
        array_map(fn (int $i): string => "o{$i}", range(0, 11)),
    ));

    $questions = app(ExamQuestionBuilder::class)->build($lesson, totalQuestions: 5, locale: 'uz');

    expect($questions)->toHaveCount(5);
    foreach ($questions as $q) {
        assertWellFormedQuestion($q);
        expect($q['correct_translation'])->toBe('uz-'.$q['word']);
        foreach ($q['options'] as $option) {
            expect($option)->toStartWith('uz-');
        }
    }
});

it('keeps the ru texts for a ru group even when uz rows exist', function (): void {
    $lesson = localizedLessons(5, 12, array_map(fn (int $i): string => "t{$i}", range(0, 4)));

    foreach (app(ExamQuestionBuilder::class)->build($lesson, totalQuestions: 5, locale: 'ru') as $q) {
        expect($q['correct_translation'])->toBe('ru-'.$q['word']);
        foreach ($q['options'] as $option) {
            expect($option)->toStartWith('ru-');
        }
    }
});

it('falls back to ru for a word without uz and then picks ru distractors, so the language does not give the answer away', function (): void {
    // Targets t0..t3 have NO uz; 6 of the 12 sibling words have uz, 6 do not.
    $withUz = array_map(fn (int $i): string => "o{$i}", range(0, 5));
    $lesson = localizedLessons(4, 12, $withUz);

    $questions = app(ExamQuestionBuilder::class)->build($lesson, totalQuestions: 4, locale: 'uz');

    foreach ($questions as $q) {
        assertWellFormedQuestion($q);
        expect($q['correct_translation'])->toBe('ru-'.$q['word']);
        // 6 ru-only candidates exist, so all 3 distractors are ru as well: no uz option beside a ru answer.
        foreach ($q['options'] as $option) {
            expect($option)->toStartWith('ru-');
        }
    }
});

it('picks uz distractors for a uz answer even when ru-only candidates are plentiful', function (): void {
    // Only t0..t3 have uz plus 4 sibling words; 8 sibling words are ru-only.
    $withUz = array_merge(['t0', 't1', 't2', 't3'], ['o0', 'o1', 'o2', 'o3']);
    $lesson = localizedLessons(4, 12, $withUz);

    foreach (app(ExamQuestionBuilder::class)->build($lesson, totalQuestions: 4, locale: 'uz') as $q) {
        expect($q['correct_translation'])->toStartWith('uz-');
        foreach ($q['options'] as $option) {
            expect($option)->toStartWith('uz-');
        }
    }
});

it('mixes languages only as a last resort, when the answer language cannot supply enough distractors', function (): void {
    // Only t0 has uz and nothing else does, so its distractors must be the ru texts of other words.
    $lesson = localizedLessons(4, 12, ['t0']);

    $questions = collect(app(ExamQuestionBuilder::class)->build($lesson, totalQuestions: 4, locale: 'uz'))->keyBy('word');

    $q = $questions['t0'];
    assertWellFormedQuestion($q);
    expect($q['correct_translation'])->toBe('uz-t0')
        ->and($q['options'])->toHaveCount(ExamQuestionBuilder::OPTIONS_PER_QUESTION);
    foreach (array_diff($q['options'], ['uz-t0']) as $option) {
        expect($option)->toStartWith('ru-');
    }
});

it('resolves translations in batches, not one query per word', function (): void {
    $lesson = localizedLessons(10, 30, []);

    DB::enableQueryLog();
    app(ExamQuestionBuilder::class)->build($lesson, totalQuestions: 10, locale: 'uz');
    $translationQueries = collect(DB::getQueryLog())
        ->filter(fn (array $q): bool => str_contains($q['query'], 'word_translations'))
        ->count();
    DB::disableQueryLog();

    // target words + candidate layers (same stage, other stages of the course, same lesson, foreign courses)
    expect($translationQueries)->toBeLessThanOrEqual(5);
});

// ---------------------------------------------------------------------------
// Inactive words (W1) and the course boundary of distractors (W2)
// ---------------------------------------------------------------------------

/** @return list<string> every option of every question */
function allOptions(array $questions): array
{
    return collect($questions)->pluck('options')->flatten()->all();
}

function wordsIn(Lesson $lesson, string $prefix, int $count, array $attrs = []): void
{
    for ($i = 0; $i < $count; $i++) {
        Word::factory()->for($lesson)->create(['word' => "{$prefix}{$i}", 'translation' => "{$prefix}-{$i}"] + $attrs);
    }
}

function lessonInCourse(Course $course, ?Stage $stage = null): Lesson
{
    $stage ??= Stage::factory()->create(['course_id' => $course->id]);

    return Lesson::factory()->for($stage)->create();
}

it('never asks an inactive word and never uses an inactive word as a distractor', function (): void {
    $course = Course::factory()->published()->create();
    $stage = Stage::factory()->create(['course_id' => $course->id]);
    $target = lessonInCourse($course, $stage);
    wordsIn($target, 'live', 5);
    wordsIn($target, 'off-target', 5, ['is_active' => false]);
    wordsIn(lessonInCourse($course, $stage), 'sibling', 10);
    wordsIn(lessonInCourse($course, $stage), 'off-sibling', 10, ['is_active' => false]);
    wordsIn(lessonInCourse($course), 'other-stage', 10);
    wordsIn(lessonInCourse($course), 'off-other-stage', 10, ['is_active' => false]);

    $questions = app(ExamQuestionBuilder::class)->build($target, totalQuestions: 30, locale: 'ru');

    expect($questions)->toHaveCount(5);
    foreach ($questions as $q) {
        expect($q['word'])->toStartWith('live');
    }
    foreach (allOptions($questions) as $option) {
        expect($option)->not->toStartWith('off-');
    }
});

it('does not count inactive words of the target lesson towards the minimum', function (): void {
    $course = Course::factory()->published()->create();
    $target = lessonInCourse($course);
    wordsIn($target, 'live', 3);
    wordsIn($target, 'off', 5, ['is_active' => false]);
    wordsIn(lessonInCourse($course), 'sibling', 10);

    expect(app(ExamQuestionBuilder::class)->build($target, totalQuestions: 10, locale: 'ru'))->toBe([]);
});

it('takes distractors from the own course (other stages included) before touching foreign courses', function (): void {
    $course = Course::factory()->published()->create();
    $stage = Stage::factory()->create(['course_id' => $course->id]);
    $target = lessonInCourse($course, $stage);
    wordsIn($target, 'w', 5);
    wordsIn(lessonInCourse($course, $stage), 'stage', 2);
    wordsIn(lessonInCourse($course), 'course', 20);

    $foreign = Course::factory()->published()->create();
    wordsIn(lessonInCourse($foreign), 'foreign', 60);

    $options = allOptions(app(ExamQuestionBuilder::class)->build($target, totalQuestions: 5, locale: 'ru'));

    expect($options)->not->toBeEmpty();
    foreach ($options as $option) {
        expect($option)->not->toStartWith('foreign-');
    }
    // other stages of the SAME course do supply distractors once the stage is exhausted
    expect(collect($options)->contains(fn (string $o): bool => str_starts_with($o, 'course-')))->toBeTrue();
});

it('uses published foreign courses only as the last layer', function (): void {
    $course = Course::factory()->published()->create();
    $target = lessonInCourse($course);
    // 4 words with ONE translation: the own course has no real distractor at all.
    Word::factory()->count(4)->for($target)->create(['translation' => 'same']);

    $foreign = Course::factory()->published()->create();
    wordsIn(lessonInCourse($foreign), 'foreign', 10);

    $questions = app(ExamQuestionBuilder::class)->build($target, totalQuestions: 4, locale: 'ru');

    expect($questions)->toHaveCount(4);
    foreach ($questions as $q) {
        expect(collect($q['options'])->reject(fn (string $o): bool => $o === 'same')->every(fn (string $o): bool => str_starts_with($o, 'foreign-')))->toBeTrue();
    }
});

it('never takes distractors from foreign courses outside the published + public + platform whitelist', function (string $status, string $visibility, string $ownerType): void {
    $course = Course::factory()->published()->create();
    $target = lessonInCourse($course);
    Word::factory()->count(4)->for($target)->create(['translation' => 'same']);

    $foreign = Course::factory()->create(['status' => $status, 'visibility' => $visibility, 'owner_type' => $ownerType]);
    wordsIn(lessonInCourse($foreign), 'foreign', 10);

    expect(fn () => app(ExamQuestionBuilder::class)->build($target, totalQuestions: 4, locale: 'ru'))
        ->toThrow(ExamSessionException::class, 'distractor');
})->with([
    'draft' => ['draft', 'public', 'platform'],
    'review' => ['review', 'public', 'platform'],
    'archived' => ['archived', 'public', 'platform'],
    'published but private' => ['published', 'private', 'platform'],
    'published but unlisted' => ['published', 'unlisted', 'platform'],
    'published public but not a platform course' => ['published', 'public', 'organization'],
]);

it('borrows answer-language distractors from a foreign course when the own course has only ru-only words', function (): void {
    // Own course: 40 ru-only sibling words (enough in total, but none of them in uz) + 4 target words, 2 of them in uz.
    $target = localizedLessons(4, 40, ['t0', 't1']);

    $foreign = Course::factory()->published()->create();
    $foreignLesson = lessonInCourse($foreign);
    for ($i = 0; $i < 10; $i++) {
        $word = Word::factory()->for($foreignLesson)->create(['word' => "f{$i}", 'translation' => "ru-f{$i}"]);
        WordTranslation::factory()->uz()->create(['word_id' => $word->id, 'translation' => "uz-f{$i}"]);
    }

    $questions = collect(app(ExamQuestionBuilder::class)->build($target, totalQuestions: 4, locale: 'uz'))->keyBy('word');

    foreach (['t0', 't1'] as $name) {
        $q = $questions[$name];
        assertWellFormedQuestion($q);
        expect($q['options'])->toHaveCount(ExamQuestionBuilder::OPTIONS_PER_QUESTION);
        foreach ($q['options'] as $option) {
            expect($option)->toStartWith('uz-');
        }
        // the lesson offers one other uz word; the other two distractors can only come from the foreign course
        expect(collect($q['options'])->filter(fn (string $o): bool => str_starts_with($o, 'uz-f'))->count())->toBeGreaterThanOrEqual(2);
    }
});

it('does not load foreign courses while the own course supplies enough answer-language candidates', function (): void {
    $stage = Stage::factory()->create(['course_id' => Course::factory()->published()->create()->id]);
    $target = Lesson::factory()->for($stage)->create();
    $sibling = Lesson::factory()->for($stage)->create();
    foreach ([[$target, 't', 4], [$sibling, 'o', 40]] as [$lesson, $prefix, $count]) {
        for ($i = 0; $i < $count; $i++) {
            $word = Word::factory()->for($lesson)->create(['word' => "{$prefix}{$i}", 'translation' => "ru-{$prefix}{$i}"]);
            WordTranslation::factory()->uz()->create(['word_id' => $word->id, 'translation' => "uz-{$prefix}{$i}"]);
        }
    }

    $foreign = Course::factory()->published()->create();
    wordsIn(lessonInCourse($foreign), 'uz-foreign', 10);

    foreach (app(ExamQuestionBuilder::class)->build($target, totalQuestions: 4, locale: 'uz') as $q) {
        foreach ($q['options'] as $option) {
            expect($option)->not->toContain('foreign');
        }
    }
});

// --- forWords (personal mocks, docs/13 §8.4) ------------------------------------

/** @return array{course: Course, words: list<Word>} */
function seedCourseForWords(int $words, bool $withForeign = false): array
{
    $course = Course::factory()->published()->create(['exam_tag' => 'ielts']);
    $stage = Stage::factory()->create(['course_id' => $course->id]);
    $lessonA = Lesson::factory()->for($stage)->create();
    $lessonB = Lesson::factory()->for($stage)->create();

    $list = [];
    foreach (range(0, $words - 1) as $i) {
        $list[] = Word::factory()->for($i % 2 === 0 ? $lessonA : $lessonB)->create([
            'word' => "cw{$i}",
            'translation' => "course translation {$i}",
            'is_active' => true,
        ]);
    }
    Word::factory()->for($lessonA)->create(['word' => 'inactive', 'translation' => 'inactive translation', 'is_active' => false]);

    if ($withForeign) {
        $foreign = Course::factory()->published()->create();
        $lesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $foreign->id]))->create();
        foreach (range(0, 9) as $i) {
            Word::factory()->for($lesson)->create(['word' => "fw{$i}", 'translation' => "foreign translation {$i}"]);
        }
    }

    return ['course' => $course, 'words' => $list];
}

it('forWords builds one question per given word, in the given order, from the words of the course', function (): void {
    ['course' => $course, 'words' => $words] = seedCourseForWords(12);
    $chosen = [$words[5], $words[0], $words[9], $words[2]];

    $questions = app(ExamQuestionBuilder::class)->forWords($chosen, $course, 'ru');

    expect(array_column($questions, 'word_id'))->toBe(array_map(fn (Word $w): int => (int) $w->id, $chosen));
    foreach ($questions as $q) {
        assertWellFormedQuestion($q);
        expect(count($q['options']))->toBe(4);
        foreach ($q['options'] as $option) {
            expect($option)->toStartWith('course translation')
                ->and($option)->not->toBe('inactive translation');
        }
    }
});

it('forWords borrows public platform courses only when the course itself is too small', function (): void {
    ['course' => $small, 'words' => $words] = seedCourseForWords(2, withForeign: true);
    $questions = app(ExamQuestionBuilder::class)->forWords($words, $small, 'ru');

    expect($questions)->toHaveCount(2);
    foreach ($questions as $q) {
        assertWellFormedQuestion($q);
        // The one other course word comes first, the public course fills the remaining two slots.
        expect(count($q['options']))->toBe(4)
            ->and(count(array_filter($q['options'], fn (string $o): bool => str_starts_with($o, 'course translation'))))->toBe(2)
            ->and(count(array_filter($q['options'], fn (string $o): bool => str_starts_with($o, 'foreign translation'))))->toBe(2);
    }

    ['course' => $big, 'words' => $bigWords] = seedCourseForWords(50);
    foreach (app(ExamQuestionBuilder::class)->forWords(array_slice($bigWords, 0, 10), $big, 'ru') as $q) {
        foreach ($q['options'] as $option) {
            expect($option)->not->toStartWith('foreign translation');
        }
    }
});

it('forWords returns nothing for an empty list and throws when a word has no distractor at all', function (): void {
    $course = Course::factory()->published()->create(['exam_tag' => 'sat']);
    $lesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $course->id]))->create();
    $only = Word::factory()->for($lesson)->create(['word' => 'alone', 'translation' => 'один']);

    $builder = app(ExamQuestionBuilder::class);
    expect($builder->forWords([], $course, 'ru'))->toBe([]);
    expect(fn () => $builder->forWords([$only], $course, 'ru'))
        ->toThrow(ExamSessionException::class);
});
