<?php

declare(strict_types=1);

use App\Domain\Learning\Exceptions\ExamSessionException;
use App\Domain\Learning\Services\ExamQuestionBuilder;
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

    // target words + three candidate layers (same stage, anywhere, same lesson)
    expect($translationQueries)->toBeLessThanOrEqual(4);
});
