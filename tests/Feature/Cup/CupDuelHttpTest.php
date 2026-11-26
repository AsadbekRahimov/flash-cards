<?php

declare(strict_types=1);

use App\Domain\Cup\Services\CupMembershipGate;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Http\Middleware\EnsureCupAccess;
use App\Http\Middleware\EnsureCupDuels;
use App\Http\Middleware\TwaAuth;
use App\Jobs\PostCupMessageJob;
use App\Jobs\SendCupDuelDmJob;
use App\Models\Cup;
use App\Models\CupDuel;
use App\Models\CupParticipant;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Cup\CupDuelKit;
use Tests\Support\Cup\CupKit;
use Tests\Support\Practice\PracticeKit;

/*
 * docs/04 A.23d, docs/33 §11.9.4 (D2): the HTTP API of the duels of a cup - the exact forms (the contract of the screens), the door
 * (a stranger, a participant who left and a missing cup get one 403; a duel the participant may not see one 404; the switch off one
 * 409), the codes of the core through HTTP, the DMs queued after the commit, the availability on the table, the limiters.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake([PostCupMessageJob::class, SendCupDuelDmJob::class]);
    Cache::flush();
    PureCache::store()->flush();
});

afterEach(fn () => CarbonImmutable::setTestNow());

const DUEL_FIELDS = ['id', 'status', 'end_reason', 'rated_offered', 'rated', 'public', 'mode', 'day', 'accepted_day', 'expires_at', 'battle_id', 'role', 'challenger', 'opponent', 'points', 'winner_participant_id', 'server_now'];

const DUEL_AVAILABILITY = ['day', 'can_challenge', 'rated_available', 'challenges_left', 'open_duel_id'];

/** @param array<string, mixed> $body */
function duelHttp(Learner $learner, string $method, string $path, array $body = []): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->json($method, '/api/twa'.$path, $body);
}

/** @param array<string, mixed> $extra */
function duelChallengeBody(?CupParticipant $target = null, bool $public = false, array $extra = []): array
{
    return ['opponent_participant_id' => $target?->id, 'public' => $public, 'mode' => 'pairs', 'request_id' => (string) Str::uuid(), ...$extra];
}

/** @return list<string> every key of a nested JSON body */
function duelKeys(array $body): array
{
    return array_values(array_unique(array_map(static fn (string $key): string => (string) preg_replace('/^.*\./', '', $key), array_keys(Arr::dot($body)))));
}

/**
 * A second running cup (its own group) where `$opponent` plays too, and a challenge to him there - "a duel of another cup" that the core
 * alone would let him act on.
 *
 * @return array{cup: Cup, duel: CupDuel}
 */
function otherCupDuel(Learner $opponent): array
{
    $group = CupKit::group();
    $learners = [Learner::factory()->create(), $opponent];
    $cup = Cup::factory()->scheduled(now()->subHour()->toDateTimeString())->create(['telegram_group_id' => $group->id, 'status' => 'running', 'active_group_id' => $group->id]);
    $p = [];
    foreach ($learners as $learner) {
        $p[] = CupParticipant::factory()->create(['cup_id' => $cup->id, 'learner_id' => $learner->id]);
    }
    $duel = CupDuel::factory()->create(['cup_id' => $cup->id, 'challenger_participant_id' => $p[0]->id, 'opponent_participant_id' => $p[1]->id]);

    return ['cup' => $cup, 'duel' => $duel];
}

// ------------------------------------------------------------------------------------------------------------------- the contract

