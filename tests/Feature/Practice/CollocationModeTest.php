<?php

declare(strict_types=1);

use App\Domain\Practice\DTO\ElementState;
use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\DTO\RetryMaterial;
use App\Domain\Practice\DTO\Snapshot;
use App\Domain\Practice\DTO\SnapshotElement;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Enums\VersionState;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Modes\CollocationMode;
use App\Domain\Practice\Services\PracticeSnapshotBuilder;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PracticeAttempt;
use App\Models\PracticeItem;
use App\Models\PracticeItemVersion;
use App\Models\PracticeItemWord;
use App\Models\PracticeSession;
use App\Models\Word;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * "Pick the collocation" on the REAL handler (task 18.3, docs/16 §4.2, §5.4): the tasks come from the factories of 18.1 (a published
 * version with a valid content), the whole path is the one of the API. What the other tests of the practice do with the test
 * handler - the life cycle of the session, the idempotency, the isolation - is theirs; here: the material, the layout, the
 * projection, the grading and the review of this mode, for a solo session and for the battle that is built the same way.
 */

uses(RefreshDatabase::class);

const COLL_SEED = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 10:00:00');
    PracticeKit::openAccess();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * The content of the task number `$n`: the right option first (the factory's shuffle moves it), texts that name their number
 * so that an assertion can tell the tasks apart.
 *
 * @param  array<string, mixed>  $overrides  keys that replace the whole key
 * @return array<string, mixed>
 */
function collContent(int $n = 1, array $overrides = []): array
{
    return [
        'text' => "Phrase {$n}: they ___ it.",
        'options' => ["right{$n}", "wrongA{$n}", "wrongB{$n}"],
        'correct' => [0],
        'translation' => ['ru' => "Перевод {$n}", 'uz' => "Tarjima {$n}"],
        'explanation' => ['ru' => "Пояснение {$n}", 'uz' => "Izoh {$n}"],
        ...$overrides,
    ];
}

/**
 * A task published for the lesson, aimed at `$targets`.
 *
 * @param  Word|list<Word>  $targets
 * @param  array<string, mixed>  $overrides
 */
function collTask(Lesson $lesson, Word|array $targets, int $n, array $overrides = []): PracticeItem
{
    return PracticeItem::factory()->collocation()->published()->forLesson($lesson)->targeting($targets)->withContent(collContent($n, $overrides))->create();
}

/**
 * A lesson, a learner who has met every word of it, and `$tasks` published tasks (the n-th aimed at the n-th word).
 *
 * @return array{0: Learner, 1: Course, 2: Lesson, 3: list<PracticeItem>}
 */
function collWorld(int $tasks = 5, string $locale = 'ru', string $slug = 'english-a1'): array
{
    [$course, $lesson] = PracticeKit::course($slug);
    $learner = Learner::factory()->create(['interface_locale' => $locale]);

    $items = [];
    foreach (PracticeKit::words($lesson, $tasks, $learner) as $i => $word) {
        $items[] = collTask($lesson, $word, $i + 1);
    }

    return [$learner, $course, $lesson, $items];
}

function collHandler(): CollocationMode
{
    return app(CollocationMode::class);
}

/** The snapshot as the builder makes it (a seed of the test, so the layout is the same every time). */
function collBuild(Course $course, ?Lesson $lesson, ?Learner $learner, LocaleSet $locales, int $size = 5, bool $strict = false, string $seed = COLL_SEED): Snapshot
{
    return app(PracticeSnapshotBuilder::class)->build(PracticeMode::Collocation, new MaterialScope($course, $lesson), $learner, $locales, $size, $strict, $seed);
}

/** @param array<string, mixed> $body */
function collOpen(Learner $learner, Course $course, array $body = []): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/practice/sessions', ['mode' => 'collocation', 'course' => $course->slug, ...$body]);
}

function collGet(Learner $learner, int $sessionId): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/practice/sessions/{$sessionId}");
}

/** @param array<string, mixed> $answer */
function collAnswer(Learner $learner, int $sessionId, string $elementId, array $answer, ?string $requestId = null): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/practice/sessions/{$sessionId}/answer", [
        'request_id' => $requestId ?? (string) Str::uuid(),
        'element_id' => $elementId,
        'answer' => $answer,
    ]);
}

/** The element of the snapshot of the session as the SERVER keeps it (a test may look). @return array<string, mixed> */
function collStored(int $sessionId, string $elementId): array
{
    foreach (PracticeSession::query()->findOrFail($sessionId)->snapshot['elements'] as $element) {
        if ($element['id'] === $elementId) {
            return $element;
        }
    }

    throw new RuntimeException("No element {$elementId} in the session {$sessionId}.");
}

/** The id of a right option of the stored element, or - `$right = false` - of a wrong one. */
function collPick(array $stored, bool $right = true): string
{
    foreach ($stored['options'] as $option) {
        if (in_array($option['id'], $stored['correct_ids'], true) === $right) {
            return $option['id'];
        }
    }

    throw new RuntimeException('The element has no such option.');
}

