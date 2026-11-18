<?php

declare(strict_types=1);

use App\Domain\Practice\DTO\ElementState;
use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\DTO\RetryMaterial;
use App\Domain\Practice\DTO\Snapshot;
use App\Domain\Practice\DTO\SnapshotElement;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Modes\ErrorHuntMode;
use App\Domain\Practice\Services\PracticeSnapshotBuilder;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Practice\ErrorHuntKit;
use Tests\Support\Practice\PracticeKit;

/*
 * "Find the mistake" on the REAL handler (docs/16 §5.7): the material and the availability (as the collocation's), the snapshot (words
 * in the order of the sentence, the corrections shuffled), the two projections, the two steps of the grading, the review and the
 * labels of the mistakes. The life of a session through the API is in ErrorHuntSessionTest.
 */

uses(RefreshDatabase::class);

const EHM_SEED = 'dddddddddddddddddddddddddddddddd';

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-19 10:00:00');
    PracticeKit::openAccess();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function ehmHandler(): ErrorHuntMode
{
    return app(ErrorHuntMode::class);
}

function ehmBuild(Course $course, Lesson $lesson, Learner $learner, string $seed = EHM_SEED, string $locale = 'ru'): Snapshot
{
    return app(PracticeSnapshotBuilder::class)->build(PracticeMode::ErrorHunt, new MaterialScope($course, $lesson), $learner, LocaleSet::of($locale), 5, false, $seed);
}

/** The state of the element after the step `locate` with the given word. */
function ehmLocated(SnapshotElement $element, bool $right): ElementState
{
    $stored = $element->toArray();
    $tokenId = ErrorHuntKit::token($stored, $right);

    return new ElementState(extra: ['located' => $tokenId, 'located_ok' => $right]);
}