it('shapes a challenge, its repeat, the list, one duel and the acceptance exactly, without learner ids', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [$pa, $pb]] = CupDuelKit::running();
    $body = duelChallengeBody($pb);

    $made = duelHttp($a, 'POST', "/cups/{$cup->id}/duels", $body)->assertCreated()->json();
    expect(array_keys($made))->toBe(DUEL_FIELDS)
        ->and($made['status'])->toBe('open')
        ->and($made['role'])->toBe('challenger')
        ->and($made['rated_offered'])->toBeTrue()
        ->and($made['rated'])->toBeNull()
        ->and($made['public'])->toBeFalse()
        ->and($made['mode'])->toBe('pairs')
        ->and($made['day'])->toBe(1)
        ->and($made['battle_id'])->toBeNull()
        ->and($made['points'])->toBeNull()
        ->and($made['challenger'])->toBe(['participant_id' => $pa->id, 'display_name' => $pa->display_name, 'pro_emoji' => null])
        ->and($made['opponent'])->toBe(['participant_id' => $pb->id, 'display_name' => $pb->display_name, 'pro_emoji' => null])
        ->and($made['expires_at'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/')
        ->and(array_intersect(duelKeys($made), ['learner_id', 'telegram_user_id', 'request_id', 'open_challenger_id', 'public_code']))->toBe([]);

    $again = duelHttp($a, 'POST', "/cups/{$cup->id}/duels", $body)->assertOk()->json();
    expect($again['id'])->toBe($made['id']);

    $list = duelHttp($b, 'GET', "/cups/{$cup->id}/duels")->assertOk()->json();
    expect(array_keys($list))->toBe(['incoming', 'open', 'mine', 'today', 'availability', 'server_now'])
        ->and(array_column($list['incoming'], 'id'))->toBe([$made['id']])
        ->and(array_keys($list['incoming'][0]))->toBe(DUEL_FIELDS)
        ->and($list['incoming'][0]['role'])->toBe('opponent')
        ->and($list['open'])->toBe([])
        ->and($list['mine'])->toBeNull()
        ->and(array_keys($list['availability']))->toBe(DUEL_AVAILABILITY);

    $mine = duelHttp($a, 'GET', "/cups/{$cup->id}/duels")->assertOk()->json();
    expect($mine['mine']['id'])->toBe($made['id'])
        ->and($mine['incoming'])->toBe([])
        ->and($mine['availability'])->toBe(['day' => 1, 'can_challenge' => false, 'rated_available' => true, 'challenges_left' => 19, 'open_duel_id' => $made['id']]);

    $one = duelHttp($b, 'GET', "/cups/{$cup->id}/duels/{$made['id']}")->assertOk()->json();
    expect(array_keys($one))->toBe(DUEL_FIELDS)->and($one['id'])->toBe($made['id']);

    $accepted = duelHttp($b, 'POST', "/cups/{$cup->id}/duels/{$made['id']}/accept", ['expect_rated' => true])->assertCreated()->json();
    expect(array_keys($accepted))->toBe(DUEL_FIELDS)
        ->and($accepted['status'])->toBe('accepted')
        ->and($accepted['rated'])->toBeTrue()
        ->and($accepted['accepted_day'])->toBe(1)
        ->and($accepted['role'])->toBe('opponent')
        ->and($accepted['battle_id'])->toBe(CupDuelKit::fresh($made['id'])->battle_id)
        ->and($accepted['battle_id'])->toBeInt()
        ->and($accepted['opponent']['display_name'])->toBe($pb->display_name);

    duelHttp($b, 'POST', "/cups/{$cup->id}/duels/{$made['id']}/accept", ['expect_rated' => true])->assertOk()->assertJsonPath('battle_id', $accepted['battle_id']);

    // The waiting screen of the challenger polls the duel: the match is there at once - and it is the existing screen of a battle.
    $polled = duelHttp($a, 'GET', "/cups/{$cup->id}/duels/{$made['id']}")->assertOk()->json();
    expect($polled['battle_id'])->toBe($accepted['battle_id'])->and($polled['status'])->toBe('accepted');
    duelHttp($a, 'GET', "/practice/battles/{$accepted['battle_id']}")->assertOk()->assertJsonPath('status', 'room');

    $today = duelHttp($a, 'GET', "/cups/{$cup->id}/duels")->assertOk()->json();
    expect(array_column($today['today'], 'id'))->toBe([$made['id']])
        ->and($today['mine'])->toBeNull()
        ->and($today['availability'])->toBe(['day' => 1, 'can_challenge' => true, 'rated_available' => false, 'challenges_left' => 19, 'open_duel_id' => null]);
});

