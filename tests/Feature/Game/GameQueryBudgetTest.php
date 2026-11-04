<?php

declare(strict_types=1);

use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Game\Enums\LedgerKind;
use App\Domain\Game\Enums\RunEvent;
use App\Domain\Game\Services\Economy\WalletService;
use App\Domain\Game\Services\Runs\RunService;
use App\Domain\Operations\Support\PureCache;
use App\Models\ClassCaravan;
use App\Models\ClassCaravanMember;
use App\Models\GameInventoryItem;
use App\Models\GameItem;
use App\Models\GameProfile;
use App\Models\GameRun;
use App\Models\GameStopProgress;
use App\Models\GameWallet;
use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PracticeItem;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Database\Seeders\GameItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Game\ClassKit;
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
 * active run, the insert and the event 3, and (G2.5b part 2) the boosters of the learner for the view 1 and the block `limits` of the 201 with the
 * plan of the learner (a cold cache) 2 more, and (G2.9a) the caravan of the class the run joins (the learner's active groups with an
 * active caravan, one read without a lock) 1. All of them are fixed phases - none runs per word. (docs/18 §12.14 guessed 14; the real count is
 * 22-24: the authored tasks and the wider tiers are read once more when the dice of the pool say so.)
 */
const BUDGET_GAME_START = 24;

/**
 * Statements of the start of a boss, measured (G2.4): `TwaAuth` 1, the course 1, the stage 1, the live run 1, the plan (G2.8) 1, the readiness of the city 1, the picker 1,
 * the words and their translations 2, the distractors of the wider tiers 1 + 1 (when the city is short of them), the lock of the active
 * run, the insert and the event 3 (a Free learner adds the profile row of the claim); the 201 adds the boosters of the inventory and the
 * limits after the start 2 (G2.5b part 2), the caravan of the class the fight joins 1 (G2.9a, every start). 16-17 measured. A fixed set of phases, none per word or per lesson of the city.
 */
const BUDGET_BOSS_START = 17;

/**
 * Statements of the `POST /answer` that WINS the boss for the first time, measured on a learner the game sees for the first time (every
 * table is touched cold, the first activity of the day): `TwaAuth`, the run, the journal and the attempt 4, the city 3 (+ its UPDATE),
 * the wallet and its entry 6, the profile 2, the day 4, the run 1 - 21 in the transaction; after the commit the two events of the finish 2,
 * the streak 3, the league 10, the achievements of the day (streak, words) 3 + `game.boss_defeated` 1 and `game_first_boss` (the insert,
 * the row, `achievement.unlocked`) 3, and the pair to share on the card of the first win (G2.7b: the invite code of a learner who has none - read, written once - and the locale) 1-3. The queued card DM is a job of its own. None of them runs per word or per stop of the city. That is 43 + the share on SQLite; PostgreSQL
 * spends two more (the placement in the league), hence the ceiling.
 */
const BUDGET_BOSS_FINISH = 48;

/** Statements of the read of a run: `TwaAuth`, the run itself - the snapshot is in its row - and, while the run is on, the boosters of the inventory (G2.5b part 2). */
const BUDGET_GAME_READ = 3;

/**
 * Statements of an answer, measured on the dearest one - the answer after a hint, a mistake for the SRS: `TwaAuth` 1, the lock of the run,
 * the journal check, the attempt and the update of the run 3, then after the commit the rule of the SRS (the due check, the
 * repetition, the misses 3, the counter of the run) 6, and the boosters of the view 1. A plain right answer is the first five and the one.
 */
const BUDGET_GAME_ANSWER = 12;

/** Statements of the hint of the bazaar, measured: `TwaAuth` 1, the lock of the run and the journal check 2, the wallet 5, the state of the run, the attempt and the event 3, the boosters of the view 1. */
const BUDGET_GAME_HINT = 12;

/**
 * The start of a Free learner with a limit of runs (G2.8, docs/18 §12.7.2), measured with the entitlement cached: the start above plus
 * the courtesy count (1), the lock of the learner's row (1) and the count under it (1) - the free run of the day; a run paid with a
 * ticket adds the UPDATE of the profile, the ledger entry, the balance and the event `game.ticket_spent` (4). Pro and a Free learner
 * without a limit pay BUDGET_GAME_START.
 */
const BUDGET_GAME_START_FREE = 26;

const BUDGET_GAME_START_TICKET = 30;

