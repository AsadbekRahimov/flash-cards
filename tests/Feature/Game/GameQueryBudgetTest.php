<?php

declare(strict_types=1);

use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Game\Enums\LedgerKind;
use App\Domain\Game\Enums\RunEvent;
use App\Domain\Game\Services\Economy\WalletService;
use App\Domain\Operations\Support\PureCache;
use App\Models\GameInventoryItem;
use App\Models\GameItem;
use App\Models\GameProfile;
use App\Models\GameRun;
use App\Models\GameStopProgress;
use App\Models\GameWallet;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PracticeItem;
use Carbon\CarbonImmutable;
use Database\Seeders\GameItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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

/**
 * Statements of the start of a boss, measured (G2.4): `TwaAuth` 1, the course 1, the stage 1, the readiness of the city 1, the picker 1,
 * the words and their translations 2, the distractors of the wider tiers 1 + 1 (when the city is short of them), the lock of the active
 * run, the insert and the event 3. A fixed set of phases, none per word or per lesson of the city.
 */
const BUDGET_BOSS_START = 12;

/**
 * Statements of the `POST /answer` that WINS the boss for the first time, measured on a learner the game sees for the first time (every
 * table is touched cold, the first activity of the day): `TwaAuth`, the run, the journal and the attempt 4, the city 3 (+ its UPDATE),
 * the wallet and its entry 6, the profile 2, the day 4, the run 1 - 21 in the transaction; after the commit the two events of the finish 2,
 * the streak 3, the league 10, the achievements of the day (streak, words) 3 + `game.boss_defeated` 1 and `game_first_boss` (the insert,
 * the row, `achievement.unlocked`) 3. The queued card DM is a job of its own. None of them runs per word or per stop of the city. That is 43 on SQLite; PostgreSQL
 * spends two more (the placement in the league), hence the ceiling.
 */
const BUDGET_BOSS_FINISH = 45;

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

/**
 * Statements of the shop with the catalogue cold (docs/18 §12.13, G2.5b): `TwaAuth` 1, the catalogue 1 (a warm cache: 0), what the learner
 * owns 1, the wallet and the profile in one join 1. The plan of a Free learner is read only because an exclusive item is in the catalogue; it
 * is the entitlement cache of the whole API (the default store), so it is warm in a real request and costs one more statement when it is not.
 */
const BUDGET_GAME_SHOP = 4;

/** Statements of the wallet: `TwaAuth` 1, the wallet and the profile in one join 1, the ledger 1. */
const BUDGET_GAME_WALLET = 3;

/** Cold: every cache of the request is empty, the pure one included (it is a store of its own); the plan of the learner is warm when asked. */
function gameBudgetCold(Learner $learner, bool $plan = true): void
{
    Cache::flush();
    PureCache::store()->flush();
    app(EntitlementResolver::class)->forget($learner);
    if ($plan) {
        app(EntitlementResolver::class)->for($learner);
    }
}

it('keeps the shop within its budget, and the number does not grow with the catalogue and the inventory', function (): void {
    (new GameItemSeeder)->run();
    GameItem::factory()->exclusive()->create(['key' => 'camel_emir']);
    $learner = Learner::factory()->create();
    DB::transaction(fn () => app(WalletService::class)->credit($learner->id, 500, LedgerKind::RunReward, "run:{$learner->id}:1", 'game_run', 1));
    GameProfile::factory()->create(['learner_id' => $learner->id]);
    $headers = PracticeKit::headers($learner);
    $measure = function (bool $plan) use ($headers, $learner): array {
        gameBudgetCold($learner, $plan);
        $cold = QueryLog::during(fn () => test()->withHeaders($headers)->getJson('/api/twa/game/shop')->assertOk());
        $warm = QueryLog::during(fn () => test()->withHeaders($headers)->getJson('/api/twa/game/shop')->assertOk());

        return [count($cold), count($warm)];
    };
    [$small, $smallWarm] = $measure(plan: true);
    [$noPlan] = $measure(plan: false);

    // five times the catalogue, a third of it owned
    foreach (GameItem::factory()->count(48)->create() as $i => $item) {
        if ($i % 3 === 0) {
            GameInventoryItem::factory()->create(['learner_id' => $learner->id, 'item_id' => $item->id]);
        }
    }
    [$big, $bigWarm] = $measure(plan: true);

    expect($small)->toBeLessThanOrEqual(BUDGET_GAME_SHOP)->and($big)->toBe($small)
        ->and($smallWarm)->toBe($small - 1)->and($bigWarm)->toBe($smallWarm)
        ->and($noPlan)->toBe($small + 1); // the plan of a learner nobody has asked about yet: one statement
});

