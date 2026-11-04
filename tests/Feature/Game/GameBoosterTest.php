<?php

declare(strict_types=1);

use App\Domain\Game\Enums\BoosterEffect;
use App\Domain\Game\Enums\RunFinishReason;
use App\Domain\Game\Services\Runs\RunService;
use App\Models\GameBoosterUse;
use App\Models\GameCoinLedgerEntry;
use App\Models\GameInventoryItem;
use App\Models\GameItem;
use App\Models\GameRun;
use App\Models\GameRunAttempt;
use App\Models\Learner;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Game\GameKit;
use Tests\Support\Practice\PracticeKit;

/*
 * docs/18 §12.5.2, §12.7.2, §12.8 (G2.5b part 2): the boosters in a run - `hint`, `extra_flask`, `second_chance` - from the inventory,
 * under the lock of the run, once per `request_id`, within the limit of the week. The race of the last booster is in
 * GameBoosterConcurrencyTest (PostgreSQL).
 */

uses(RefreshDatabase::class);

/** The end of the local week of 2026-11-05 (a Thursday) of a learner in Asia/Tashkent: the Monday midnight, in UTC. */
const BOOSTER_WEEK_END = '2026-11-08T19:00:00.000Z';

/** The kinds of element that have a hint. */
const BOOSTER_HINTED_KINDS = ['translate_fwd', 'translate_back', 'spell', 'gap'];

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    GameKit::openAccess();
    GameKit::noEvents();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** The three boosters of the shop with the keys of the seeder. @return array{hint: GameItem, extra_flask: GameItem, second_chance: GameItem} */
function boosterItems(): array
{
    return [
        'hint' => GameItem::factory()->booster(BoosterEffect::Hint)->create(['key' => 'booster_hint']),
        'extra_flask' => GameItem::factory()->booster(BoosterEffect::ExtraFlask)->create(['key' => 'booster_extra_flask']),
        'second_chance' => GameItem::factory()->booster(BoosterEffect::SecondChance)->create(['key' => 'booster_second_chance']),
    ];
}

function boosterOwn(Learner $learner, GameItem $item, int $qty = 1): GameInventoryItem
{
    return GameInventoryItem::factory()->create(['learner_id' => $learner->id, 'item_id' => $item->id, 'qty' => $qty]);
}

/**
 * A learner with a started run of a stop and the three boosters (`$qty` of each); the run stands on an element that has a hint.
 *
 * @return array{0: Learner, 1: GameRun, 2: array{hint: GameItem, extra_flask: GameItem, second_chance: GameItem}}
 */
function boosterWorld(int $qty = 1): array
{
    [$learner, $course, $lesson] = GameKit::stop(8);
    $items = boosterItems();
    foreach ($items as $item) {
        boosterOwn($learner, $item, $qty);
    }
    $run = app(RunService::class)->start($learner, $course->slug, $lesson->id);
    boosterStandOnHinted($run);

    return [$learner, $run, $items];
}

/** Moves the run to the first element from the current one on that has a hint (a test may: the elements before stay open). */
function boosterStandOnHinted(GameRun $run): void
{
    $from = (int) $run->refresh()->state['current'];
    foreach ($run->snapshot['elements'] as $index => $element) {
        if ($index >= $from && in_array($element['kind'], BOOSTER_HINTED_KINDS, true)) {
            $state = $run->state;
            $state['current'] = $index;
            $run->forceFill(['state' => $state])->save();

            return;
        }
    }

    throw new RuntimeException('No element with a hint in the run.');
}

/** @param array<string, mixed> $extra */
function boosterPost(Learner $learner, int $runId, string $itemKey, array $extra = [], ?string $requestId = null): TestResponse
{
    Cache::flush(); // `twa-api` is 60 requests a minute, and the clock of the test is frozen

    return test()->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/game/runs/{$runId}/booster", ['request_id' => $requestId ?? (string) Str::uuid(), 'item_key' => $itemKey, ...$extra]);
}

function boosterQty(Learner $learner, GameItem $item): int
{
    return (int) GameInventoryItem::query()->where('learner_id', $learner->id)->where('item_id', $item->id)->value('qty');
}

/** @return list<array<string, mixed>> */
function boosterEvents(string $name): array
{
    return ProductEvent::query()->where('name', $name)->orderBy('id')->get()->map(fn (ProductEvent $e): array => (array) $e->props)->all();
}