/**
 * The last answer, with the finish (docs/18 §12.5.3, §12.6; measured over HTTP on the tree with G2.8 and G2.10): the answer's own
 * statements, the row of the stop (1), the wallet and its ledger (4), the stop and the profile (2), the day (2), the run (1), the
 * events (2) - 16 for the second run of a day (the learner's rows exist, the day is held already: no hook runs). The FIRST run of a
 * day adds the hooks after the commit: the streak (the read of the learner, the UPDATE, its event - and the rows of the held days
 * when the series is rebuilt), the ticket of the streak (none on an ordinary day: no query), the seat in the league (the week, the
 * membership), the achievements (the keys, the best streak, the learned words) - 26. The first run of a learner the game has never
 * seen makes the stop, the wallet, the profile and the day on the way (`insertOrIgnore` + the read again) and the week and the group
 * of the league - 39 on SQLite, 41 on PostgreSQL (the group of the league is taken under an advisory lock and read again there).
 */
const BUDGET_GAME_FINISH = 16;

const BUDGET_GAME_FINISH_NEW_DAY = 26;

const BUDGET_GAME_FINISH_FIRST = 41;

/**
 * The finish of a run that joined the caravan of its class (G2.9a, docs/18 §12.5.3 step 6), measured: the finish above plus the
 * caravan's row FOR UPDATE, the member's row and the totals (3); the first run of a member in the caravan makes the member's row
 * (`insertOrIgnore` after the UPDATE of nothing) - one more. Measured on the second run of a day of a learner the game has seen.
 */
const BUDGET_GAME_FINISH_CLASS = 19;

const BUDGET_GAME_FINISH_CLASS_FIRST_RUN = 20;

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

/** Plays the run to its last element (the service, outside the log) and measures the last answer over HTTP: the finish. */
function gameBudgetFinish(Learner $learner, int $runId): int
{
    $runs = app(RunService::class);
    $run = GameRun::query()->findOrFail($runId);
    for ($i = 0; $i < $run->total - 1; $i++) {
        $element = GameKit::current($run);
        foreach (GameKit::rightAnswers($element) as $answer) {
            $runs->answer($run, $learner, (string) Str::uuid(), $element['id'], $answer);
        }
    }
    // The last element may be a round of pairs (several answers): all but its final one go through the service as well.
    $last = GameKit::current($run);
    $answers = GameKit::rightAnswers($last);
    $final = array_pop($answers);
    foreach ($answers as $answer) {
        $runs->answer($run, $learner, (string) Str::uuid(), $last['id'], $answer);
    }
    Cache::flush();
    test()->withHeaders(PracticeKit::headers($learner))->getJson('/api/twa/me')->assertOk(); // warms the entitlement and the auth

    return count(QueryLog::during(fn () => test()->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/game/runs/{$runId}/answer", [
        'request_id' => (string) Str::uuid(), 'element_id' => $last['id'], 'answer' => $final,
    ])->assertOk()->assertJsonPath('run.status', 'finished')));
}

it('stays within the budgets of the start of a Free learner with a limit - the free run of the day and a run paid with a ticket - and of Pro', function (): void {
    GameKit::limits(1);
    [$learner, $course, $lesson] = GameKit::richStop(13);
    GameProfile::factory()->withTickets(2)->create(['learner_id' => $learner->id]);
    $headers = PracticeKit::headers($learner);
    $body = ['kind' => 'stop', 'course' => $course->slug, 'lesson_id' => $lesson->id];
    $start = fn (): int => count(QueryLog::during(fn () => test()->withHeaders($headers)->postJson('/api/twa/game/runs', $body)->assertCreated()));
    test()->withHeaders($headers)->getJson('/api/twa/me')->assertOk(); // warms the entitlement, the way the Mini App's first request does

    $freeSlot = $start();
    $firstId = GameRun::query()->latest('id')->value('id');
    expect(GameRun::query()->findOrFail($firstId)->free_slot?->value)->toBe('daily');

    // The free run of the day is spent with one answer; the next start pays a ticket.
    $run = GameRun::query()->findOrFail($firstId);
    $element = GameKit::current($run);
    test()->withHeaders($headers)->postJson("/api/twa/game/runs/{$firstId}/answer", ['request_id' => (string) Str::uuid(), 'element_id' => $element['id'], 'answer' => GameKit::rightAnswers($element)[0]])->assertOk();
    test()->withHeaders($headers)->postJson("/api/twa/game/runs/{$firstId}/abandon", ['confirm' => true])->assertOk();
    Cache::flush();
    test()->withHeaders($headers)->getJson('/api/twa/me')->assertOk();
    $ticket = $start();
    expect(GameRun::query()->latest('id')->firstOrFail()->free_slot?->value)->toBe('ticket');

    test()->withHeaders($headers)->postJson('/api/twa/game/runs/'.GameRun::query()->latest('id')->value('id').'/abandon', ['confirm' => true])->assertOk();
    Cache::flush(); // before Pro: a flush after it would reset the version of the entitlement and resurrect the Free snapshot of the LRU store
    PracticeKit::pro($learner);
    test()->withHeaders($headers)->getJson('/api/twa/me')->assertOk();
    $pro = $start();

    expect($freeSlot)->toBeLessThanOrEqual(BUDGET_GAME_START_FREE)
        ->and($ticket)->toBeLessThanOrEqual(BUDGET_GAME_START_TICKET)
        ->and($pro)->toBeLessThanOrEqual(BUDGET_GAME_START);
});

