<?php

declare(strict_types=1);

use App\Domain\Game\Enums\RunEvent;
use App\Domain\Game\Enums\RunFinishReason;
use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Services\Runs\RunService;
use App\Models\GameCoinLedgerEntry;
use App\Models\GameRun;
use App\Models\GameRunAttempt;
use App\Models\GameWallet;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\ProductEvent;
use App\Models\Stage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\Game\GameKit;
use Tests\Support\Practice\PracticeKit;

/*
 * docs/18 §12.2.1, §12.3, §12.5.2: the life of a run of a stop through RunService - the snapshot is fixed at the start, a repeated
 * request changes nothing, the flasks and the stars, the lazy closing of an expired run, the hint of the bazaar. The HTTP layer
 * (GameRunTest) comes with G2.2c.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    GameKit::openAccess();
    GameKit::noEvents();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function runService(): RunService
{
    return app(RunService::class);
}

/** @return list<array<string, mixed>> */
function runEvents(string $name): array
{
    return ProductEvent::query()->where('name', $name)->orderBy('id')->get()->map(fn (ProductEvent $e): array => (array) $e->props)->all();
}

it('starts a run of a stop with a fixed snapshot of 8 to 10 elements, the flasks and the day of the learner', function (): void {
    [$learner, $course, $lesson, $words] = GameKit::stop(8);

    $run = runService()->start($learner, $course->slug, $lesson->id);

    expect($run->isActive())->toBeTrue()
        ->and($run->active_learner_id)->toBe($learner->id)
        ->and($run->kind->value)->toBe('stop')
        ->and($run->course_id)->toBe($course->id)
        ->and($run->stage_id)->toBe($lesson->stage_id)
        ->and($run->lesson_id)->toBe($lesson->id)
        ->and($run->total)->toBeGreaterThanOrEqual(6)->toBeLessThanOrEqual(10)
        ->and(count($run->snapshot['elements']))->toBe($run->total)
        ->and($run->flasks)->toBe(3)->and($run->flasks_max)->toBe(3)
        ->and($run->local_date->toDateString())->toBe('2026-11-05')
        ->and($run->expires_at->toDateTimeString())->toBe('2026-11-05 10:30:00')
        ->and($run->snapshot['kind'])->toBe('stop')
        ->and($run->snapshot['scope_widened'])->toBeFalse()
        ->and($run->snapshot['stage_number'])->toBe(1)
        ->and($run->snapshot['lesson_number'])->toBe(1)
        ->and($run->snapshot['city'])->toBe('game.cities.1')
        ->and($run->state['current'])->toBe(0)
        ->and($run->state['flasks'])->toBe(3);

    // The first element is a warm-up made of a word; every element is about a word the learner knows.
    $kinds = array_column($run->snapshot['elements'], 'kind');
    expect(in_array($kinds[0], ['translate_fwd', 'translate_back', 'listen', 'spell', 'gap'], true))->toBeTrue();
    $wordIds = array_map(static fn ($w): int => $w->id, $words);
    foreach ($run->snapshot['elements'] as $element) {
        if ($element['kind'] === 'pairs_round') {
            foreach ($element['left'] as $card) {
                expect(in_array($card['word_id'], $wordIds, true))->toBeTrue();
            }
        } else {
            expect(in_array($element['word_id'], $wordIds, true))->toBeTrue();
        }
    }

    expect(runEvents('game.run_started'))->toHaveCount(1)
        ->and(runEvents('game.run_started')[0])->toMatchArray(['run_id' => $run->id, 'kind' => 'stop', 'lesson_id' => $lesson->id, 'total' => $run->total, 'scope_widened' => false]);

    // The view shows the current element without its secrets.
    $view = runService()->view($run, $learner)->toArray();
    expect($view['position'])->toBe(1)
        ->and($view['current']['element']['id'])->toBe($run->snapshot['elements'][0]['id'])
        ->and($view['current']['element'])->not->toHaveKeys(['correct_option_id', 'expected', 'word_id', 'audio_path'])
        ->and($view['hint'])->toBe(['price' => 5, 'available' => false])
        ->and($view['summary'])->toBeNull()
        ->and($view['city'])->toBe('Ташкент');
});

