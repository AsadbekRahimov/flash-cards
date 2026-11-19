<?php

declare(strict_types=1);

use App\Domain\Practice\DTO\ElementState;
use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\DTO\RetryMaterial;
use App\Domain\Practice\DTO\Snapshot;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Services\Modes\WordFormMode;
use App\Domain\Practice\Services\PracticeSnapshotBuilder;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PracticeAttempt;
use App\Models\PracticeItem;
use App\Models\PracticeSession;
use App\Models\Word;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Practice\PracticeKit;

/*
 * "Word form" on the REAL handler (docs/16 §5.6): the material and the availability (as the collocation's), the snapshot, the projection
 * (no right form ever), the grading through the API, the review from the snapshot, the summary and the retry of the mistakes.
 */

uses(RefreshDatabase::class);

const WFM_SEED = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-19 10:00:00');
    PracticeKit::openAccess();
    config(['practice.modes.word_form' => true]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * The content of the task number `$n` (stored form): `form{n}` is right, `base{n}` the base word.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function wfmContent(int $n, array $overrides = []): array
{
    return [
        'text' => "Sentence {$n}: we ___ it.",
        'base' => "base{$n}",
        'accepted' => ["form{$n}", "other{$n}"],
        'translation' => ['ru' => "Перевод {$n}", 'uz' => "Tarjima {$n}"],
        'explanation' => ['ru' => "Пояснение {$n}", 'uz' => "Izoh {$n}"],
        ...$overrides,
    ];
}

/** @param  array<string, mixed>  $overrides */
function wfmTask(Lesson $lesson, Word $target, int $n, array $overrides = []): PracticeItem
{
    return PracticeItem::factory()->wordForm()->published()->forLesson($lesson)->targeting($target)->withContent(wfmContent($n, $overrides))->create();
}

/** @return array{0: Learner, 1: Course, 2: Lesson, 3: list<PracticeItem>} */
function wfmWorld(int $tasks = 5, string $locale = 'ru'): array
{
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create(['interface_locale' => $locale]);

    $items = [];
    foreach (PracticeKit::words($lesson, $tasks, $learner) as $i => $word) {
        $items[] = wfmTask($lesson, $word, $i + 1);
    }

    return [$learner, $course, $lesson, $items];
}

function wfmBuild(Course $course, Lesson $lesson, Learner $learner, string $seed = WFM_SEED): Snapshot
{
    return app(PracticeSnapshotBuilder::class)->build(PracticeMode::WordForm, new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'), 5, false, $seed);
}

function wfmOpen(Learner $learner, Course $course, Lesson $lesson): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/practice/sessions', ['mode' => 'word_form', 'course' => $course->slug, 'lesson_id' => $lesson->id]);
}

/** @param  array<string, mixed>  $answer */
function wfmAnswer(Learner $learner, int $sessionId, string $elementId, array $answer, ?string $requestId = null): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/practice/sessions/{$sessionId}/answer", [
        'request_id' => $requestId ?? (string) Str::uuid(), 'element_id' => $elementId, 'answer' => $answer,
    ]);
}

/** @return array<string, mixed> */
function wfmStored(int $sessionId, string $elementId): array
{
    return collect(PracticeSession::query()->findOrFail($sessionId)->snapshot['elements'])->firstWhere('id', $elementId);
}

function wfmNumber(string $text): int
{
    preg_match('/^Sentence (\d+):/', $text, $m);

    return (int) $m[1];
}

function wfmHandler(): WordFormMode
{
    return app(WordFormMode::class);
}

