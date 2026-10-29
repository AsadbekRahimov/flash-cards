<?php

declare(strict_types=1);

use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\DTO\PairsRound;
use App\Domain\Practice\DTO\RetryMaterial;
use App\Domain\Practice\DTO\SnapshotElement;
use App\Domain\Practice\Enums\AudioStatus;
use App\Domain\Practice\Enums\PracticeItemType;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Modes\PairsMode;
use App\Domain\Practice\Services\PracticeMaterialService;
use App\Domain\Practice\Services\PracticeSnapshotBuilder;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PracticeItem;
use App\Models\PracticeItemVersion;
use App\Models\Stage;
use App\Models\Word;
use App\Models\WordTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * docs/16 §4.1-§4.2: what a learner may practice on - the words they have been introduced to, the published tasks whose target
 * words are all introduced - and the access to the course / lesson. At the end: the pairs (§4.3, §4.4) on the real handler - how
 * many rounds N words make, how the words that clash are kept apart, the language of the learner, the two languages of a battle.
 */

uses(RefreshDatabase::class);

function matService(): PracticeMaterialService
{
    return app(PracticeMaterialService::class);
}

it('finds the course by slug for a learner who may open it: published, not private, or their own deck', function (): void {
    [$course] = PracticeKit::course();
    $learner = Learner::factory()->create();
    $deck = Course::factory()->deckOf($learner->id)->create(['slug' => 'my-deck']);
    Course::factory()->deckOf(Learner::factory()->create()->id)->create(['slug' => 'their-deck']);
    Course::factory()->create(['slug' => 'draft']);
    Course::factory()->published()->create(['slug' => 'archived', 'status' => 'archived']);
    Course::factory()->published()->create(['slug' => 'unlisted', 'visibility' => 'unlisted']);

    expect(matService()->course($course->slug, $learner)->is($course))->toBeTrue()
        ->and(matService()->course('my-deck', $learner)->is($deck))->toBeTrue()
        ->and(matService()->course('unlisted', $learner)->slug)->toBe('unlisted')
        ->and(matService()->courseById($deck->id, $learner)->is($deck))->toBeTrue();

    foreach (['their-deck', 'draft', 'archived', 'nope'] as $slug) {
        expect(fn () => matService()->course($slug, $learner))
            ->toThrow(fn (PracticeException $e) => expect($e->errorCode)->toBe('course_not_found')->and($e->httpStatus())->toBe(404));
    }
    expect(fn () => matService()->courseById(Course::query()->where('slug', 'their-deck')->value('id'), $learner))->toThrow(PracticeException::class);
});

it('opens a course to a battle when it is published and not private: a deck never plays', function (): void {
    [$course] = PracticeKit::course();
    $owner = Learner::factory()->create();
    Course::factory()->deckOf($owner->id)->create(['slug' => 'my-deck']);
    Course::factory()->create(['slug' => 'draft']);

    expect(matService()->courseForBattle($course->slug)->is($course))->toBeTrue();
    foreach (['my-deck', 'draft', 'nope'] as $slug) {
        expect(fn () => matService()->courseForBattle($slug))
            ->toThrow(fn (PracticeException $e) => expect($e->errorCode)->toBe('course_not_found'));
    }
});

it('finds the lesson of the course, 404 for a missing one, 422 for a lesson of another course, 404 when that other course is closed to the learner', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create();
    $otherCourse = Course::factory()->published()->create();
    $otherLesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $otherCourse->id]))->create();
    $secretDeck = Course::factory()->deckOf(Learner::factory()->create()->id)->create();
    $secretLesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $secretDeck->id]))->create();

    $found = matService()->lesson($course, $lesson->id, $learner);
    expect($found->is($lesson))->toBeTrue()
        ->and($found->stage?->number)->toBe(1)
        ->and($found->stage?->course_id)->toBe($course->id);

    expect(fn () => matService()->lesson($course, 999999, $learner))
        ->toThrow(fn (PracticeException $e) => expect($e->errorCode)->toBe('lesson_not_found')->and($e->httpStatus())->toBe(404))
        ->and(fn () => matService()->lesson($course, $otherLesson->id, $learner))
        ->toThrow(fn (PracticeException $e) => expect($e->errorCode)->toBe('lesson_not_in_course')->and($e->httpStatus())->toBe(422))
        ->and(fn () => matService()->lesson($course, $secretLesson->id, $learner))
        ->toThrow(fn (PracticeException $e) => expect($e->errorCode)->toBe('lesson_not_found'))
        ->and(fn () => matService()->lesson($course, $secretLesson->id))   // a battle: no learner, a deck is closed to everybody
        ->toThrow(fn (PracticeException $e) => expect($e->errorCode)->toBe('lesson_not_found'));
});

it('lists the words the learner has been introduced to, whatever their state, in the order of their ids', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create();
    $other = Learner::factory()->create();

    $seen = PracticeKit::words($lesson, 4);
    foreach (['new', 'learning', 'review', 'relearning'] as $i => $state) {
        PracticeKit::introduce($learner, $seen[$i], $state);
    }
    $unseen = PracticeKit::words($lesson, 2);
    PracticeKit::introduce($other, $unseen[0]);          // somebody else's progress is not mine
    $inactive = PracticeKit::words($lesson, 1, $learner, ['is_active' => false]);

    $words = matService()->introducedWords(new MaterialScope($course), $learner, LocaleSet::of('ru'));

    expect(array_map(fn ($w) => $w->id, $words))->toBe(array_map(fn (Word $w): int => $w->id, $seen))
        ->and($words[0]->word)->toBe($seen[0]->word)
        ->and($words[0]->translation)->toBe($seen[0]->translation)
        ->and($words[0]->lessonId)->toBe($lesson->id)
        ->and($words[0]->audioPath)->toBeNull()
        ->and(array_map(fn ($w) => $w->id, $words))->not->toContain($inactive[0]->id);
});