it('answers the decline and the withdrawal with the duel, and lists open challenges to anybody for the others only', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'participants' => [, $pb]] = CupDuelKit::running();

    $addressed = duelHttp($a, 'POST', "/cups/{$cup->id}/duels", duelChallengeBody($pb))->assertCreated()->json('id');
    $declined = duelHttp($b, 'POST', "/cups/{$cup->id}/duels/{$addressed}/decline")->assertOk()->json();
    expect($declined['status'])->toBe('declined')->and($declined['end_reason'])->toBe('declined')->and($declined['role'])->toBe('opponent');
    duelHttp($b, 'POST', "/cups/{$cup->id}/duels/{$addressed}/decline")->assertOk()->assertJsonPath('status', 'declined');

    $open = duelHttp($a, 'POST', "/cups/{$cup->id}/duels", duelChallengeBody(public: true))->assertCreated()->json();
    expect($open['opponent'])->toBeNull()->and($open['public'])->toBeTrue();
    expect(array_column(duelHttp($c, 'GET', "/cups/{$cup->id}/duels")->assertOk()->json('open'), 'id'))->toBe([$open['id']])
        ->and(duelHttp($a, 'GET', "/cups/{$cup->id}/duels")->json('open'))->toBe([]);
    duelHttp($c, 'GET', "/cups/{$cup->id}/duels/{$open['id']}")->assertOk()->assertJsonPath('role', null);

    $canceled = duelHttp($a, 'POST', "/cups/{$cup->id}/duels/{$open['id']}/cancel")->assertOk()->json();
    expect($canceled['status'])->toBe('canceled')->and($canceled['end_reason'])->toBe('challenger');
    expect(duelHttp($c, 'GET', "/cups/{$cup->id}/duels")->json('open'))->toBe([]);
});

it('shows on the table whether the participant may challenge today, with no statement of its own', function (): void {
    ['cup' => $cup, 'learners' => [$a]] = CupDuelKit::running();

    $board = duelHttp($a, 'GET', "/cups/{$cup->id}")->assertOk()->json('me.duel');
    expect($board)->toBe(['day' => 1, 'can_challenge' => true, 'rated_available' => true, 'challenges_left' => 20, 'open_duel_id' => null]);

    config(['cup.duels' => false]);
    expect(duelHttp($a, 'GET', "/cups/{$cup->id}")->assertOk()->json('me.duel'))->toBeNull();
});

