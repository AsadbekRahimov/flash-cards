<?php

declare(strict_types=1);

use App\Domain\Practice\DTO\ElementState;
use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\DTO\Snapshot;
use App\Domain\Practice\DTO\SnapshotElement;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Modes\ModeRegistry;
use App\Domain\Practice\Services\PracticeMaterialService;
use App\Domain\Practice\Services\PracticeSnapshotBuilder;
use App\Domain\Practice\Support\PracticeTextNormalizer;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PracticeAttempt;
use App\Models\PracticeItem;
use App\Models\PracticeItemVersion;
use App\Models\PracticeSession;
use App\Models\ProductEvent;
use App\Models\Word;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * "Build the sentence" on the REAL handler (task 18.4; docs/16 §5.3, docs/15 §3.2): the material and its numbers, a solo session from
 * the opening to the summary, the checks of an answer, the layout that survives a reload, what the client may see, and the snapshot
 * of a battle (both languages, exactly five). The tasks come from the factories of 18.1, written with the content of the tests.
 */

uses(RefreshDatabase::class);

/**
 * The sentences of the tests: the prompt in ru and uz, the fragments in the order of the author, the allowed orders (indices into the
 * fragments) and the display. The first allowed order is the author's, so a test can send it as the right answer.
 */
const SM_SENTENCES = [
    'mother' => ['ru' => 'Моя мама — учитель.', 'uz' => "Onam o'qituvchi.", 'fragments' => ['My', 'mother', 'is', 'a', 'teacher'], 'accepted' => [[0, 1, 2, 3, 4]], 'display' => 'My mother is a teacher.'],
    'tea' => ['ru' => 'Я обычно пью чай.', 'uz' => 'Men odatda choy ichaman.', 'fragments' => ['I', 'usually', 'drink', 'tea'], 'accepted' => [[0, 1, 2, 3], [1, 0, 2, 3]], 'display' => 'I usually drink tea.'],
    'cat' => ['ru' => 'Кошка видит собаку.', 'uz' => "Mushuk itni ko'radi.", 'fragments' => ['the', 'cat', 'sees', 'the', 'dog'], 'accepted' => [[0, 1, 2, 3, 4]], 'display' => null],
    'school' => ['ru' => 'Мы ходим в школу пешком.', 'uz' => 'Biz maktabga piyoda boramiz.', 'fragments' => ['We', 'walk', 'to', 'school'], 'accepted' => [[0, 1, 2, 3]], 'display' => 'We walk to school.'],
    'bank' => ['ru' => 'Он работает в банке.', 'uz' => 'U bankda ishlaydi.', 'fragments' => ['He', 'works', 'in', 'a', 'bank'], 'accepted' => [[0, 1, 2, 3, 4]], 'display' => 'He works in a bank.'],
    'live' => ['ru' => 'Где ты живёшь?', 'uz' => 'Sen qayerda yashaysan?', 'fragments' => ['Where', 'do', 'you', 'live'], 'accepted' => [[0, 1, 2, 3]], 'display' => 'Where do you live?'],
];

/** The names that must not reach the client before the answer (the snapshot-only keys of docs/16 §2.8, §12). */
const SM_SECRET_KEYS = ['accepted', 'accepted_display', 'item_id', 'version_id', 'word_id', 'expected', 'pairs', 'correct_ids', 'pool', 'seed', 'audio_path', 'audio_disk', 'full_text'];

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 10:00:00');
    PracticeKit::openAccess();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * One published task of SM_SENTENCES[$key] (with `$content` laid over it), aimed at the words.
 *
 * @param  list<Word>  $words
 * @param  array<string, mixed>  $content
 */
function smItem(Lesson $lesson, array $words, string $key, array $content = []): PracticeItem
{
    $s = SM_SENTENCES[$key];

    return PracticeItem::factory()->sentence()->published()->forLesson($lesson)->targeting($words)->withContent([
        'prompt' => ['ru' => $s['ru'], 'uz' => $s['uz']],
        'fragments' => $s['fragments'],
        'accepted' => $s['accepted'],
        'display' => $s['display'],
        'explanation' => ['ru' => 'Порядок слов: '.$key, 'uz' => "So'z tartibi: ".$key],
        ...$content,
    ])->create();
}

/**
 * A learner who has been introduced to three words, a lesson with them, and the published sentences `$keys`.
 *
 * @param  list<string>|null  $keys  null: five different ones
 * @return array{0: Learner, 1: Course, 2: Lesson, 3: array<string, PracticeItem>, 4: list<Word>}
 */
function smWorld(?array $keys = null, string $locale = 'ru'): array
{
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create(['interface_locale' => $locale]);
    $words = PracticeKit::words($lesson, 3, $learner);

    $items = [];
    foreach ($keys ?? ['mother', 'tea', 'cat', 'school', 'bank'] as $key) {
        $items[$key] = smItem($lesson, $words, $key);
    }

    return [$learner, $course, $lesson, $items, $words];
}

/** @param array<string, mixed> $body */
function smOpen(Learner $learner, string $course, array $body = []): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/practice/sessions', ['mode' => 'sentence', 'course' => $course, ...$body]);
}

function smGet(Learner $learner, int $sessionId): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/practice/sessions/{$sessionId}");
}

/** @param array<string, mixed> $answer */
function smAnswer(Learner $learner, int $sessionId, string $elementId, array $answer, ?string $requestId = null): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/practice/sessions/{$sessionId}/answer", [
        'request_id' => $requestId ?? (string) Str::uuid(),
        'element_id' => $elementId,
        'answer' => $answer,
    ]);
}

/** The sentence of SM_SENTENCES that the prompt on the screen belongs to. @return array<string, mixed> */
function smSentenceOf(string $prompt): array
{
    foreach (SM_SENTENCES as $sentence) {
        if ($prompt === $sentence['ru'] || $prompt === $sentence['uz']) {
            return $sentence;
        }
    }

    throw new RuntimeException("No sentence has the prompt {$prompt}.");
}