it('keeps the wallet within its budget, and the number does not grow with the ledger', function (): void {
    $learner = Learner::factory()->create();
    GameProfile::factory()->withTickets(2)->create(['learner_id' => $learner->id]);
    $headers = PracticeKit::headers($learner);
    $measure = function () use ($headers): int {
        Cache::flush();

        return count(QueryLog::during(fn () => test()->withHeaders($headers)->getJson('/api/twa/game/wallet')->assertOk()));
    };
    foreach (range(1, 3) as $i) {
        DB::transaction(fn () => app(WalletService::class)->credit($learner->id, 5, LedgerKind::RunReward, "run:{$learner->id}:{$i}"));
    }
    $small = $measure();

    foreach (range(4, 60) as $i) {
        DB::transaction(fn () => app(WalletService::class)->credit($learner->id, 5, LedgerKind::RunReward, "run:{$learner->id}:{$i}"));
    }

    expect($small)->toBeLessThanOrEqual(BUDGET_GAME_WALLET)->and($measure())->toBe($small);
});

/**
 * A city of `$lessons` stops of 6 words, every stop cleared, and the statements of the start of its boss and of the answer that wins it.
 *
 * @return array{start: int, finish: int}
 */
function gameBossMeasure(int $lessons): array
{
    [$learner, $course, $first] = GameKit::stop(0);
    $stage = $first->stage;
    $all = [$first];
    for ($n = 2; $n <= $lessons; $n++) {
        $all[] = Lesson::factory()->for($stage)->create(['number' => $n]);
    }
    foreach ($all as $l => $lesson) {
        foreach (range(1, 6) as $i) {
            GameKit::word($lesson, $learner, "bossbudget{$l}x{$i}", "бюджет {$l}.{$i}", voiced: true);
        }
        GameStopProgress::factory()->cleared(1)->create(['learner_id' => $learner->id, 'lesson_id' => $lesson->id]);
    }
    $headers = PracticeKit::headers($learner);

    $started = null;
    $start = QueryLog::during(function () use ($course, $stage, $headers, &$started): void {
        $started = test()->withHeaders($headers)->postJson('/api/twa/game/runs', ['kind' => 'boss', 'course' => $course->slug, 'stage_id' => $stage->id])->assertCreated();
    });
    $id = $started->json('id');
    Cache::flush();

    // every answer right: the last one wins the boss for the first time (the reward, the city, the profile, the day, the hooks)
    $finish = [];
    for ($guard = 0; $guard < 30; $guard++) {
        $run = GameRun::query()->findOrFail($id);
        if (! $run->isActive()) {
            break;
        }
        $element = GameKit::current($run);
        $finish = QueryLog::during(fn () => test()->withHeaders($headers)->postJson("/api/twa/game/runs/{$id}/answer", ['request_id' => (string) Str::uuid(), 'element_id' => $element['id'], 'answer' => GameKit::rightAnswers($element)[0]])->assertOk());
        Cache::flush();
    }

    return ['start' => count($start), 'finish' => count($finish)];
}

it('stays within the budget of statements for the start of a boss and for the answer that wins it', function (): void {
    Queue::fake(); // the card DM is a job of its own: not part of the answer
    $measured = gameBossMeasure(2);

    expect($measured['start'])->toBeLessThanOrEqual(BUDGET_BOSS_START)->and($measured['finish'])->toBeLessThanOrEqual(BUDGET_BOSS_FINISH);
});

it('does not read more for a bigger city: the statements of the boss are those of a small one', function (): void {
    Queue::fake();
    $measured = gameBossMeasure(8);

    expect($measured['start'])->toBeLessThanOrEqual(BUDGET_BOSS_START)->and($measured['finish'])->toBeLessThanOrEqual(BUDGET_BOSS_FINISH);
});
