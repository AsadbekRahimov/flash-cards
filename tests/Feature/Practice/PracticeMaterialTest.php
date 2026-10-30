<?php

declare(strict_types=1);

use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\DTO\RetryMaterial;
use App\Domain\Practice\Enums\AudioStatus;
use App\Domain\Practice\Enums\PracticeItemType;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\PracticeMaterialService;
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
 * words are all introduced - and the access to the course / lesson. The distribution of pairs and the packing belong to 18.2.
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
            'pairs' => [false, 0, 3, null],
            'collocation' => [false, 0, 3, 'no_items'], // a real handler since 18.3: no task yet
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