it('refuses a stop the learner has not started in the cards, a lesson of another course and a lesson that does not exist', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(0);
    [$other, $otherLesson] = PracticeKit::course('english-b1');

    expect(fn () => runService()->start($learner, $course->slug, $lesson->id))
        ->toThrow(fn (GameException $e) => expect($e->errorCode)->toBe('stop_locked')->and($e->httpStatus())->toBe(409));
    expect(fn () => runService()->start($learner, $course->slug, $otherLesson->id))
        ->toThrow(fn (GameException $e) => expect($e->errorCode)->toBe('lesson_not_in_course'));
    expect(fn () => runService()->start($learner, $course->slug, 999_999))
        ->toThrow(fn (GameException $e) => expect($e->errorCode)->toBe('lesson_not_found'));
    expect(fn () => runService()->start($learner, 'nope', $lesson->id))
        ->toThrow(fn (GameException $e) => expect($e->errorCode)->toBe('course_not_found'));
    expect(GameRun::query()->count())->toBe(0);
});

it('widens a short stop to its city and refuses when the city is short too', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(2);
    $stage = Stage::query()->findOrFail($lesson->stage_id);
    $second = Lesson::factory()->for($stage)->create(['number' => 2]);
    for ($i = 1; $i <= 3; $i++) {
        GameKit::word($second, $learner, "cityword{$i}", "городское {$i}");
    }

    // 2 + 3 = 5 words of the city: still short of 6.
    expect(fn () => runService()->start($learner, $course->slug, $lesson->id))
        ->toThrow(fn (GameException $e) => expect($e->errorCode)->toBe('game_not_enough_material')->and($e->details)->toMatchArray(['available' => 5, 'required' => 6, 'reason' => 'no_words']));
    expect(runEvents('game.not_enough_material'))->toHaveCount(1)
        ->and(runEvents('game.not_enough_material')[0])->toMatchArray(['kind' => 'stop', 'lesson_id' => $lesson->id, 'available' => 5, 'required' => 6, 'reason' => 'no_words']);

    GameKit::word($second, $learner, 'cityword4', 'городское 4');
    $run = runService()->start($learner, $course->slug, $lesson->id);

    expect($run->snapshot['scope_widened'])->toBeTrue()
        ->and($run->total)->toBeGreaterThanOrEqual(6)
        ->and($run->lesson_id)->toBe($lesson->id);
});

it('answers a repeated request_id from the journal byte for byte and does not change the run', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $run = runService()->start($learner, $course->slug, $lesson->id);
    $element = GameKit::current($run);
    $requestId = (string) Str::uuid();

    $first = runService()->answer($run, $learner, $requestId, $element['id'], GameKit::rightAnswers($element)[0]);
    $run->refresh();
    $afterFirst = [$run->state, $run->score, $run->answered, $run->correct, $run->flasks];

    $again = runService()->answer($run, $learner, strtoupper($requestId), $element['id'], ['option_id' => 'whatever']);
    $run->refresh();

    expect($again->body)->toBe($first->body)
        ->and([$run->state, $run->score, $run->answered, $run->correct, $run->flasks])->toBe($afterFirst)
        ->and(GameRunAttempt::query()->where('run_id', $run->id)->count())->toBe(1)
        ->and($again->run->position)->toBe(2);

    // The id of an answer is not an id of a hint.
    expect(fn () => runService()->hint($run, $learner, $requestId, $element['id']))
        ->toThrow(fn (GameException $e) => expect($e->errorCode)->toBe('request_id_reused'));
});

it('refuses an element that is not current, an answer that was given already, a stranger\'s run and an unknown option', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $run = runService()->start($learner, $course->slug, $lesson->id);
    $elements = $run->snapshot['elements'];

    expect(fn () => runService()->answer($run, $learner, (string) Str::uuid(), $elements[1]['id'], GameKit::rightAnswers($elements[1])[0]))
        ->toThrow(fn (GameException $e) => expect($e->errorCode)->toBe('element_not_current')->and($e->details)->toBe(['current' => $elements[0]['id']]));
    expect(fn () => runService()->answer($run, $learner, (string) Str::uuid(), 'eNope', ['skip' => true]))
        ->toThrow(fn (GameException $e) => expect($e->errorCode)->toBe('unknown_element_id'));
    expect(fn () => runService()->answer($run, Learner::factory()->create(), (string) Str::uuid(), $elements[0]['id'], ['skip' => true]))
        ->toThrow(fn (GameException $e) => expect($e->errorCode)->toBe('game_run_not_found')->and($e->httpStatus())->toBe(404));

    if (in_array($elements[0]['kind'], ['translate_fwd', 'translate_back', 'listen'], true)) {
        expect(fn () => runService()->answer($run, $learner, (string) Str::uuid(), $elements[0]['id'], ['option_id' => 'oNope']))
            ->toThrow(fn (GameException $e) => expect($e->errorCode)->toBe('unknown_option_id')->and($e->httpStatus())->toBe(422));
        expect(fn () => runService()->answer($run, $learner, (string) Str::uuid(), $elements[0]['id'], ['text' => 'x']))
            ->toThrow(fn (GameException $e) => expect($e->errorCode)->toBe('invalid_answer_shape'));
    } else {
        expect(fn () => runService()->answer($run, $learner, (string) Str::uuid(), $elements[0]['id'], ['option_id' => 'oNope']))
            ->toThrow(fn (GameException $e) => expect($e->errorCode)->toBe('invalid_answer_shape'));
    }

    runService()->answer($run, $learner, (string) Str::uuid(), $elements[0]['id'], GameKit::rightAnswers($elements[0])[0]);
    expect(fn () => runService()->answer($run, $learner, (string) Str::uuid(), $elements[0]['id'], GameKit::rightAnswers($elements[0])[0]))
        ->toThrow(fn (GameException $e) => expect($e->errorCode)->toBe('element_not_current'));
});

