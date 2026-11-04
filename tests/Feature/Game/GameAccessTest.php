<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\ProGamePromise;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Billing\Support\ProPromises;
use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Services\GameAccess;
use App\Models\GameProfile;
use App\Models\GameWallet;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * docs/18 §12.7.4, §12.8: who may play (the flag and the pilot of the practice), what the endpoints answer to the others, the block
 * `game` of GET /me. There is no tariff mode: a Free learner is let in, the Free limits are GameLimits' business (G2.8).
 */

uses(RefreshDatabase::class);

// A fixed instant: the `resets_at` of the limits are the local midnights of the test learner (UTC+5) after it.
beforeEach(fn () => CarbonImmutable::setTestNow('2026-11-05 10:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

/** The learner in the pilot of the practice / not. */
function gameLearner(bool $pilot = false): Learner
{
    return Learner::factory()->create(['practice_pilot_at' => $pilot ? now() : null]);
}

it('decides the state by the flag and the pilot, in that order', function (string $access, bool $pilot, string $expected): void {
    config(['game.access' => $access]);

    expect(app(GameAccess::class)->state(gameLearner($pilot))->code)->toBe($expected);
})->with([
    'off, in pilot' => ['off', true, 'off'],
    'off, not in pilot' => ['off', false, 'off'],
    'pilot, not in it' => ['pilot', false, 'not_in_pilot'],
    'pilot, in it' => ['pilot', true, 'available'],
    'all, not in pilot' => ['all', false, 'available'],
    'all, in pilot' => ['all', true, 'available'],
    // a typo in the .env does not open the game to everybody; the case and the spaces do not matter
    'unknown access = off' => ['everybody', true, 'off'],
    'empty access = off' => ['', true, 'off'],
    'case and spaces' => [' ALL ', false, 'available'],
    'case and spaces, pilot' => [' Pilot ', false, 'not_in_pilot'],
]);

it('is off by default: the shipped config opens the game to nobody', function (): void {
    expect(config('game.access'))->toBe('off')
        ->and(app(GameAccess::class)->isOff())->toBeTrue();

    config(['game.access' => 'pilot']);
    expect(app(GameAccess::class)->isOff())->toBeFalse();
});

it('refuses with game_unavailable (403) when the game is off or the learner is not in the pilot - one code, no hint', function (): void {
    $access = app(GameAccess::class);

    config(['game.access' => 'off']);
    expect(fn () => $access->assertAvailable(gameLearner(true)))
        ->toThrow(fn (GameException $e) => expect($e->errorCode)->toBe('game_unavailable')->and($e->httpStatus())->toBe(403)->and($e->details)->toBe([]));

    config(['game.access' => 'pilot']);
    expect(fn () => $access->assertAvailable(gameLearner(false)))
        ->toThrow(fn (GameException $e) => expect($e->errorCode)->toBe('game_unavailable'));

    $access->assertAvailable(gameLearner(true)); // does not throw
    config(['game.access' => 'all']);
    $access->assertAvailable(gameLearner(false));
    expect(true)->toBeTrue();
});

it('describes a closed game without a query: not available, nothing to promise, zeros', function (): void {
    config(['game.access' => 'off']);
    $learner = gameLearner();
    GameWallet::factory()->withBalance(80)->create(['learner_id' => $learner->id]);

    $block = null;
    $queries = QueryLog::during(function () use ($learner, &$block): void {
        $block = app(GameAccess::class)->describe($learner);
    });

    expect($block)->toBe(['available' => false, 'limits' => null, 'pro_promise' => 'none', 'coins' => 0, 'tickets' => 0])
        ->and($queries)->toBe([]);
});

it('describes an open game with the limits, the coins and the tickets in ONE query, zeros while the rows are not there', function (): void {
    config(['game.access' => 'all']);
    $learner = gameLearner();
    $access = app(GameAccess::class);
    app(EntitlementResolver::class)->for($learner); // the cache of the entitlement is warm, as after any request of the Mini App

    $block = null;
    $queries = QueryLog::during(function () use ($access, $learner, &$block): void {
        $block = $access->describe($learner);
    });
    expect($block)->toMatchArray(['available' => true, 'pro_promise' => 'full', 'coins' => 0, 'tickets' => 0])
        ->and(array_keys($block))->toBe(['available', 'limits', 'pro_promise', 'coins', 'tickets'])
        // The shipped Free limits (docs/18 §12.7.1), nothing used yet; the day resets at the learner's local midnight (UTC+5).
        ->and($block['limits'])->toBe([
            'runs' => ['limit' => 1, 'used' => 0, 'remaining' => 1, 'resets_at' => '2026-11-05T19:00:00.000Z'],
            'tickets' => ['balance' => 0, 'max' => 10],
            'boosters' => ['limit' => 1, 'used' => 0, 'remaining' => 1, 'resets_at' => '2026-11-08T19:00:00.000Z'],
            'boss_stages' => 1,
        ])
        ->and($queries)->toHaveCount(1);

    GameWallet::factory()->withBalance(35)->create(['learner_id' => $learner->id]);
    expect($access->describe($learner)['coins'])->toBe(35)
        ->and($access->describe($learner)['tickets'])->toBe(0);

    GameProfile::factory()->withTickets(4)->create(['learner_id' => $learner->id]);
    $other = gameLearner();
    GameWallet::factory()->withBalance(999)->create(['learner_id' => $other->id]);

    expect(QueryLog::during(fn () => $access->describe($learner)))->toHaveCount(1)
        ->and($access->describe($learner))->toMatchArray(['coins' => 35, 'tickets' => 4])
        ->and($access->describe($learner)['limits']['tickets'])->toBe(['balance' => 4, 'max' => 10])
        ->and($access->describe($other))->toMatchArray(['coins' => 999, 'tickets' => 0]);
});

it('opens the game to a Free learner too: the tariff changes only the limits Pro lifts', function (): void {
    config(['game.access' => 'all']);
    $free = app(GameAccess::class)->describe(gameLearner());
    $pro = app(GameAccess::class)->describe(PracticeKit::pro(gameLearner()));

    expect($pro)->toMatchArray(['available' => true, 'pro_promise' => 'full', 'coins' => 0, 'tickets' => 0])
        ->and($free)->toMatchArray(['available' => true, 'pro_promise' => 'full', 'coins' => 0, 'tickets' => 0])
        ->and($free['limits']['runs'])->not->toBeNull()
        ->and($free['limits']['boss_stages'])->toBe(1)
        // Pro: no limit of runs, all the bosses; the boosters of the week - the limit of the Pro plan; the tickets are kept.
        ->and($pro['limits'])->toBe([
            'runs' => null,
            'tickets' => ['balance' => 0, 'max' => 10],
            'boosters' => ['limit' => 5, 'used' => 0, 'remaining' => 5, 'resets_at' => $free['limits']['boosters']['resets_at']],
            'boss_stages' => null,
        ]);
});

it('says in GET /me what the learner sees: closed, the pilot and open', function (): void {
    $learner = gameLearner();
    $headers = PracticeKit::headers($learner);

    config(['game.access' => 'off']);
    $this->withHeaders($headers)->getJson('/api/twa/me')->assertOk()
        ->assertJsonPath('game', ['available' => false, 'limits' => null, 'pro_promise' => 'none', 'coins' => 0, 'tickets' => 0]);

    config(['game.access' => 'pilot']);
    $this->withHeaders($headers)->getJson('/api/twa/me')->assertOk()->assertJsonPath('game.available', false);
    $insider = gameLearner(true);
    $this->withHeaders(PracticeKit::headers($insider))->getJson('/api/twa/me')->assertOk()->assertJsonPath('game.available', true);

    config(['game.access' => 'all']);
    GameWallet::factory()->withBalance(12)->create(['learner_id' => $learner->id]);
    $this->withHeaders($headers)->getJson('/api/twa/me')->assertOk()
        ->assertJsonPath('game.available', true)
        ->assertJsonPath('game.pro_promise', 'full')
        ->assertJsonPath('game.coins', 12)
        ->assertJsonPath('game.tickets', 0)
        ->assertJsonPath('game.limits.runs', ['limit' => 1, 'used' => 0, 'remaining' => 1, 'resets_at' => '2026-11-05T19:00:00.000Z'])
        ->assertJsonPath('game.limits.tickets', ['balance' => 0, 'max' => 10])
        ->assertJsonPath('game.limits.boss_stages', 1);
});

it('adds ONE query to GET /me while the game is open and none while it is closed', function (): void {
    $learner = gameLearner();
    $headers = PracticeKit::headers($learner);
    $count = fn (): int => count(QueryLog::during(fn () => $this->withHeaders($headers)->getJson('/api/twa/me')->assertOk()));

    config(['game.access' => 'off']);
    $this->withHeaders($headers)->getJson('/api/twa/me')->assertOk(); // warms the caches
    $closed = $count();

    config(['game.access' => 'all']);
    expect($count())->toBe($closed + 1);
});

// --- pro_promise (docs/18 §12.7.4) -----------------------------------------------------------------------------------

it('promises Pro for the game only while it is open to the reader and Free has a limit that Pro lifts', function (bool $open, mixed $runs, mixed $bosses, mixed $boosters, string $expected): void {
    config(['game.free_limits.runs_per_day' => $runs, 'game.free_limits.boss_stages' => $bosses, 'game.free_limits.boosters_per_week' => $boosters]);

    expect(ProPromises::game($open))->toBe(ProGamePromise::from($expected));
})->with([
    'closed, limited' => [false, 1, 1, 1, 'none'],
    'open, all three limited' => [true, 1, 1, 1, 'full'],
    'open, only runs' => [true, 1, null, null, 'full'],
    'open, only bosses' => [true, '', 2, '', 'full'],
    'open, only boosters' => [true, null, false, 1, 'full'],
    'open, 0 is a limit (only Pro)' => [true, 0, null, null, 'full'],
    'open, "0" from the env is a limit' => [true, null, '0', null, 'full'],
    'open, nothing limited' => [true, null, '', false, 'none'],
]);

it('follows the config in GET /me: pro_promise is none once Free has no limit left to lift', function (): void {
    config(['game.access' => 'all', 'game.free_limits' => ['runs_per_day' => null, 'boss_stages' => null, 'boosters_per_week' => '']]);
    $learner = gameLearner();

    $this->withHeaders(PracticeKit::headers($learner))->getJson('/api/twa/me')->assertOk()->assertJsonPath('game.pro_promise', 'none');
});

it('has the Free limits and the Pro limits of docs/18 §12.7.1 in the shipped config', function (): void {
    // The limits are compared by value: from config/game.php's defaults they are integers, from a `.env` made of
    // `.env.example` (as CI makes it) env() reads the strings '1', '5', '40' - the same setting either way; the readers
    // cast them (an empty value is "no limit").
    expect(config('game.free_limits'))->toEqual(['runs_per_day' => 1, 'boss_stages' => 1, 'boosters_per_week' => 1])
        ->and(config('game.pro_limits'))->toEqual(['boosters_per_week' => 5])
        ->and(config('game.tickets'))->toEqual(['per_streak_week' => 1, 'per_referral' => 3, 'max' => 10])
        ->and(config('game.league.daily_cap'))->toEqual(40)
        ->and(config('game.run.ttl_minutes'))->toBe(30)
        ->and(config('game.class.centers_only'))->toBeTrue();
});
