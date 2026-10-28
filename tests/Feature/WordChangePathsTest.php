<?php

declare(strict_types=1);

/*
 * Two derived numbers are read on the hot paths instead of being counted: `courses.words_count` (the `total`
 * of the catalog progress and the card of a course) and the cached `lesson:{id}:word_count` (`progress.total`
 * of every card of a lesson session). Every production path that changes the ACTIVE words of a lesson has to
 * keep both in step: the vocabulary import and the learner decks are checked here, the admin panel (switching
 * a word off / on, adding a word on the lesson screen) in WordTranslationsAdminTest.
 */

use App\Domain\Content\Services\LearnerDeckService;
use App\Domain\Content\Services\VocabularyImporter;
use App\Domain\Learning\Services\LearningCache;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Reads the cached count of every lesson first, so that a missed invalidation shows up as a stale number. */
function warmLessonCounts(Course $course): void
{
    foreach (Lesson::query()->whereHas('stage', fn ($q) => $q->where('course_id', $course->id))->pluck('id') as $lessonId) {
        app(LearningCache::class)->lessonWordCount((int) $lessonId);
    }
}

/**
 * The counters of a course against the words as they are: the denormalised total and the cached count of each lesson.
 *
 * @return array{words_count: int, live: int, lessons: array<int, array{cached: int, live: int}>}
 */
function wordCounters(Course $course): array
{
    $lessons = [];
    foreach (Lesson::query()->whereHas('stage', fn ($q) => $q->where('course_id', $course->id))->orderBy('id')->get() as $lesson) {
        $lessons[$lesson->id] = [
            'cached' => app(LearningCache::class)->lessonWordCount((int) $lesson->id),
            'live' => Word::query()->where('lesson_id', $lesson->id)->where('is_active', true)->count(),
        ];
    }

    return [
        'words_count' => Course::query()->findOrFail($course->id)->words_count,
        'live' => array_sum(array_column($lessons, 'live')),
        'lessons' => $lessons,
    ];
}

function assertCountersInStep(Course $course): void
{
    $counters = wordCounters($course);

    expect($counters['words_count'])->toBe($counters['live']);
    foreach ($counters['lessons'] as $lesson) {
        expect($lesson['cached'])->toBe($lesson['live']);
    }
}

it('keeps words_count and the lesson cache in step through the vocabulary import', function (): void {
    $importer = app(VocabularyImporter::class);
    $json = fn (int $lesson, array $words): string => json_encode([
        'stage' => ['number' => 1, 'title' => 'Beginner'],
        'lesson' => ['number' => $lesson, 'title' => "L{$lesson}"],
        'words' => array_map(fn (string $w): array => ['word' => $w, 'translation' => "т-{$w}"], $words),
    ], JSON_THROW_ON_ERROR);

    expect($importer->importAsSystem($json(1, ['one', 'two']))->ok())->toBeTrue();
    $course = Course::query()->sole();
    assertCountersInStep($course);
    expect(wordCounters($course)['words_count'])->toBe(2);

    // The same lesson grows, another one appears; the cached count of the first was warm.
    warmLessonCounts($course);
    expect($importer->importAsSystem($json(1, ['one', 'two', 'three']))->ok())->toBeTrue();
    assertCountersInStep($course);

    warmLessonCounts($course);
    expect($importer->importAsSystem($json(2, ['four']))->ok())->toBeTrue();
    assertCountersInStep($course);
    expect(wordCounters($course)['words_count'])->toBe(4);
});

it('keeps words_count and the lesson cache in step through learner decks: add, revive, remove, archive', function (): void {
    $learner = Learner::factory()->onboarded()->create();
    $decks = app(LearnerDeckService::class);
    $deck = $decks->create($learner, 'Мои слова');
    $list = fn (array $words): string => implode("\n", array_map(fn (string $w): string => "{$w} — слово-{$w}", $words));

    $decks->importText($learner, $deck, $list(['apple', 'pear', 'plum']), 'list');
    assertCountersInStep($deck);
    expect(wordCounters($deck)['words_count'])->toBe(3);

    warmLessonCounts($deck);
    $decks->importText($learner, $deck, $list(['cherry', 'fig']), 'list');
    assertCountersInStep($deck);
    expect(wordCounters($deck)['words_count'])->toBe(5);

    $pear = (int) Word::query()->where('word', 'pear')->value('id');
    warmLessonCounts($deck);
    $decks->removeWord($learner, $deck, $pear);
    assertCountersInStep($deck);
    expect(wordCounters($deck)['words_count'])->toBe(4);

    // The removed word comes back by importing it again.
    warmLessonCounts($deck);
    $decks->importText($learner, $deck, $list(['pear']), 'list');
    assertCountersInStep($deck);
    expect(wordCounters($deck)['words_count'])->toBe(5);

    warmLessonCounts($deck);
    $decks->archive($learner, $deck);
    assertCountersInStep($deck);
    expect(wordCounters($deck)['words_count'])->toBe(0);
});