/** A wrong answer to the current element of the run (over HTTP). */
function boosterWrong(Learner $learner, GameRun $run): TestResponse
{
    Cache::flush();
    $element = GameKit::current($run);

    return test()->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/game/runs/{$run->id}/answer", [
        'request_id' => (string) Str::uuid(), 'element_id' => $element['id'], 'answer' => GameKit::wrongAnswer($element),
    ]);
}

// ---------------------------------------------------------------- the effects

it('gives the hint of the current element with a booster: the shape of the kind, no coins, the booster spent, the use and the attempt written, the event sent', function (): void {
    [$learner, $run, $items] = boosterWorld(2);
    $element = GameKit::current($run);
    $before = count($run->snapshot['elements']);

    $response = boosterPost($learner, $run->id, 'booster_hint')->assertOk();

    $hint = $response->json('current.element.hint');
    expect($hint)->toBeArray()->and($hint !== [])->toBeTrue();
    isset($hint['options_removed']) ? expect($hint['options_removed'])->toHaveCount(2) : expect($hint['prefix'])->toBeString();
    $response->assertJsonPath('current.element.id', $element['id'])
        ->assertJsonPath('boosters.used', [['key' => 'booster_hint', 'effect' => 'hint', 'element_id' => $element['id']]])
        ->assertJsonPath('boosters.available.0', ['key' => 'booster_hint', 'effect' => 'hint', 'qty' => 1])
        ->assertJsonPath('status', 'active');

    $use = GameBoosterUse::query()->sole();
    $attempt = GameRunAttempt::query()->sole();
    expect(boosterQty($learner, $items['hint']))->toBe(1)
        ->and($use->run_id)->toBe($run->id)->and($use->item_id)->toBe($items['hint']->id)->and($use->element_id)->toBe($element['id'])
        ->and($use->week_start->toDateString())->toBe('2026-11-02') // the Monday of the local week
        ->and($attempt->kind->value)->toBe('booster')->and($attempt->element_id)->toBe($element['id'])->and($attempt->verdict)->toBeNull()
        ->and(GameCoinLedgerEntry::query()->count())->toBe(0)
        ->and(boosterEvents('game.booster_used'))->toEqual([['run_id' => $run->id, 'effect' => 'hint']])
        ->and($before)->toBe(count($run->refresh()->snapshot['elements']));

    // The answer after the hint is counted as a hint, whatever it says (docs/18 §12.5.2).
    $answered = test()->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/game/runs/{$run->id}/answer", [
        'request_id' => (string) Str::uuid(), 'element_id' => $element['id'], 'answer' => GameKit::rightAnswers($element)[0],
    ])->assertOk();
    expect($answered->json('run.boosters.used'))->toHaveCount(1)
        ->and($run->refresh()->hints)->toBe(1)->and($run->correct)->toBe(0);
});

it('refuses a second hint booster on the same element, a hint on an element that is not current, and one on an element that has its hint already - and keeps the booster', function (): void {
    [$learner, $run, $items] = boosterWorld(3);
    $element = GameKit::current($run);
    $other = collect($run->snapshot['elements'])->first(fn (array $e): bool => $e['id'] !== $element['id']);

    boosterPost($learner, $run->id, 'booster_hint', ['element_id' => $element['id']])->assertOk();
    boosterPost($learner, $run->id, 'booster_hint')->assertConflict()->assertJsonPath('error.code', 'booster_already_used');
    boosterPost($learner, $run->id, 'booster_hint', ['element_id' => $other['id']])->assertConflict()
        ->assertJsonPath('error.code', 'element_not_current')->assertJsonPath('error.details.current', $element['id']);
    boosterPost($learner, $run->id, 'booster_hint', ['element_id' => 'nope'])->assertUnprocessable()->assertJsonPath('error.code', 'unknown_element_id');

    expect(boosterQty($learner, $items['hint']))->toBe(2)->and(GameBoosterUse::query()->count())->toBe(1);

    // The hint of the bazaar is on the element already: the booster is not wasted on a second one.
    $run->refresh();
    $next = collect($run->snapshot['elements'])->first(fn (array $e): bool => $e['id'] !== $element['id'] && in_array($e['kind'], BOOSTER_HINTED_KINDS, true));
    expect($next)->not->toBeNull();
    $state = $run->state;
    $state['current'] = array_search($next['id'], array_column($run->snapshot['elements'], 'id'), true);
    $state['elements'][$next['id']] = ['status' => 'open', 'hinted' => true, 'hint' => ['prefix' => 'ab']];
    $run->forceFill(['state' => $state])->save();
    boosterPost($learner, $run->id, 'booster_hint')->assertConflict()->assertJsonPath('error.code', 'hint_not_available');
    expect(boosterQty($learner, $items['hint']))->toBe(2);
});

