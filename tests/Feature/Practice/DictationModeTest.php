<?php

declare(strict_types=1);

use App\Domain\Content\Services\WordAudioService;
use App\Domain\Practice\DTO\ElementState;
use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\DTO\RetryMaterial;
use App\Domain\Practice\DTO\SnapshotElement;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Modes\DictationMode;
use App\Domain\Practice\Services\PracticeSnapshotBuilder;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PracticeItem;
use App\Models\Word;
use App\Models\WordTranslation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Practice\DictationKit;
use Tests\Support\Practice\PracticeKit;

/*
 * The mode "Listen and write" (docs/16 §4.3, §5.2, §7.2, §10): which material it has and how it says so, the composition of a
 * solo session and of a battle, the element of the snapshot and what the client sees of it, the grading and the review, the
 * spares for a swap. The life cycle of a session around it (answers, the swap, the summary) is DictationSessionTest.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 10:00:00');
    PracticeKit::openAccess();
    Storage::fake('practice_audio');
    // The disk of the words is faked for every test by Tests\TestCase.
});

afterEach(fn () => CarbonImmutable::setTestNow());

function dmMode(): DictationMode
{
    return app(DictationMode::class);
}

/** @return list<SnapshotElement> */
function dmBuild(Course $course, Lesson $lesson, ?Learner $learner, int $size = 5, bool $strict = false, string $seed = 'a', ?LocaleSet $locales = null, ?RetryMaterial $retry = null): array
{
    return dmMode()->build(new MaterialScope($course, $lesson, $retry), $learner, $locales ?? LocaleSet::of('ru'), str_repeat($seed, 32), $size, $strict);
}

/** @return list<string> the sources of the elements in order */
function dmSources(array $elements): array
{
    return array_map(static fn (SnapshotElement $e): string => (string) $e->get('source'), $elements);
}

function dmCatch(Closure $run): ?PracticeException
{
    try {
        $run();
    } catch (PracticeException $e) {
        return $e;
    }

    return null;
}

// ------------------------------------------------------------------ availability

it('has nothing to play for a learner who has been introduced to nothing: no_words', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();

    $report = dmMode()->availability(new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'));

    expect($report->isAvailable)->toBeFalse()
        ->and($report->toArray())->toBe(['available' => 0, 'required' => 3, 'reason' => 'no_words']);
});

it('says no_audio_words when the learner knows words but none of them has a voice', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::word($lesson, $learner, 'mother', voiced: false);
    DictationKit::word($lesson, $learner, 'father', voiced: false);

    $report = dmMode()->availability(new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'));

    expect($report->toArray())->toBe(['available' => 0, 'required' => 3, 'reason' => 'no_audio_words']);
});

it('says no_items when there are a few voiced words and phrases, but not enough', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::word($lesson, $learner, 'mother');
    DictationKit::phrase($lesson, $learner, 'See you tomorrow');

    $report = dmMode()->availability(new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'));

    expect($report->toArray())->toBe(['available' => 2, 'required' => 3, 'reason' => 'no_items']);
});

it('says items_locked when voiced phrases wait for words the learner has not met and would make the number, and no_items when they would not', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    $report = fn (): array => dmMode()->availability(new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'))->toArray();
    DictationKit::word($lesson, $learner, 'mother');                                             // one clip to play now

    DictationKit::phrase($lesson, $learner, 'Nice to meet you', introduced: false);
    expect($report())->toBe(['available' => 1, 'required' => 3, 'reason' => 'no_items']);       // 1 + 1 locked = 2: learning would not be enough

    DictationKit::phrase($lesson, $learner, 'How much is it', introduced: false);
    expect($report())->toBe(['available' => 1, 'required' => 3, 'reason' => 'items_locked']);    // 1 + 2 locked = 3

    $error = dmCatch(fn () => dmBuild($course, $lesson, $learner));
    expect($error?->errorCode)->toBe('practice_not_enough_material')
        ->and($error?->details)->toMatchArray(['mode' => 'dictation', 'available' => 1, 'required' => 3, 'reason' => 'items_locked']);
});

