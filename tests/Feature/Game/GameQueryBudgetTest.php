<?php

declare(strict_types=1);

use App\Domain\Game\Enums\RunEvent;
use App\Models\GameRun;
use App\Models\GameWallet;
use App\Models\Lesson;
use App\Models\PracticeItem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\Game\GameKit;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * docs/18 §12.8, docs/20 §1: the statements a request of a run may issue, the `TwaAuth` one included. A budget is a ceiling measured
 * on a real run, not a guess - and it must not grow with the size of the lesson (the words of the pool, the lessons of the city): only
 * the start reads the material, and it reads it in a fixed number of queries.
 */

uses(RefreshDatabase::class);

/**
 * Statements of the start of a stop, measured: `TwaAuth` 1, the course 1, the lesson 1, the active run 1, the words of the pool and their
 * translations 2, the authored tasks 3 kinds x 2 (the items, their versions) + the clips of the dictation 3, the distractors of the
 * wider tiers 1 + the translations of those 1 (only when the pool is short of distractors: 19 without them), then the lock of the
 * active run, the insert and the event 3. All of them are fixed phases - none runs per word. (docs/18 §12.14 guessed 14; the real count is 19-20.)
 */
const BUDGET_GAME_START = 20;

/** Statements of the read of a run: `TwaAuth`, the run itself - the snapshot is in its row. */
const BUDGET_GAME_READ = 2;

/**
 * Statements of an answer, measured on the dearest one - the answer after a hint, a mistake for the SRS: `TwaAuth` 1, the lock of the run,
 * the journal check, the attempt and the update of the run 3, then after the commit the rule of the SRS (the due check, the
 * repetition, the misses 3, the counter of the run) 6. A plain right answer is the first five.
 */
const BUDGET_GAME_ANSWER = 11;

/** Statements of the hint of the bazaar, measured: `TwaAuth` 1, the lock of the run and the journal check 2, the wallet 5, the state of the run, the attempt and the event 3. */
const BUDGET_GAME_HINT = 11;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    GameKit::openAccess();
    GameKit::noEvents();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * The statements of each route on a run of a stop of a rich world.
 *
 * @return array{start: int, read: int, answer: int, hint: int}
 */
function gameBudgetMeasure(int $words): array
{
    [$learner, $course, $lesson] = GameKit::richStop($words);
    GameWallet::factory()->withBalance(50)->create(['learner_id' => $learner->id]);
    $headers = PracticeKit::headers($learner);

    $started = null;
    $start = QueryLog::during(function () use ($course, $lesson, $headers, &$started): void {
        $started = test()->withHeaders($headers)->postJson('/api/twa/game/runs', ['kind' => 'stop', 'course' => $course->slug, 'lesson_id' => $lesson->id])->assertCreated();
    });
    $id = $started->json('id');
    Cache::flush();

    $read = QueryLog::during(fn () => test()->withHeaders($headers)->getJson("/api/twa/game/runs/{$id}")->assertOk());
    Cache::flush();

    // The bazaar on the first element for the hint; the hint is measured before the answer.
    $run = GameRun::query()->findOrFail($id);
    $run->forceFill(['snapshot' => [...$run->snapshot, 'events' => [['at' => 0, 'type' => RunEvent::Bazaar->value]]]])->save();
    $element = GameKit::current($run);
    $hint = [];
    if (in_array($element['kind'], ['translate_fwd', 'translate_back', 'listen', 'spell', 'gap', 'collocation'], true)) {
        $hint = QueryLog::during(fn () => test()->withHeaders($headers)->postJson("/api/twa/game/runs/{$id}/hint", ['request_id' => (string) Str::uuid(), 'element_id' => $element['id']])->assertOk());
        Cache::flush();
    }

    // The most expensive answer of the element (a round of pairs has several).
    $answer = [];
    foreach (GameKit::rightAnswers($element) as $body) {
        $queries = QueryLog::during(fn () => test()->withHeaders($headers)->postJson("/api/twa/game/runs/{$id}/answer", ['request_id' => (string) Str::uuid(), 'element_id' => $element['id'], 'answer' => $body])->assertOk());
        $answer = count($queries) > count($answer) ? $queries : $answer;
        Cache::flush();
    }

    return ['start' => count($start), 'read' => count($read), 'answer' => count($answer), 'hint' => count($hint)];
}

