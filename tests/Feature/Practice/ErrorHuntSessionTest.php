<?php

declare(strict_types=1);

use App\Models\PracticeAttempt;
use App\Models\PracticeSession;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\Practice\ErrorHuntKit;
use Tests\Support\Practice\PracticeKit;

/*
 * "Find the mistake" through the API (docs/16 §5.7, §6.3): two answers per element, the element answered only at the final one,
 * `correct_first` only for both steps right, `element_done`, the step a reload restores, a repeated request of either step, the answer of
 * the other step (409 `step_not_current`), the summary and the retry of the mistakes.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-19 10:00:00');
    PracticeKit::openAccess();
    config(['practice.modes.error_hunt' => true]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('plays a whole session in two steps per element: found and fixed, found and not, not found, skipped at each step', function (): void {
    [$learner, $course, $lesson] = ErrorHuntKit::world(5);
    $session = ErrorHuntKit::open($learner, $course, $lesson)->assertCreated()->json();
    $id = $session['id'];
    $plan = ['both', 'bad_fix', 'not_found', 'skip_locate', 'skip_fix'];
    $made = [];

    foreach ($plan as $index => $how) {
        $stored = ErrorHuntKit::stored($id, $session['current']['id']);
        $n = ErrorHuntKit::number($stored['wrong_text']);
        $made[$n] = ['how' => $how, 'stored' => $stored];
        expect($session['current']['step'])->toBe('locate');

        if ($how === 'skip_locate') {
            $final = ErrorHuntKit::answer($learner, $id, $stored['id'], ['skip' => true])->assertOk();
        } else {
            $token = ErrorHuntKit::token($stored, wrong: $how !== 'not_found');
            $step = ErrorHuntKit::answer($learner, $id, $stored['id'], ['token_id' => $token])->assertOk();

            expect($step->json('element_done'))->toBeFalse()
                ->and($step->json('verdict'))->toBe($how === 'not_found' ? 'wrong' : 'correct')
                ->and($step->json('first_attempt'))->toBeTrue()
                ->and($step->json('session.progress.done'))->toBe($index)
                ->and($step->json('session.counters.correct_first'))->toBe($session['counters']['correct_first'])   // a right search is not a right element
                ->and($step->json('session.current.id'))->toBe($stored['id'])
                ->and($step->json('session.current.step'))->toBe('fix');

            // A reload between the steps shows the step fix again, with the corrections.
            ErrorHuntKit::get($learner, $id)->assertOk()
                ->assertJsonPath('current.step', 'fix')
                ->assertJsonPath('current.options', $step->json('session.current.options'));

            $answer = match ($how) {
                'skip_fix' => ['skip' => true],
                'bad_fix' => ['option_id' => ErrorHuntKit::option($stored, right: false)],
                default => ['option_id' => ErrorHuntKit::option($stored)],
            };
            $final = ErrorHuntKit::answer($learner, $id, $stored['id'], $answer)->assertOk();
        }

        expect($final->json('element_done'))->toBeTrue()
            ->and($final->json('verdict'))->toBe(match ($how) {
                'both' => 'correct',
                'skip_locate', 'skip_fix' => 'skipped',
                default => 'wrong',
            })
            ->and($final->json('reveal.step'))->toBe('fix')
            ->and($final->json('reveal.full_text'))->toBe("Task {$n}: we right{$n} it here.")
            ->and($final->json('session.progress.done'))->toBe($index + 1);
        $session = $final->json('session');
    }

    $summary = $session['summary'];
    expect($session['status'])->toBe('finished')
        ->and($summary)->toMatchArray(['total' => 5, 'answered' => 5, 'correct_first' => 1, 'typos' => 0, 'hints' => 2, 'retry_available' => true])
        ->and($summary['mistakes'])->toHaveCount(4);

    foreach ($summary['mistakes'] as $row) {
        $n = ErrorHuntKit::number($row['label']);
        $stored = $made[$n]['stored'];
        $badText = array_column($stored['options'], 'text', 'id')[ErrorHuntKit::option($stored, right: false)];

        expect($row['kind'])->toBe('error_hunt')
            ->and($row['label'])->toBe("Task {$n}: we right{$n} it here.")
            ->and($row['your_answer'])->toBe(match ($made[$n]['how']) {
                'bad_fix' => "Task {$n}: we {$badText} it here.",
                'not_found' => "Task {$n}: we wrong{$n} it here.",
                default => null,
            });
    }

    // Every step is a row of the journal: an answer (a search or a correction) or a skip.
    expect(PracticeAttempt::query()->where('session_id', $id)->orderBy('id')->pluck('kind')->all())->toBe([
        'answer', 'answer', 'answer', 'answer', 'answer', 'answer', 'skip', 'answer', 'skip',
    ]);

    // The retry of the mistakes: the four tasks that were not right.
    $retry = test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/practice/sessions', ['retry_of' => $id, 'source' => 'retry'])->assertCreated();
    expect($retry->json('mode'))->toBe('error_hunt')
        ->and($retry->json('progress.total'))->toBe(4)
        ->and($retry->json('current.step'))->toBe('locate');
});

it('answers a repeated step locate with its stored result and the CURRENT view - the step fix, or the next element after the fix', function (): void {
    [$learner, $course, $lesson] = ErrorHuntKit::world(3);
    $session = ErrorHuntKit::open($learner, $course, $lesson)->assertCreated()->json();
    $stored = ErrorHuntKit::stored($session['id'], $session['current']['id']);
    $rid = (string) Str::uuid();

    $first = ErrorHuntKit::answer($learner, $session['id'], $stored['id'], ['token_id' => ErrorHuntKit::token($stored)], $rid)->assertOk();
    $again = ErrorHuntKit::answer($learner, $session['id'], $stored['id'], ['token_id' => ErrorHuntKit::token($stored)], $rid)->assertOk();
    expect($again->json('reveal'))->toBe($first->json('reveal'))
        ->and($again->json('element_done'))->toBeFalse()
        ->and($again->json('session.current.step'))->toBe('fix');

    ErrorHuntKit::answer($learner, $session['id'], $stored['id'], ['option_id' => ErrorHuntKit::option($stored)])->assertOk();
    $late = ErrorHuntKit::answer($learner, $session['id'], $stored['id'], ['token_id' => ErrorHuntKit::token($stored)], $rid)->assertOk();

    expect($late->json('reveal'))->toBe($first->json('reveal'))
        ->and($late->json('session.current.id'))->not->toBe($stored['id'])
        ->and($late->json('session.current.step'))->toBe('locate')
        ->and($late->json('session.counters.correct_first'))->toBe(1)
        ->and(PracticeAttempt::query()->where('session_id', $session['id'])->count())->toBe(2);
});

it('refuses the answer of the other step with 409 step_not_current, and a final element with answer_already_accepted', function (): void {
    [$learner, $course, $lesson] = ErrorHuntKit::world(3);
    $session = ErrorHuntKit::open($learner, $course, $lesson)->assertCreated()->json();
    $stored = ErrorHuntKit::stored($session['id'], $session['current']['id']);

    // A correction before the word: the server expects the step locate.
    ErrorHuntKit::answer($learner, $session['id'], $stored['id'], ['option_id' => ErrorHuntKit::option($stored)])
        ->assertStatus(409)->assertJsonPath('error.code', 'step_not_current')->assertJsonPath('error.details', ['step' => 'locate']);

    // The word, then another tab sends a word again: the server expects the step fix.
    ErrorHuntKit::answer($learner, $session['id'], $stored['id'], ['token_id' => ErrorHuntKit::token($stored)])->assertOk();
    ErrorHuntKit::answer($learner, $session['id'], $stored['id'], ['token_id' => ErrorHuntKit::token($stored, wrong: false)])
        ->assertStatus(409)->assertJsonPath('error.code', 'step_not_current')->assertJsonPath('error.details', ['step' => 'fix']);

    // An id of another element at the step fix is unknown.
    $other = collect(PracticeSession::query()->findOrFail($session['id'])->snapshot['elements'])->firstWhere('id', '!=', $stored['id']);
    ErrorHuntKit::answer($learner, $session['id'], $stored['id'], ['option_id' => $other['options'][0]['id']])
        ->assertStatus(422)->assertJsonPath('error.code', 'unknown_element_id');

    ErrorHuntKit::answer($learner, $session['id'], $stored['id'], ['option_id' => ErrorHuntKit::option($stored, right: false)])->assertOk();
    ErrorHuntKit::answer($learner, $session['id'], $stored['id'], ['option_id' => ErrorHuntKit::option($stored)])
        ->assertStatus(409)->assertJsonPath('error.code', 'answer_already_accepted')->assertJsonPath('error.details', ['verdict' => 'wrong']);

    expect(PracticeAttempt::query()->where('session_id', $session['id'])->count())->toBe(2);
});

it('closes the element on a skip after the step locate: skipped, the search kept in the review', function (bool $found): void {
    // No step in the request: `{skip: true}` ends the element at whichever step it is (docs/16 §5.7).
    [$learner, $course, $lesson] = ErrorHuntKit::world(3);
    $session = ErrorHuntKit::open($learner, $course, $lesson)->assertCreated()->json();
    $stored = ErrorHuntKit::stored($session['id'], $session['current']['id']);
    $token = ErrorHuntKit::token($stored, wrong: $found);

    ErrorHuntKit::answer($learner, $session['id'], $stored['id'], ['token_id' => $token])->assertOk()->assertJsonPath('session.current.step', 'fix');
    $skip = ErrorHuntKit::answer($learner, $session['id'], $stored['id'], ['skip' => true])->assertOk();

    expect($skip->json('element_done'))->toBeTrue()
        ->and($skip->json('verdict'))->toBe('skipped')
        ->and($skip->json('reveal'))->toMatchArray([
            'step' => 'fix', 'located_ok' => $found, 'your_token_id' => $token, 'error_token_id' => $stored['error_id'],
            'fixed_ok' => false, 'your_option_id' => null, 'correct_option_ids' => $stored['correct_ids'],
        ])
        ->and($skip->json('session.progress.done'))->toBe(1)
        ->and($skip->json('session.counters.correct_first'))->toBe(0)
        ->and($skip->json('session.current.id'))->not->toBe($stored['id'])
        ->and(PracticeAttempt::query()->where('session_id', $session['id'])->orderBy('id')->pluck('kind')->all())->toBe(['answer', 'skip']);
})->with(['the mistake found' => [true], 'the mistake not found' => [false]]);

it('takes a token_id of at most 16 characters only', function (): void {
    [$learner, $course, $lesson] = ErrorHuntKit::world(3);
    $session = ErrorHuntKit::open($learner, $course, $lesson)->assertCreated()->json();

    ErrorHuntKit::answer($learner, $session['id'], $session['current']['id'], ['token_id' => str_repeat('t', 17)])
        ->assertStatus(422)->assertJsonValidationErrors('answer.token_id');
});