it('puts audio_pending before items_locked, and does not count a locked phrase that has no voice yet', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::phrase($lesson, $learner, 'See you tomorrow');
    DictationKit::phrase($lesson, $learner, 'Nice to meet you', audio: 'pending');                      // the words are met, the voice is not
    DictationKit::phrase($lesson, $learner, 'How much is it', audio: 'failed');
    DictationKit::phrase($lesson, $learner, 'Where is the bank', introduced: false);                    // voiced, locked: also enough with the rest
    DictationKit::phrase($lesson, $learner, 'Open the window', audio: 'pending', introduced: false);    // locked AND voiceless: counted nowhere

    $report = dmMode()->availability(new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'));

    expect($report->toArray())->toBe(['available' => 1, 'required' => 3, 'reason' => 'audio_pending']);
});

it('says items_locked rather than no_audio_words when the learner knows only voiceless words and the locked phrases would make the number', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::word($lesson, $learner, 'mother', voiced: false);
    foreach (['See you tomorrow', 'Nice to meet you', 'How much is it'] as $text) {
        DictationKit::phrase($lesson, $learner, $text, introduced: false);
    }

    $report = dmMode()->availability(new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'));

    expect($report->toArray())->toBe(['available' => 0, 'required' => 3, 'reason' => 'items_locked']);
});

it('has no locked phrases in a battle: no learner, no progress', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    foreach (range(1, 4) as $n) {
        DictationKit::phrase($lesson, $learner, "Phrase number {$n}", introduced: false);
    }
    $other = PracticeKit::words($lesson, 1, null, ['is_active' => false])[0];

    $battle = dmMode()->availability(new MaterialScope($course, $lesson), null, LocaleSet::of('ru', 'uz'));

    expect($battle->toArray())->toBe(['available' => 4, 'required' => 5, 'reason' => 'no_items']);
});

it('says audio_pending when the tasks are there and their voice is not (still being made, or failed)', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::phrase($lesson, $learner, 'See you tomorrow');
    DictationKit::phrase($lesson, $learner, 'Nice to meet you', audio: 'pending');
    DictationKit::phrase($lesson, $learner, 'How much is it', audio: 'failed');

    $report = dmMode()->availability(new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'));

    // One phrase plays; the two that wait for a voice would make three.
    expect($report->isAvailable)->toBeFalse()
        ->and($report->toArray())->toBe(['available' => 1, 'required' => 3, 'reason' => 'audio_pending']);
});

it('counts the phrases and the voiced words together, a phrase and a word of one text once each', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 2, 3);

    $report = dmMode()->availability(new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'));

    expect($report->isAvailable)->toBeTrue()
        ->and($report->toArray())->toBe(['available' => 5, 'required' => 3, 'reason' => null]);
});

it('leaves out of the words the ones that are not introduced, inactive, without a voice of their current text, and repeated', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::word($lesson, $learner, 'mother');
    DictationKit::word($lesson, $learner, 'father', introduced: false);                 // never shown to the learner
    $inactive = DictationKit::word($lesson, $learner, 'sister');
    $inactive->forceFill(['is_active' => false])->save();
    $edited = DictationKit::word($lesson, $learner, 'brother');
    $edited->forceFill(['word' => 'brothers'])->save();                       // the voice is the one of the old text
    DictationKit::word($lesson, $learner, 'grandma', voiced: false);                      // no voice yet
    DictationKit::word(Lesson::factory()->for($lesson->stage)->create(), $learner, 'Mother'); // the same text in another lesson

    $report = dmMode()->availability(new MaterialScope($course), $learner, LocaleSet::of('ru'));

    expect($report->available)->toBe(1);
});