it('stays within the budgets of the finish: the first run of a learner makes the rows on the way, the second of a day runs no hook, the first of a new day runs them', function (): void {
    [$learner, $course, $lesson] = GameKit::richStop(13);
    $headers = PracticeKit::headers($learner);
    $body = ['kind' => 'stop', 'course' => $course->slug, 'lesson_id' => $lesson->id];
    $start = fn (): int => test()->withHeaders($headers)->postJson('/api/twa/game/runs', $body)->assertCreated()->json('id');

    $first = gameBudgetFinish($learner, $start());
    $again = gameBudgetFinish($learner, $start());
    CarbonImmutable::setTestNow('2026-11-06 10:00:00');
    $newDay = gameBudgetFinish($learner, $start());

    expect($first)->toBeLessThanOrEqual(BUDGET_GAME_FINISH_FIRST)
        ->and($again)->toBeLessThanOrEqual(BUDGET_GAME_FINISH)
        ->and($newDay)->toBeLessThanOrEqual(BUDGET_GAME_FINISH_NEW_DAY);
});

it('stays within the budget of the finish of a run in the caravan of the class: the caravan and the member cost three statements, the first run of the member one more', function (): void {
    [$learner, $course, $lesson] = GameKit::richStop(13);
    $headers = PracticeKit::headers($learner);
    $body = ['kind' => 'stop', 'course' => $course->slug, 'lesson_id' => $lesson->id];
    $start = fn (): int => test()->withHeaders($headers)->postJson('/api/twa/game/runs', $body)->assertCreated()->json('id');
    gameBudgetFinish($learner, $start()); // the learner's rows exist, the day is held
    $group = TelegramGroup::factory()->create(['course_id' => $course->id]);
    GroupMember::factory()->create(['telegram_group_id' => $group->id, 'learner_id' => $learner->id]);
    $caravan = ClassCaravan::factory()->create(['telegram_group_id' => $group->id, 'course_id' => $course->id]);

    $firstInCaravan = gameBudgetFinish($learner, $start());
    $again = gameBudgetFinish($learner, $start());

    expect(GameRun::query()->where('class_caravan_id', $caravan->id)->count())->toBe(2)
        ->and($caravan->fresh()?->runs_total)->toBe(2)
        ->and($firstInCaravan)->toBeLessThanOrEqual(BUDGET_GAME_FINISH_CLASS_FIRST_RUN)
        ->and($again)->toBeLessThanOrEqual(BUDGET_GAME_FINISH_CLASS);
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
 * Statements of a booster (G2.5b part 2), measured on the dearest one - the hint, with a limit of the week, the entitlement cold: `TwaAuth` 1,
 * the lock of the run and the journal check 2, the item 1, what the learner owns 1, the plan of the learner (the entitlement cache is the
 * default store) and the count of the week 2, then the spend, the use, the attempt, the run 4, the boosters of the view 1, the event 1.
 */
const BUDGET_GAME_BOOSTER = 13;

it('keeps the booster within its budget, and the read of a run grows by the one statement of the boosters only', function (): void {
    GameKit::limits(null, null, 3);
    (new GameItemSeeder)->run();
    [$learner, $course, $lesson] = GameKit::richStop(13);
    foreach (GameItem::query()->where('kind', 'booster')->get() as $item) {
        GameInventoryItem::factory()->create(['learner_id' => $learner->id, 'item_id' => $item->id, 'qty' => 2]);
    }
    $headers = PracticeKit::headers($learner);
    $id = test()->withHeaders($headers)->postJson('/api/twa/game/runs', ['kind' => 'stop', 'course' => $course->slug, 'lesson_id' => $lesson->id])->assertCreated()->json('id');
    $run = GameRun::query()->findOrFail($id);
    $state = $run->state;
    $state['current'] = (int) array_search('translate_fwd', array_column($run->snapshot['elements'], 'kind'), true);
    $run->forceFill(['state' => $state])->save();

    $measure = function (string $key) use ($headers, $id): int {
        Cache::flush();

        return count(QueryLog::during(fn () => test()->withHeaders($headers)->postJson("/api/twa/game/runs/{$id}/booster", ['request_id' => (string) Str::uuid(), 'item_key' => $key])->assertOk()));
    };
    $hint = $measure('booster_hint');
    $flask = $measure('booster_extra_flask');
    Cache::flush();
    $read = count(QueryLog::during(fn () => test()->withHeaders($headers)->getJson("/api/twa/game/runs/{$id}")->assertOk()));

    // A finished run reads its boosters from the state: no inventory statement.
    test()->withHeaders($headers)->postJson("/api/twa/game/runs/{$id}/abandon", ['confirm' => true])->assertOk();
    Cache::flush();
    $closedRead = count(QueryLog::during(fn () => test()->withHeaders($headers)->getJson("/api/twa/game/runs/{$id}")->assertOk()));

    expect($hint)->toBeLessThanOrEqual(BUDGET_GAME_BOOSTER)->and($flask)->toBeLessThanOrEqual(BUDGET_GAME_BOOSTER)
        ->and($read)->toBeLessThanOrEqual(BUDGET_GAME_READ)->and($closedRead)->toBe(BUDGET_GAME_READ - 1);
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

/**
 * Statements of the caravans of the class (G2.9b), `TwaAuth` 1 included. The list: the caravans 1, their groups and courses 2, the learner's
 * fights with the bosses 1 - fixed, however many caravans there are. One caravan: the caravan 1,
 * its group and course 2, the table of the class 1 (the pure cache holds it 60 s while the caravan is active), the boss fight 1.
 */
const BUDGET_GAME_CLASS_LIST = 5;

const BUDGET_GAME_CLASS_SHOW = 6;

it('keeps the caravans of the class within their budgets, and the list does not grow with the number of caravans and members', function (): void {
    $world = ClassKit::world(3);
    $me = $world['learners'][0];
    $headers = PracticeKit::headers($me);
    $measure = function (string $path) use ($headers): int {
        Cache::flush();
        PureCache::store()->flush();

        return count(QueryLog::during(fn () => test()->withHeaders($headers)->getJson('/api/twa/game/class'.$path)->assertOk()));
    };
    ClassCaravan::factory()->create(['telegram_group_id' => $world['group']->id, 'course_id' => $world['course']->id]);
    $smallList = $measure('');

    // five groups of the learner, each with a finished and an active caravan, a class of 30 with a table
    foreach (range(1, 4) as $i) {
        $other = ClassKit::world(0, chatId: -5000 - $i, teacherTelegramId: null);
        GroupMember::factory()->create(['telegram_group_id' => $other['group']->id, 'learner_id' => $me->id]);
        ClassCaravan::factory()->create(['telegram_group_id' => $other['group']->id, 'course_id' => $other['course']->id]);
        ClassCaravan::factory()->finished()->create(['telegram_group_id' => $other['group']->id, 'course_id' => $other['course']->id]);
    }
    $caravan = ClassCaravan::query()->where('telegram_group_id', $world['group']->id)->firstOrFail();
    foreach (Learner::factory()->count(27)->create() as $learner) {
        GroupMember::factory()->create(['telegram_group_id' => $world['group']->id, 'learner_id' => $learner->id]);
        ClassCaravanMember::factory()->create(['class_caravan_id' => $caravan->id, 'learner_id' => $learner->id, 'stars' => 2, 'runs' => 1]);
    }
    $bigList = $measure('');
    $show = $measure("/{$caravan->id}");
    $showFinished = $measure('/'.ClassCaravan::query()->where('status', 'finished')->firstOrFail()->id);

    expect($smallList)->toBeLessThanOrEqual(BUDGET_GAME_CLASS_LIST)->and($bigList)->toBe($smallList)
        ->and($show)->toBeLessThanOrEqual(BUDGET_GAME_CLASS_SHOW)->and($showFinished)->toBe($show);
});

it('serves the table of an active caravan from the pure cache for a minute: a warm read costs one statement less', function (): void {
    config(['cache.pure_store' => 'array']);
    $world = ClassKit::world(3);
    $me = $world['learners'][0];
    $caravan = ClassCaravan::factory()->create(['telegram_group_id' => $world['group']->id, 'course_id' => $world['course']->id]);
    $headers = PracticeKit::headers($me);
    PureCache::store()->flush();

    $cold = count(QueryLog::during(fn () => test()->withHeaders($headers)->getJson("/api/twa/game/class/{$caravan->id}")->assertOk()));
    $warm = count(QueryLog::during(fn () => test()->withHeaders($headers)->getJson("/api/twa/game/class/{$caravan->id}")->assertOk()));

    expect($warm)->toBe($cold - 1);
});