it('has no hint booster for a round of pairs, a sentence or a dictation: 409 hint_not_available, nothing spent', function (): void {
    [$learner, $course, $lesson] = GameKit::richStop();
    $items = boosterItems();
    boosterOwn($learner, $items['hint'], 2);
    $run = app(RunService::class)->start($learner, $course->slug, $lesson->id);

    $index = null;
    foreach ($run->snapshot['elements'] as $i => $element) {
        if (in_array($element['kind'], ['pairs_round', 'sentence', 'dictation'], true)) {
            $index = $i;
            break;
        }
    }
    expect($index)->not->toBeNull();
    $state = $run->state;
    $state['current'] = $index;
    $run->forceFill(['state' => $state])->save();

    boosterPost($learner, $run->id, 'booster_hint')->assertConflict()->assertJsonPath('error.code', 'hint_not_available');

    expect(boosterQty($learner, $items['hint']))->toBe(2)->and(GameBoosterUse::query()->count())->toBe(0)->and(GameRunAttempt::query()->count())->toBe(0);
});

it('adds a flask with the extra flask: four flasks, the maximum four, once per run', function (): void {
    [$learner, $run, $items] = boosterWorld(2);

    $response = boosterPost($learner, $run->id, 'booster_extra_flask')->assertOk()
        ->assertJsonPath('flasks', 4)->assertJsonPath('flasks_max', 4)
        ->assertJsonPath('boosters.used', [['key' => 'booster_extra_flask', 'effect' => 'extra_flask', 'element_id' => null]]);

    $run->refresh();
    expect($run->flasks)->toBe(4)->and($run->flasks_max)->toBe(4)
        ->and($run->state['boosters']['extra_flask'])->toBeTrue()
        ->and(GameBoosterUse::query()->sole()->element_id)->toBeNull()
        ->and(GameRunAttempt::query()->sole()->element_id)->toBe(GameKit::current($run)['id'])
        ->and(boosterQty($learner, $items['extra_flask']))->toBe(1)
        ->and(boosterEvents('game.booster_used'))->toEqual([['run_id' => $run->id, 'effect' => 'extra_flask']]);

    // One per run: the second is refused and costs nothing (the owner still has one).
    boosterPost($learner, $run->id, 'booster_extra_flask')->assertConflict()->assertJsonPath('error.code', 'booster_already_used');
    expect(boosterQty($learner, $items['extra_flask']))->toBe(1)->and(GameBoosterUse::query()->count())->toBe(1);

    // The fourth flask is a real one: it takes four mistakes to lose the run.
    $response->assertJsonPath('boosters.available.1.key', 'booster_extra_flask');
    for ($i = 0; $i < 3; $i++) {
        boosterWrong($learner, $run)->assertOk();
    }
    expect($run->refresh()->flasks)->toBe(1)->and($run->status->value)->toBe('active');
    boosterWrong($learner, $run)->assertOk();
    expect($run->refresh()->finish_reason)->toBe(RunFinishReason::Failed);
});

it('forgives the first mistake of the run with the second chance: no flask lost, the next one costs it', function (): void {
    [$learner, $run, $items] = boosterWorld();

    boosterPost($learner, $run->id, 'booster_second_chance')->assertOk()
        ->assertJsonPath('boosters.used', [['key' => 'booster_second_chance', 'effect' => 'second_chance', 'element_id' => null]])
        ->assertJsonPath('flasks', 3);
    expect($run->refresh()->state['boosters']['second_chance_left'])->toBe(1)->and(boosterQty($learner, $items['second_chance']))->toBe(0);

    boosterWrong($learner, $run)->assertOk()->assertJsonPath('flask_lost', false)->assertJsonPath('run.flasks', 3);
    expect($run->refresh()->state['boosters']['second_chance_left'])->toBe(0);
    boosterWrong($learner, $run)->assertOk()->assertJsonPath('flask_lost', true)->assertJsonPath('run.flasks', 2);
    // What was used stays in the view after the chance is spent.
    expect($run->refresh()->state['boosters']['used'])->toHaveCount(1);
});