it('narrows the words to a lesson or lets them span the course, and leaves the other courses out', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create();
    $second = Lesson::factory()->for($lesson->stage)->create(['number' => 2]);
    [$one] = PracticeKit::words($lesson, 1, $learner);
    [$two] = PracticeKit::words($second, 1, $learner);
    [$far] = PracticeKit::words(Lesson::factory()->for(Stage::factory()->create(['course_id' => Course::factory()->published()->create()->id]))->create(), 1, $learner);

    $ofLesson = matService()->introducedWords(new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'));
    $ofCourse = matService()->introducedWords(new MaterialScope($course), $learner, LocaleSet::of('ru'));

    expect(array_map(fn ($w) => $w->id, $ofLesson))->toBe([$one->id])
        ->and(array_map(fn ($w) => $w->id, $ofCourse))->toBe([$one->id, $two->id])
        ->and(array_map(fn ($w) => $w->id, $ofCourse))->not->toContain($far->id);
});

it('lists the words of a learner\'s own deck', function (): void {
    $learner = Learner::factory()->create();
    $deck = Course::factory()->deckOf($learner->id)->create();
    $lesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $deck->id]))->create();
    $words = PracticeKit::words($lesson, 3, $learner);

    $found = matService()->introducedWords(new MaterialScope($deck, $lesson), $learner, LocaleSet::of('ru'));

    expect(array_map(fn ($w) => $w->id, $found))->toBe(array_map(fn (Word $w): int => $w->id, $words));
});

it('gives the translation of each locale of the set, the ru one as the fallback of uz', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create();
    [$both, $onlyRu, $none] = PracticeKit::words($lesson, 3, $learner);
    $both->update(['translation' => 'base-both']);
    $onlyRu->update(['translation' => 'base-only-ru']);
    $none->update(['translation' => 'base-none']);
    WordTranslation::factory()->create(['word_id' => $both->id, 'locale' => 'ru', 'translation' => 'мама']);
    WordTranslation::factory()->uz()->create(['word_id' => $both->id, 'translation' => 'ona']);
    WordTranslation::factory()->create(['word_id' => $onlyRu->id, 'locale' => 'ru', 'translation' => 'папа']);

    $ru = matService()->introducedWords(new MaterialScope($course), $learner, LocaleSet::of('ru'));
    $uz = matService()->introducedWords(new MaterialScope($course), $learner, LocaleSet::of('uz'));
    $both2 = matService()->introducedWords(new MaterialScope($course), $learner, LocaleSet::of('ru', 'uz'));

    expect(array_map(fn ($w) => $w->translationFor('ru'), $ru))->toBe(['мама', 'папа', 'base-none'])
        ->and(array_map(fn ($w) => $w->translationFor('uz'), $uz))->toBe(['ona', 'папа', 'base-none']) // the fallback: uz -> ru -> words.translation
        ->and($both2[0]->translations)->toBe(['ru' => 'мама', 'uz' => 'ona'])
        ->and($both2[1]->translations)->toBe(['ru' => 'папа', 'uz' => 'папа'])
        ->and($both2[0]->translation)->toBe('base-both');
});

it('reads the words in two queries for one locale and remembers them for the scope', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create();
    PracticeKit::words($lesson, 5, $learner);
    $scope = new MaterialScope($course);

    $first = QueryLog::during(fn () => matService()->introducedWords($scope, $learner, LocaleSet::of('uz')));
    $again = QueryLog::during(fn () => matService()->introducedWords($scope, $learner, LocaleSet::of('uz')));
    $fresh = QueryLog::during(fn () => matService()->introducedWords(new MaterialScope($course), $learner, LocaleSet::of('uz')));
    $two = QueryLog::during(fn () => matService()->introducedWords(new MaterialScope($course), $learner, LocaleSet::of('ru', 'uz')));

    expect($first)->toHaveCount(2)
        ->and($again)->toBe([])      // the scope remembers: four modes of a hub read the words once
        ->and($fresh)->toHaveCount(2) // a scope of the next request starts clean: nothing can go stale between requests
        ->and($two)->toHaveCount(3);  // a battle: the words and one translation query per locale
});

it('lists every active word of the scope for a battle, whatever the progress of the players', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $words = PracticeKit::words($lesson, 3);
    PracticeKit::words($lesson, 1, null, ['is_active' => false]);

    $found = matService()->allWords(new MaterialScope($course, $lesson), LocaleSet::of('ru', 'uz'));

    expect(array_map(fn ($w) => $w->id, $found))->toBe(array_map(fn (Word $w): int => $w->id, $words))
        ->and($found[0]->translations)->toHaveKeys(['ru', 'uz']);
});

it('narrows the words to the ones of a retry', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create();
    $words = PracticeKit::words($lesson, 4, $learner);

    $found = matService()->introducedWords(new MaterialScope($course, null, new RetryMaterial([$words[1]->id, $words[3]->id], [])), $learner, LocaleSet::of('ru'));

    expect(array_map(fn ($w) => $w->id, $found))->toBe([$words[1]->id, $words[3]->id]);
});

/** @return array{0: Course, 1: Lesson, 2: Learner, 3: list<Word>} a course with a lesson, a learner, 3 introduced words */
function matItemWorld(): array
{
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create();

    return [$course, $lesson, $learner, PracticeKit::words($lesson, 3, $learner)];
}

it('hands out a published task whose target words are all introduced, as its published version', function (): void {
    [$course, $lesson, $learner, $words] = matItemWorld();
    $item = PracticeItem::factory()->sentence()->published()->forLesson($lesson)->targeting([$words[0], $words[1]])->create();

    $found = matService()->eligibleItems(new MaterialScope($course, $lesson), PracticeItemType::Sentence, $learner);

    expect($found)->toHaveCount(1)
        ->and($found[0])->toBeInstanceOf(PracticeItemVersion::class)
        ->and($found[0]->id)->toBe($item->published_version_id)
        ->and($found[0]->item_id)->toBe($item->id)
        ->and($found[0]->content)->toHaveKey('fragments');
});