it('passes the codes of the core through: self, already open, rating changed, mode, validation', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'participants' => [$pa, $pb, $pc]] = CupDuelKit::running();

    duelHttp($a, 'POST', "/cups/{$cup->id}/duels", duelChallengeBody($pa))->assertStatus(422)->assertJsonPath('error.code', 'cup_duel_self');
    duelHttp($a, 'POST', "/cups/{$cup->id}/duels", duelChallengeBody($pb, extra: ['mode' => 'pairs_timed']))->assertStatus(422)->assertJsonPath('error.code', 'cup_duel_mode_invalid');
    duelHttp($a, 'POST', "/cups/{$cup->id}/duels", duelChallengeBody($pb, extra: ['request_id' => 'nope']))->assertStatus(422)->assertJsonValidationErrors('request_id');
    duelHttp($a, 'POST', "/cups/{$cup->id}/duels", ['request_id' => (string) Str::uuid()])->assertStatus(422)->assertJsonValidationErrors('public');

    $first = duelHttp($a, 'POST', "/cups/{$cup->id}/duels", duelChallengeBody($pb))->assertCreated()->json('id');
    duelHttp($a, 'POST', "/cups/{$cup->id}/duels", duelChallengeBody($pc))->assertStatus(409)
        ->assertJsonPath('error.code', 'cup_duel_already_open')->assertJsonPath('error.details.duel_id', $first);

    duelHttp($a, 'POST', "/cups/{$cup->id}/duels/{$first}/accept", ['expect_rated' => true])->assertStatus(409)->assertJsonPath('error.code', 'cup_duel_own');
    duelHttp($b, 'POST', "/cups/{$cup->id}/duels/{$first}/accept", ['expect_rated' => false])->assertStatus(409)
        ->assertJsonPath('error.code', 'cup_duel_rating_changed')->assertJsonPath('error.details.rated', true);
    duelHttp($b, 'POST', "/cups/{$cup->id}/duels/{$first}/accept", [])->assertStatus(422)->assertJsonValidationErrors('expect_rated');
    duelHttp($a, 'POST', "/cups/{$cup->id}/duels/{$first}/decline")->assertStatus(403)->assertJsonPath('error.code', 'cup_duel_not_recipient');
    // Addressed to b: c may neither accept nor see it - the answer of a missing duel.
    duelHttp($c, 'POST', "/cups/{$cup->id}/duels/{$first}/accept", ['expect_rated' => true])->assertStatus(404)->assertJsonPath('error.code', 'cup_duel_not_found');
    duelHttp($c, 'GET', "/cups/{$cup->id}/duels/{$first}")->assertStatus(404)->assertJsonPath('error.code', 'cup_duel_not_found');

    duelHttp($b, 'POST', "/cups/{$cup->id}/duels/{$first}/decline")->assertOk();
    duelHttp($b, 'POST', "/cups/{$cup->id}/duels/{$first}/accept", ['expect_rated' => true])->assertStatus(409)
        ->assertJsonPath('error.code', 'cup_duel_closed')->assertJsonPath('error.details.status', 'declined');
});

it('queues the DM of a private challenge to the one challenged and of an acceptance to the challenger, after the commit', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'participants' => [, $pb]] = CupDuelKit::running();

    $private = duelHttp($a, 'POST', "/cups/{$cup->id}/duels", duelChallengeBody($pb))->assertCreated()->json('id');
    duelHttp($a, 'POST', "/cups/{$cup->id}/duels", duelChallengeBody($pb))->assertStatus(409);
    Queue::assertPushed(SendCupDuelDmJob::class, 1);
    Queue::assertPushed(SendCupDuelDmJob::class, fn (SendCupDuelDmJob $job): bool => $job->kind === 'challenged' && $job->duelId === $private);

    duelHttp($b, 'POST', "/cups/{$cup->id}/duels/{$private}/accept", ['expect_rated' => true])->assertCreated();
    duelHttp($b, 'POST', "/cups/{$cup->id}/duels/{$private}/accept", ['expect_rated' => true])->assertOk();
    Queue::assertPushed(SendCupDuelDmJob::class, fn (SendCupDuelDmJob $job): bool => $job->kind === 'accepted' && $job->duelId === $private);
    Queue::assertPushed(SendCupDuelDmJob::class, 2);

    // A public challenge is the message in the chat, not a DM.
    duelHttp($c, 'POST', "/cups/{$cup->id}/duels", duelChallengeBody(public: true))->assertCreated();
    Queue::assertPushed(SendCupDuelDmJob::class, 2);
    Queue::assertPushed(PostCupMessageJob::class, 1);
});

// ------------------------------------------------------------------------------------------------------------------- the doors

/** Every route of the duels for a cup id and a duel id. @return list<array{0: string, 1: string, 2: array<string, mixed>}> */
function duelRoutes(int $cupId, int $duelId): array
{
    return [
        ['GET', "/cups/{$cupId}/duels", []],
        ['GET', "/cups/{$cupId}/duels/{$duelId}", []],
        ['POST', "/cups/{$cupId}/duels", duelChallengeBody()],
        ['POST', "/cups/{$cupId}/duels/{$duelId}/accept", ['expect_rated' => true]],
        ['POST', "/cups/{$cupId}/duels/{$duelId}/decline", []],
        ['POST', "/cups/{$cupId}/duels/{$duelId}/cancel", []],
    ];
}