it('uses the three boosters together in one run', function (): void {
    [$learner, $run] = boosterWorld(1);

    boosterPost($learner, $run->id, 'booster_hint')->assertOk();
    boosterPost($learner, $run->id, 'booster_extra_flask')->assertOk();
    $response = boosterPost($learner, $run->id, 'booster_second_chance')->assertOk();

    expect($response->json('boosters.used'))->toHaveCount(3)->and($response->json('boosters.available'))->toBe([])
        ->and(GameBoosterUse::query()->count())->toBe(3)->and(GameRunAttempt::query()->count())->toBe(3);
});

it('has no flask and no second chance in a run without flasks (a boss): 409 booster_not_applicable, nothing spent', function (): void {
    [$learner, $run, $items] = boosterWorld(2);
    $run->forceFill(['flasks' => 0, 'flasks_max' => 0])->save();

    boosterPost($learner, $run->id, 'booster_extra_flask')->assertConflict()->assertJsonPath('error.code', 'booster_not_applicable');
    boosterPost($learner, $run->id, 'booster_second_chance')->assertConflict()->assertJsonPath('error.code', 'booster_not_applicable');
    boosterPost($learner, $run->id, 'booster_hint')->assertOk(); // the hint works everywhere

    expect(boosterQty($learner, $items['extra_flask']))->toBe(2)->and(boosterQty($learner, $items['second_chance']))->toBe(2)->and(GameBoosterUse::query()->count())->toBe(1);
});

// ---------------------------------------------------------------- ownership

it('refuses a booster the learner does not own (no row, qty 0), a missing item and a cosmetic: the quantity never goes below zero', function (): void {
    [$learner, $run, $items] = boosterWorld(1);
    GameInventoryItem::query()->where('item_id', $items['second_chance']->id)->update(['qty' => 0]);
    GameInventoryItem::query()->where('item_id', $items['extra_flask']->id)->delete();
    GameItem::factory()->create(['key' => 'camel_x']);

    boosterPost($learner, $run->id, 'booster_second_chance')->assertConflict()->assertJsonPath('error.code', 'booster_not_owned');
    boosterPost($learner, $run->id, 'booster_extra_flask')->assertConflict()->assertJsonPath('error.code', 'booster_not_owned');
    boosterPost($learner, $run->id, 'booster_nothing')->assertNotFound()->assertJsonPath('error.code', 'item_not_found');
    boosterPost($learner, $run->id, 'camel_x')->assertNotFound()->assertJsonPath('error.code', 'item_not_found');

    // The last one is spent once; the learner is left with 0, never -1.
    boosterPost($learner, $run->id, 'booster_hint')->assertOk();
    $element = GameKit::current($run);
    test()->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/game/runs/{$run->id}/answer", [
        'request_id' => (string) Str::uuid(), 'element_id' => $element['id'], 'answer' => GameKit::rightAnswers($element)[0],
    ])->assertOk();
    boosterStandOnHinted($run);
    boosterPost($learner, $run->id, 'booster_hint')->assertConflict()->assertJsonPath('error.code', 'booster_not_owned');

    expect(boosterQty($learner, $items['hint']))->toBe(0)->and(GameBoosterUse::query()->count())->toBe(1)
        ->and(boosterEvents('game.booster_used'))->toHaveCount(1);
});

it("does not use another learner's booster, run or inventory", function (): void {
    [$learner, $run] = boosterWorld(1);
    $stranger = Learner::factory()->create();

    boosterPost($stranger, $run->id, 'booster_hint')->assertNotFound()->assertJsonPath('error.code', 'game_run_not_found');
    expect(GameBoosterUse::query()->count())->toBe(0);
});

// ---------------------------------------------------------------- the limit of the week