it('stays within the budgets of statements for the start, the read, the answer and the hint', function (): void {
    $measured = gameBudgetMeasure(13);

    expect($measured['start'])->toBeLessThanOrEqual(BUDGET_GAME_START)
        ->and($measured['read'])->toBeLessThanOrEqual(BUDGET_GAME_READ)
        ->and($measured['answer'])->toBeLessThanOrEqual(BUDGET_GAME_ANSWER)
        ->and($measured['hint'])->toBeLessThanOrEqual(BUDGET_GAME_HINT);
});

it('does not read more for a bigger lesson or a bigger city: the number of statements of the start does not grow', function (): void {
    [$learner, $course, $lesson, $words] = GameKit::richStop(13);
    $headers = PracticeKit::headers($learner);
    $body = ['kind' => 'stop', 'course' => $course->slug, 'lesson_id' => $lesson->id];
    $startCount = function () use ($headers, $body): int {
        $queries = QueryLog::during(fn () => test()->withHeaders($headers)->postJson('/api/twa/game/runs', $body)->assertCreated());
        $id = GameRun::query()->latest('id')->value('id');
        test()->withHeaders($headers)->postJson("/api/twa/game/runs/{$id}/abandon", ['confirm' => true])->assertOk();
        Cache::flush();

        return count($queries);
    };

    $small = $startCount();

    // Four times the words in the lesson, five more lessons in the city, more authored tasks of every kind.
    foreach (range(14, 52) as $i) {
        GameKit::word($lesson, $learner, "extraword{$i}", "дополнительно {$i}", voiced: true);
    }
    foreach (range(2, 6) as $i) {
        $other = Lesson::factory()->for($lesson->stage)->create(['number' => $i]);
        foreach (range(1, 8) as $n) {
            GameKit::word($other, $learner, "lesson{$i}word{$n}", "урок {$i} слово {$n}", voiced: true);
        }
    }
    foreach (range(1, 4) as $i) {
        PracticeItem::factory()->collocation()->published()->forLesson($lesson)->targeting($words[$i + 2])->create();
        PracticeItem::factory()->sentence()->published()->forLesson($lesson)->targeting($words[$i + 6])->create();
    }
    $big = $startCount();

    // The count depends on whether the pool has the distractors itself (a wider tier is one more read), never on how big the pool is.
    expect($small)->toBeLessThanOrEqual(BUDGET_GAME_START)->and($big)->toBeLessThanOrEqual(BUDGET_GAME_START);
});

it('does not read the material while a run is played: no word, lesson or practice item is touched by a read, an answer or a hint', function (): void {
    [$learner, $course, $lesson] = GameKit::richStop();
    $headers = PracticeKit::headers($learner);
    $id = test()->withHeaders($headers)->postJson('/api/twa/game/runs', ['kind' => 'stop', 'course' => $course->slug, 'lesson_id' => $lesson->id])->assertCreated()->json('id');
    $element = GameKit::current(GameRun::query()->findOrFail($id));

    $queries = QueryLog::during(function () use ($headers, $id, $element): void {
        test()->withHeaders($headers)->getJson("/api/twa/game/runs/{$id}")->assertOk();
        foreach (GameKit::rightAnswers($element) as $body) {
            test()->withHeaders($headers)->postJson("/api/twa/game/runs/{$id}/answer", ['request_id' => (string) Str::uuid(), 'element_id' => $element['id'], 'answer' => $body])->assertOk();
        }
    });

    expect(QueryLog::matching($queries, '/\b(from|join)\s+"?(words|lessons|stages|courses|practice_items|practice_item_versions)"?/i'))->toBe([]);
});