it('answers a stranger, a participant who left and a missing cup the same 403 on every route, without asking Telegram', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [, $pb]] = CupDuelKit::running();
    $duel = CupDuelKit::challenge($a, $cup, $pb)->duel;
    $stranger = Learner::factory()->create();
    CupKit::member($stranger); // even a member of the chat who has not joined: a duel is between participants
    CupParticipant::query()->whereKey($pb->id)->update(['status' => 'left', 'left_at' => now()]);
    CupKit::telegram()->chatMemberLookups = [];

    $bodies = [];
    foreach ([[$stranger, $cup->id], [$b, $cup->id], [$a, 999_999]] as [$who, $cupId]) {
        foreach (duelRoutes($cupId, $duel->id) as [$method, $path, $body]) {
            $response = duelHttp($who, $method, $path, $body)->assertForbidden();
            $bodies[] = $response->getContent();
        }
    }

    expect(array_unique($bodies))->toHaveCount(1)
        ->and(json_decode((string) $bodies[0], true))->toBe(['error' => ['code' => 'cup_not_member', 'message' => 'The cup is open to the members of its chat only.']])
        ->and(CupKit::telegram()->chatMemberLookups)->toBe([])
        ->and(CupDuelKit::fresh($duel)->status->value)->toBe('open');
});

it('answers a participant the same 404 for a missing duel, a duel of another cup and a duel addressed to somebody else', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'participants' => [, $pb]] = CupDuelKit::running();
    $addressed = CupDuelKit::challenge($a, $cup, $pb)->duel;
    ['duel' => $foreign] = otherCupDuel($c);

    $bodies = [];
    foreach ([999_999, $foreign->id] as $duelId) {
        foreach (array_slice(duelRoutes($cup->id, $duelId), 1) as $i => [$method, $path, $body]) {
            if ($i === 1) {
                continue; // POST /duels is not about a duel
            }
            $bodies[] = duelHttp($c, $method, $path, $body)->assertNotFound()->getContent();
        }
    }
    $bodies[] = duelHttp($c, 'GET', "/cups/{$cup->id}/duels/{$addressed->id}")->assertNotFound()->getContent();
    $bodies[] = duelHttp($c, 'POST', "/cups/{$cup->id}/duels/{$addressed->id}/accept", ['expect_rated' => true])->assertNotFound()->getContent();
    $bodies[] = duelHttp($c, 'POST', "/cups/{$cup->id}/duels/{$addressed->id}/cancel")->assertNotFound()->getContent();

    expect(array_unique($bodies))->toHaveCount(1)
        ->and(json_decode((string) $bodies[0], true)['error']['code'])->toBe('cup_duel_not_found')
        ->and(DB::table('cup_duels')->where('id', $foreign->id)->value('status'))->toBe('open');
});

it('answers every route the same 409 while the duels are off - the cup, the duel, a stranger or not', function (array $config): void {
    ['cup' => $cup, 'learners' => [$a], 'participants' => [, $pb]] = CupDuelKit::running();
    $duel = CupDuelKit::challenge($a, $cup, $pb)->duel;
    $stranger = Learner::factory()->create();
    config($config);

    $bodies = [];
    foreach ([[$a, $cup->id, $duel->id], [$stranger, $cup->id, $duel->id], [$a, 999_999, 999_999]] as [$who, $cupId, $duelId]) {
        foreach (duelRoutes($cupId, $duelId) as [$method, $path, $body]) {
            $bodies[] = duelHttp($who, $method, $path, $body)->assertStatus(409)->getContent();
        }
    }

    expect(array_unique($bodies))->toHaveCount(1)
        ->and(json_decode((string) $bodies[0], true)['error']['code'])->toBe('cup_duel_disabled');
})->with([
    'CUP_DUELS off' => [['cup.duels' => false]],
    'battles off' => [['practice.battle.enabled' => false]],
    'practice off' => [['practice.access' => 'off']],
]);