it('completes a run played without a mistake: three stars, the combo, the score, one finished event', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $run = runService()->start($learner, $course->slug, $lesson->id);

    $run = GameKit::play(runService(), $run, $learner);

    $n = $run->total;
    // 10 per answer, +5 from the third in a row, +10 the fourth, +15 from the fifth on.
    $expected = 10 * $n + ($n >= 3 ? 5 : 0) + ($n >= 4 ? 10 : 0) + max(0, $n - 4) * 15;
    expect($run->status->value)->toBe('finished')
        ->and($run->finish_reason)->toBe(RunFinishReason::Completed)
        ->and($run->active_learner_id)->toBeNull()
        ->and($run->stars)->toBe(3)
        ->and($run->answered)->toBe($n)->and($run->correct)->toBe($n)->and($run->wrong)->toBe(0)
        ->and($run->flasks)->toBe(3)
        ->and($run->combo_best)->toBe($n)
        ->and($run->score)->toBe($expected)
        ->and($run->srs_pulled)->toBe(0)
        ->and($run->finished_at?->toDateTimeString())->toBe('2026-11-05 10:00:00');

    // The finish paid the first clearing of the stop (10 + 5 per star) and 2 league points per correct answer (docs/18 §12.5.3, G2.5a).
    $finished = runEvents('game.run_finished');
    expect($finished)->toHaveCount(1)
        ->and($finished[0])->toMatchArray(['run_id' => $run->id, 'finish_reason' => 'completed', 'correct' => $n, 'stars' => 3, 'score' => $expected, 'srs_pulled' => 0, 'coins' => 25, 'league_points' => 2 * $n]);

    $view = runService()->view($run, $learner)->toArray();
    expect($view['current'])->toBeNull()
        ->and($view['summary'])->toMatchArray(['stars' => 3, 'score' => $expected, 'mistakes' => [], 'coins' => 25, 'league_points' => 2 * $n]);

    expect(fn () => runService()->answer($run, $learner, (string) Str::uuid(), $run->snapshot['elements'][0]['id'], ['skip' => true]))
        ->toThrow(fn (GameException $e) => expect($e->errorCode)->toBe('game_run_closed')->and($e->httpStatus())->toBe(410)->and($e->details)->toBe(['finish_reason' => 'completed']));
});

it('loses a flask per mistake, two stars for one, one for two, and fails on the third - the run closes with that answer', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);

    $one = GameKit::play(runService(), runService()->start($learner, $course->slug, $lesson->id), $learner, wrongAt: [1]);
    expect($one->finish_reason)->toBe(RunFinishReason::Completed)->and($one->stars)->toBe(2)->and($one->flasks)->toBe(2)->and($one->wrong + ($one->snapshot['elements'][1]['kind'] === 'pairs_round' ? 1 : 0))->toBeGreaterThanOrEqual(1);

    $two = GameKit::play(runService(), runService()->start($learner, $course->slug, $lesson->id), $learner, wrongAt: [1, 3]);
    expect($two->finish_reason)->toBe(RunFinishReason::Completed)->and($two->stars)->toBe(1)->and($two->flasks)->toBe(1);

    $failed = GameKit::play(runService(), runService()->start($learner, $course->slug, $lesson->id), $learner, wrongAt: [0, 1, 2]);
    expect($failed->finish_reason)->toBe(RunFinishReason::Failed)
        ->and($failed->stars)->toBe(0)
        ->and($failed->flasks)->toBe(0)
        ->and($failed->active_learner_id)->toBeNull()
        ->and($failed->answered)->toBeLessThanOrEqual(3);

    $summary = runService()->view($failed, $learner)->toArray()['summary'];
    expect($summary['stars'])->toBe(0)->and(count($summary['mistakes']))->toBeGreaterThanOrEqual(3);
    foreach ($summary['mistakes'] as $mistake) {
        expect($mistake)->toHaveKeys(['element_id', 'kind', 'label', 'your_answer', 'verdict'])->and($mistake['label'])->not->toBe('');
    }

    expect(array_column(runEvents('game.run_finished'), 'finish_reason'))->toBe(['completed', 'completed', 'failed']);
});