it('leaves out a task with a target word that is not introduced, or inactive, and one that is not published', function (): void {
    [$course, $lesson, $learner, $words] = matItemWorld();
    $unseen = PracticeKit::words($lesson, 1)[0];
    $inactive = PracticeKit::words($lesson, 1, $learner, ['is_active' => false])[0];

    $fine = PracticeItem::factory()->collocation()->published()->forLesson($lesson)->targeting($words)->create();
    PracticeItem::factory()->collocation()->published()->forLesson($lesson)->targeting([$words[0], $unseen])->create();   // one word was never shown
    PracticeItem::factory()->collocation()->published()->forLesson($lesson)->targeting([$words[0], $inactive])->create(); // one word was switched off
    PracticeItem::factory()->collocation()->forLesson($lesson)->targeting($words)->create();                              // draft
    PracticeItem::factory()->collocation()->reviewed()->forLesson($lesson)->targeting($words)->create();                  // reviewed
    PracticeItem::factory()->collocation()->archived()->forLesson($lesson)->targeting($words)->create();                   // archived

    $found = matService()->eligibleItems(new MaterialScope($course, $lesson), PracticeItemType::Collocation, $learner);

    expect(array_map(fn (PracticeItemVersion $v): int => $v->item_id, $found))->toBe([$fine->id]);
});

it('counts a task without target words as suitable: publishing demands them, the filter does not', function (): void {
    [$course, $lesson, $learner] = matItemWorld();
    $item = PracticeItem::factory()->dictation()->published()->audioReady()->forLesson($lesson)->create();

    $found = matService()->eligibleItems(new MaterialScope($course, $lesson), PracticeItemType::Dictation, $learner);

    expect(array_map(fn (PracticeItemVersion $v): int => $v->item_id, $found))->toBe([$item->id]);
});

it('splits the tasks by the type, takes the course or the lesson as asked, and the right course', function (): void {
    [$course, $lesson, $learner, $words] = matItemWorld();
    $second = Lesson::factory()->for($lesson->stage)->create(['number' => 2]);
    $sentence = PracticeItem::factory()->sentence()->published()->forLesson($lesson)->targeting($words)->create();
    $collocation = PracticeItem::factory()->collocation()->published()->forLesson($second)->targeting($words)->create();
    $courseWide = PracticeItem::factory()->sentence()->published()->forCourse($course)->targeting($words)->create();
    PracticeItem::factory()->sentence()->published()->targeting($words)->create(); // another course

    $ids = fn (array $versions): array => array_map(fn (PracticeItemVersion $v): int => $v->item_id, $versions);
    $ofCourse = new MaterialScope($course);
    $ofLesson = new MaterialScope($course, $lesson);

    expect($ids(matService()->eligibleItems($ofCourse, PracticeItemType::Sentence, $learner)))->toBe([$sentence->id, $courseWide->id])
        ->and($ids(matService()->eligibleItems($ofCourse, PracticeItemType::Collocation, $learner)))->toBe([$collocation->id])
        ->and($ids(matService()->eligibleItems($ofCourse, PracticeItemType::Dictation, $learner)))->toBe([])
        // A lesson takes ITS tasks only; a task of the whole course (no lesson) belongs to the course scope.
        ->and($ids(matService()->eligibleItems($ofLesson, PracticeItemType::Sentence, $learner)))->toBe([$sentence->id])
        ->and($ids(matService()->eligibleItems($ofLesson, PracticeItemType::Collocation, $learner)))->toBe([]);
});

it('hands out the PUBLISHED version while a newer draft of the same task is being edited', function (): void {
    [$course, $lesson, $learner, $words] = matItemWorld();
    $item = PracticeItem::factory()->collocation()->published()->forLesson($lesson)->targeting($words)->create();
    $published = $item->published_version_id;
    $draft = PracticeItemVersion::factory()->create(['item_id' => $item->id, 'number' => 2, 'content' => ['text' => 'EDITED ___.']]);
    $item->update(['current_version_id' => $draft->id]);

    $found = matService()->eligibleItems(new MaterialScope($course, $lesson), PracticeItemType::Collocation, $learner);

    expect($found)->toHaveCount(1)
        ->and($found[0]->id)->toBe($published)
        ->and($found[0]->content['text'])->not->toBe('EDITED ___.');
});

it('gives a dictation out only when its audio is ready, and counts the ones that wait for the audio', function (): void {
    [$course, $lesson, $learner, $words] = matItemWorld();
    $ready = PracticeItem::factory()->dictation()->published()->audioReady()->forLesson($lesson)->targeting($words)->create();
    PracticeItem::factory()->dictation()->published()->audioPending()->forLesson($lesson)->targeting($words)->create();
    PracticeItem::factory()->dictation()->published()->audioFailed()->forLesson($lesson)->targeting($words)->create();
    $scope = new MaterialScope($course, $lesson);

    $found = matService()->eligibleItems($scope, PracticeItemType::Dictation, $learner);

    expect(array_map(fn (PracticeItemVersion $v): int => $v->item_id, $found))->toBe([$ready->id])
        ->and($found[0]->audio_status)->toBe(AudioStatus::Ready)
        ->and(matService()->pendingAudioItems($scope, $learner))->toBe(2);
});

it('checks only that the words are active for a battle: no learner, no progress', function (): void {
    [$course, $lesson, , $words] = matItemWorld();
    $unseen = PracticeKit::words($lesson, 1)[0];
    $inactive = PracticeKit::words($lesson, 1, null, ['is_active' => false])[0];
    $fine = PracticeItem::factory()->sentence()->published()->forLesson($lesson)->targeting([$words[0], $unseen])->create();
    PracticeItem::factory()->sentence()->published()->forLesson($lesson)->targeting([$words[0], $inactive])->create();

    $found = matService()->eligibleItems(new MaterialScope($course, $lesson), PracticeItemType::Sentence, null);

    expect(array_map(fn (PracticeItemVersion $v): int => $v->item_id, $found))->toBe([$fine->id]);
});