it('stops a Free learner at boosters_per_week with 409 booster_limit_reached {limit, used, resets_at, upsell: true} and the event; the booster is kept', function (): void {
    GameKit::limits(null, null, 1);
    [$learner, $run, $items] = boosterWorld(2);

    boosterPost($learner, $run->id, 'booster_extra_flask')->assertOk();
    boosterPost($learner, $run->id, 'booster_second_chance')->assertConflict()
        ->assertJsonPath('error.code', 'booster_limit_reached')
        ->assertJsonPath('error.details', ['limit' => 1, 'used' => 1, 'resets_at' => BOOSTER_WEEK_END, 'upsell' => true]);

    expect(boosterQty($learner, $items['second_chance']))->toBe(2)->and(GameBoosterUse::query()->count())->toBe(1)
        ->and(boosterEvents('game.limit_reached'))->toEqual([['kind' => 'boosters', 'limit' => 1]]);
});

it('lets Pro with boosters_plus use pro_limits.boosters_per_week, then stops it - without an upsell: Pro would not help', function (): void {
    GameKit::limits(null, null, 1);
    config(['game.pro_limits.boosters_per_week' => 2]);
    [$learner, $run] = boosterWorld(2);
    PracticeKit::pro($learner);

    boosterPost($learner, $run->id, 'booster_extra_flask')->assertOk();
    boosterPost($learner, $run->id, 'booster_second_chance')->assertOk();
    boosterPost($learner, $run->id, 'booster_hint')->assertConflict()
        ->assertJsonPath('error.code', 'booster_limit_reached')->assertJsonPath('error.details.limit', 2)->assertJsonPath('error.details.used', 2)
        ->assertJsonPath('error.details.upsell', false);
});

it('has no limit when the config leaves it empty, and "0" means only Pro', function (): void {
    GameKit::limits(null, null, null);
    [$learner, $run] = boosterWorld(1);
    boosterPost($learner, $run->id, 'booster_extra_flask')->assertOk();
    boosterPost($learner, $run->id, 'booster_second_chance')->assertOk();

    GameKit::limits(null, null, 0);
    boosterPost($learner, $run->id, 'booster_hint')->assertConflict()->assertJsonPath('error.details.limit', 0);
});

it('counts the week from Monday of the learner\'s local week: a use of last week does not count, and the limit comes back on Monday', function (): void {
    GameKit::limits(null, null, 1);
    [$learner, $run, $items] = boosterWorld(3);
    // A use of last week (the Monday of the 26th of October) is not this week's.
    GameBoosterUse::factory()->create(['run_id' => $run->id, 'item_id' => $items['hint']->id, 'element_id' => 'old', 'week_start' => '2026-10-26', 'used_at' => '2026-10-28 10:00:00']);

    boosterPost($learner, $run->id, 'booster_extra_flask')->assertOk();
    boosterPost($learner, $run->id, 'booster_second_chance')->assertConflict()->assertJsonPath('error.code', 'booster_limit_reached');

    // Sunday evening in Tashkent (UTC+5) is still the same week; Monday 00:00 local (Sunday 19:00 UTC) opens the next one.
    CarbonImmutable::setTestNow('2026-11-08 18:59:00');
    $run->forceFill(['expires_at' => '2026-11-09 00:00:00'])->save();
    boosterPost($learner, $run->id, 'booster_second_chance')->assertConflict()->assertJsonPath('error.code', 'booster_limit_reached');
    CarbonImmutable::setTestNow('2026-11-08 19:00:00');
    boosterPost($learner, $run->id, 'booster_second_chance')->assertOk();

    expect(GameBoosterUse::query()->where('run_id', $run->id)->orderBy('id')->pluck('week_start')->map(fn ($d) => $d->toDateString())->all())
        ->toBe(['2026-10-26', '2026-11-02', '2026-11-09']);
});

// ---------------------------------------------------------------- idempotency and the run