it('refuses a second start while a run is active, and replaces it on request (the old one is abandoned, pays nothing)', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $first = runService()->start($learner, $course->slug, $lesson->id);
    $stage = Stage::query()->findOrFail($lesson->stage_id);
    $other = Lesson::factory()->for($stage)->create(['number' => 2]);
    for ($i = 1; $i <= 6; $i++) {
        GameKit::word($other, $learner, "otherword{$i}", "другое {$i}");
    }

    // The same stop again is the run that is on; another stop is refused.
    expect(runService()->start($learner, $course->slug, $lesson->id)->id)->toBe($first->id);
    expect(fn () => runService()->start($learner, $course->slug, $other->id))
        ->toThrow(fn (GameException $e) => expect($e->errorCode)->toBe('game_run_active')->and($e->details['run_id'])->toBe($first->id)->and($e->details['progress']['total'])->toBe($first->total));

    $second = runService()->start($learner, $course->slug, $lesson->id, replaceActive: true);
    $first->refresh();

    expect($second->id)->not->toBe($first->id)
        ->and($second->isActive())->toBeTrue()
        ->and($first->finish_reason)->toBe(RunFinishReason::Abandoned)
        ->and($first->active_learner_id)->toBeNull()
        ->and($first->stars)->toBeNull()
        ->and(runEvents('game.run_finished')[0])->toMatchArray(['run_id' => $first->id, 'finish_reason' => 'abandoned']);

    $abandoned = runService()->abandon($second, $learner);
    expect($abandoned->finish_reason)->toBe(RunFinishReason::Abandoned)->and(runService()->activeFor($learner))->toBeNull();
    // A second abandon changes nothing.
    expect(runService()->abandon($second->id, $learner)->finish_reason)->toBe(RunFinishReason::Abandoned)
        ->and(runEvents('game.run_finished'))->toHaveCount(2);
});

it('closes an expired run lazily on an answer (410 expired) and by the scheduler, once, without a reward', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $run = runService()->start($learner, $course->slug, $lesson->id);
    $element = GameKit::current($run);
    $later = CarbonImmutable::parse('2026-11-05 10:30:00');

    expect(fn () => runService()->answer($run, $learner, (string) Str::uuid(), $element['id'], GameKit::rightAnswers($element)[0], null, $later))
        ->toThrow(fn (GameException $e) => expect($e->errorCode)->toBe('game_run_closed')->and($e->details)->toBe(['finish_reason' => 'expired']));
    $run->refresh();
    expect($run->finish_reason)->toBe(RunFinishReason::Expired)->and($run->active_learner_id)->toBeNull()->and($run->stars)->toBeNull()
        ->and(GameRunAttempt::query()->count())->toBe(0)
        ->and(runService()->closeExpired($later))->toBe(0);

    // The scheduler closes what nobody touched; `find` closes what it meets.
    $second = runService()->start($learner, $course->slug, $lesson->id, now: $later);
    $third = GameRun::factory()->forLesson($lesson)->create(['learner_id' => Learner::factory()->create()->id, 'started_at' => $later, 'expires_at' => $later->addMinutes(30)]);
    $afterwards = $later->addMinutes(31);
    expect(runService()->closeExpired($afterwards))->toBe(2)
        ->and(runService()->closeExpired($afterwards))->toBe(0)
        ->and($second->refresh()->finish_reason)->toBe(RunFinishReason::Expired)
        ->and($third->refresh()->finish_reason)->toBe(RunFinishReason::Expired)
        ->and(array_column(runEvents('game.run_finished'), 'finish_reason'))->toBe(['expired', 'expired', 'expired']);

    $fourth = runService()->start($learner, $course->slug, $lesson->id, now: $afterwards);
    $found = runService()->find($learner, $fourth->id, $afterwards->addHour());
    expect($found->finish_reason)->toBe(RunFinishReason::Expired)
        ->and(fn () => runService()->find(Learner::factory()->create(), $fourth->id))->toThrow(GameException::class);
});