it('narrows the tasks to the ones of a retry', function (): void {
    [$course, $lesson, $learner, $words] = matItemWorld();
    $items = PracticeItem::factory()->count(3)->sentence()->published()->forLesson($lesson)->targeting($words)->create();

    $found = matService()->eligibleItems(new MaterialScope($course, $lesson, new RetryMaterial([], [$items[0]->id, $items[2]->id])), PracticeItemType::Sentence, $learner);

    expect(array_map(fn (PracticeItemVersion $v): int => $v->item_id, $found))->toBe([$items[0]->id, $items[2]->id]);
});

it('reads the tasks of ALL the types in two queries and remembers them for the scope', function (): void {
    [$course, $lesson, $learner, $words] = matItemWorld();
    PracticeItem::factory()->sentence()->published()->forLesson($lesson)->targeting($words)->create();
    PracticeItem::factory()->collocation()->published()->forLesson($lesson)->targeting($words)->create();
    PracticeItem::factory()->dictation()->published()->audioReady()->forLesson($lesson)->targeting($words)->create();
    $scope = new MaterialScope($course, $lesson);

    $log = QueryLog::during(function () use ($scope, $learner): void {
        foreach (PracticeItemType::cases() as $type) {
            matService()->eligibleItems($scope, $type, $learner);
        }
        matService()->pendingAudioItems($scope, $learner);
    });
    $battle = QueryLog::during(fn () => matService()->eligibleItems($scope, PracticeItemType::Sentence, null));

    expect($log)->toHaveCount(2)
        ->and(QueryLog::matching($log, '/practice_items/'))->toHaveCount(1)
        ->and(QueryLog::matching($log, '/practice_item_versions/'))->toHaveCount(1)
        ->and($battle)->toHaveCount(2); // the other viewpoint (no learner) is another question: asked once, then remembered
});

it('needs three tasks to play, and one to repeat the mistakes', function (): void {
    [$course] = PracticeKit::course();

    expect(matService()->minItems(new MaterialScope($course)))->toBe(3)
        ->and(matService()->minItems(new MaterialScope($course, null, new RetryMaterial([1], []))))->toBe(1);

    config(['practice.solo.items.min' => 4]);
    expect(matService()->minItems(new MaterialScope($course)))->toBe(4);
});

it('reports the material of the four modes: nothing yet for the skeletons, the numbers of a handler once there is one', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create();
    $scope = new MaterialScope($course, $lesson);
    $locales = LocaleSet::of('ru');

    $report = matService()->availability($scope, $learner, $locales);
    expect(array_keys($report))->toBe(['pairs', 'collocation', 'sentence', 'dictation'])
        ->and(array_map(fn ($a) => [$a->isAvailable, $a->available, $a->required, $a->reason], $report))->toBe([
            'pairs' => [false, 0, 3, 'no_words'], // a real handler: no word introduced yet
            'collocation' => [false, 0, 3, null],
            'sentence' => [false, 0, 3, null],
            'dictation' => [false, 0, 3, null],
        ]);

    PracticeKit::fake(PracticeMode::Sentence, candidates: 7);
    PracticeKit::fake(PracticeMode::Dictation, candidates: 2);
    $report = matService()->availability($scope, $learner, $locales);

    expect($report['sentence']->toArray())->toBe(['available' => 7, 'required' => 3, 'reason' => null])
        ->and($report['sentence']->isAvailable)->toBeTrue()
        ->and($report['dictation']->toArray())->toBe(['available' => 2, 'required' => 3, 'reason' => 'no_items'])
        ->and($report['dictation']->isAvailable)->toBeFalse();
});

// ---- pairs (18.2): the words, the number of rounds, the words that clash, the languages ----

/** The seed of the tests that want one layout: `PracticeSnapshotBuilder::build(..., seed: ...)` of docs/16 §4.6. */
const MAT_SEED = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

/**
 * Words of the lesson with the translations given: `[english, ru, uz]`, `uz` null or left out - no uz text (ru is the fallback of
 * a uz learner). The learner, if there is one, has been introduced to them.
 *
 * @param  list<array{0: string, 1: string, 2?: string|null}>  $rows
 * @return list<Word>
 */
function matPairWords(Lesson $lesson, ?Learner $learner, array $rows): array
{
    $words = [];
    foreach ($rows as $row) {
        $word = Word::factory()->for($lesson)->create(['word' => $row[0], 'translation' => $row[1]]);
        if (($row[2] ?? null) !== null) {
            WordTranslation::factory()->uz()->create(['word_id' => $word->id, 'translation' => $row[2]]);
        }
        if ($learner !== null) {
            PracticeKit::introduce($learner, $word);
        }
        $words[] = $word;
    }

    return $words;
}

/**
 * Words nobody could mix up: "w1" - "перевод 1", "w2" - "перевод 2"...
 *
 * @return list<array{0: string, 1: string}>
 */
function matPairRows(int $count, int $from = 1): array
{
    $rows = [];
    for ($i = $from; $i < $from + $count; $i++) {
        $rows[] = ["w{$i}", "перевод {$i}"];
    }

    return $rows;
}

/** @return list<SnapshotElement> the rounds of a solo session as the real handler builds them */
function matPairRounds(Course $course, ?Lesson $lesson, Learner $learner, string $seed = MAT_SEED, string $locale = 'ru'): array
{
    return app(PairsMode::class)->build(new MaterialScope($course, $lesson), $learner, LocaleSet::of($locale), $seed, 3, false);
}

/**
 * @param  list<SnapshotElement>  $rounds
 * @return list<int> the number of pairs of each round
 */
function matPairSizes(array $rounds): array
{
    return array_map(fn (SnapshotElement $e): int => count(PairsRound::of($e)->left), $rounds);
}

/**
 * @param  list<SnapshotElement>  $rounds
 * @return list<string> the English words of the round that has `$english` (itself included), empty when no round has it
 */
function matPairMates(array $rounds, string $english): array
{
    foreach ($rounds as $element) {
        $texts = array_column(PairsRound::of($element)->left, 'text');
        if (in_array($english, $texts, true)) {
            return $texts;
        }
    }

    return [];
}