/**
 * The ids a CLIENT would send to put `$texts` in a line: it knows the fragments only as the response shows them. Fragments with the
 * same text are taken in the order they are shown.
 *
 * @param  array<string, mixed>  $current
 * @param  list<string>  $texts
 * @return list<string>
 */
function smIdsFor(array $current, array $texts): array
{
    $pool = $current['fragments'];
    $ids = [];
    foreach ($texts as $text) {
        foreach ($pool as $i => $fragment) {
            if ($fragment['text'] === $text) {
                $ids[] = $fragment['id'];
                unset($pool[$i]);

                continue 2;
            }
        }

        throw new RuntimeException("The element has no fragment {$text}.");
    }

    return $ids;
}

/** The texts of the allowed order `$sequence` of the sentence on the screen. @param array<string, mixed> $current @return list<string> */
function smTexts(array $current, int $sequence = 0): array
{
    $sentence = smSentenceOf($current['prompt']);

    return array_map(static fn (int $i): string => $sentence['fragments'][$i], $sentence['accepted'][$sequence]);
}

/** The right answer to the element on the screen. @param array<string, mixed> $current @return array{order: list<string>} */
function smRight(array $current): array
{
    return ['order' => smIdsFor($current, smTexts($current))];
}

/** A wrong answer: all the fragments, in the reverse of the right order. @param array<string, mixed> $current @return array{order: list<string>} */
function smWrong(array $current): array
{
    return ['order' => array_reverse(smIdsFor($current, smTexts($current)))];
}

/** Every key of a decoded JSON, at any depth. @return list<string> */
function smKeys(mixed $value): array
{
    $keys = [];
    foreach (is_array($value) ? $value : [] as $key => $item) {
        if (is_string($key)) {
            $keys[] = $key;
        }
        $keys = [...$keys, ...smKeys($item)];
    }

    return $keys;
}

/** What the shown fragments of an element read as, normalized. */
function smShownText(SnapshotElement $element): string
{
    return PracticeTextNormalizer::normalize(implode(' ', array_column((array) $element->get('fragments'), 'text')));
}

/** A snapshot of the sentence mode for the scope, the way the opening of a session or the invitation to a battle builds it. */
function smSnapshot(Course $course, Lesson $lesson, ?Learner $learner, LocaleSet $locales, int $size = 5, bool $strict = false, ?string $seed = null): Snapshot
{
    return app(PracticeSnapshotBuilder::class)->build(PracticeMode::Sentence, new MaterialScope($course, $lesson), $learner, $locales, $size, $strict, $seed);
}

// ---- the material ----

it('is a card of the hub with the numbers of the tasks: no_items below the minimum, available from it', function (): void {
    [$learner, $course, $lesson, , $words] = smWorld(['mother', 'tea']);
    $hub = fn () => $this->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/practice/hub?course={$course->slug}&lesson_id={$lesson->id}")->assertOk()->json('modes.2');

    expect($hub())->toBe(['mode' => 'sentence', 'state' => 'not_enough', 'available' => 2, 'required' => 3, 'reason' => 'no_items', 'estimate_seconds' => 150]);

    smItem($lesson, $words, 'cat');

    expect($hub())->toBe(['mode' => 'sentence', 'state' => 'available', 'available' => 3, 'required' => 3, 'reason' => null, 'estimate_seconds' => 150]);
});

it('refuses to open a session below the minimum with the numbers of the shortage', function (): void {
    [$learner, $course, $lesson] = smWorld(['mother', 'tea']);

    smOpen($learner, $course->slug, ['lesson_id' => $lesson->id])->assertUnprocessable()
        ->assertJsonPath('error.code', 'practice_not_enough_material')
        ->assertJsonPath('error.details', ['mode' => 'sentence', 'available' => 2, 'required' => 3, 'reason' => 'no_items', 'course' => $course->slug, 'lesson_id' => $lesson->id]);
    expect(PracticeSession::query()->count())->toBe(0);
});