it('takes the lesson or the whole course as the scope', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    $other = Lesson::factory()->for($lesson->stage)->create(['number' => 2]);
    DictationKit::stock($lesson, $learner, 0, 3);
    DictationKit::word($other, $learner, 'neighbour');

    expect(dmMode()->availability(new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'))->available)->toBe(3)
        ->and(dmMode()->availability(new MaterialScope($course), $learner, LocaleSet::of('ru'))->available)->toBe(4);
});

it('needs only one for the repeat of the mistakes', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    $word = DictationKit::word($lesson, $learner, 'mother');
    DictationKit::word($lesson, $learner, 'father');

    $scope = new MaterialScope($course, $lesson, new RetryMaterial([$word->id], []));
    $report = dmMode()->availability($scope, $learner, LocaleSet::of('ru'));

    expect($report->isAvailable)->toBeTrue()
        ->and($report->toArray())->toBe(['available' => 1, 'required' => 1, 'reason' => null]);
});

it('does not look at the disk: a phrase that is ready in the row counts even if its file is gone', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::phrase($lesson, $learner, 'See you tomorrow', file: false);
    DictationKit::phrase($lesson, $learner, 'Nice to meet you', file: false);
    DictationKit::phrase($lesson, $learner, 'How much is it', file: false);

    $report = dmMode()->availability(new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'));

    expect($report->isAvailable)->toBeTrue()
        ->and($report->available)->toBe(3);
});

it('counts only the phrases for a battle, and needs five of them', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 4, 6);

    $report = dmMode()->availability(new MaterialScope($course, $lesson), null, LocaleSet::of('ru', 'uz'));
    expect($report->toArray())->toBe(['available' => 4, 'required' => 5, 'reason' => 'no_items']);

    DictationKit::phrase($lesson, $learner, 'A fifth phrase');
    expect(dmMode()->availability(new MaterialScope($course, $lesson), null, LocaleSet::of('ru', 'uz'))->isAvailable)->toBeTrue();
});

it('says audio_pending for a battle whose phrases wait for their voice', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 3, 0);
    DictationKit::phrase($lesson, $learner, 'Waiting one', audio: 'pending');
    DictationKit::phrase($lesson, $learner, 'Waiting two', audio: 'pending');

    $report = dmMode()->availability(new MaterialScope($course, $lesson), null, LocaleSet::of('ru'));

    expect($report->toArray())->toBe(['available' => 3, 'required' => 5, 'reason' => 'audio_pending']);
});

// ------------------------------------------------------------------ the composition of a solo session

it('builds up to three phrases, the rest words, and the words come first', function (int $phrases, int $words, int $expectWords, int $expectPhrases): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, $phrases, $words);

    $elements = dmBuild($course, $lesson, $learner);

    expect(dmSources($elements))->toBe([...array_fill(0, $expectWords, 'word'), ...array_fill(0, $expectPhrases, 'phrase')]);
})->with([
    'plenty of both: 2 words, 3 phrases' => [5, 6, 2, 3],
    'exactly three phrases' => [3, 6, 2, 3],
    'one phrase: 4 words' => [1, 10, 4, 1],
    'no phrases: 5 words' => [0, 10, 5, 0],
    'no words: 5 phrases (3 and 2 more)' => [10, 0, 0, 5],
    'a shortage of words is made up with phrases: 4 phrases, 1 word' => [4, 1, 1, 4],
    'the least that plays: 3' => [2, 1, 1, 2],
    'three words only' => [0, 3, 3, 0],
]);

it('refuses a lesson with fewer than three to play, and says how many there were', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 1, 1);

    $error = dmCatch(fn () => dmBuild($course, $lesson, $learner));

    expect($error?->errorCode)->toBe('practice_not_enough_material')
        ->and($error?->details)->toMatchArray(['mode' => 'dictation', 'available' => 2, 'required' => 3, 'reason' => 'no_items', 'course' => 'english-a1', 'lesson_id' => $lesson->id]);
});

it('keeps the layout for a seed and changes it with another', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 10, 12);
    $identity = fn (array $elements): array => array_map(static fn (SnapshotElement $e): array => [$e->get('source'), $e->get('version_id'), $e->get('word_id')], $elements);

    $first = $identity(dmBuild($course, $lesson, $learner, seed: 'a'));
    $again = $identity(dmBuild($course, $lesson, $learner, seed: 'a'));
    $other = $identity(dmBuild($course, $lesson, $learner, seed: 'b'));

    expect($again)->toBe($first)
        ->and($other)->not->toBe($first);
});