/**
 * @param  list<SnapshotElement>  $rounds
 * @return list<string> the English words of all the rounds
 */
function matPairTexts(array $rounds): array
{
    return $rounds === [] ? [] : array_merge(...array_map(fn (SnapshotElement $e): array => array_column(PairsRound::of($e)->left, 'text'), $rounds));
}

it('shares N introduced words out into rounds as docs/16 §4.3 says: 3 or 4 words a round, 3 rounds at most, the 13th word waits', function (int $words, array $sizes): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create();
    matPairWords($lesson, $learner, matPairRows($words));
    $scope = new MaterialScope($course, $lesson);
    $mode = app(PairsMode::class);

    $availability = $mode->availability($scope, $learner, LocaleSet::of('ru'));
    if ($sizes === []) {
        expect($availability->isAvailable)->toBeFalse()
            ->and($availability->toArray())->toBe(['available' => $words, 'required' => 3, 'reason' => 'no_words']);
        expect(fn () => matPairRounds($course, $lesson, $learner))
            ->toThrow(fn (PracticeException $e) => expect($e->errorCode)->toBe('practice_not_enough_material')
                ->and($e->details)->toBe(['mode' => 'pairs', 'available' => $words, 'required' => 3, 'reason' => 'no_words', 'course' => 'english-a1', 'lesson_id' => $lesson->id]));

        return;
    }

    $rounds = matPairRounds($course, $lesson, $learner);

    expect(matPairSizes($rounds))->toBe($sizes)
        ->and($availability->isAvailable)->toBeTrue()
        ->and($availability->toArray())->toBe(['available' => $words, 'required' => 3, 'reason' => null])
        ->and(array_map(fn (SnapshotElement $e): int => PairsRound::of($e)->number, $rounds))->toBe(range(1, count($sizes)))
        ->and(array_values(array_unique(array_map(fn (SnapshotElement $e): int => PairsRound::of($e)->rounds, $rounds))))->toBe([count($sizes)]);

    // Every word of the rounds is a different word.
    $ids = array_merge(...array_map(fn (SnapshotElement $e): array => array_column(PairsRound::of($e)->left, 'word_id'), $rounds));
    expect(array_unique($ids))->toHaveCount(array_sum($sizes));
})->with([
    'N=2: too few' => [2, []],
    'N=3' => [3, [3]],
    'N=4' => [4, [4]],
    'N=5' => [5, [4]],
    'N=6' => [6, [3, 3]],
    'N=7' => [7, [4, 3]],
    'N=8' => [8, [4, 4]],
    'N=9' => [9, [3, 3, 3]],
    'N=10' => [10, [4, 3, 3]],
    'N=11' => [11, [4, 4, 3]],
    'N=12' => [12, [4, 4, 4]],
    'N=13' => [13, [4, 4, 4]],
]);

it('takes the number of rounds from the size it is asked for, not from the config', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create();
    matPairWords($lesson, $learner, matPairRows(12));

    $two = app(PairsMode::class)->build(new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'), MAT_SEED, 2, false);
    $one = app(PairsMode::class)->build(new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'), MAT_SEED, 1, false);

    expect(matPairSizes($two))->toBe([4, 4])
        ->and(matPairSizes($one))->toBe([4])
        ->and(PairsRound::of($two[0])->rounds)->toBe(2);
});

it('counts only the words the learner has been introduced to, active, of the course and the lesson asked for', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $second = Lesson::factory()->for($lesson->stage)->create(['number' => 2]);
    $learner = Learner::factory()->create();
    matPairWords($lesson, $learner, matPairRows(5));                       // introduced
    matPairWords($lesson, null, matPairRows(4, 6));                         // never shown to this learner
    matPairWords($second, $learner, matPairRows(3, 10));                    // another lesson
    $off = matPairWords($lesson, $learner, matPairRows(1, 20))[0];
    $off->update(['is_active' => false]);                                   // switched off

    $mode = app(PairsMode::class);

    expect($mode->availability(new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'))->available)->toBe(5)
        ->and($mode->availability(new MaterialScope($course), $learner, LocaleSet::of('ru'))->available)->toBe(8)
        ->and($mode->availability(new MaterialScope($course, $second), $learner, LocaleSet::of('ru'))->available)->toBe(3);
});

it('leaves out a word with nothing to put on its card, and says there are too few words when that is the reason', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create();
    matPairWords($lesson, $learner, [['alpha', 'альфа'], ['beta', 'бета'], ['gamma', '(разг.)'], ['delta', '  '], ['', 'пусто']]);

    $availability = app(PairsMode::class)->availability(new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'));

    expect($availability->toArray())->toBe(['available' => 2, 'required' => 3, 'reason' => 'no_words']);

    matPairWords($lesson, $learner, [['epsilon', 'эпсилон']]);
    expect(app(PairsMode::class)->availability(new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'))->toArray())
        ->toBe(['available' => 3, 'required' => 3, 'reason' => null]);
});

it('keeps the words that clash apart: «mother» and «mum» are both «мама» and stand in different rounds, both of them', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create();
    matPairWords($lesson, $learner, [['mother', 'мама'], ['mum', 'Мама, мамочка'], ...matPairRows(10)]);

    foreach (range(0, 59) as $n) {
        $rounds = matPairRounds($course, $lesson, $learner, "mum-{$n}");

        expect(matPairSizes($rounds))->toBe([4, 4, 4], "seed {$n}")
            ->and(matPairMates($rounds, 'mother'))->not->toContain('mum', "seed {$n}")
            ->and(matPairTexts($rounds))->toContain('mother', 'mum');  // not thrown out: they are in different rounds
    }
});

it('keeps a word apart from another one with the same English text, whatever the translations', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create();
    matPairWords($lesson, $learner, [['bank', 'банк'], ['Bank ', 'берег'], ...matPairRows(6)]);

    foreach (range(0, 39) as $n) {
        $rounds = matPairRounds($course, $lesson, $learner, "bank-{$n}");
        $banks = fn (array $texts): int => count(array_filter($texts, fn (string $t): bool => strtolower($t) === 'bank'));

        expect($banks(matPairTexts($rounds)))->toBe(2, "seed {$n}");
        foreach ($rounds as $element) {
            expect($banks(array_column(PairsRound::of($element)->left, 'text')))->toBeLessThanOrEqual(1, "seed {$n}");
        }
    }
});

it('sees a clash through a variant, a bracket, the case, ё and a closing mark of the translation', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create();
    // «hedgehog» = «Ёж!», «urchin» = «еж (колючий)», «spiky» = «колючий, Еж»: all three share «еж».
    matPairWords($lesson, $learner, [['hedgehog', 'Ёж!'], ['urchin', 'еж (колючий)'], ['spiky', 'колючий, Еж'], ...matPairRows(5)]);

    foreach (range(0, 39) as $n) {
        $rounds = matPairRounds($course, $lesson, $learner, "hedgehog-{$n}");

        foreach ($rounds as $element) {
            $texts = array_column(PairsRound::of($element)->left, 'text');
            expect(count(array_intersect($texts, ['hedgehog', 'urchin', 'spiky'])))->toBeLessThanOrEqual(1, "seed {$n}");
        }
    }
});

it('says «ambiguous» when there are words enough but too many say the same: what can stand together is what is available', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create();
    // Five words, three of them «мама», two «папа»: no round of three words without a repeat.
    matPairWords($lesson, $learner, [['mother', 'мама'], ['mum', 'мама'], ['mummy', 'мама'], ['father', 'папа'], ['dad', 'папа']]);
    $mode = app(PairsMode::class);

    $availability = $mode->availability(new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'));

    expect($availability->isAvailable)->toBeFalse()
        ->and($availability->toArray())->toBe(['available' => 2, 'required' => 3, 'reason' => 'ambiguous']);
    expect(fn () => matPairRounds($course, $lesson, $learner))
        ->toThrow(fn (PracticeException $e) => expect($e->errorCode)->toBe('practice_not_enough_material')
            ->and($e->httpStatus())->toBe(422)
            ->and($e->details)->toBe(['mode' => 'pairs', 'available' => 2, 'required' => 3, 'reason' => 'ambiguous', 'course' => 'english-a1', 'lesson_id' => $lesson->id]));
});

it('makes rounds of the words that can stand together when the others repeat', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create();
    // Six words, three meanings twice each: two rounds of «мама, папа, брат» are the only way.
    matPairWords($lesson, $learner, [['mother', 'мама'], ['mum', 'мама'], ['father', 'папа'], ['dad', 'папа'], ['brother', 'брат'], ['bro', 'брат']]);

    foreach (range(0, 19) as $n) {
        $rounds = matPairRounds($course, $lesson, $learner, "groups-{$n}");

        expect(matPairSizes($rounds))->toBe([3, 3], "seed {$n}");
    }
});