it('counts only the published tasks whose target words the learner has been introduced to', function (): void {
    [$learner, $course, $lesson, , $words] = smWorld(['mother', 'tea']);
    $unseen = PracticeKit::words($lesson, 1)[0];

    smItem($lesson, [$words[0], $unseen], 'cat');                                                         // a word that was never shown to the learner
    PracticeItem::factory()->sentence()->forLesson($lesson)->targeting($words)->create();                 // a draft
    PracticeItem::factory()->sentence()->archived()->forLesson($lesson)->targeting($words)->create();     // archived
    PracticeItem::factory()->collocation()->published()->forLesson($lesson)->targeting($words)->create(); // another type

    $availability = app(PracticeMaterialService::class)->availability(new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'));

    expect($availability['sentence']->available)->toBe(2)
        ->and($availability['sentence']->isAvailable)->toBeFalse()
        ->and($availability['sentence']->reason)->toBe('no_items');
});

it('leaves out a version whose content cannot be played, from the count and from the choice, instead of failing on it', function (): void {
    [$learner, $course, $lesson, , $words] = smWorld(['mother', 'tea', 'cat']);

    smItem($lesson, $words, 'school', ['fragments' => ['We', ['walk'], 'to', 'school']]); // a fragment that is no text
    smItem($lesson, $words, 'bank', ['accepted' => [[0, 1, 2]]]);                          // an order that is no permutation of five fragments
    smItem($lesson, $words, 'live', ['accepted' => []]);                                   // no allowed order at all
    smItem($lesson, $words, 'bank', ['prompt' => ['ru' => '  ']]);                         // nothing to show the learner
    smItem($lesson, $words, 'live', ['fragments' => []]);                                  // nothing to put together

    $availability = app(PracticeMaterialService::class)->availability(new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'));
    $session = smOpen($learner, $course->slug, ['lesson_id' => $lesson->id])->assertCreated();

    expect($availability['sentence']->available)->toBe(3)
        ->and($availability['sentence']->isAvailable)->toBeTrue()
        ->and($session->json('progress.total'))->toBe(3);
});

it('keeps the allowed orders that are orders and drops the ones that are not, in one task', function (): void {
    [$learner, $course, $lesson, , $words] = smWorld(['mother', 'tea', 'cat']);
    smItem($lesson, $words, 'school', ['accepted' => [[0, 0, 1, 2], [3, 2, 1, 0]]]); // the first is no permutation, the second is

    $snapshot = smSnapshot($course, $lesson, $learner, LocaleSet::of('ru'));
    $school = collect($snapshot->elements)->first(fn (SnapshotElement $e): bool => $e->get('prompt') === ['ru' => SM_SENTENCES['school']['ru']]);

    expect($snapshot->count())->toBe(4)
        ->and($school)->not->toBeNull()
        ->and($school?->get('accepted'))->toBe(['school to walk we'])
        ->and($school?->get('accepted_display'))->toBe(['We walk to school.']); // the display: the author's record of the FIRST allowed order
});

it('asks the battle for five tasks, whoever has been introduced to what', function (): void {
    [$learner, $course, $lesson, , $words] = smWorld(['mother', 'tea', 'cat', 'school']);
    $scope = fn () => new MaterialScope($course, $lesson);
    $handler = app(ModeRegistry::class)->for(PracticeMode::Sentence);

    $four = $handler->availability($scope(), null, LocaleSet::of('ru', 'uz'));
    smItem($lesson, PracticeKit::words($lesson, 1), 'bank'); // a word nobody has been introduced to: a battle does not look at the progress
    $five = $handler->availability($scope(), null, LocaleSet::of('ru', 'uz'));

    expect([$four->isAvailable, $four->available, $four->required, $four->reason])->toBe([false, 4, 5, 'no_items'])
        ->and([$five->isAvailable, $five->available, $five->required, $five->reason])->toBe([true, 5, 5, null]);
});

// ---- a session from the opening to the summary ----

it('plays a whole session: five sentences put right, the verdicts, the review and the summary', function (): void {
    [$learner, $course, $lesson] = smWorld();

    $session = smOpen($learner, $course->slug, ['lesson_id' => $lesson->id])->assertCreated()
        ->assertJsonPath('mode', 'sentence')
        ->assertJsonPath('progress', ['done' => 0, 'total' => 5])
        ->json();
    $seen = [];

    for ($i = 1; $i <= 5; $i++) {
        $current = $session['current'];
        expect($current['kind'])->toBe('sentence')->and($seen)->not->toContain($current['id']);
        $seen[] = $current['id'];

        $answer = smAnswer($learner, $session['id'], $current['id'], smRight($current))->assertOk();

        expect($answer->json('verdict'))->toBe('correct')
            ->and($answer->json('first_attempt'))->toBeTrue()
            ->and($answer->json('reveal.correct_text'))->toBe(smSentenceOf($current['prompt'])['display'] ?? 'The cat sees the dog.')
            ->and($answer->json('reveal.your_text'))->toBe(ucfirst(implode(' ', smTexts($current))))
            ->and($answer->json('reveal.explanation'))->toStartWith('Порядок слов: ')
            ->and($answer->json('session.progress.done'))->toBe($i);
        $session = $answer->json('session');
    }

    expect($session['status'])->toBe('finished')
        ->and($session['current'])->toBeNull()
        ->and($session['summary'])->toMatchArray(['total' => 5, 'answered' => 5, 'correct_first' => 5, 'hints' => 0, 'typos' => 0, 'mismatches' => null, 'finish_reason' => 'completed'])
        ->and($session['summary']['mistakes'])->toBe([])
        ->and(PracticeAttempt::query()->where('session_id', $session['id'])->count())->toBe(5)
        ->and(ProductEvent::query()->where('name', 'practice.session_finished')->count())->toBe(1);
});

it('takes a wrong order as wrong - not counted on the first try - and shows what was put together next to the right sentence', function (): void {
    [$learner, $course] = smWorld(['mother', 'tea', 'school']);
    $session = smOpen($learner, $course->slug)->assertCreated()->json();
    $current = $session['current'];

    $answer = smAnswer($learner, $session['id'], $current['id'], smWrong($current))->assertOk();

    expect($answer->json('verdict'))->toBe('wrong')
        ->and($answer->json('first_attempt'))->toBeTrue()
        ->and($answer->json('reveal.correct_text'))->toBe(smSentenceOf($current['prompt'])['display'])
        ->and($answer->json('reveal.your_text'))->toBe(ucfirst(implode(' ', array_reverse(smTexts($current)))))
        ->and($answer->json('session.counters.correct_first'))->toBe(0)
        ->and($answer->json('session.counters.hints'))->toBe(0);
});

it('takes "I don\'t know" as a hint: the sentence is shown, nothing is claimed to have been put together', function (): void {
    [$learner, $course] = smWorld(['mother', 'tea', 'school']);
    $session = smOpen($learner, $course->slug)->assertCreated()->json();

    $answer = smAnswer($learner, $session['id'], $session['current']['id'], ['skip' => true])->assertOk();

    expect($answer->json('verdict'))->toBe('skipped')
        ->and($answer->json('reveal.your_text'))->toBeNull()
        ->and($answer->json('reveal.correct_text'))->not->toBe('')
        ->and($answer->json('session.counters.hints'))->toBe(1)
        ->and($answer->json('session.counters.correct_first'))->toBe(0);
});

it('lists the mistakes of the summary with the right sentence and what the learner put together, and repeats only them', function (): void {
    [$learner, $course] = smWorld(['mother', 'tea', 'school', 'bank']);
    $session = smOpen($learner, $course->slug)->assertCreated()->json();

    // 1: wrong, 2: "I don't know", 3 and 4: right
    $first = $session['current'];
    $session = smAnswer($learner, $session['id'], $first['id'], smWrong($first))->assertOk()->json('session');
    $second = $session['current'];
    $session = smAnswer($learner, $session['id'], $second['id'], ['skip' => true])->assertOk()->json('session');
    foreach ([3, 4] as $_) {
        $session = smAnswer($learner, $session['id'], $session['current']['id'], smRight($session['current']))->assertOk()->json('session');
    }

    $mistakes = $session['summary']['mistakes'];
    expect($session['summary']['correct_first'])->toBe(2)
        ->and($session['summary']['hints'])->toBe(1)
        ->and($session['summary']['retry_available'])->toBeTrue()
        ->and($mistakes)->toHaveCount(2)
        ->and($mistakes[0])->toBe([
            'element_id' => $first['id'], 'kind' => 'sentence', 'label' => smSentenceOf($first['prompt'])['display'],
            'your_answer' => ucfirst(implode(' ', array_reverse(smTexts($first)))), 'verdict' => 'wrong',
        ])
        ->and($mistakes[1]['element_id'])->toBe($second['id'])
        ->and($mistakes[1]['your_answer'])->toBeNull()
        ->and($mistakes[1]['verdict'])->toBe('skipped');

    // "Repeat the mistakes": a session of exactly those two sentences (one task is enough for a repeat, three are not needed).
    $retry = smOpen($learner, $course->slug, ['retry_of' => $session['id'], 'source' => 'retry'])->assertCreated();
    $again = $retry->json();
    $repeated = [];
    expect($again['progress']['total'])->toBe(2);
    for ($i = 0; $i < 2; $i++) {
        $repeated[] = smSentenceOf($again['current']['prompt'])['display'];
        $again = smAnswer($learner, $again['id'], $again['current']['id'], smRight($again['current']))->assertOk()->json('session');
    }
    expect($repeated)->toEqualCanonicalizing(array_column($mistakes, 'label'))
        ->and($again['status'])->toBe('finished');
});

it('accepts every order the author allowed, and only those', function (): void {
    [$learner, $course, $lesson, , $words] = smWorld([]);
    foreach ([1, 2, 3] as $_) {
        smItem($lesson, $words, 'tea');
    }
    $session = smOpen($learner, $course->slug)->assertCreated()->json();

    $verdicts = [];
    foreach ([['I', 'usually', 'drink', 'tea'], ['usually', 'I', 'drink', 'tea'], ['I', 'drink', 'usually', 'tea']] as $texts) {
        $current = $session['current'];
        $answer = smAnswer($learner, $session['id'], $current['id'], ['order' => smIdsFor($current, $texts)])->assertOk();
        $verdicts[] = $answer->json('verdict');
        $session = $answer->json('session');
    }

    expect($verdicts)->toBe(['correct', 'correct', 'wrong']);
});

it('names the other allowed orders in the review, written out by the rule, and the display of the author as the right sentence', function (): void {
    [$learner, $course, $lesson, , $words] = smWorld([]);
    foreach ([1, 2, 3] as $_) {
        smItem($lesson, $words, 'tea');
    }
    $session = smOpen($learner, $course->slug)->assertCreated()->json();

    $answer = smAnswer($learner, $session['id'], $session['current']['id'], ['order' => smIdsFor($session['current'], ['usually', 'I', 'drink', 'tea'])])->assertOk();

    expect($answer->json('verdict'))->toBe('correct')
        ->and($answer->json('reveal'))->toEqual([
            'correct_text' => 'I usually drink tea.',
            'alternatives' => ['Usually I drink tea.'],
            'your_text' => 'Usually I drink tea',
            'explanation' => 'Порядок слов: tea',
        ]);
});

it('writes the right sentence out by the rule when the task has no display, and keeps the display, question mark included, when it has one', function (): void {
    [$learner, $course, $lesson] = smWorld(['cat', 'mother', 'live']);
    $session = smOpen($learner, $course->slug, ['lesson_id' => $lesson->id])->assertCreated()->json();

    $written = [];
    for ($i = 0; $i < 3; $i++) {
        $current = $session['current'];
        $answer = smAnswer($learner, $session['id'], $current['id'], smRight($current))->assertOk();
        $written[$current['prompt']] = $answer->json('reveal.correct_text');
        $session = $answer->json('session');
    }

    expect($written)->toEqual([
        SM_SENTENCES['cat']['ru'] => 'The cat sees the dog.',
        SM_SENTENCES['mother']['ru'] => 'My mother is a teacher.',
        SM_SENTENCES['live']['ru'] => 'Where do you live?',
    ]);
});

it('takes the same words in any order of their fragments: "the cat sees the dog" with either "the" first', function (): void {
    [$learner, $course, $lesson, , $words] = smWorld([]);
    foreach ([1, 2, 3] as $_) {
        smItem($lesson, $words, 'cat');
    }
    $session = smOpen($learner, $course->slug)->assertCreated()->json();

    $verdicts = [];
    foreach ([[0, 1], [1, 0], 'wrong'] as $swap) {
        $current = $session['current'];
        $the = array_values(array_column(array_filter($current['fragments'], static fn (array $f): bool => $f['text'] === 'the'), 'id'));
        $other = array_column($current['fragments'], 'id', 'text');
        expect($the)->toHaveCount(2)->and($the[0])->not->toBe($the[1]);

        $order = $swap === 'wrong'
            ? [$the[0], $the[1], $other['sees'], $other['cat'], $other['dog']]
            : [$the[$swap[0]], $other['cat'], $other['sees'], $the[$swap[1]], $other['dog']];
        $answer = smAnswer($learner, $session['id'], $current['id'], ['order' => $order])->assertOk();
        $verdicts[] = $answer->json('verdict');
        $session = $answer->json('session');
    }

    expect($verdicts)->toBe(['correct', 'correct', 'wrong']);
});

it('refuses an answer that is not all the fragments once each, and the element stays the current one', function (string $how, string $code, ?string $expected): void {
    [$learner, $course] = smWorld(['mother', 'tea', 'school']);
    $session = smOpen($learner, $course->slug)->assertCreated()->json();
    $current = $session['current'];
    $right = smRight($current)['order'];

    $order = match ($how) {
        'missing' => array_slice($right, 0, -1),
        'twice' => [...array_slice($right, 0, -1), $right[0]],
        'surplus' => [...$right, $right[0]],
        'empty' => [],
        'unknown' => [...array_slice($right, 0, -1), 'fNoSuchFrag'],
        'foreign' => [...array_slice($right, 0, -1), $current['id']], // the id of the ELEMENT is not the id of a fragment
    };

    $refused = smAnswer($learner, $session['id'], $current['id'], ['order' => $order])->assertUnprocessable()->assertJsonPath('error.code', $code);
    if ($expected !== null) {
        $refused->assertJsonPath('error.details', ['expected' => $expected]);
    }

    // nothing was counted: no attempt, the same element, the same layout, and the right answer is still welcome
    expect(PracticeAttempt::query()->count())->toBe(0)
        ->and(smGet($learner, $session['id'])->json('current'))->toBe($current)
        ->and(smGet($learner, $session['id'])->json('progress'))->toBe(['done' => 0, 'total' => 3]);
    smAnswer($learner, $session['id'], $current['id'], ['order' => $right])->assertOk()->assertJsonPath('verdict', 'correct');
})->with([
    'a fragment missing' => ['missing', 'invalid_answer_shape', 'order_of_all_fragments'],
    'a fragment twice, the count right' => ['twice', 'invalid_answer_shape', 'order_of_all_fragments'],
    'one more id than there are fragments' => ['surplus', 'invalid_answer_shape', 'order_of_all_fragments'],
    'an empty line' => ['empty', 'invalid_answer_shape', 'order_of_all_fragments'],
    'an id that is no fragment' => ['unknown', 'unknown_element_id', null],
    'the id of the element instead of a fragment' => ['foreign', 'unknown_element_id', null],
]);

it('refuses a line of more than eight ids before it gets to the task', function (): void {
    [$learner, $course] = smWorld(['mother', 'tea', 'school']);
    $session = smOpen($learner, $course->slug)->assertCreated()->json();

    smAnswer($learner, $session['id'], $session['current']['id'], ['order' => array_map(static fn (int $i): string => "f{$i}", range(1, 9))])->assertUnprocessable();
    expect(PracticeAttempt::query()->count())->toBe(0);
});

it('counts a repeated request once and gives back the first answer, even when the repeat carries another order', function (): void {
    [$learner, $course] = smWorld(['mother', 'tea', 'school']);
    $session = smOpen($learner, $course->slug)->assertCreated()->json();
    $current = $session['current'];
    $requestId = (string) Str::uuid();

    $first = smAnswer($learner, $session['id'], $current['id'], smRight($current), $requestId)->assertOk();
    $again = smAnswer($learner, $session['id'], $current['id'], smWrong($current), $requestId)->assertOk();

    expect($again->json('verdict'))->toBe('correct')
        ->and($again->json('reveal'))->toBe($first->json('reveal'))
        ->and($again->json('session.progress.done'))->toBe(1)
        ->and(PracticeAttempt::query()->count())->toBe(1)
        ->and(PracticeSession::query()->findOrFail($session['id'])->correct_first)->toBe(1);
});

it('does not take a second final answer to an element, and does not take the element for the current one twice', function (): void {
    [$learner, $course] = smWorld(['mother', 'tea', 'school']);
    $session = smOpen($learner, $course->slug)->assertCreated()->json();
    $current = $session['current'];

    smAnswer($learner, $session['id'], $current['id'], smWrong($current))->assertOk();
    smAnswer($learner, $session['id'], $current['id'], smRight($current))->assertConflict()
        ->assertJsonPath('error.code', 'answer_already_accepted')
        ->assertJsonPath('error.details.verdict', 'wrong');
});

// ---- leaving and coming back ----

it('gives the same fragments in the same order whenever the session is read, and never shuffles them again', function (): void {
    [$learner, $course] = smWorld();
    $opened = smOpen($learner, $course->slug)->assertCreated()->json();
    $stored = fn () => json_encode(PracticeSession::query()->findOrFail($opened['id'])->snapshot['elements'], JSON_THROW_ON_ERROR);
    $before = $stored();

    $first = smGet($learner, $opened['id'])->assertOk()->json('current');
    $second = smGet($learner, $opened['id'])->assertOk()->json('current');

    expect($first)->toBe($opened['current'])->and($second)->toBe($opened['current'])->and($stored())->toBe($before);

    // the next element, read after the answer and read again later, is laid out the same way
    $answered = smAnswer($learner, $opened['id'], $opened['current']['id'], smRight($opened['current']))->assertOk()->json('session');
    $later = smGet($learner, $opened['id'])->assertOk()->json('current');

    expect($later)->toBe($answered['current'])->and($later['id'])->not->toBe($opened['current']['id']);
});

it('keeps what was accepted when the learner leaves and comes back: the progress and the next element', function (): void {
    [$learner, $course] = smWorld();
    $session = smOpen($learner, $course->slug)->assertCreated()->json();

    foreach ([1, 2] as $_) {
        $session = smAnswer($learner, $session['id'], $session['current']['id'], smRight($session['current']))->assertOk()->json('session');
    }

    $back = smGet($learner, $session['id'])->assertOk();
    expect($back->json('progress'))->toBe(['done' => 2, 'total' => 5])
        ->and($back->json('counters.correct_first'))->toBe(2)
        ->and($back->json('current'))->toBe($session['current'])
        ->and($back->json('status'))->toBe('active');
});

it('does not change a session that is on when the task is edited: the answer is graded by the version it was issued from', function (): void {
    [$learner, $course, $lesson, $items] = smWorld(['mother', 'tea', 'school']);
    $opened = smOpen($learner, $course->slug)->assertCreated()->json();
    $current = $opened['current'];
    $issued = PracticeSession::query()->findOrFail($opened['id'])->snapshot['elements'][0]['version_id'];

    // every task is rewritten and published again: another prompt, other words, another order
    foreach ($items as $item) {
        $version = PracticeItemVersion::factory()->sentence()->published()->create([
            'item_id' => $item->id,
            'number' => 2,
            'content' => [
                'prompt' => ['ru' => 'Совсем другое.', 'uz' => 'Butunlay boshqa.'],
                'fragments' => ['Totally', 'different', 'text'],
                'accepted' => [[2, 1, 0]],
                'explanation' => ['ru' => 'Новое.', 'uz' => 'Yangi.'],
            ],
        ]);
        $item->forceFill(['current_version_id' => $version->id, 'published_version_id' => $version->id])->save();
    }

    $answer = smAnswer($learner, $opened['id'], $current['id'], smRight($current))->assertOk();
    expect($answer->json('verdict'))->toBe('correct')
        ->and($answer->json('reveal.correct_text'))->toBe(smSentenceOf($current['prompt'])['display'])
        ->and($answer->json('session.current.prompt'))->not->toBe('Совсем другое.')
        ->and(PracticeSession::query()->findOrFail($opened['id'])->snapshot['elements'][0]['version_id'])->toBe($issued);

    // a session opened after that gets the new text
    $fresh = smOpen($learner, $course->slug, ['replace_active' => true])->assertCreated();
    expect($fresh->json('current.prompt'))->toBe('Совсем другое.');
});

it('speaks the language of the learner: the prompt and the explanation of an Uzbek learner are Uzbek, and only theirs are kept', function (): void {
    [$learner, $course] = smWorld(['mother', 'tea', 'school'], 'uz');
    $session = smOpen($learner, $course->slug)->assertCreated()->json();
    $current = $session['current'];

    $answer = smAnswer($learner, $session['id'], $current['id'], smRight($current))->assertOk();
    $element = PracticeSession::query()->findOrFail($session['id'])->snapshot['elements'][0];

    expect(smSentenceOf($current['prompt'])['uz'])->toBe($current['prompt'])
        ->and($answer->json('reveal.explanation'))->toStartWith("So'z tartibi: ")
        ->and(array_keys($element['prompt']))->toBe(['uz'])
        ->and(array_keys($element['explanation']))->toBe(['uz']);
});

// ---- what the client sees ----

it('shows the client the prompt and the fragments and nothing else of the task, before the answer', function (): void {
    [$learner, $course, $lesson] = smWorld();
    $headers = PracticeKit::headers($learner);

    $opened = smOpen($learner, $course->slug, ['lesson_id' => $lesson->id])->assertCreated();
    $bodies = [
        $opened->json(),
        smGet($learner, $opened->json('id'))->json(),
        $this->withHeaders($headers)->getJson("/api/twa/practice/hub?course={$course->slug}&lesson_id={$lesson->id}")->json(),
    ];
    $json = json_encode($bodies, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    $opened->assertExactJsonStructure([
        'id', 'status', 'mode', 'source', 'retry_of', 'course' => ['slug', 'title'], 'lesson' => ['id', 'stage', 'number', 'title'],
        'progress' => ['done', 'total'], 'counters' => ['correct_first', 'hints', 'mismatches'], 'expires_at', 'server_now',
        'current' => ['id', 'kind', 'prompt', 'fragments' => ['*' => ['id', 'text']]], 'summary',
    ]);
    expect(array_values(array_intersect(smKeys($bodies), SM_SECRET_KEYS)))->toBe([]);

    // none of the right sentences, none of the explanations of the five tasks is anywhere in what was sent
    foreach (SM_SENTENCES as $key => $sentence) {
        expect($json)->not->toContain('Порядок слов: '.$key);
        if ($sentence['display'] !== null) {
            expect($json)->not->toContain($sentence['display']);
        }
    }
});

it('shows the fragments of the first element in an order that is not an allowed sentence, whatever the seed', function (): void {
    [$learner, $course, $lesson, , $words] = smWorld(['mother', 'tea', 'bank']);
    smItem($lesson, $words, 'school', ['fragments' => ['I', 'like', 'tea'], 'accepted' => [[0, 1, 2]], 'display' => null]); // six orders, one of them right

    for ($i = 0; $i < 60; $i++) {
        $snapshot = smSnapshot($course, $lesson, $learner, LocaleSet::of('ru'), 4, false, random_bytes(32));

        foreach ($snapshot->elements as $element) {
            expect($element->get('accepted'))->not->toContain(smShownText($element));
        }
    }
});

it('settles for any order after sixteen draws when every order is allowed, and still shows each fragment once', function (): void {
    [$learner, $course, $lesson, , $words] = smWorld(['mother', 'tea']);
    smItem($lesson, $words, 'live', ['fragments' => ['Hello', 'Bob'], 'accepted' => [[0, 1], [1, 0]], 'display' => null]);

    $snapshot = smSnapshot($course, $lesson, $learner, LocaleSet::of('ru'), 3);
    $hello = collect($snapshot->elements)->first(fn (SnapshotElement $e): bool => count((array) $e->get('fragments')) === 2);

    expect($hello)->not->toBeNull()
        ->and(array_column((array) $hello?->get('fragments'), 'text'))->toEqualCanonicalizing(['Hello', 'Bob']);
});

it('lays the fragments out from the seed: the same seed, the same layout; another seed, another one; ids are fresh and opaque', function (): void {
    [$learner, $course, $lesson] = smWorld();
    $layout = fn (Snapshot $s): array => array_map(static fn (SnapshotElement $e): array => [$e->get('prompt'), array_column((array) $e->get('fragments'), 'text')], $s->elements);

    $a = smSnapshot($course, $lesson, $learner, LocaleSet::of('ru'), 5, false, str_repeat('a', 32));
    $again = smSnapshot($course, $lesson, $learner, LocaleSet::of('ru'), 5, false, str_repeat('a', 32));
    $b = smSnapshot($course, $lesson, $learner, LocaleSet::of('ru'), 5, false, str_repeat('b', 32));

    expect($layout($a))->toBe($layout($again))->and($layout($a))->not->toBe($layout($b));

    $idsOf = function (Snapshot $snapshot): array {
        $ids = [];
        foreach ($snapshot->elements as $element) {
            $ids[] = $element->id;
            expect($element->id)->toMatch('/^e[A-Za-z0-9]{10}$/');
            foreach ((array) $element->get('fragments') as $fragment) {
                $ids[] = $fragment['id'];
                expect($fragment['id'])->toMatch('/^f[A-Za-z0-9]{10}$/');
            }
        }

        return $ids;
    };
    $ids = $idsOf($a);

    expect(array_unique($ids))->toHaveCount(count($ids))        // no two ids of one snapshot are the same
        ->and(array_intersect($ids, $idsOf($again)))->toBe([]); // and the same layout is not the same ids: they are drawn again each time
});

it('projects the prompt and the fragments and nothing else, whatever the snapshot of the element holds', function (): void {
    [$learner, $course, $lesson] = smWorld();
    $element = smSnapshot($course, $lesson, $learner, LocaleSet::of('ru', 'uz'))->elements[0];
    $handler = app(ModeRegistry::class)->for(PracticeMode::Sentence);

    // the snapshot keeps the secrets (docs/16 §2.8) ...
    expect(array_keys($element->toArray()))->toEqualCanonicalizing(['id', 'kind', 'item_id', 'version_id', 'prompt', 'fragments', 'accepted', 'accepted_display', 'explanation'])
        ->and($element->kind)->toBe('sentence')
        ->and($element->get('item_id'))->toBeInt()
        ->and($element->get('version_id'))->toBeInt();

    // ... and the handler gives the client its part by name, not by taking the secrets away: this holds even without the cleaning of the resource
    foreach (['ru', 'uz'] as $locale) {
        $projection = $handler->project($element, $locale, ElementState::open());

        expect(array_keys($projection))->toBe(['id', 'kind', 'prompt', 'fragments'])
            ->and($projection['id'])->toBe($element->id)
            ->and($projection['kind'])->toBe('sentence')
            ->and($projection['prompt'])->toBe(smSentenceOf($projection['prompt'])[$locale])
            ->and($projection['fragments'])->toHaveCount(count((array) $element->get('fragments')));
        foreach ($projection['fragments'] as $fragment) {
            expect(array_keys($fragment))->toBe(['id', 'text']);
        }
    }

    // a locale the snapshot does not have falls back to Russian, then to what there is
    expect($handler->project($element, 'en', ElementState::open())['prompt'])->toBe(smSentenceOf($handler->project($element, 'ru', ElementState::open())['prompt'])['ru']);
});

// ---- a battle ----

it('builds exactly five rounds for a battle, with the prompt and the explanation of both players, from the same tasks', function (): void {
    [$learner, $course, $lesson] = smWorld(['mother', 'tea', 'cat', 'school', 'bank', 'live']);

    $snapshot = smSnapshot($course, $lesson, null, LocaleSet::of('ru', 'uz'), 5, true, str_repeat('c', 32));
    $handler = app(ModeRegistry::class)->for(PracticeMode::Sentence);

    expect($snapshot->count())->toBe(5)
        ->and($snapshot->locales)->toBe(['ru', 'uz']);
    $items = [];
    foreach ($snapshot->elements as $element) {
        $sentence = smSentenceOf($handler->project($element, 'ru', ElementState::open())['prompt']);
        $items[] = $element->get('item_id');

        expect($element->get('prompt'))->toBe(['ru' => $sentence['ru'], 'uz' => $sentence['uz']])
            ->and(array_keys((array) $element->get('explanation')))->toBe(['ru', 'uz'])
            ->and($handler->project($element, 'uz', ElementState::open())['prompt'])->toBe($sentence['uz'])
            ->and($handler->project($element, 'ru', ElementState::open())['prompt'])->toBe($sentence['ru'])
            // the layout is one for both players: the fragments do not depend on the viewer
            ->and($handler->project($element, 'uz', ElementState::open())['fragments'])->toBe($handler->project($element, 'ru', ElementState::open())['fragments']);
    }
    expect(array_unique($items))->toHaveCount(5);
});

it('does not build a battle of fewer than five tasks, and says how many there are', function (): void {
    [$learner, $course, $lesson] = smWorld(['mother', 'tea', 'cat', 'school']);

    try {
        smSnapshot($course, $lesson, null, LocaleSet::of('ru', 'uz'), 5, true);
        $this->fail('A battle was built from four tasks.');
    } catch (PracticeException $e) {
        expect($e->errorCode)->toBe('practice_not_enough_material')
            ->and($e->details)->toBe(['mode' => 'sentence', 'available' => 4, 'required' => 5, 'reason' => 'no_items', 'course' => $course->slug, 'lesson_id' => $lesson->id]);
    }

    // a solo session of the same four is fine: four is more than the minimum of three
    expect(smSnapshot($course, $lesson, $learner, LocaleSet::of('ru'))->count())->toBe(4);
});

it('takes five of the tasks for a battle when there are more, and a task only once', function (): void {
    [, $course, $lesson] = smWorld(['mother', 'tea', 'cat', 'school', 'bank', 'live']);

    $itemIds = [];
    foreach ([str_repeat('a', 32), str_repeat('b', 32), str_repeat('c', 32)] as $seed) {
        $snapshot = smSnapshot($course, $lesson, null, LocaleSet::of('ru', 'uz'), 5, true, $seed);
        $ids = array_map(static fn (SnapshotElement $e): int => (int) $e->get('item_id'), $snapshot->elements);
        $itemIds[] = $ids;

        expect($ids)->toHaveCount(5)->and(array_unique($ids))->toHaveCount(5);
    }
    expect(array_unique(array_map(fn (array $ids): string => implode(',', $ids), $itemIds)))->toHaveCount(3); // another seed, another choice and order
});

it('grades a round of a battle with points: 100 for a correct final answer, 0 for a wrong one or a hint', function (): void {
    [, $course, $lesson] = smWorld(['mother', 'tea', 'cat', 'school', 'bank']);
    $handler = app(ModeRegistry::class)->for(PracticeMode::Sentence);
    $element = smSnapshot($course, $lesson, null, LocaleSet::of('ru', 'uz'), 5, true)->elements[0];
    $sentence = smSentenceOf($handler->project($element, 'ru', ElementState::open())['prompt']);
    $ids = fn (array $texts): array => ['order' => smIdsFor($handler->project($element, 'ru', ElementState::open()), $texts)];
    $right = array_map(static fn (int $i): string => $sentence['fragments'][$i], $sentence['accepted'][0]);

    $correct = $handler->grade($element, $ids($right), ElementState::open());
    $wrong = $handler->grade($element, $ids(array_reverse($right)), ElementState::open());
    $skipped = $handler->grade($element, ['skip' => true], ElementState::open());

    expect([$correct->verdict, $correct->points, $correct->final, $correct->firstAttempt])->toBe([Verdict::Correct, 100, true, true])
        ->and([$wrong->verdict, $wrong->points])->toBe([Verdict::Wrong, 0])
        ->and([$skipped->verdict, $skipped->points])->toBe([Verdict::Skipped, 0])
        ->and($correct->normalizedAnswer)->toHaveKey('order')
        ->and($skipped->normalizedAnswer)->toBe(['skip' => true]);
});

// ---- cost ----

it('stays within the query budgets of docs/16 §13 on the real handler, and reads tasks, not words', function (): void {
    [$learner, $course, $lesson] = smWorld();
    $headers = PracticeKit::headers($learner);
    $reads = fn (array $queries, string $table): int => count(QueryLog::matching($queries, '/^select .* from ["`]'.$table.'["`]/i'));

    $hub = QueryLog::during(fn () => $this->withHeaders($headers)->getJson("/api/twa/practice/hub?course={$course->slug}&lesson_id={$lesson->id}")->assertOk()->assertJsonPath('modes.2.available', 5));
    $session = null;
    $open = QueryLog::during(function () use ($learner, $course, $lesson, &$session): void {
        $session = smOpen($learner, $course->slug, ['lesson_id' => $lesson->id])->assertCreated()->json();
    });
    $answer = QueryLog::during(function () use ($learner, &$session): void {
        $session = smAnswer($learner, $session['id'], $session['current']['id'], smRight($session['current']))->assertOk()->json('session');
    });

    expect(count($hub))->toBeLessThanOrEqual(9, implode("\n", $hub))
        ->and(count($open))->toBeLessThanOrEqual(12, implode("\n", $open))
        ->and(count($answer))->toBeLessThanOrEqual(6, implode("\n", $answer))
        ->and($reads($hub, 'practice_items'))->toBe(1)
        ->and($reads($hub, 'practice_item_versions'))->toBe(1)
        ->and($reads($hub, 'words'))->toBeLessThanOrEqual(1) // the hub asks every mode: that one read is the pairs' own material (18.2)
        ->and($reads($open, 'practice_items'))->toBe(1)
        ->and($reads($open, 'words'))->toBe(0)
        ->and($reads($answer, 'practice_items'))->toBe(0)  // the answer is graded by the snapshot: no task is read
        ->and($reads($answer, 'practice_item_versions'))->toBe(0);
});

it('keeps a session of the longest tasks the authoring allows well inside the limits of the snapshot and of the response', function (): void {
    [$learner, $course, $lesson, , $words] = smWorld([]);
    // docs/16 §4.5: 8 fragments, 6 allowed orders, a prompt of 200 and an explanation of 300 characters (Cyrillic is the heaviest in JSON)
    $orders = [range(0, 7), array_reverse(range(0, 7)), [1, 0, 2, 3, 4, 5, 6, 7], [2, 1, 0, 3, 4, 5, 6, 7], [3, 1, 2, 0, 4, 5, 6, 7], [4, 1, 2, 3, 0, 5, 6, 7]];
    foreach (range(1, 5) as $n) {
        smItem($lesson, $words, 'bank', [
            'prompt' => ['ru' => str_repeat('Я', 200), 'uz' => str_repeat('Y', 200)],
            'fragments' => array_map(static fn (int $i): string => "fragment{$n}x{$i}", range(1, 8)),
            'accepted' => $orders,
            'display' => null,
            'explanation' => ['ru' => str_repeat('Я', 300), 'uz' => str_repeat('Y', 300)],
        ]);
    }

    $response = smOpen($learner, $course->slug)->assertCreated();
    $solo = strlen(json_encode(PracticeSession::query()->findOrFail($response->json('id'))->snapshot, JSON_THROW_ON_ERROR));
    $battle = strlen(json_encode(smSnapshot($course, $lesson, null, LocaleSet::of('ru', 'uz'), 5, true)->toArray(), JSON_THROW_ON_ERROR));

    expect(strlen((string) $response->getContent()))->toBeLessThan(8 * 1024)
        ->and($solo)->toBeLessThan(32 * 1024)
        ->and($battle)->toBeLessThan(32 * 1024); // with both languages in every element
});