it('counts the tasks it can play: no_items, items_locked, and one is enough to repeat the mistakes', function (): void {
    [$learner, $course, $lesson, $items] = ErrorHuntKit::world(2);
    $report = function (?MaterialScope $scope = null) use ($learner, $course, $lesson): array {
        $a = ehmHandler()->availability($scope ?? new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'));

        return [$a->isAvailable, $a->available, $a->required, $a->reason];
    };

    expect($report())->toBe([false, 2, 3, 'no_items']);

    $unseen = PracticeKit::words($lesson, 1)[0];
    ErrorHuntKit::task($lesson, $unseen, 3);
    expect($report())->toBe([false, 2, 3, 'items_locked'])
        ->and($report(new MaterialScope($course, $lesson, new RetryMaterial([], [$items[0]->id]))))->toBe([true, 1, 1, null]);

    PracticeKit::introduce($learner, $unseen);
    expect($report())->toBe([true, 3, 3, null]);
});

it('skips a task whose content cannot be played, in the count and in the build alike', function (): void {
    [$learner, $course, $lesson] = ErrorHuntKit::world(3);
    $word = PracticeKit::words($lesson, 1, $learner)[0];
    $n = 10;
    foreach ([
        ['text' => 'Alone'], ['error_index' => 9], ['error_index' => '3'], ['options' => ['right']], ['options' => ['a', ' ', 'b']],
        ['correct' => []], ['correct' => [5]], ['correct' => [0, 1, 2]], ['options' => 'a, b, c'],
    ] as $broken) {
        ErrorHuntKit::task($lesson, $word, $n++, $broken);
    }

    expect(ehmHandler()->availability(new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'))->available)->toBe(3)
        ->and(array_map(fn (SnapshotElement $e): int => ErrorHuntKit::number((string) $e->get('wrong_text')), ehmBuild($course, $lesson, $learner)->elements))
        ->each->toBeLessThanOrEqual(3);
});

it('builds the element: the words in the order of the sentence, the corrections shuffled, the answers on the server only', function (): void {
    [$learner, $course, $lesson, $items] = ErrorHuntKit::world(5);

    $snapshot = ehmBuild($course, $lesson, $learner);
    $layouts = [];
    $everyId = [];
    foreach ($snapshot->elements as $element) {
        $n = ErrorHuntKit::number((string) $element->get('wrong_text'));
        $tokens = $element->get('tokens');

        expect(array_keys($element->toArray()))->toBe(['id', 'kind', 'item_id', 'version_id', 'tokens', 'error_id', 'options', 'correct_ids', 'wrong_text', 'full_text', 'translation', 'explanation'])
            ->and($element->kind)->toBe('error_hunt')
            ->and($element->get('item_id'))->toBe($items[$n - 1]->id)
            ->and(array_column($tokens, 'text'))->toBe(['Task', "{$n}:", 'we', "wrong{$n}", 'it', 'here.'])
            ->and($element->get('error_id'))->toBe($tokens[3]['id'])
            ->and(array_column($element->get('options'), 'text', 'id')[$element->get('correct_ids')[0]])->toBe("right{$n}")
            ->and($element->get('wrong_text'))->toBe("Task {$n}: we wrong{$n} it here.")
            ->and($element->get('full_text'))->toBe("Task {$n}: we right{$n} it here.")
            ->and($element->get('translation'))->toBe(['ru' => "Перевод {$n}"]);

        foreach ($tokens as $token) {
            expect($token['id'])->toMatch('/^t[0-9A-Za-z]{10}$/');
        }
        $layouts[] = implode(',', array_map(fn (array $o): string => substr($o['text'], 0, 4), $element->get('options')));
        array_push($everyId, $element->id, ...array_column($tokens, 'id'), ...array_column($element->get('options'), 'id'));
    }

    // 5 elements, 30 words, 15 corrections: every id its own; the right correction is not always in the same place.
    expect($everyId)->toHaveCount(50)->and(array_unique($everyId))->toHaveCount(50)
        ->and(array_unique($layouts))->not->toHaveCount(1);

    $order = fn (Snapshot $s): array => array_map(fn (SnapshotElement $e): int => (int) $e->get('item_id'), $s->elements);
    expect($order(ehmBuild($course, $lesson, $learner)))->toBe($order($snapshot));
});

it('keeps the punctuation around the corrected word and corrects the first word with its capital', function (): void {
    [$learner, $course, $lesson] = ErrorHuntKit::world(0);
    $words = PracticeKit::words($lesson, 3, $learner);
    ErrorHuntKit::task($lesson, $words[0], 1, ['text' => 'Task 1: some useful "informations".', 'error_index' => 4, 'error' => 'informations', 'options' => ['information', 'informing', 'informed']]);
    ErrorHuntKit::task($lesson, $words[1], 2, ['text' => 'Their is a cat, Task 2:', 'error_index' => 0, 'error' => 'Their', 'options' => ['There', 'They', 'Theirs']]);
    ErrorHuntKit::task($lesson, $words[2], 3);

    $full = array_map(fn (SnapshotElement $e): string => (string) $e->get('full_text'), ehmBuild($course, $lesson, $learner)->elements);
    sort($full);

    expect($full)->toBe(['Task 1: some useful "information".', 'Task 3: we right3 it here.', 'There is a cat, Task 2:']);
});

it('projects the step locate without a correction, the step fix with them and the word that was wrong', function (): void {
    [$learner, $course, $lesson] = ErrorHuntKit::world(3);
    $element = ehmBuild($course, $lesson, $learner)->elements[0];
    $tokens = array_map(fn (array $t): array => ['id' => $t['id'], 'text' => $t['text']], $element->get('tokens'));

    expect(ehmHandler()->project($element, 'ru', ElementState::open()))->toBe([
        'id' => $element->id, 'kind' => 'error_hunt', 'step' => 'locate', 'tokens' => $tokens, 'located' => null, 'options' => null,
    ]);

    $state = ehmLocated($element, right: false);
    expect(ehmHandler()->project($element, 'ru', $state))->toBe([
        'id' => $element->id, 'kind' => 'error_hunt', 'step' => 'fix', 'tokens' => $tokens,
        'located' => ['token_id' => $state->extra['located'], 'ok' => false, 'error_token_id' => $element->get('error_id')],
        'options' => $element->get('options'),
    ]);
});

it('grades the step locate - not final, the verdict of the search - then the step fix: correct only when both were right', function (bool $found, bool $fixed, Verdict $verdict): void {
    [$learner, $course, $lesson] = ErrorHuntKit::world(3);
    $element = ehmBuild($course, $lesson, $learner)->elements[0];
    $stored = $element->toArray();
    $tokenId = ErrorHuntKit::token($stored, $found);

    $first = ehmHandler()->grade($element, ['token_id' => $tokenId], ElementState::open());
    expect($first->final)->toBeFalse()
        ->and($first->firstAttempt)->toBeTrue()
        ->and($first->verdict)->toBe($found ? Verdict::Correct : Verdict::Wrong)
        ->and($first->extra)->toBe(['located' => $tokenId, 'located_ok' => $found])
        ->and($first->normalizedAnswer)->toBe(['token_id' => $tokenId])
        ->and($first->reveal)->toBe(['step' => 'locate', 'located_ok' => $found, 'your_token_id' => $tokenId, 'error_token_id' => $stored['error_id']]);

    $optionId = ErrorHuntKit::option($stored, $fixed);
    $second = ehmHandler()->grade($element, ['option_id' => $optionId], new ElementState(extra: $first->extra ?? []));
    $n = ErrorHuntKit::number($stored['wrong_text']);

    expect($second->final)->toBeTrue()
        ->and($second->verdict)->toBe($verdict)
        ->and($second->extra)->toBe(['located' => $tokenId, 'located_ok' => $found, 'fixed' => $optionId, 'fixed_ok' => $fixed])
        ->and($second->normalizedAnswer)->toBe(['option_id' => $optionId])
        ->and($second->reveal)->toBe([
            'step' => 'fix', 'located_ok' => $found, 'fixed_ok' => $fixed,
            'your_token_id' => $tokenId, 'error_token_id' => $stored['error_id'],
            'your_option_id' => $optionId, 'correct_option_ids' => $stored['correct_ids'],
            'wrong_text' => "Task {$n}: we wrong{$n} it here.", 'full_text' => "Task {$n}: we right{$n} it here.",
            'translation' => "Перевод {$n}", 'explanation' => "Пояснение {$n}",
        ]);
})->with([
    'both right' => [true, true, Verdict::Correct],
    'found, fixed wrong' => [true, false, Verdict::Wrong],
    'not found, fixed right' => [false, true, Verdict::Wrong],
    'neither' => [false, false, Verdict::Wrong],
]);

it('ends the element on "I don\'t know" at either step, with the steps not taken as not done', function (): void {
    [$learner, $course, $lesson] = ErrorHuntKit::world(3);
    $element = ehmBuild($course, $lesson, $learner)->elements[0];

    $atLocate = ehmHandler()->grade($element, ['skip' => true], ElementState::open());
    expect($atLocate->verdict)->toBe(Verdict::Skipped)
        ->and($atLocate->final)->toBeTrue()
        ->and($atLocate->extra)->toBe(['located' => null, 'located_ok' => false, 'fixed' => null, 'fixed_ok' => false])
        ->and($atLocate->reveal)->toMatchArray(['step' => 'fix', 'your_token_id' => null, 'your_option_id' => null, 'located_ok' => false, 'fixed_ok' => false]);

    $state = ehmLocated($element, right: true);
    $atFix = ehmHandler()->grade($element, ['skip' => true], $state);
    expect($atFix->verdict)->toBe(Verdict::Skipped)
        ->and($atFix->extra)->toBe(['located' => $state->extra['located'], 'located_ok' => true, 'fixed' => null, 'fixed_ok' => false]);
});

it('refuses the answer of the other step and an unknown id', function (): void {
    [$learner, $course, $lesson] = ErrorHuntKit::world(3);
    $snapshot = ehmBuild($course, $lesson, $learner);
    [$element, $other] = $snapshot->elements;
    $code = function (Closure $call): array {
        try {
            $call();
        } catch (PracticeException $e) {
            return [$e->errorCode, $e->details];
        }

        return ['', []];
    };
    $stored = $element->toArray();
    $located = ehmLocated($element, right: true);

    expect($code(fn () => ehmHandler()->grade($element, ['option_id' => ErrorHuntKit::option($stored)], ElementState::open())))->toBe(['step_not_current', ['step' => 'locate']])
        ->and($code(fn () => ehmHandler()->grade($element, ['token_id' => ErrorHuntKit::token($stored)], $located)))->toBe(['step_not_current', ['step' => 'fix']])
        ->and($code(fn () => ehmHandler()->grade($element, ['token_id' => ErrorHuntKit::token($other->toArray())], ElementState::open())))->toBe(['unknown_element_id', []])
        ->and($code(fn () => ehmHandler()->grade($element, ['option_id' => ErrorHuntKit::option($other->toArray())], $located)))->toBe(['unknown_element_id', []])
        ->and($code(fn () => ehmHandler()->grade($element, ['text' => 'on'], ElementState::open())))->toBe(['invalid_answer_shape', ['expected' => 'token_id']])
        ->and($code(fn () => ehmHandler()->grade($element, [], $located)))->toBe(['invalid_answer_shape', ['expected' => 'option_id']]);
});

it('reveals for a viewer: the wrong word, the right correction, both sentences in that language', function (): void {
    [$learner, $course, $lesson] = ErrorHuntKit::world(3);
    $element = app(PracticeSnapshotBuilder::class)->build(PracticeMode::ErrorHunt, new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru', 'uz'), 5, false, EHM_SEED)->elements[0];
    $n = ErrorHuntKit::number((string) $element->get('wrong_text'));

    expect(ehmHandler()->reveal($element, 'uz', ElementState::open()))->toBe([
        'error_token_id' => $element->get('error_id'), 'correct_option_ids' => $element->get('correct_ids'),
        'wrong_text' => "Task {$n}: we wrong{$n} it here.", 'full_text' => "Task {$n}: we right{$n} it here.",
        'translation' => "Tarjima {$n}", 'explanation' => "Izoh {$n}",
    ]);
});

it('labels a mistake: the wrong sentence when the mistake was not found, the sentence with the correction picked, nothing for a skip', function (): void {
    [$learner, $course, $lesson] = ErrorHuntKit::world(3);
    $element = ehmBuild($course, $lesson, $learner)->elements[0];
    $stored = $element->toArray();
    $n = ErrorHuntKit::number($stored['wrong_text']);
    $bad = ErrorHuntKit::option($stored, right: false);
    $badText = array_column($stored['options'], 'text', 'id')[$bad];
    $label = "Task {$n}: we right{$n} it here.";

    $notFound = new ElementState(answered: true, verdict: Verdict::Wrong, answer: ['option_id' => ErrorHuntKit::option($stored)], extra: ['located' => ErrorHuntKit::token($stored, false), 'located_ok' => false, 'fixed' => ErrorHuntKit::option($stored), 'fixed_ok' => true]);
    $badFix = new ElementState(answered: true, verdict: Verdict::Wrong, answer: ['option_id' => $bad], extra: ['located' => $stored['error_id'], 'located_ok' => true, 'fixed' => $bad, 'fixed_ok' => false]);
    $skipped = new ElementState(answered: true, verdict: Verdict::Skipped, answer: ['skip' => true], extra: ['located' => null, 'located_ok' => false, 'fixed' => null, 'fixed_ok' => false]);

    expect(ehmHandler()->mistakeLabel($element, $notFound, 'ru'))->toBe([['label' => $label, 'your_answer' => "Task {$n}: we wrong{$n} it here."]])
        ->and(ehmHandler()->mistakeLabel($element, $badFix, 'ru'))->toBe([['label' => $label, 'your_answer' => "Task {$n}: we {$badText} it here."]])
        ->and(ehmHandler()->mistakeLabel($element, $skipped, 'ru'))->toBe([['label' => $label, 'your_answer' => null]]);
});

it('is a solo mode of two steps that no battle plays', function (): void {
    expect(PracticeMode::ErrorHunt->battleable())->toBeFalse()
        ->and(PracticeMode::ErrorHunt->isMultiStep())->toBeTrue()
        ->and(PracticeMode::WordForm->isMultiStep())->toBeFalse()
        ->and(PracticeMode::ErrorHunt->estimateSeconds())->toBe(150)
        ->and(array_slice(PracticeMode::values(), -2))->toBe(['word_form', 'error_hunt']);
});