it('takes the next candidate when the file of a chosen one is gone', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    $broken = [DictationKit::phrase($lesson, $learner, 'Missing clip one', file: false), DictationKit::phrase($lesson, $learner, 'Missing clip two', file: false)];
    DictationKit::stock($lesson, $learner, 4, 0);

    $elements = dmBuild($course, $lesson, $learner);

    // Six phrases, two of them without a clip: the four that play are taken (three and one more), the broken ones never.
    expect($elements)->toHaveCount(4)
        ->and(array_map(static fn (SnapshotElement $e): int => (int) $e->get('version_id'), $elements))
        ->not->toContain(DictationKit::version($broken[0])->id)->not->toContain(DictationKit::version($broken[1])->id);
});

it('says the voice is not ready when too many files are gone, though the rows say ready', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    foreach (['One', 'Two', 'Three', 'Four'] as $name) {
        DictationKit::phrase($lesson, $learner, "Phrase {$name}", file: $name === 'One');
    }

    $error = dmCatch(fn () => dmBuild($course, $lesson, $learner));

    expect($error?->errorCode)->toBe('practice_not_enough_material')
        ->and($error?->details)->toMatchArray(['available' => 1, 'required' => 3, 'reason' => 'audio_pending']);
});

it('puts the mistakes of a session back: the retry takes the same phrases and words, however few', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 5, 5);
    $phrase = DictationKit::phrase($lesson, $learner, 'The one I got wrong');
    $word = DictationKit::word($lesson, $learner, 'wrongword');

    $elements = dmBuild($course, $lesson, $learner, retry: new RetryMaterial([$word->id], [$phrase->id]));

    expect(dmSources($elements))->toBe(['word', 'phrase'])
        ->and($elements[0]->get('word_id'))->toBe($word->id)
        ->and($elements[1]->get('item_id'))->toBe($phrase->id);
});

it('fills the session with more phrases than three when the retry has no words', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    $items = [];
    foreach (['A', 'B', 'C', 'D'] as $name) {
        $items[] = DictationKit::phrase($lesson, $learner, "Mistaken phrase {$name}")->id;
    }

    $elements = dmBuild($course, $lesson, $learner, retry: new RetryMaterial([], $items));

    expect(dmSources($elements))->toBe(['phrase', 'phrase', 'phrase', 'phrase']);
});

// ------------------------------------------------------------------ the element of the snapshot

it('keeps in the element of a phrase everything the game needs, and nothing it does not', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    $item = DictationKit::phrase($lesson, $learner, 'See you TOMORROW!', [
        'accepted' => ['See you TOMORROW!', "See ya tomorrow, I'm off"],
        'translation' => ['ru' => 'До завтра', 'uz' => 'Ertagacha'],
        'explanation' => ['ru' => 'Прощание.', 'uz' => 'Xayrlashuv.'],
    ]);
    DictationKit::stock($lesson, $learner, 2, 0);

    $element = collect(dmBuild($course, $lesson, $learner, size: 3))->first(fn (SnapshotElement $e): bool => $e->get('item_id') === $item->id);

    expect($element)->not->toBeNull()
        ->and($element?->kind)->toBe('dictation')
        ->and($element?->id)->toMatch('/\Ae[A-Za-z0-9]{10}\z/')
        ->and($element?->data)->toBe([
            'source' => 'phrase',
            'item_id' => $item->id,
            'version_id' => DictationKit::version($item)->id,
            'word_id' => null,
            'audio_disk' => 'practice_audio',
            'audio_path' => DictationKit::version($item)->audio_key.'.mp3',
            'display' => 'See you TOMORROW!',
            'expected' => ['see you tomorrow', "see ya tomorrow i'm off"],
            'words' => 3,
            'translation' => ['ru' => 'До завтра'],
            'explanation' => ['ru' => 'Прощание.'],
        ]);
});