/** Every key of a decoded JSON, at any depth. @return list<string> */
function collKeys(mixed $value): array
{
    $keys = [];
    foreach (is_array($value) ? $value : [] as $key => $item) {
        if (is_string($key)) {
            $keys[] = $key;
        }
        $keys = [...$keys, ...collKeys($item)];
    }

    return $keys;
}

/** @param list<string> $queries */
function collReads(array $queries, string $table): int
{
    return count(QueryLog::matching($queries, '/^select .* from ["`]'.$table.'["`]/i'));
}

/** The number n of a task, out of its text `Phrase n: …`. */
function collNumberOf(SnapshotElement|array $element): int
{
    $text = $element instanceof SnapshotElement ? (string) $element->get('text') : (string) $element['text'];
    preg_match('/^Phrase (\d+):/', $text, $match);

    return (int) $match[1];
}

// ---------------------------------------------------------------------------------------------------------------- material

it('counts the tasks it can play, and says no_items when there are fewer than the session needs', function (): void {
    [$learner, $course, $lesson] = collWorld(2);
    $report = function (?Learner $who) use ($course, $lesson): array {
        $a = collHandler()->availability(new MaterialScope($course, $lesson), $who, LocaleSet::of('ru'));

        return [$a->isAvailable, $a->available, $a->required, $a->reason];
    };

    expect($report($learner))->toBe([false, 2, 3, 'no_items']);

    collTask($lesson, PracticeKit::words($lesson, 1, $learner)[0], 3);
    expect($report($learner))->toBe([true, 3, 3, null]);

    collTask($lesson, PracticeKit::words($lesson, 1, $learner)[0], 4);
    expect($report($learner))->toBe([true, 4, 3, null]);
});

it('needs only one task to repeat the mistakes, as the minimum of the retry says', function (): void {
    [$learner, $course, $lesson, $items] = collWorld(3);
    $scope = new MaterialScope($course, $lesson, new RetryMaterial([], [$items[1]->id]));

    $availability = collHandler()->availability($scope, $learner, LocaleSet::of('ru'));

    expect([$availability->isAvailable, $availability->available, $availability->required])->toBe([true, 1, 1]);
});

it('does not hand out a task whose target word the learner has not met - the opening answers 422 with the numbers - and hands it out once the word is met', function (): void {
    [$learner, $course, $lesson] = collWorld(2);
    $unseen = PracticeKit::words($lesson, 1)[0];                            // nobody has met it
    $met = PracticeKit::words($lesson, 1, $learner)[0];
    collTask($lesson, [$met, $unseen], 3);                                  // needs both words
    collTask($lesson, $unseen, 4);

    collOpen($learner, $course, ['lesson_id' => $lesson->id])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'practice_not_enough_material')
        ->assertJsonPath('error.details', ['mode' => 'collocation', 'available' => 2, 'required' => 3, 'reason' => 'no_items', 'course' => 'english-a1', 'lesson_id' => $lesson->id]);
    expect(PracticeSession::query()->count())->toBe(0);

    PracticeKit::introduce($learner, $unseen);

    collOpen($learner, $course, ['lesson_id' => $lesson->id])->assertCreated()->assertJsonPath('progress.total', 4);
});