it('rounds out a pairs session of 4 words of which two say the same: the round of 3 is made', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create();
    // One round of four places; «mum» cannot join «mother», so the round has three words - the least a round may have.
    matPairWords($lesson, $learner, [['mother', 'мама'], ['mum', 'мама'], ['father', 'папа'], ['brother', 'брат']]);

    foreach (range(0, 19) as $n) {
        expect(matPairSizes(matPairRounds($course, $lesson, $learner, "four-{$n}")))->toBe([3], "seed {$n}");
    }
});

it('checks a uz learner against the uz translations and the ru ones where there is no uz text', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create(['interface_locale' => 'uz']);
    matPairWords($lesson, $learner, [
        ['mother', 'мама', 'ona'],                  // the same «ona» in uz, different in ru
        ['mum', 'мамочка', 'ona'],
        ['dad', 'папа'],                            // no uz text at all: the ru one stands in for it...
        ['father', 'папа'],                         // ...and it is the same for both
        ...matPairRows(6),
    ]);
    $together = [];

    foreach (range(0, 39) as $n) {
        $uz = matPairRounds($course, $lesson, $learner, "uz-{$n}", 'uz');
        $ru = matPairRounds($course, $lesson, $learner, "uz-{$n}", 'ru');

        expect(matPairSizes($uz))->toBe([4, 3, 3], "seed {$n}")
            ->and(matPairMates($uz, 'mother'))->not->toContain('mum', "seed {$n}")
            ->and(matPairMates($uz, 'dad'))->not->toContain('father', "seed {$n}")
            ->and(matPairMates($ru, 'dad'))->not->toContain('father', "seed {$n}");
        $together[] = in_array('mum', matPairMates($ru, 'mother'), true);
    }

    // In ru they are two different words («мама», «мамочка»): nothing keeps them apart.
    expect($together)->toContain(true);
});

it('puts the card in the language of the learner: the uz text where there is one, the ru text where there is none', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create(['interface_locale' => 'uz']);
    matPairWords($lesson, $learner, [['mother', 'мама', 'ona'], ['father', 'папа'], ['sister', 'сестра', 'opa']]);

    $round = PairsRound::of(matPairRounds($course, $lesson, $learner, locale: 'uz')[0]);

    expect(array_column($round->rightCards('uz'), 'text'))->toEqualCanonicalizing(['ona', 'папа', 'opa'])
        ->and(array_keys($round->right[0]['text']))->toBe(['uz']); // the snapshot keeps the locales it was built for
});

it('writes the card with the first variant of the translation, as the author wrote it', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create();
    matPairWords($lesson, $learner, [['mother', 'Мама (разг.), мамочка'], ['hedgehog', 'Ёж / колючка'], ['hello', 'Привет!']]);

    $round = PairsRound::of(matPairRounds($course, $lesson, $learner)[0]);
    $byWord = [];
    foreach ($round->left as $card) {
        $byWord[$card['text']] = $round->rightText($round->table[$card['id']], 'ru');
    }
    ksort($byWord);

    expect($byWord)->toBe(['hedgehog' => 'Ёж', 'hello' => 'Привет!', 'mother' => 'Мама']);
});