it('sells the hint of the bazaar once per element: the coins go once, a second request on the element repeats the hint for free', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    GameWallet::factory()->withBalance(7)->create(['learner_id' => $learner->id]);
    $run = runService()->start($learner, $course->slug, $lesson->id);
    $element = GameKit::current($run);
    expect(fn () => runService()->hint($run, $learner, (string) Str::uuid(), $element['id']))
        ->toThrow(fn (GameException $e) => expect($e->errorCode)->toBe('hint_not_available'));

    // The bazaar is fixed in the snapshot at the start; put it on the first element.
    $run->forceFill(['snapshot' => [...$run->snapshot, 'events' => [['at' => 0, 'type' => RunEvent::Bazaar->value]]]])->save();
    expect(runService()->view($run, $learner)->toArray()['hint'])->toBe(['price' => 5, 'available' => true])
        ->and(runService()->view($run, $learner)->toArray()['current']['event'])->toBe('bazaar');

    $first = runService()->hint($run, $learner, (string) Str::uuid(), $element['id']);
    $second = runService()->hint($run, $learner, (string) Str::uuid(), $element['id']);
    $repeat = runService()->hint($run, $learner, (string) Str::uuid(), $element['id']);

    $expectedHint = in_array($element['kind'], ['spell', 'gap'], true)
        ? ['prefix' => mb_substr($element['kind'] === 'spell' ? $element['expected'] : $element['expected'][0], 0, 2)]
        : ['options_removed' => array_slice(array_values(array_diff(array_column($element['options'], 'id'), [$element['correct_option_id']])), 0, 2)];
    expect($first->body['hint'])->toBe($expectedHint)
        ->and($first->body['coins'])->toBe(['balance' => 2])
        ->and($second->body)->toBe($first->body)->and($repeat->body)->toBe($first->body)
        ->and(GameWallet::query()->where('learner_id', $learner->id)->value('balance'))->toBe(2)
        ->and(GameCoinLedgerEntry::query()->count())->toBe(1)
        ->and(GameCoinLedgerEntry::query()->first()?->idempotency_key)->toBe("run:{$run->id}:hint:{$element['id']}")
        ->and(GameCoinLedgerEntry::query()->first()?->balance_after)->toBe(2)
        ->and(runEvents('game.coins_spent'))->toHaveCount(1)
        ->and($first->run->current['element']['hint'])->toBe($expectedHint)
        ->and($first->run->hint['available'])->toBeFalse();

    // The answer after the hint: correct - no flask, 0 points, counted in `hints`, a mistake for the SRS (GameSrsRuleTest).
    $outcome = runService()->answer($run, $learner, (string) Str::uuid(), $element['id'], GameKit::rightAnswers($element)[0]);
    $run->refresh();
    expect($outcome->body['verdict'])->toBe('correct')
        ->and($outcome->body['points'])->toBe(0)
        ->and($outcome->body['flask_lost'])->toBeFalse()
        ->and($outcome->body['combo'])->toBe(0)
        ->and($run->hints)->toBe(1)->and($run->correct)->toBe(0)->and($run->flasks)->toBe(3)->and($run->score)->toBe(0);

    // Not enough coins: nothing is written.
    $next = GameKit::current($run);
    $run->forceFill(['snapshot' => [...$run->snapshot, 'events' => [['at' => 1, 'type' => RunEvent::Bazaar->value]]]])->save();
    expect(fn () => runService()->hint($run, $learner, (string) Str::uuid(), $next['id']))
        ->toThrow(fn (GameException $e) => expect($e->errorCode)->toBe('coins_insufficient')->and($e->details)->toBe(['balance' => 2, 'price' => 5]));
    expect(GameCoinLedgerEntry::query()->count())->toBe(1)->and(GameRunAttempt::query()->where('kind', 'hint')->count())->toBe(3);
});

it('gives the oasis flask back after the element in the middle and keeps the snapshot fixed under a reload', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $run = runService()->start($learner, $course->slug, $lesson->id);
    $snapshot = $run->snapshot;
    $middle = intdiv($run->total, 2);
    $run->forceFill(['snapshot' => [...$snapshot, 'events' => [['at' => $middle, 'type' => RunEvent::Oasis->value]]]])->save();

    // One mistake before the middle, then the oasis gives the flask back.
    $run = GameKit::play(runService(), $run, $learner, wrongAt: [0]);
    expect($run->finish_reason)->toBe(RunFinishReason::Completed)
        ->and($run->flasks)->toBe(3)
        ->and($run->state['oasis_used'])->toBeTrue()
        ->and($run->stars)->toBe(2) // the stars count the losses, not the flasks left
        ->and(ksorted($run->snapshot['elements']))->toBe(ksorted($snapshot['elements']));

    $element = $run->snapshot['elements'][$middle];
    $attempt = GameRunAttempt::query()->where('element_id', $element['id'])->orderByDesc('id')->first();
    expect($attempt?->result['event_applied'])->toBe('oasis');
});