it('keeps in the element of a word the file of the word and its translation, and no explanation', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    $word = DictationKit::word($lesson, $learner, 'Ice cream', 'мороженое');
    DictationKit::stock($lesson, $learner, 0, 2);

    $element = collect(dmBuild($course, $lesson, $learner, size: 3))->first(fn (SnapshotElement $e): bool => $e->get('word_id') === $word->id);

    expect($element?->data)->toBe([
        'source' => 'word',
        'item_id' => null,
        'version_id' => null,
        'word_id' => $word->id,
        'audio_disk' => 'public',
        'audio_path' => app(WordAudioService::class)->pathFor('Ice cream'),
        'display' => 'Ice cream',
        'expected' => ['ice cream'],
        'words' => 2,
        'translation' => ['ru' => 'мороженое'],
        'explanation' => null,
    ]);
});

it('writes the texts for the locale of the learner, with the Russian one as the fallback', function (): void {
    [$learner, $course, $lesson] = DictationKit::world('uz');
    $translated = DictationKit::word($lesson, $learner, 'mother', 'мама');
    WordTranslation::query()->create(['word_id' => $translated->id, 'locale' => 'uz', 'translation' => 'ona']);
    DictationKit::word($lesson, $learner, 'father', 'папа');                     // no uz text: the Russian one
    DictationKit::phrase($lesson, $learner, 'See you tomorrow', ['translation' => ['ru' => 'До завтра', 'uz' => 'Ertagacha'], 'explanation' => null]);
    DictationKit::phrase($lesson, $learner, 'Nice to meet you', ['translation' => ['ru' => 'Приятно познакомиться'], 'explanation' => ['ru' => 'Вежливо.', 'uz' => 'Xushmuomala.']]);

    $elements = dmBuild($course, $lesson, $learner, locales: LocaleSet::of('uz'));

    $byDisplay = [];
    foreach ($elements as $element) {
        $byDisplay[(string) $element->get('display')] = $element->data;
    }
    expect($byDisplay['mother']['translation'])->toBe(['uz' => 'ona'])
        ->and($byDisplay['father']['translation'])->toBe(['uz' => 'папа'])
        ->and($byDisplay['See you tomorrow']['translation'])->toBe(['uz' => 'Ertagacha'])
        ->and($byDisplay['See you tomorrow']['explanation'])->toBeNull()
        ->and($byDisplay['Nice to meet you']['translation'])->toBe(['uz' => 'Приятно познакомиться'])
        ->and($byDisplay['Nice to meet you']['explanation'])->toBe(['uz' => 'Xushmuomala.']);
});

it('counts the words of the phrase for the empty tiles, whatever the hyphens and punctuation', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::phrase($lesson, $learner, 'Where is the ice-cream shop?');
    DictationKit::stock($lesson, $learner, 2, 0);

    $elements = dmBuild($course, $lesson, $learner, size: 3);
    $words = array_map(static fn (SnapshotElement $e): int => (int) $e->get('words'), $elements);

    expect($words)->toContain(6); // where is the ice cream shop
});

// ------------------------------------------------------------------ a battle

it('builds exactly five phrases for two locales, and no words', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 7, 6);
    foreach (PracticeItem::query()->get() as $item) {
        $version = DictationKit::version($item);
        $version->forceFill(['content' => [...$version->content, 'translation' => ['ru' => 'по-русски', 'uz' => "o'zbekcha"], 'explanation' => ['ru' => 'пояснение', 'uz' => 'izoh']]])->save();
    }

    $snapshot = app(PracticeSnapshotBuilder::class)->build(PracticeMode::Dictation, new MaterialScope($course, $lesson), null, LocaleSet::of('ru', 'uz'), 5, true, str_repeat('a', 32));

    expect($snapshot->elements)->toHaveCount(5)
        ->and($snapshot->locales)->toBe(['ru', 'uz'])
        ->and($snapshot->pool)->toBe([]); // a battle never swaps
    foreach ($snapshot->elements as $element) {
        expect($element->get('source'))->toBe('phrase')
            ->and($element->get('translation'))->toBe(['ru' => 'по-русски', 'uz' => "o'zbekcha"])
            ->and($element->get('explanation'))->toBe(['ru' => 'пояснение', 'uz' => 'izoh']);
    }
});