it('shuffles the words and the translations on their own, never to the same rows, and the same seed gives the same rounds', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create();
    matPairWords($lesson, $learner, matPairRows(9));
    $texts = Word::query()->pluck('translation', 'id')->all();
    $inOrder = 0;

    foreach (range(0, 59) as $n) {
        $rounds = matPairRounds($course, $lesson, $learner, "shuffle-{$n}");
        $again = matPairRounds($course, $lesson, $learner, "shuffle-{$n}");

        foreach ($rounds as $index => $element) {
            $round = PairsRound::of($element);

            // A bijection, and the card of a word says that word's translation.
            expect(array_values($round->table))->toEqualCanonicalizing($round->rightIds());
            $aligned = true;
            foreach ($round->left as $row => $card) {
                $partner = $round->table[$card['id']];
                expect($round->rightText($partner, 'ru'))->toBe($texts[$card['word_id']]);
                $aligned = $aligned && $partner === $round->right[$row]['id'];
            }
            expect($aligned)->toBeFalse("seed {$n}, round {$index}: every word beside its own translation");

            // The same seed, the same order of the words and of the cards (the ids are drawn afresh).
            $other = PairsRound::of($again[$index]);
            expect(array_column($other->left, 'word_id'))->toBe(array_column($round->left, 'word_id'))
                ->and(array_column($other->rightCards('ru'), 'text'))->toBe(array_column($round->rightCards('ru'), 'text'))
                ->and($other->leftIds())->not->toBe($round->leftIds());

            $ids = array_column($round->left, 'word_id');
            $sorted = $ids;
            sort($sorted);
            $inOrder += $ids === $sorted ? 1 : 0;
        }
    }

    // The words are not left in the order of their ids (a shuffle that did nothing would give 180 of 180).
    expect($inOrder)->toBeLessThan(60);
    // Another seed, another layout.
    expect(array_column(PairsRound::of(matPairRounds($course, $lesson, $learner, 'x')[0])->left, 'word_id'))
        ->not->toBe(array_column(PairsRound::of(matPairRounds($course, $lesson, $learner, 'y')[0])->left, 'word_id'));
});

it('marks no round of a solo session as a round of a battle, gives ids of its own to every card, and keeps the snapshot small', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create();
    matPairWords($lesson, $learner, matPairRows(12));
    $rounds = matPairRounds($course, $lesson, $learner);
    $ids = [];

    foreach ($rounds as $element) {
        $ids[] = $element->id;
        expect($element->kind)->toBe('pairs_round')
            ->and($element->data)->not->toHaveKey('battle')
            ->and($element->id)->toStartWith('e');
        foreach (PairsRound::of($element)->left as $card) {
            $ids[] = $card['id'];
            expect($card['id'])->toStartWith('l')->and(strlen($card['id']))->toBe(11);
        }
        foreach (PairsRound::of($element)->right as $card) {
            $ids[] = $card['id'];
            expect($card['id'])->toStartWith('r');
        }
    }

    expect(array_unique($ids))->toHaveCount(count($ids))
        ->and(strlen(json_encode(array_map(fn (SnapshotElement $e): array => $e->toArray(), $rounds), JSON_THROW_ON_ERROR)))->toBeLessThan(4 * 1024);
});

it('narrows a pairs session to the words of a retry', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create();
    $words = matPairWords($lesson, $learner, matPairRows(10));
    $scope = new MaterialScope($course, $lesson, new RetryMaterial([$words[1]->id, $words[4]->id, $words[7]->id, $words[8]->id], []));

    $rounds = app(PairsMode::class)->build($scope, $learner, LocaleSet::of('ru'), MAT_SEED, 3, false);

    expect(matPairSizes($rounds))->toBe([4])
        ->and(array_column(PairsRound::of($rounds[0])->left, 'word_id'))->toEqualCanonicalizing([$words[1]->id, $words[4]->id, $words[7]->id, $words[8]->id]);
});

it('reads the words once for the availability and the build of a pairs session', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create();
    matPairWords($lesson, $learner, matPairRows(9));
    $scope = new MaterialScope($course, $lesson);
    $mode = app(PairsMode::class);

    $queries = QueryLog::during(function () use ($mode, $scope, $learner): void {
        $mode->availability($scope, $learner, LocaleSet::of('uz'));
        $mode->build($scope, $learner, LocaleSet::of('uz'), MAT_SEED, 3, false);
        $mode->availability($scope, $learner, LocaleSet::of('uz'));
    });

    expect($queries)->toHaveCount(2); // the words and their translations, once
});