it('counts the tasks it can play: no_items when there are too few, items_locked when the unmet words would make the number', function (): void {
    [$learner, $course, $lesson] = wfmWorld(2);
    $report = function () use ($learner, $course, $lesson): array {
        $a = wfmHandler()->availability(new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'));

        return [$a->isAvailable, $a->available, $a->required, $a->reason];
    };

    expect($report())->toBe([false, 2, 3, 'no_items']);

    $unseen = PracticeKit::words($lesson, 1)[0];
    wfmTask($lesson, $unseen, 3);
    expect($report())->toBe([false, 2, 3, 'items_locked']);

    PracticeKit::introduce($learner, $unseen);
    expect($report())->toBe([true, 3, 3, null]);
});

it('needs one task to repeat the mistakes', function (): void {
    [$learner, $course, $lesson, $items] = wfmWorld(1);

    $a = wfmHandler()->availability(new MaterialScope($course, $lesson, new RetryMaterial([], [$items[0]->id])), $learner, LocaleSet::of('ru'));

    expect([$a->isAvailable, $a->available, $a->required])->toBe([true, 1, 1]);
});

it('skips a task whose content cannot be played, in the count and in the build alike', function (): void {
    [$learner, $course, $lesson] = wfmWorld(3);
    $word = PracticeKit::words($lesson, 1, $learner)[0];
    $n = 10;
    foreach ([['text' => 'No blank.'], ['text' => '___ and ___'], ['base' => ''], ['accepted' => []], ['accepted' => ['ok', ' ']], ['accepted' => 'form']] as $broken) {
        wfmTask($lesson, $word, $n++, $broken);
    }

    expect(wfmHandler()->availability(new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'))->available)->toBe(3)
        ->and(array_map(fn ($e): int => wfmNumber((string) $e->get('text')), wfmBuild($course, $lesson, $learner)->elements))->each->toBeLessThanOrEqual(3);
});

it('builds the element with the right forms kept on the server, from the seed', function (): void {
    [$learner, $course, $lesson, $items] = wfmWorld(5);

    $snapshot = wfmBuild($course, $lesson, $learner);
    $again = wfmBuild($course, $lesson, $learner);
    $other = wfmBuild($course, $lesson, $learner, str_repeat('c', 32));

    expect($snapshot->count())->toBe(5);
    foreach ($snapshot->elements as $element) {
        $n = wfmNumber((string) $element->get('text'));
        expect(array_keys($element->toArray()))->toBe(['id', 'kind', 'item_id', 'version_id', 'text', 'base', 'accepted', 'full_text', 'translation', 'explanation'])
            ->and($element->kind)->toBe('word_form')
            ->and($element->get('item_id'))->toBe($items[$n - 1]->id)
            ->and($element->get('accepted'))->toBe(["form{$n}", "other{$n}"])
            ->and($element->get('full_text'))->toBe("Sentence {$n}: we form{$n} it.")
            ->and($element->get('translation'))->toBe(['ru' => "Перевод {$n}"]);
    }
    $order = fn (Snapshot $s): array => array_map(fn ($e): int => (int) $e->get('item_id'), $s->elements);
    expect($order($again))->toBe($order($snapshot))
        ->and($order($other))->not->toBe($order($snapshot));
});

it('shows the client the sentence and the base word only, and the review comes from the snapshot', function (): void {
    [$learner, $course, $lesson] = wfmWorld(3);
    $element = wfmBuild($course, $lesson, $learner)->elements[0];
    $n = wfmNumber((string) $element->get('text'));

    expect(wfmHandler()->project($element, 'ru', ElementState::open()))->toBe(['id' => $element->id, 'kind' => 'word_form', 'text' => "Sentence {$n}: we ___ it.", 'base' => "base{$n}"])
        ->and(wfmHandler()->reveal($element, 'ru', ElementState::open()))->toBe([
            'expected' => "form{$n}", 'alternatives' => ["other{$n}"], 'full_text' => "Sentence {$n}: we form{$n} it.", 'translation' => "Перевод {$n}", 'explanation' => "Пояснение {$n}",
        ]);
});

it('labels a mistake with the sentence as it should be and as it was typed, nothing for a skip', function (): void {
    [$learner, $course, $lesson] = wfmWorld(3);
    $element = wfmBuild($course, $lesson, $learner)->elements[0];
    $n = wfmNumber((string) $element->get('text'));

    $typed = new ElementState(answered: true, verdict: Verdict::Wrong, answer: ['text' => ' base'.$n.' ']);
    $skipped = new ElementState(answered: true, verdict: Verdict::Skipped, answer: ['skip' => true]);

    expect(wfmHandler()->mistakeLabel($element, $typed, 'ru'))->toBe([['label' => "Sentence {$n}: we form{$n} it.", 'your_answer' => "Sentence {$n}: we base{$n} it."]])
        ->and(wfmHandler()->mistakeLabel($element, $skipped, 'ru'))->toBe([['label' => "Sentence {$n}: we form{$n} it.", 'your_answer' => null]]);
});

it('plays a whole session through the API: right, another right form, the base word, a wrong form, a skip; the summary and the retry', function (): void {
    [$learner, $course, $lesson] = wfmWorld(5);
    $session = wfmOpen($learner, $course, $lesson)->assertCreated()->json();
    $id = $session['id'];

    expect($session['mode'])->toBe('word_form')
        ->and(array_keys($session['current']))->toBe(['id', 'kind', 'text', 'base']);

    $wrongItems = [];
    foreach (['right', 'other', 'base', 'wrong', 'skip'] as $step => $how) {
        $stored = wfmStored($id, $session['current']['id']);
        $n = wfmNumber($stored['text']);
        $answer = match ($how) {
            'right' => ['text' => "Form{$n}."],
            'other' => ['text' => "other{$n}"],
            'base' => ['text' => "base{$n}"],
            'wrong' => ['text' => "nope{$n}"],
            'skip' => ['skip' => true],
        };
        if (in_array($how, ['base', 'wrong', 'skip'], true)) {
            $wrongItems[] = $stored['item_id'];
        }

        $response = wfmAnswer($learner, $id, $stored['id'], $answer)->assertOk();

        expect($response->json('verdict'))->toBe(match ($how) {
            'right', 'other' => 'correct',
            'skip' => 'skipped',
            default => 'wrong',
        })
            ->and($response->json())->not->toHaveKey('element_done')->not->toHaveKey('round_done')
            ->and(ksorted($response->json('reveal')))->toBe(ksorted([
                'expected' => "form{$n}",
                'alternatives' => ["other{$n}"],
                'your_text' => $answer['text'] ?? null,
                'same_as_base' => $how === 'base',
                'full_text' => "Sentence {$n}: we form{$n} it.",
                'translation' => "Перевод {$n}",
                'explanation' => "Пояснение {$n}",
            ]))
            ->and($response->json('session.progress.done'))->toBe($step + 1);

        $session = $response->json('session');
    }

    expect($session['summary'])->toMatchArray(['total' => 5, 'answered' => 5, 'correct_first' => 2, 'typos' => 0, 'hints' => 1, 'retry_available' => true])
        ->and(array_column($session['summary']['mistakes'], 'kind'))->toBe(['word_form', 'word_form', 'word_form'])
        ->and(array_column($session['summary']['mistakes'], 'verdict'))->toBe(['wrong', 'wrong', 'skipped'])
        ->and(PracticeAttempt::query()->where('session_id', $id)->orderBy('id')->pluck('kind')->all())->toBe(['answer', 'answer', 'answer', 'answer', 'skip']);

    $retry = test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/practice/sessions', ['retry_of' => $id, 'source' => 'retry'])->assertCreated();
    $retried = collect(PracticeSession::query()->findOrFail($retry->json('id'))->snapshot['elements'])->pluck('item_id')->sort()->values()->all();

    expect($retry->json('mode'))->toBe('word_form')
        ->and($retried)->toBe(collect($wrongItems)->sort()->values()->all());
});

it('refuses an answer of another shape with 422 text_or_skip and stores nothing', function (): void {
    [$learner, $course, $lesson] = wfmWorld(3);
    $session = wfmOpen($learner, $course, $lesson)->assertCreated()->json();

    wfmAnswer($learner, $session['id'], $session['current']['id'], ['option_id' => 'oAAAAAAAAA'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_answer_shape')
        ->assertJsonPath('error.details.expected', 'text_or_skip');

    expect(PracticeAttempt::query()->count())->toBe(0);
});

it('tells an Uzbek learner in Uzbek', function (): void {
    [$learner, $course, $lesson] = wfmWorld(3, 'uz');
    $session = wfmOpen($learner, $course, $lesson)->assertCreated()->json();
    $n = wfmNumber(wfmStored($session['id'], $session['current']['id'])['text']);

    wfmAnswer($learner, $session['id'], $session['current']['id'], ['text' => 'x'])
        ->assertOk()
        ->assertJsonPath('reveal.translation', "Tarjima {$n}")
        ->assertJsonPath('reveal.explanation', "Izoh {$n}");
});

it('cannot be opened when switched off, and no battle plays it', function (): void {
    [$learner, $course, $lesson] = wfmWorld(3);
    config(['practice.modes.word_form' => false]);

    wfmOpen($learner, $course, $lesson)->assertStatus(409)->assertJsonPath('error.code', 'practice_mode_disabled');
    expect(PracticeMode::WordForm->battleable())->toBeFalse()
        ->and(PracticeMode::WordForm->estimateSeconds())->toBe(120);
});