it('leaves out a draft, a reviewed and an archived task and a task with a word that was switched off', function (): void {
    [$learner, $course, $lesson] = collWorld(3);
    $word = PracticeKit::words($lesson, 1, $learner)[0];
    $off = PracticeKit::words($lesson, 1, $learner, ['is_active' => false])[0];
    PracticeItem::factory()->collocation()->forLesson($lesson)->targeting($word)->withContent(collContent(4))->create();
    PracticeItem::factory()->collocation()->reviewed()->forLesson($lesson)->targeting($word)->withContent(collContent(5))->create();
    PracticeItem::factory()->collocation()->archived()->forLesson($lesson)->targeting($word)->withContent(collContent(6))->create();
    collTask($lesson, $off, 7);

    $availability = collHandler()->availability(new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'));

    expect($availability->available)->toBe(3);
});

it('counts for a battle without looking at the progress of anybody, and needs all five rounds', function (): void {
    [$learner, $course, $lesson] = collWorld(3);
    $unseen = PracticeKit::words($lesson, 2);                              // met by nobody
    collTask($lesson, $unseen[0], 4);
    $off = PracticeKit::words($lesson, 1, null, ['is_active' => false])[0];
    collTask($lesson, $off, 5);                                            // a switched-off word spoils the task for everybody

    $four = collHandler()->availability(new MaterialScope($course, $lesson), null, LocaleSet::of('ru', 'uz'));
    expect([$four->isAvailable, $four->available, $four->required, $four->reason])->toBe([false, 4, 5, 'no_items']);

    collTask($lesson, $unseen[1], 6);
    $five = collHandler()->availability(new MaterialScope($course, $lesson), null, LocaleSet::of('ru', 'uz'));
    expect([$five->isAvailable, $five->available, $five->required, $five->reason])->toBe([true, 5, 5, null]);

    // The same scope for a solo learner: the unseen words are not his.
    $solo = collHandler()->availability(new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'));
    expect($solo->available)->toBe(3);
});

it('skips a task whose content cannot be played - in the count and in the build alike', function (): void {
    [$learner, $course, $lesson] = collWorld(3);
    $word = PracticeKit::words($lesson, 1, $learner)[0];
    $broken = [
        'no blank' => ['text' => 'They have it.'],
        'two blanks' => ['text' => 'They ___ it ___.'],
        'one option' => ['options' => ['right']],
        'an empty option' => ['options' => ['right', '  ', 'wrong']],
        'nothing right' => ['correct' => []],
        'a right option that is not there' => ['correct' => [3]],
        'a negative index' => ['correct' => [-1]],
        'everything right' => ['correct' => [0, 1, 2]],
        'a text for an index' => ['correct' => ['right1']],
        'options that are no list' => ['options' => 'right, wrong'],
    ];
    $n = 10;
    foreach ($broken as $overrides) {
        collTask($lesson, $word, $n++, $overrides);
    }

    $availability = collHandler()->availability(new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'));
    $snapshot = collBuild($course, $lesson, $learner, LocaleSet::of('ru'));

    expect($availability->available)->toBe(3)
        ->and($availability->isAvailable)->toBeTrue()
        ->and($snapshot->count())->toBe(3)
        ->and(array_map(collNumberOf(...), $snapshot->elements))->each->toBeLessThanOrEqual(3);
});

// ----------------------------------------------------------------------------------------------------------------- build

it('builds the element with everything the game needs and nothing to guess the answer from', function (): void {
    [$learner, $course, $lesson, $items] = collWorld(5);
    $versions = array_map(fn (PracticeItem $item): int => (int) $item->published_version_id, $items);

    $snapshot = collBuild($course, $lesson, $learner, LocaleSet::of('ru'));

    expect($snapshot->count())->toBe(5)->and($snapshot->mode)->toBe(PracticeMode::Collocation);
    $everyId = [];
    foreach ($snapshot->elements as $element) {
        $n = collNumberOf($element);
        $options = $element->get('options');
        $optionTexts = array_column($options, 'text');
        sort($optionTexts);

        expect(array_keys($element->toArray()))->toBe(['id', 'kind', 'item_id', 'version_id', 'text', 'options', 'correct_ids', 'full_text', 'translation', 'explanation'])
            ->and($element->id)->toMatch('/^e[0-9A-Za-z]{10}$/')
            ->and($element->kind)->toBe('collocation')
            ->and($element->get('item_id'))->toBe($items[$n - 1]->id)
            ->and($element->get('version_id'))->toBe($versions[$n - 1])
            ->and($element->get('text'))->toBe("Phrase {$n}: they ___ it.")
            ->and($options)->toHaveCount(3)
            ->and($optionTexts)->toBe(["right{$n}", "wrongA{$n}", "wrongB{$n}"])
            ->and($element->get('correct_ids'))->toHaveCount(1)
            ->and($element->get('full_text'))->toBe("Phrase {$n}: they right{$n} it.")
            ->and($element->get('translation'))->toBe(['ru' => "Перевод {$n}"])
            ->and($element->get('explanation'))->toBe(['ru' => "Пояснение {$n}"]);

        // The right id belongs to the right text, whatever the layout.
        $rightId = $element->get('correct_ids')[0];
        expect(array_column($options, 'text', 'id')[$rightId])->toBe("right{$n}");

        array_push($everyId, $element->id, ...array_column($options, 'id'));
        foreach ($options as $option) {
            expect(array_keys($option))->toBe(['id', 'text'])->and($option['id'])->toMatch('/^o[0-9A-Za-z]{10}$/');
        }
    }
    expect($everyId)->toHaveCount(20)->and(array_unique($everyId))->toHaveCount(20); // 5 elements + 15 options, every id its own
});

it('lays the tasks out from the seed: the same order for the same seed, another for another, the options shuffled task by task', function (): void {
    [$learner, $course, $lesson] = collWorld(8);
    $layout = fn (Snapshot $s): array => array_map(fn (SnapshotElement $e): array => [$e->get('item_id'), array_column($e->get('options'), 'text')], $s->elements);

    $a = collBuild($course, $lesson, $learner, LocaleSet::of('ru'));
    $again = collBuild($course, $lesson, $learner, LocaleSet::of('ru'));
    $other = collBuild($course, $lesson, $learner, LocaleSet::of('ru'), seed: str_repeat('b', 32));

    expect($layout($a))->toBe($layout($again))
        ->and($layout($a))->not->toBe($layout($other))
        ->and($a->elements[0]->id)->not->toBe($again->elements[0]->id) // the ids are made afresh, the order is what the seed freezes
        ->and($a->count())->toBe(5);

    // The right option is not always where the author put it, nor always in the same place: positions of the five tasks.
    $positions = array_map(fn (SnapshotElement $e): int => (int) array_search($e->get('correct_ids')[0], array_column($e->get('options'), 'id'), true), $a->elements);
    expect(array_unique($positions))->not->toHaveCount(1);
});

it('takes five of the tasks it has, all of them when there are three or four, and refuses with two', function (): void {
    [$learner, $course, $lesson] = collWorld(7, slug: 'english-seven');
    expect(collBuild($course, $lesson, $learner, LocaleSet::of('ru'))->count())->toBe(5);

    [$learner, $course, $lesson] = collWorld(3, slug: 'english-three');
    expect(collBuild($course, $lesson, $learner, LocaleSet::of('ru'))->count())->toBe(3);

    [$learner, $course, $lesson] = collWorld(2, slug: 'english-two');
    try {
        collBuild($course, $lesson, $learner, LocaleSet::of('ru'));
        $this->fail('Two tasks made a session.');
    } catch (PracticeException $e) {
        expect($e->errorCode)->toBe('practice_not_enough_material')
            ->and($e->details)->toBe(['mode' => 'collocation', 'available' => 2, 'required' => 3, 'reason' => 'no_items', 'course' => 'english-two', 'lesson_id' => $lesson->id]);
    }
});

it('writes the whole phrase with the first right option the author listed, and takes any of two right ones', function (): void {
    [$learner, $course, $lesson] = collWorld(3);
    $word = PracticeKit::words($lesson, 1, $learner)[0];
    collTask($lesson, $word, 4, ['text' => 'I need to ___ a decision.', 'options' => ['take', 'make', 'do'], 'correct' => [1, 0]]);

    $snapshot = collBuild($course, $lesson, $learner, LocaleSet::of('ru'));
    $element = collect($snapshot->elements)->first(fn (SnapshotElement $e): bool => $e->get('text') === 'I need to ___ a decision.');
    $byText = array_column($element->get('options'), 'id', 'text');
    $state = ElementState::open();

    expect($element->get('full_text'))->toBe('I need to make a decision.')                  // `make` is listed first, though `take` comes first among the options
        ->and($element->get('correct_ids'))->toBe([$byText['make'], $byText['take']])
        ->and(collHandler()->grade($element, ['option_id' => $byText['make']], $state)->verdict->value)->toBe('correct')
        ->and(collHandler()->grade($element, ['option_id' => $byText['take']], $state)->verdict->value)->toBe('correct')
        ->and(collHandler()->grade($element, ['option_id' => $byText['do']], $state)->verdict->value)->toBe('wrong')
        ->and(collHandler()->reveal($element, 'ru', $state)['correct_option_ids'])->toBe([$byText['make'], $byText['take']]);
});

it('builds a battle the same way: exactly five rounds, the texts of both players, the same elements for both', function (): void {
    [$learner, $course, $lesson] = collWorld(8);
    $mode = collHandler();
    $scope = new MaterialScope($course, $lesson);

    $elements = $mode->build($scope, null, LocaleSet::of('ru', 'uz'), COLL_SEED, 5, true);

    expect($elements)->toHaveCount(5);
    foreach ($elements as $element) {
        $n = collNumberOf($element);
        expect($element->get('translation'))->toBe(['ru' => "Перевод {$n}", 'uz' => "Tarjima {$n}"])
            ->and($element->get('explanation'))->toBe(['ru' => "Пояснение {$n}", 'uz' => "Izoh {$n}"]);

        // Each player is told in his own language, from the same element.
        $viewer = ['ru' => $mode->reveal($element, 'ru', ElementState::open()), 'uz' => $mode->reveal($element, 'uz', ElementState::open())];
        expect($viewer['ru'])->toBe(['correct_option_ids' => $element->get('correct_ids'), 'full_text' => "Phrase {$n}: they right{$n} it.", 'translation' => "Перевод {$n}", 'explanation' => "Пояснение {$n}"])
            ->and($viewer['uz'])->toBe([...$viewer['ru'], 'translation' => "Tarjima {$n}", 'explanation' => "Izoh {$n}"])
            ->and($mode->project($element, 'uz', ElementState::open()))->toBe($mode->project($element, 'ru', ElementState::open()));
    }
});

it('refuses a battle of fewer than five tasks, however many a solo session would take', function (): void {
    [, $course, $lesson] = collWorld(4);

    try {
        collHandler()->build(new MaterialScope($course, $lesson), null, LocaleSet::of('ru', 'uz'), COLL_SEED, 5, true);
        $this->fail('Four tasks made a battle.');
    } catch (PracticeException $e) {
        expect($e->errorCode)->toBe('practice_not_enough_material')
            ->and($e->details)->toMatchArray(['mode' => 'collocation', 'available' => 4, 'required' => 5, 'reason' => 'no_items']);
    }
});

it('gives a text in the other language its fallback: ru when the uz text is missing, nothing when there is no text at all', function (): void {
    [$learner, $course, $lesson] = collWorld(3);
    $word = PracticeKit::words($lesson, 1, $learner)[0];
    collTask($lesson, $word, 4, ['translation' => ['ru' => 'Только по-русски'], 'explanation' => ['ru' => ' ', 'uz' => '']]);

    $uz = collBuild($course, $lesson, $learner, LocaleSet::of('uz'));
    $element = collect($uz->elements)->first(fn (SnapshotElement $e): bool => collNumberOf($e) === 4);

    expect($element->get('translation'))->toBe(['uz' => 'Только по-русски'])
        ->and($element->get('explanation'))->toBe([])
        ->and(collHandler()->reveal($element, 'uz', ElementState::open())['translation'])->toBe('Только по-русски')
        ->and(collHandler()->reveal($element, 'uz', ElementState::open())['explanation'])->toBeNull();
});

// ------------------------------------------------------------------------------------------------------------ projection

it('shows the client the phrase and the options and nothing else - the blank and the ids, no right answer, no explanation', function (): void {
    [$learner, $course, $lesson] = collWorld(5);
    $session = collOpen($learner, $course, ['lesson_id' => $lesson->id])->assertCreated();

    $session->assertExactJsonStructure([
        'id', 'status', 'mode', 'source', 'retry_of',
        'course' => ['slug', 'title'],
        'lesson' => ['id', 'stage', 'number', 'title'],
        'progress' => ['done', 'total'],
        'counters' => ['correct_first', 'hints', 'mismatches'],
        'expires_at', 'server_now',
        'current' => ['id', 'kind', 'text', 'options' => ['*' => ['id', 'text']]],
        'summary',
    ]);
    $current = $session->json('current');
    $stored = collStored($session->json('id'), $current['id']);

    expect($current['kind'])->toBe('collocation')
        ->and($current['text'])->toBe($stored['text'])
        ->and($current['text'])->toContain('___')
        ->and($current['options'])->toBe($stored['options'])
        ->and($session->json('progress.total'))->toBe(5);

    // Neither a key nor a word of the secrets of the snapshot is on the wire - the right ids, the whole phrase, the explanation.
    $json = (string) $session->getContent();
    expect(collKeys($session->json()))->not->toContain('correct_ids', 'full_text', 'version_id', 'item_id', 'word_id', 'translation', 'explanation', 'accepted', 'expected')
        ->and($json)->not->toContain($stored['full_text'])
        ->and($json)->not->toContain($stored['explanation']['ru'])
        ->and($json)->not->toContain($stored['translation']['ru']);
    foreach ($session->json('current.options') as $option) {
        // The right option is among the options, of course - but nothing marks it.
        expect(array_keys($option))->toBe(['id', 'text']);
    }
});

it('shows the same layout on every read: the options do not move when the page is loaded again', function (): void {
    [$learner, $course, $lesson] = collWorld(5);
    $opened = collOpen($learner, $course, ['lesson_id' => $lesson->id])->assertCreated()->json();

    $first = collGet($learner, $opened['id'])->assertOk()->json('current');
    $second = collGet($learner, $opened['id'])->assertOk()->json('current');

    expect($first)->toBe($opened['current'])->and($second)->toBe($opened['current']);
});

// ---------------------------------------------------------------------------------------------------- the whole session

it('plays a whole session: an answer, its review, the next phrase, the summary with the phrase as it should be and as it was made', function (): void {
    [$learner, $course, $lesson] = collWorld(5);
    $session = collOpen($learner, $course, ['lesson_id' => $lesson->id])->assertCreated()->json();
    $id = $session['id'];
    $plan = ['right', 'wrong', 'skip', 'right', 'wrong'];
    $made = [];
    $remembered = [];

    foreach ($plan as $step => $how) {
        $stored = collStored($id, $session['current']['id']);
        $n = collNumberOf($stored);
        $answer = match ($how) {
            'right' => ['option_id' => collPick($stored)],
            'wrong' => ['option_id' => collPick($stored, right: false)],
            'skip' => ['skip' => true],
        };

        $response = collAnswer($learner, $id, $stored['id'], $answer)->assertOk();
        $response->assertExactJsonStructure([
            'verdict', 'first_attempt', 'pair',
            'reveal' => ['correct_option_ids', 'full_text', 'translation', 'explanation'],
            'session' => ['id', 'status', 'mode', 'source', 'retry_of', 'course' => ['slug', 'title'], 'lesson' => ['id', 'stage', 'number', 'title'], 'progress' => ['done', 'total'],
                'counters' => ['correct_first', 'hints', 'mismatches'], 'expires_at', 'server_now', 'current', 'summary'],
        ]);

        expect($response->json('verdict'))->toBe(['right' => 'correct', 'wrong' => 'wrong', 'skip' => 'skipped'][$how])
            ->and($response->json('first_attempt'))->toBeTrue()
            ->and($response->json('pair'))->toBeNull()
            ->and(ksorted($response->json('reveal')))->toBe(ksorted([
                'correct_option_ids' => $stored['correct_ids'],
                'full_text' => "Phrase {$n}: they right{$n} it.",
                'translation' => "Перевод {$n}",
                'explanation' => "Пояснение {$n}",
            ]))
            ->and($response->json('session.progress.done'))->toBe($step + 1);

        $made[$n] = ['how' => $how, 'picked' => $answer['option_id'] ?? null, 'stored' => $stored];
        $remembered[] = $n;
        $session = $response->json('session');
    }

    expect($session['status'])->toBe('finished')->and($session['current'])->toBeNull();
    $summary = $session['summary'];
    expect($summary)->toMatchArray(['total' => 5, 'answered' => 5, 'correct_first' => 2, 'typos' => 0, 'hints' => 1, 'mismatches' => null, 'finish_reason' => 'completed', 'retry_available' => true])
        ->and($summary['mistakes'])->toHaveCount(3);

    foreach ($summary['mistakes'] as $row) {
        $n = collNumberOf(['text' => $row['label']]);
        $pick = $made[$n]['picked'];
        $options = array_column($made[$n]['stored']['options'], 'text', 'id');

        expect($row['kind'])->toBe('collocation')
            ->and($row['label'])->toBe("Phrase {$n}: they right{$n} it.")
            ->and($row['your_answer'])->toBe($pick === null ? null : "Phrase {$n}: they {$options[$pick]} it.")
            ->and($row['verdict'])->toBe($made[$n]['how'] === 'skip' ? 'skipped' : 'wrong');
    }

    $attempts = PracticeAttempt::query()->where('session_id', $id)->orderBy('id')->get();
    expect($attempts)->toHaveCount(5)
        ->and($attempts->pluck('kind')->all())->toBe(['answer', 'answer', 'skip', 'answer', 'answer'])
        ->and($attempts->pluck('verdict')->map(fn ($v) => $v->value)->all())->toBe(['correct', 'wrong', 'skipped', 'correct', 'wrong'])
        ->and($attempts[0]->payload)->toBe(['option_id' => $made[$remembered[0]]['picked']])
        ->and($attempts[2]->payload)->toBe(['skip' => true]);
});

it('keeps a perfect session free of mistakes', function (): void {
    [$learner, $course] = collWorld(3);
    $session = collOpen($learner, $course)->assertCreated()->json();

    while ($session['status'] === 'active') {
        $stored = collStored($session['id'], $session['current']['id']);
        $session = collAnswer($learner, $session['id'], $stored['id'], ['option_id' => collPick($stored)])->assertOk()->json('session');
    }

    expect($session['summary'])->toMatchArray(['total' => 3, 'correct_first' => 3, 'hints' => 0, 'retry_available' => false])
        ->and($session['summary']['mistakes'])->toBe([]);
});

it('does not take a substituted option: an id that is not an option of the element is 422, nothing is stored, the element can still be answered', function (): void {
    [$learner, $course, $lesson] = collWorld(5);
    $session = collOpen($learner, $course, ['lesson_id' => $lesson->id])->assertCreated()->json();
    $id = $session['id'];
    $first = collStored($id, $session['current']['id']);
    $other = collect(PracticeSession::query()->findOrFail($id)->snapshot['elements'])->firstWhere('id', '!=', $first['id']);

    $substitutes = [
        'an id made up' => 'oZZZZZZZZZ',
        'an option of ANOTHER element of the session' => $other['correct_ids'][0],
        'the id of the element' => $first['id'],
        'the id of a fragment' => 'fQ1ZZZZZZZ',
    ];
    foreach ($substitutes as $why => $optionId) {
        collAnswer($learner, $id, $first['id'], ['option_id' => $optionId])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'unknown_element_id')
            ->assertJsonMissingPath('error.details');
    }

    // No option at all, or an answer of another shape: the shape is refused and says what was expected.
    foreach ([[], ['text' => 'right1'], ['order' => ['fAAAAAAAAA']], ['option_id' => 5]] as $answer) {
        collAnswer($learner, $id, $first['id'], $answer)
            ->assertStatus(422);
    }
    collAnswer($learner, $id, $first['id'], ['text' => 'right1'])->assertStatus(422)->assertJsonPath('error.code', 'invalid_answer_shape')->assertJsonPath('error.details', ['expected' => 'option_id']);

    $stored = PracticeSession::query()->findOrFail($id);
    expect(PracticeAttempt::query()->where('session_id', $id)->count())->toBe(0)
        ->and($stored->answered)->toBe(0)
        ->and($stored->state['current'])->toBe(0);

    collAnswer($learner, $id, $first['id'], ['option_id' => collPick($first)])->assertOk()->assertJsonPath('verdict', 'correct');
});

it('answers a repeated request again with the very same review, and counts it once', function (): void {
    [$learner, $course, $lesson] = collWorld(5);
    $session = collOpen($learner, $course, ['lesson_id' => $lesson->id])->assertCreated()->json();
    $first = collStored($session['id'], $session['current']['id']);
    $requestId = (string) Str::uuid();
    $body = ['option_id' => collPick($first, right: false)];

    $one = collAnswer($learner, $session['id'], $first['id'], $body, $requestId)->assertOk();
    $two = collAnswer($learner, $session['id'], $first['id'], $body, $requestId)->assertOk();
    // The retry of a request, even one that carries another answer, is the answer that was taken.
    $three = collAnswer($learner, $session['id'], $first['id'], ['option_id' => collPick($first)], $requestId)->assertOk();

    foreach ([$two, $three] as $repeat) {
        expect($repeat->json('verdict'))->toBe('wrong')
            ->and($repeat->json('reveal'))->toBe($one->json('reveal'))
            ->and($repeat->json('first_attempt'))->toBe($one->json('first_attempt'))
            ->and($repeat->json('session.progress.done'))->toBe(1);
    }
    $row = PracticeSession::query()->findOrFail($session['id']);
    expect(PracticeAttempt::query()->where('session_id', $session['id'])->count())->toBe(1)
        ->and($row->answered)->toBe(1)
        ->and($row->correct_first)->toBe(0);

    // A second answer to the same element is refused, with the verdict of the first.
    collAnswer($learner, $session['id'], $first['id'], ['option_id' => collPick($first)])
        ->assertStatus(409)->assertJsonPath('error.code', 'answer_already_accepted')->assertJsonPath('error.details.verdict', 'wrong');
});

it('is not changed by an edit of a task while the session is on: the same phrase, the same right answer, the same review', function (): void {
    [$learner, $course, $lesson, $items] = collWorld(5);
    $session = collOpen($learner, $course, ['lesson_id' => $lesson->id])->assertCreated()->json();
    $before = collGet($learner, $session['id'])->assertOk()->json('current');
    $stored = collStored($session['id'], $before['id']);
    $n = collNumberOf($stored);

    // The author publishes a new version of the task (other phrase, other options, other right answer, other explanation)...
    $item = $items[$n - 1];
    $old = PracticeItemVersion::query()->findOrFail($item->published_version_id);
    $new = PracticeItemVersion::factory()->collocation()->published()->create([
        'item_id' => $item->id,
        'number' => 2,
        'content' => collContent(90 + $n, ['text' => 'Nothing like ___ the old one.', 'options' => ['alpha', 'beta', 'gamma'], 'correct' => [2]]),
    ]);
    $old->forceFill(['state' => VersionState::Superseded, 'superseded_at' => now()])->save();
    $item->forceFill(['current_version_id' => $new->id, 'published_version_id' => $new->id])->save();
    // ...archives another task and aims a third at a word the learner has not met.
    $items[($n % 5)]->forceFill(['status' => 'archived', 'archived_at' => now()])->save();
    PracticeItemWord::query()->where('item_id', $items[(($n + 1) % 5)]->id)->update(['word_id' => PracticeKit::words($lesson, 1)[0]->id]);

    // The game on is the game it was: the phrase, the options with their ids...
    expect(collGet($learner, $session['id'])->assertOk()->json('current'))->toBe($before);

    // ...the verdict is by the old right answer, the review is the old one.
    $answer = collAnswer($learner, $session['id'], $stored['id'], ['option_id' => collPick($stored)])->assertOk();
    expect($answer->json('verdict'))->toBe('correct')
        ->and(ksorted($answer->json('reveal')))->toBe(ksorted([
            'correct_option_ids' => $stored['correct_ids'],
            'full_text' => "Phrase {$n}: they right{$n} it.",
            'translation' => "Перевод {$n}",
            'explanation' => "Пояснение {$n}",
        ]));

    // The next session sees the edit: that task has the new text, the archived one and the one without words are gone.
    collAnswer($learner, $session['id'], $answer->json('session.current.id'), ['skip' => true])->assertOk();
    $replaced = collOpen($learner, $course, ['lesson_id' => $lesson->id, 'replace_active' => true])->assertCreated();
    $texts = array_column(PracticeSession::query()->findOrFail($replaced->json('id'))->snapshot['elements'], 'full_text');

    expect($replaced->json('progress.total'))->toBe(3)
        ->and($texts)->toContain('Nothing like gamma the old one.')
        ->and($texts)->not->toContain("Phrase {$n}: they right{$n} it.");
});

it('is not changed by the archive of the task it plays: the game goes on to its end', function (): void {
    [$learner, $course, $lesson, $items] = collWorld(3);
    $session = collOpen($learner, $course, ['lesson_id' => $lesson->id])->assertCreated()->json();
    foreach ($items as $item) {
        $item->forceFill(['status' => 'archived', 'archived_at' => now()])->save();
    }

    while ($session['status'] === 'active') {
        $stored = collStored($session['id'], $session['current']['id']);
        $session = collAnswer($learner, $session['id'], $stored['id'], ['option_id' => collPick($stored)])->assertOk()->json('session');
    }

    expect($session['summary']['correct_first'])->toBe(3);
});

it('repeats the mistakes of a collocation session: exactly the tasks that were got wrong, one is enough to play', function (): void {
    [$learner, $course, $lesson] = collWorld(4);
    $session = collOpen($learner, $course, ['lesson_id' => $lesson->id])->assertCreated()->json();
    $wrongItems = [];

    foreach (['wrong', 'right', 'skip', 'right'] as $how) {
        $stored = collStored($session['id'], $session['current']['id']);
        if ($how !== 'right') {
            $wrongItems[] = $stored['item_id'];
        }
        $answer = match ($how) {
            'right' => ['option_id' => collPick($stored)],
            'wrong' => ['option_id' => collPick($stored, right: false)],
            'skip' => ['skip' => true],
        };
        $session = collAnswer($learner, $session['id'], $stored['id'], $answer)->assertOk()->json('session');
    }
    expect($session['summary']['retry_available'])->toBeTrue();

    $retry = test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/practice/sessions', ['retry_of' => $session['id'], 'source' => 'retry'])->assertCreated();
    $stored = PracticeSession::query()->findOrFail($retry->json('id'))->snapshot['elements'];

    expect($retry->json('mode'))->toBe('collocation')
        ->and($retry->json('retry_of'))->toBe($session['id'])
        ->and($retry->json('progress.total'))->toBe(2)
        ->and(collect($stored)->pluck('item_id')->sort()->values()->all())->toBe(collect($wrongItems)->sort()->values()->all());
});

it('tells a learner in the language of the interface: Uzbek translations and explanations for an Uzbek learner', function (): void {
    [$learner, $course, $lesson] = collWorld(3, 'uz');
    $session = collOpen($learner, $course, ['lesson_id' => $lesson->id])->assertCreated()->json();
    $stored = collStored($session['id'], $session['current']['id']);
    $n = collNumberOf($stored);

    $answer = collAnswer($learner, $session['id'], $stored['id'], ['option_id' => collPick($stored, right: false)])->assertOk();

    expect($stored['translation'])->toBe(['uz' => "Tarjima {$n}"])
        ->and($answer->json('reveal.translation'))->toBe("Tarjima {$n}")
        ->and($answer->json('reveal.explanation'))->toBe("Izoh {$n}")
        ->and($answer->json('reveal.full_text'))->toBe("Phrase {$n}: they right{$n} it.");
});

// --------------------------------------------------------------------------------------------------------- the budgets

it('stays within the statement budgets of docs/16 §13 with the real handler: the hub, the opening, a read, an answer', function (): void {
    [$learner, $course, $lesson] = collWorld(6);
    // The other three modes read their material the way the final handlers do, so the hub is the hub of the release.
    foreach ([PracticeMode::Pairs, PracticeMode::Sentence, PracticeMode::Dictation] as $mode) {
        PracticeKit::fake($mode, candidates: 9, spares: 2)->readsMaterial = true;
    }
    $headers = PracticeKit::headers($learner);

    $hub = QueryLog::during(fn () => test()->withHeaders($headers)->getJson("/api/twa/practice/hub?course={$course->slug}&lesson_id={$lesson->id}")
        ->assertOk()->assertJsonPath('modes.1.mode', 'collocation')->assertJsonPath('modes.1.available', 6)->assertJsonPath('modes.1.state', 'available'));
    $hubCourse = QueryLog::during(fn () => test()->withHeaders($headers)->getJson("/api/twa/practice/hub?course={$course->slug}")->assertOk());

    $opened = null;
    $open = QueryLog::during(function () use ($learner, $course, $lesson, &$opened): void {
        $opened = collOpen($learner, $course, ['lesson_id' => $lesson->id])->assertCreated()->json();
    });
    $show = QueryLog::during(fn () => collGet($learner, $opened['id'])->assertOk());
    $stored = collStored($opened['id'], $opened['current']['id']);
    $answer = QueryLog::during(fn () => collAnswer($learner, $opened['id'], $stored['id'], ['option_id' => collPick($stored)])->assertOk());

    expect(count($hub))->toBeLessThanOrEqual(9, implode("\n", $hub))
        ->and(count($hubCourse))->toBeLessThanOrEqual(9, implode("\n", $hubCourse))
        ->and(collReads($hub, 'practice_items'))->toBe(1)
        ->and(collReads($hub, 'practice_item_versions'))->toBe(1)
        ->and(count($open))->toBeLessThanOrEqual(12, implode("\n", $open))
        ->and(collReads($open, 'practice_items'))->toBe(1)
        ->and(collReads($open, 'practice_item_versions'))->toBe(1)
        ->and(collReads($open, 'words'))->toBe(0)               // a collocation reads tasks, not words
        ->and(count($show))->toBeLessThanOrEqual(3, implode("\n", $show))
        ->and(count($answer))->toBeLessThanOrEqual(6, implode("\n", $answer))
        ->and(collReads($answer, 'practice_items'))->toBe(0)    // the answer is graded on the snapshot
        ->and(collReads($answer, 'practice_item_versions'))->toBe(0)
        ->and(collReads($answer, 'words'))->toBe(0);
});

it('keeps the response of a session and the snapshot of five tasks of the largest size well within the limits', function (): void {
    [$learner, $course, $lesson] = collWorld(5);
    // Texts at the limits of the content rules (docs/16 §4.5): a 160-character phrase, 300-character explanations in two languages.
    foreach (PracticeItem::query()->get() as $item) {
        $version = PracticeItemVersion::query()->findOrFail($item->published_version_id);
        $content = $version->content;
        $content['text'] = str_repeat('word ', 30).'___ ok.';
        $content['explanation'] = ['ru' => str_repeat('я', 300), 'uz' => str_repeat('u', 300)];
        $content['translation'] = ['ru' => str_repeat('я', 160), 'uz' => str_repeat('u', 160)];
        $version->forceFill(['content' => $content])->save();
    }

    $response = collOpen($learner, $course, ['lesson_id' => $lesson->id])->assertCreated();
    $battle = collBuild($course, $lesson, null, LocaleSet::of('ru', 'uz'), 5, true);

    expect(strlen((string) $response->getContent()))->toBeLessThan(8 * 1024)
        ->and(strlen(json_encode($battle->toArray(), JSON_THROW_ON_ERROR)))->toBeLessThan(Snapshot::MAX_BYTES);
});