describe('the pairs of a battle', function (): void {
    it('wants exactly 5 rounds of 4 words from the words of the course, no learner and no progress asked about', function (): void {
        [$course, $lesson] = matBattleWorld();
        $scope = new MaterialScope($course, $lesson);
        $mode = app(PairsMode::class);

        $availability = $mode->availability($scope, null, LocaleSet::of('ru', 'uz'));
        $rounds = $mode->build($scope, null, LocaleSet::of('ru', 'uz'), MAT_SEED, 5, true);

        expect($availability->toArray())->toBe(['available' => 24, 'required' => 20, 'reason' => null])
            ->and($availability->isAvailable)->toBeTrue()
            ->and(matPairSizes($rounds))->toBe([4, 4, 4, 4, 4])
            ->and(array_map(fn (SnapshotElement $e): bool => PairsRound::of($e)->battle, $rounds))->toBe([true, true, true, true, true])
            ->and(array_map(fn (SnapshotElement $e): int => PairsRound::of($e)->rounds, $rounds))->toBe([5, 5, 5, 5, 5]);
    });

    it('keeps the words that clash in EITHER language apart, and carries the text of both languages on the cards', function (): void {
        [$course, $lesson] = matBattleWorld(extra: 18);   // 20 words: every one of them is in a round
        $scope = new MaterialScope($course, $lesson);

        foreach (range(0, 29) as $n) {
            $rounds = app(PairsMode::class)->build($scope, null, LocaleSet::of('ru', 'uz'), "battle-{$n}", 5, true);

            // «ona» in uz: apart, whatever the ru texts. The scope remembers the words, so the seed is the only thing that changes.
            expect(matPairMates($rounds, 'mother'))->not->toContain('mum', "seed {$n}");
            foreach ($rounds as $element) {
                foreach (PairsRound::of($element)->right as $card) {
                    expect(array_keys($card['text']))->toBe(['ru', 'uz']);
                }
            }
        }
    });

    it('builds the snapshot of a battle for two languages: 5 rounds, both locales, the same for both players', function (): void {
        [$course, $lesson] = matBattleWorld(extra: 18);

        $snapshot = app(PracticeSnapshotBuilder::class)->build(PracticeMode::Pairs, new MaterialScope($course, $lesson), null, LocaleSet::of('ru', 'uz'), 5, true, MAT_SEED);
        $again = app(PracticeSnapshotBuilder::class)->build(PracticeMode::Pairs, new MaterialScope($course, $lesson), null, LocaleSet::of('ru', 'uz'), 5, true, MAT_SEED);
        $words = fn ($s): array => array_map(fn (SnapshotElement $e): array => array_column(PairsRound::of($e)->left, 'word_id'), $s->elements);

        expect($snapshot->locales)->toBe(['ru', 'uz'])
            ->and($snapshot->count())->toBe(5)
            ->and($snapshot->mode)->toBe(PracticeMode::Pairs)
            ->and($snapshot->lessonNumber)->toBe(1)
            ->and($snapshot->pool)->toBe([])
            ->and($words($snapshot))->toBe($words($again));

        // The viewer sees his own language; the table is the same for both.
        $round = collect($snapshot->elements)->map(fn (SnapshotElement $e): PairsRound => PairsRound::of($e))
            ->first(fn (PairsRound $r): bool => in_array('mother', array_column($r->left, 'text'), true));
        $mother = collect($round->left)->firstWhere('text', 'mother');
        expect($round->rightText($round->table[$mother['id']], 'uz'))->toBe('ona')
            ->and($round->rightText($round->table[$mother['id']], 'ru'))->toBe('мама');
    });

    it('refuses the format when the course has fewer than 20 words: no_words, with the numbers', function (): void {
        [$course, $lesson] = matBattleWorld(extra: 17);   // 19 words
        $scope = new MaterialScope($course, $lesson);
        $mode = app(PairsMode::class);

        expect($mode->availability($scope, null, LocaleSet::of('ru'))->toArray())->toBe(['available' => 19, 'required' => 20, 'reason' => 'no_words']);
        expect(fn () => $mode->build($scope, null, LocaleSet::of('ru'), MAT_SEED, 5, true))
            ->toThrow(fn (PracticeException $e) => expect($e->errorCode)->toBe('practice_not_enough_material')
                ->and($e->details)->toBe(['mode' => 'pairs', 'available' => 19, 'required' => 20, 'reason' => 'no_words', 'course' => 'english-a1', 'lesson_id' => $lesson->id]));
    });

    it('refuses the format when so many words clash that 5 full rounds cannot be made: ambiguous', function (): void {
        [$course, $lesson] = PracticeKit::course();
        // 6 words say «мама»: at most one to a round, so at most 19 of 20 places are filled.
        matPairWords($lesson, null, [...array_map(fn (int $i): array => ["mama{$i}", 'мама'], range(1, 6)), ...matPairRows(14)]);
        $mode = app(PairsMode::class);

        $availability = $mode->availability(new MaterialScope($course, $lesson), null, LocaleSet::of('ru'));

        expect($availability->isAvailable)->toBeFalse()
            ->and($availability->toArray())->toBe(['available' => 19, 'required' => 20, 'reason' => 'ambiguous']);
        expect(fn () => $mode->build(new MaterialScope($course, $lesson), null, LocaleSet::of('ru'), MAT_SEED, 5, true))
            ->toThrow(fn (PracticeException $e) => expect($e->details['reason'])->toBe('ambiguous')->and($e->details['available'])->toBe(19));
    });

    it('accepts exactly 20 words with a pair that clashes, whatever the seed: the 5 rounds are found', function (): void {
        [$course, $lesson] = PracticeKit::course();
        matPairWords($lesson, null, [['mother', 'мама'], ['mum', 'мама'], ...matPairRows(18)]);
        $scope = new MaterialScope($course, $lesson);

        foreach (range(0, 59) as $n) {
            $rounds = app(PairsMode::class)->build($scope, null, LocaleSet::of('ru'), "exact-{$n}", 5, true);

            expect(matPairSizes($rounds))->toBe([4, 4, 4, 4, 4], "seed {$n}")
                ->and(matPairMates($rounds, 'mother'))->not->toContain('mum', "seed {$n}");
        }
    });

    it('takes a learner out of the question: the same words whoever asks', function (): void {
        [$course, $lesson] = matBattleWorld();
        $stranger = Learner::factory()->create();       // introduced to nothing

        $solo = app(PairsMode::class)->availability(new MaterialScope($course, $lesson), $stranger, LocaleSet::of('ru'));
        $battle = app(PairsMode::class)->availability(new MaterialScope($course, $lesson), null, LocaleSet::of('ru'));

        expect($solo->toArray())->toBe(['available' => 0, 'required' => 3, 'reason' => 'no_words'])
            ->and($battle->toArray())->toBe(['available' => 24, 'required' => 20, 'reason' => null]);
    });
});

/**
 * 24 words nobody introduced to anyone; «mother» and «mum» say the same in uz only.
 *
 * @return array{0: Course, 1: Lesson}
 */
function matBattleWorld(int $extra = 22): array
{
    [$course, $lesson] = PracticeKit::course();
    matPairWords($lesson, null, [['mother', 'мама', 'ona'], ['mum', 'мамочка', 'ona'], ...matPairRows($extra)]);

    return [$course, $lesson];
}