it('asks for exactly five in a battle and says how many there are', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 4, 4);

    $error = dmCatch(fn () => dmBuild($course, $lesson, null, size: 5, strict: true, locales: LocaleSet::of('ru', 'uz')));

    expect($error?->errorCode)->toBe('practice_not_enough_material')
        ->and($error?->details)->toMatchArray(['available' => 4, 'required' => 5, 'reason' => 'no_items']);
});

it('puts the files of the five phrases of a battle through the same look as a solo session', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    foreach (['One', 'Two', 'Three', 'Four', 'Five'] as $name) {
        DictationKit::phrase($lesson, $learner, "Battle phrase {$name}", file: $name !== 'Five');
    }

    $error = dmCatch(fn () => dmBuild($course, $lesson, null, size: 5, strict: true));

    expect($error?->details)->toMatchArray(['available' => 4, 'required' => 5, 'reason' => 'audio_pending']);
});

// ------------------------------------------------------------------ the spares

it('keeps a few phrases and words that the session did not take, each with everything an element needs', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 8, 10);
    $scope = new MaterialScope($course, $lesson);
    $locales = LocaleSet::of('ru');
    $seed = str_repeat('a', 32);

    $elements = dmMode()->build($scope, $learner, $locales, $seed, 5, false);
    $pool = dmMode()->spares($scope, $learner, $locales, $seed, $elements);

    expect($pool)->toHaveCount(8)
        ->and(array_count_values(array_column($pool, 'source')))->toBe(['phrase' => 4, 'word' => 4]);
    $chosen = array_map(static fn (SnapshotElement $e): array => [$e->get('version_id'), $e->get('word_id')], $elements);
    foreach ($pool as $spare) {
        expect(array_keys($spare))->toBe(['source', 'item_id', 'version_id', 'word_id', 'audio_disk', 'audio_path', 'display', 'expected', 'words', 'translation', 'explanation'])
            ->and($chosen)->not->toContain([$spare['version_id'], $spare['word_id']]);
    }
});

it('has no spares when there is nothing left, and none for a battle', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 0, 5);
    $scope = new MaterialScope($course, $lesson);
    $seed = str_repeat('a', 32);
    $elements = dmMode()->build($scope, $learner, LocaleSet::of('ru'), $seed, 5, false);

    expect(dmMode()->spares($scope, $learner, LocaleSet::of('ru'), $seed, $elements))->toBe([])
        ->and(dmMode()->spares($scope, null, LocaleSet::of('ru'), $seed, $elements))->toBe([]);
});