it('answers a repeated request_id with the same run and spends nothing twice; the id of another kind of request is reused', function (): void {
    [$learner, $run, $items] = boosterWorld(3);
    $id = (string) Str::uuid();

    $first = boosterPost($learner, $run->id, 'booster_extra_flask', [], $id)->assertOk();
    $again = boosterPost($learner, $run->id, 'booster_extra_flask', [], $id)->assertOk();

    expect($again->json('flasks'))->toBe(4)->and($again->json('boosters'))->toBe($first->json('boosters'))
        ->and(boosterQty($learner, $items['extra_flask']))->toBe(2)->and(GameBoosterUse::query()->count())->toBe(1)
        ->and(GameRunAttempt::query()->count())->toBe(1)->and(boosterEvents('game.booster_used'))->toHaveCount(1);

    // A request_id of a booster used by an answer or a hint (and the other way round) is "reused".
    $element = GameKit::current($run);
    test()->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/game/runs/{$run->id}/answer", [
        'request_id' => $id, 'element_id' => $element['id'], 'answer' => GameKit::rightAnswers($element)[0],
    ])->assertConflict()->assertJsonPath('error.code', 'request_id_reused');
    $answerId = (string) Str::uuid();
    test()->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/game/runs/{$run->id}/answer", [
        'request_id' => $answerId, 'element_id' => $element['id'], 'answer' => GameKit::rightAnswers($element)[0],
    ])->assertOk();
    boosterPost($learner, $run->id, 'booster_second_chance', [], $answerId)->assertConflict()->assertJsonPath('error.code', 'request_id_reused');
    expect(boosterQty($learner, $items['second_chance']))->toBe(3);
});

it('refuses a booster to a closed run (410) and to an expired one, which it closes', function (): void {
    [$learner, $run, $items] = boosterWorld(1);
    $expired = GameRun::query()->findOrFail($run->id);
    $expired->forceFill(['expires_at' => CarbonImmutable::now()->subMinute()])->save();

    boosterPost($learner, $run->id, 'booster_hint')->assertStatus(410)->assertJsonPath('error.details.finish_reason', 'expired');
    expect($run->refresh()->finish_reason)->toBe(RunFinishReason::Expired);

    boosterPost($learner, $run->id, 'booster_hint')->assertStatus(410);
    expect(boosterQty($learner, $items['hint']))->toBe(1)->and(GameBoosterUse::query()->count())->toBe(0);
});

it('validates the request (422), asks for the token (401) and is closed to a learner outside the game (403)', function (): void {
    [$learner, $run] = boosterWorld(1);
    $headers = PracticeKit::headers($learner);

    test()->withHeaders($headers)->postJson("/api/twa/game/runs/{$run->id}/booster", ['item_key' => 'booster_hint'])->assertUnprocessable();
    test()->withHeaders($headers)->postJson("/api/twa/game/runs/{$run->id}/booster", ['request_id' => 'nope', 'item_key' => 'booster_hint'])->assertUnprocessable();
    test()->withHeaders($headers)->postJson("/api/twa/game/runs/{$run->id}/booster", ['request_id' => (string) Str::uuid(), 'item_key' => 'Bad Key!'])->assertUnprocessable();
    test()->withHeaders($headers)->postJson("/api/twa/game/runs/{$run->id}/booster", ['request_id' => (string) Str::uuid()])->assertUnprocessable();
    test()->flushHeaders()->postJson("/api/twa/game/runs/{$run->id}/booster", [])->assertUnauthorized();

    config(['game.access' => 'off']);
    boosterPost($learner, $run->id, 'booster_hint')->assertForbidden()->assertJsonPath('error.code', 'game_unavailable');
});

// ---------------------------------------------------------------- the view

it('shows the boosters of the inventory with qty > 0 in the order of the shop, none for a finished run, no cosmetic', function (): void {
    [$learner, $run, $items] = boosterWorld(2);
    GameInventoryItem::query()->where('item_id', $items['extra_flask']->id)->update(['qty' => 0]);
    boosterOwn($learner, GameItem::factory()->create(['key' => 'camel_y']));

    $read = test()->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/game/runs/{$run->id}")->assertOk();
    expect($read->json('boosters'))->toBe([
        'available' => [['key' => 'booster_hint', 'effect' => 'hint', 'qty' => 2], ['key' => 'booster_second_chance', 'effect' => 'second_chance', 'qty' => 2]],
        'used' => [],
    ]);

    $abandoned = test()->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/game/runs/{$run->id}/abandon", ['confirm' => true])->assertOk();
    expect($abandoned->json('boosters'))->toBe(['available' => [], 'used' => []]);
});

it('keeps the boosters used in the finished run in its view', function (): void {
    [$learner, $run] = boosterWorld(1);
    boosterPost($learner, $run->id, 'booster_second_chance')->assertOk();

    $read = test()->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/game/runs/{$run->id}/abandon", ['confirm' => true])->assertOk();

    expect($read->json('boosters.used'))->toBe([['key' => 'booster_second_chance', 'effect' => 'second_chance', 'element_id' => null]]);
});