it('answers 403 cup_unavailable with the cup switched off, and in a pilot to who got through the door', function (): void {
    ['cup' => $cup, 'learners' => [$a]] = CupDuelKit::running();

    config(['cup.access' => 'pilot']);
    duelHttp($a, 'GET', "/cups/{$cup->id}/duels")->assertForbidden()->assertJsonPath('error.code', 'cup_unavailable');
    duelHttp(Learner::factory()->create(), 'GET', "/cups/{$cup->id}/duels")->assertForbidden()->assertJsonPath('error.code', 'cup_not_member');

    config(['cup.access' => 'off']);
    duelHttp($a, 'POST', "/cups/{$cup->id}/duels", duelChallengeBody())->assertForbidden()->assertJsonPath('error.code', 'cup_unavailable');
});

it('tells a participant the reasons of the core: the pause, Telegram out', function (): void {
    ['cup' => $cup, 'learners' => [$a]] = CupDuelKit::running();
    Cache::forget(CupMembershipGate::key($cup->id, $a->id));
    DB::table('cup_participants')->where('learner_id', $a->id)->update(['membership_checked_at' => now()->subDays(3)]);
    CupKit::memberFails($a, new TelegramApiException('cURL error 28: Operation timed out'));

    duelHttp($a, 'POST', "/cups/{$cup->id}/duels", duelChallengeBody())->assertStatus(503)->assertJsonPath('error.code', 'cup_unverifiable');

    DB::table('telegram_groups')->where('id', $cup->telegram_group_id)->update(['bot_status' => 'kicked']);
    duelHttp($a, 'POST', "/cups/{$cup->id}/duels", duelChallengeBody())->assertStatus(409)->assertJsonPath('error.code', 'cup_paused');
});

// ------------------------------------------------------------------------------------------------------------------- the limiters

it('puts every route of the duels behind twa-api, both switches and its limiter', function (string $route, string $limiter): void {
    $middleware = Route::getRoutes()->getByName($route)?->gatherMiddleware() ?? [];

    expect($middleware)->toContain(EnsureCupAccess::class, EnsureCupDuels::class, TwaAuth::class, 'throttle:twa-api', "throttle:{$limiter}");
})->with([
    ['twa.cups.duels.index', 'twa-cup-read'],
    ['twa.cups.duels.show', 'twa-cup-duel-poll'],
    ['twa.cups.duels.store', 'twa-cup-duel'],
    ['twa.cups.duels.accept', 'twa-cup-duel'],
    ['twa.cups.duels.decline', 'twa-cup-duel'],
    ['twa.cups.duels.cancel', 'twa-cup-duel'],
]);

it('sizes the limiters of the duels from the config, keyed by the learner', function (string $name, string $key, int $default): void {
    $request = Request::create('/api/twa/cups/1/duels');
    $request->attributes->set('twa', ['learner_id' => 42]);
    $limit = RateLimiter::limiter($name)($request);
    expect($limit)->toBeInstanceOf(Limit::class)
        ->and($limit->maxAttempts)->toBe($default)
        ->and($limit->key)->toBe("{$name}|42");

    config([$key => 7]);
    expect(RateLimiter::limiter($name)($request)->maxAttempts)->toBe(7);
})->with([
    ['twa-cup-duel', 'cup.duel.http.actions_per_minute', 20],
    ['twa-cup-duel-poll', 'cup.duel.http.polls_per_minute', 40],
]);

it('cuts the actions of the duels at the limit of a minute, for that learner only', function (): void {
    config(['cup.duel.http.actions_per_minute' => 6]);
    ['cup' => $cup, 'learners' => [$a, $b]] = CupDuelKit::running();

    for ($i = 0; $i < 3; $i++) {
        $id = duelHttp($a, 'POST', "/cups/{$cup->id}/duels", duelChallengeBody())->assertCreated()->json('id');
        duelHttp($a, 'POST', "/cups/{$cup->id}/duels/{$id}/cancel")->assertOk();
    }
    duelHttp($a, 'POST', "/cups/{$cup->id}/duels", duelChallengeBody())->assertStatus(429);
    duelHttp($b, 'POST', "/cups/{$cup->id}/duels", duelChallengeBody())->assertCreated();
});