it('swaps for the next spare of the same source, then of the other, and drops what it tried', function (): void {
    $word = ['source' => 'word', 'audio_disk' => 'public', 'audio_path' => 'audio/w1.mp3', 'display' => 'one'];
    $word2 = ['source' => 'word', 'audio_disk' => 'public', 'audio_path' => 'audio/w2.mp3', 'display' => 'two'];
    $phrase = ['source' => 'phrase', 'audio_disk' => 'practice_audio', 'audio_path' => 'p1.mp3', 'display' => 'Phrase one'];
    foreach (['audio/w1.mp3', 'audio/w2.mp3'] as $path) {
        Storage::disk('public')->put($path, 'mp3');
    }
    Storage::disk('practice_audio')->put('p1.mp3', 'mp3');

    $replacedPhrase = new SnapshotElement('eOld0000001', 'dictation', ['source' => 'phrase']);
    $replacedWord = new SnapshotElement('eOld0000002', 'dictation', ['source' => 'word']);
    $pool = [$word, $phrase, $word2];

    // A phrase is replaced by the phrase first, though a word comes before it in the pool.
    [$new, $rest] = dmMode()->replacement($replacedPhrase, $pool, LocaleSet::of('ru'));
    expect($new->get('display'))->toBe('Phrase one')->and($new->kind)->toBe('dictation')->and($new->id)->not->toBe('eOld0000001')
        ->and(array_column($rest, 'display'))->toBe(['one', 'two']);

    // A word by the first word.
    [$new, $rest] = dmMode()->replacement($replacedWord, $pool, LocaleSet::of('ru'));
    expect($new->get('display'))->toBe('one')->and(array_column($rest, 'display'))->toBe(['Phrase one', 'two']);

    // Without a spare of its own source: the other kind.
    [$new, $rest] = dmMode()->replacement($replacedWord, [$phrase], LocaleSet::of('ru'));
    expect($new->get('source'))->toBe('phrase')->and($rest)->toBe([]);

    // A spare whose file is gone is passed over and dropped.
    Storage::disk('public')->delete('audio/w1.mp3');
    [$new, $rest] = dmMode()->replacement($replacedWord, $pool, LocaleSet::of('ru'));
    expect($new->get('display'))->toBe('two')->and(array_column($rest, 'display'))->toBe(['Phrase one']);

    // Nothing playable at all: null (the session then drops the element).
    Storage::disk('public')->delete('audio/w2.mp3');
    Storage::disk('practice_audio')->delete('p1.mp3');
    expect(dmMode()->replacement($replacedWord, $pool, LocaleSet::of('ru')))->toBeNull()
        ->and(dmMode()->replacement($replacedWord, [], LocaleSet::of('ru')))->toBeNull();
});

// ------------------------------------------------------------------ what the client sees

it('shows the client the clip, the number of words and the swaps left, and no text', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 3, 2);
    $elements = dmBuild($course, $lesson, $learner);
    $element = $elements[0];
    $state = ElementState::open()->withContext("/api/twa/practice/sessions/7/audio/{$element->id}", 2);

    $projection = dmMode()->project($element, 'ru', $state);

    expect($projection)->toBe([
        'id' => $element->id,
        'kind' => 'dictation',
        'source' => 'word',
        'audio_url' => "/api/twa/practice/sessions/7/audio/{$element->id}",
        'words' => 1,
        'replacements_left' => 2,
    ]);
    $json = json_encode($projection, JSON_THROW_ON_ERROR);
    foreach ($elements as $each) {
        expect($json)->not->toContain((string) $each->get('display'))->not->toContain((string) $each->get('audio_path'));
    }
});

// ------------------------------------------------------------------ grading and the review

/** A phrase element made by hand, as the snapshot stores it. */
function dmElement(string $display = 'See you tomorrow', ?array $expected = null, array $extra = []): SnapshotElement
{
    return new SnapshotElement('eDict000001', 'dictation', [
        'source' => 'phrase',
        'display' => $display,
        'expected' => $expected ?? [mb_strtolower($display)],
        'words' => 3,
        'translation' => ['ru' => 'До завтра'],
        'explanation' => ['ru' => 'Так прощаются.'],
        ...$extra,
    ]);
}

it('grades what was written and shows the review with the translation and the explanation', function (): void {
    $result = dmMode()->grade(dmElement(), ['text' => 'see you tomorow'], ElementState::open());

    expect($result->verdict)->toBe(Verdict::Typo)
        ->and($result->final)->toBeTrue()
        ->and($result->firstAttempt)->toBeTrue()
        ->and($result->points)->toBe(0)
        ->and($result->normalizedAnswer)->toBe(['text' => 'see you tomorow'])
        ->and($result->reveal)->toBe([
            'expected' => 'See you tomorrow',
            'your_text' => 'see you tomorow',
            'diff' => [
                ['expected' => 'see', 'got' => 'see', 'ok' => true],
                ['expected' => 'you', 'got' => 'you', 'ok' => true],
                ['expected' => 'tomorrow', 'got' => 'tomorow', 'ok' => false],
            ],
            'translation' => 'До завтра',
            'explanation' => 'Так прощаются.',
        ]);
});

it('gives the points of a battle round for a right answer only', function (): void {
    expect(dmMode()->grade(dmElement(), ['text' => 'See you tomorrow!'], ElementState::open())->points)->toBe(100)
        ->and(dmMode()->grade(dmElement(), ['text' => 'see you tomorow'], ElementState::open())->points)->toBe(0)
        ->and(dmMode()->grade(dmElement(), ['text' => 'bye'], ElementState::open())->points)->toBe(0);
});

it('accepts the variants of the author', function (): void {
    $element = dmElement('I am a student', ['i am a student', "i'm a student"]);

    expect(dmMode()->grade($element, ['text' => "I'M a student."], ElementState::open())->verdict)->toBe(Verdict::Correct)
        ->and(dmMode()->grade($element, ['text' => "I'm student"], ElementState::open())->verdict)->toBe(Verdict::Wrong);
});

it('takes "I don\'t know" and an empty text as a skip, shows the answer and no text of the learner', function (array $answer): void {
    $result = dmMode()->grade(dmElement(), $answer, ElementState::open());

    expect($result->verdict)->toBe(Verdict::Skipped)
        ->and($result->normalizedAnswer)->toBe(['skip' => true])
        ->and($result->reveal)->toMatchArray(['expected' => 'See you tomorrow', 'your_text' => null, 'diff' => null, 'translation' => 'До завтра'])
        ->and($result->points)->toBe(0);
})->with([
    'skip' => [['skip' => true]],
    'skip with a text (the skip wins)' => [['skip' => true, 'text' => 'see you tomorrow']],
    'an empty text' => [['text' => '']],
    'a null text (an empty string after the middleware)' => [['text' => null]],
    'spaces' => [['text' => '   ']],
]);

it('refuses an answer that is neither a text nor a skip: 422 invalid_answer_shape', function (array $answer): void {
    $error = dmCatch(fn () => dmMode()->grade(dmElement(), $answer, ElementState::open()));

    expect($error?->errorCode)->toBe('invalid_answer_shape')
        ->and($error?->details)->toBe(['expected' => 'text_or_skip']);
})->with([
    'nothing the mode knows' => [['option_id' => 'oAAAAAAAAA']],
    'skip that is not true' => [['skip' => false]],
    'skip as a word' => [['skip' => 'yes']],
    'a text that is a number' => [['text' => 5]],
    'a text that is a list' => [['text' => ['see', 'you']]],
]);

it('says the review in the first locale of the snapshot, and null for a missing translation or explanation', function (): void {
    $bare = new SnapshotElement('eDict000002', 'dictation', ['source' => 'word', 'display' => 'mother', 'expected' => ['mother'], 'words' => 1, 'translation' => [], 'explanation' => null]);

    $result = dmMode()->grade($bare, ['text' => 'mother'], ElementState::open());

    expect($result->reveal)->toMatchArray(['translation' => null, 'explanation' => null]);

    $both = dmElement(extra: ['translation' => ['uz' => 'Ertagacha', 'ru' => 'До завтра']]);
    expect(dmMode()->grade($both, ['skip' => true], ElementState::open())->reveal)->toMatchArray(['translation' => 'Ertagacha']);
});

it('labels a mistake of the summary with the phrase and what the learner wrote', function (): void {
    $element = dmElement();

    $typed = dmMode()->mistakeLabel($element, ElementState::fromStored(['status' => 'answered', 'verdict' => 'typo', 'answer' => ['text' => 'see you tomorow']]), 'ru');
    $skipped = dmMode()->mistakeLabel($element, ElementState::fromStored(['status' => 'answered', 'verdict' => 'skipped', 'answer' => ['skip' => true]]), 'ru');
    $blank = dmMode()->mistakeLabel($element, ElementState::fromStored(['status' => 'answered', 'verdict' => 'skipped', 'answer' => ['text' => '  ']]), 'ru');

    expect($typed)->toBe([['label' => 'See you tomorrow', 'your_answer' => 'see you tomorow']])
        ->and($skipped)->toBe([['label' => 'See you tomorrow', 'your_answer' => null]])
        ->and($blank)->toBe([['label' => 'See you tomorrow', 'your_answer' => null]]);
});
