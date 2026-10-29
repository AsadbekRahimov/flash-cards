<?php

declare(strict_types=1);

use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Practice\DTO\PracticeAccessState;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Exceptions\BattleException;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\PracticeAccess;
use App\Models\Learner;
use App\Models\PracticeSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * docs/16 §3.2-§3.3: who may practice (the flags, the pilot, the tariff), what the endpoints answer to the others, the block
 * `practice` of GET /me. The switches of a mode / of the battle block only the CREATION.
 */

uses(RefreshDatabase::class);

/** The learner in the pilot / not, on Free / Pro. */
function accessLearner(bool $pilot, bool $pro): Learner
{
    $learner = Learner::factory()->create(['practice_pilot_at' => $pilot ? now() : null]);

    return $pro ? PracticeKit::pro($learner) : $learner;
}

it('decides the state by the flags, the pilot and the plan, in that order', function (string $access, string $plan, bool $pilot, bool $pro, string $expected): void {
    config(['practice.access' => $access, 'practice.plan' => $plan]);

    expect(app(PracticeAccess::class)->state(accessLearner($pilot, $pro))->code)->toBe($expected);
})->with([
    // access = off: nobody, whatever the pilot and the plan are
    'off, in pilot, pro' => ['off', 'all', true, true, 'off'],
    'off, plan pro, free' => ['off', 'pro', false, false, 'off'],
    // access = pilot: only the learners with practice_pilot_at
    'pilot, not in it' => ['pilot', 'all', false, false, 'not_in_pilot'],
    'pilot, not in it, plan pro, free' => ['pilot', 'pro', false, false, 'not_in_pilot'],
    'pilot, in it' => ['pilot', 'all', true, false, 'available'],
    'pilot, in it, plan pro, free' => ['pilot', 'pro', true, false, 'pro_required'],
    'pilot, in it, plan pro, pro' => ['pilot', 'pro', true, true, 'available'],
    // access = all
    'all, free' => ['all', 'all', false, false, 'available'],
    'all, pro' => ['all', 'all', false, true, 'available'],
    'all, plan pro, free' => ['all', 'pro', false, false, 'pro_required'],
    'all, plan pro, pro' => ['all', 'pro', false, true, 'available'],
    // a typo in the .env does not open the practice to everybody; the case and the spaces do not matter
    'unknown access = off' => ['everybody', 'all', true, true, 'off'],
    'empty access = off' => ['', 'all', true, true, 'off'],
    'case and spaces' => [' ALL ', ' Pro ', false, false, 'pro_required'],
    'unknown plan = all' => ['all', 'premium', false, false, 'available'],
]);

it('refuses with practice_unavailable (off, not in the pilot) or practice_pro_required (the plan)', function (): void {
    $access = app(PracticeAccess::class);

    config(['practice.access' => 'off']);
    expect(fn () => $access->assertCanPractice(accessLearner(true, true)))
        ->toThrow(fn (PracticeException $e) => expect($e->errorCode)->toBe('practice_unavailable')->and($e->httpStatus())->toBe(403));

    config(['practice.access' => 'pilot']);
    expect(fn () => $access->assertCanPractice(accessLearner(false, true)))
        ->toThrow(fn (PracticeException $e) => expect($e->errorCode)->toBe('practice_unavailable')); // the same code: no hint that a pilot exists

    config(['practice.access' => 'all', 'practice.plan' => 'pro']);
    expect(fn () => $access->assertCanPractice(accessLearner(false, false)))
        ->toThrow(fn (PracticeException $e) => expect($e->errorCode)->toBe('practice_pro_required')->and($e->httpStatus())->toBe(403));

    $access->assertCanPractice(accessLearner(false, true)); // does not throw
    expect(true)->toBeTrue();
});

it('reads the plan of the learner from the cached entitlement only when the plan flag asks for it', function (): void {
    $learner = Learner::factory()->create();
    $access = app(PracticeAccess::class);

    config(['practice.access' => 'all', 'practice.plan' => 'all']);
    expect(QueryLog::during(fn () => $access->state($learner)))->toBe([]);

    config(['practice.plan' => 'pro']);
    $access->state($learner); // warms the cache of the entitlement
    expect(QueryLog::during(fn () => $access->state($learner)))->toBe([]);
});

it('says in GET /me what the learner sees: the flags of the modes and of the battle', function (): void {
    $learner = Learner::factory()->create();
    $headers = PracticeKit::headers($learner);

    config(['practice.access' => 'off']);
    $this->withHeaders($headers)->getJson('/api/twa/me')->assertOk()
        ->assertJsonPath('practice', ['available' => false, 'pro_required' => false, 'modes' => ['pairs', 'collocation', 'sentence', 'dictation'], 'battle' => true]);

    config(['practice.access' => 'all', 'practice.modes.sentence' => false, 'practice.battle.enabled' => false]);
    $this->withHeaders($headers)->getJson('/api/twa/me')->assertOk()
        ->assertJsonPath('practice', ['available' => true, 'pro_required' => false, 'modes' => ['pairs', 'collocation', 'dictation'], 'battle' => false]);

    // A Free learner at plan = pro still SEES the entries (with a Pro chip): available + pro_required.
    config(['practice.plan' => 'pro']);
    $this->withHeaders($headers)->getJson('/api/twa/me')->assertOk()
        ->assertJsonPath('practice.available', true)
        ->assertJsonPath('practice.pro_required', true);

    // The plan is bought: pro_required goes.
    PracticeKit::pro($learner);
    $this->withHeaders($headers)->getJson('/api/twa/me')->assertOk()
        ->assertJsonPath('practice.available', true)
        ->assertJsonPath('practice.pro_required', false);

    // Not in the pilot: the entries are hidden.
    config(['practice.access' => 'pilot']);
    $outsider = Learner::factory()->create();
    $this->withHeaders(PracticeKit::headers($outsider))->getJson('/api/twa/me')->assertOk()
        ->assertJsonPath('practice.available', false);
    $insider = Learner::factory()->create(['practice_pilot_at' => now()]);
    $this->withHeaders(PracticeKit::headers($insider))->getJson('/api/twa/me')->assertOk()
        ->assertJsonPath('practice.available', true);
});

it('answers every endpoint of the practice with 403 practice_unavailable while the practice is off or the learner is not in the pilot', function (string $access): void {
    PracticeKit::openAccess();
    [$course] = PracticeKit::course();
    $learner = Learner::factory()->create();
    $session = PracticeSession::factory()->create(['learner_id' => $learner->id, 'course_id' => $course->id]);
    config(['practice.access' => $access]);

    $headers = PracticeKit::headers($learner);
    $requestId = '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d';
    $responses = [
        $this->withHeaders($headers)->getJson('/api/twa/practice/hub?course=english-a1'),
        $this->withHeaders($headers)->postJson('/api/twa/practice/sessions', ['mode' => 'pairs', 'course' => 'english-a1']),
        $this->withHeaders($headers)->getJson("/api/twa/practice/sessions/{$session->id}"),
        $this->withHeaders($headers)->postJson("/api/twa/practice/sessions/{$session->id}/answer", ['request_id' => $requestId, 'element_id' => 'eAAAAAAAAAA', 'answer' => ['skip' => true]]),
        $this->withHeaders($headers)->postJson("/api/twa/practice/sessions/{$session->id}/replace", ['request_id' => $requestId, 'element_id' => 'eAAAAAAAAAA', 'reason' => 'audio_unavailable']),
        $this->withHeaders($headers)->get("/api/twa/practice/sessions/{$session->id}/audio/eAAAAAAAAAA"),
    ];

    foreach ($responses as $response) {
        $response->assertForbidden()->assertJsonPath('error.code', 'practice_unavailable');
    }
})->with(['off', 'pilot']);

it('answers the paywall code to a Free learner at plan = pro: 403 practice_pro_required everywhere but the hub', function (): void {
    PracticeKit::openAccess();
    [$course] = PracticeKit::course();
    $learner = Learner::factory()->create();
    $session = PracticeSession::factory()->create(['learner_id' => $learner->id, 'course_id' => $course->id]);
    config(['practice.plan' => 'pro']);

    $headers = PracticeKit::headers($learner);
    $requestId = '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d';
    foreach ([
        $this->withHeaders($headers)->postJson('/api/twa/practice/sessions', ['mode' => 'pairs', 'course' => 'english-a1']),
        $this->withHeaders($headers)->getJson("/api/twa/practice/sessions/{$session->id}"),
        $this->withHeaders($headers)->postJson("/api/twa/practice/sessions/{$session->id}/answer", ['request_id' => $requestId, 'element_id' => 'eAAAAAAAAAA', 'answer' => ['skip' => true]]),
        $this->withHeaders($headers)->get("/api/twa/practice/sessions/{$session->id}/audio/eAAAAAAAAAA"),
    ] as $response) {
        $response->assertForbidden()->assertJsonPath('error.code', 'practice_pro_required');
    }

    // The hub itself loads: its entries carry the Pro chip (`state: pro_required`), the tap opens the paywall.
    $hub = $this->withHeaders($headers)->getJson('/api/twa/practice/hub?course=english-a1')->assertOk();
    expect(array_column($hub->json('modes'), 'state'))->toBe(['pro_required', 'pro_required', 'pro_required', 'pro_required']);

    PracticeKit::pro($learner);
    $this->withHeaders($headers)->getJson("/api/twa/practice/sessions/{$session->id}")->assertOk();
});

it('blocks only the CREATION of a session of a switched-off mode: the open one is read, answered and finished', function (): void {
    PracticeKit::openAccess();
    PracticeKit::fake(PracticeMode::Collocation, candidates: 4);
    PracticeKit::fake(PracticeMode::Sentence, candidates: 4);
    [$course] = PracticeKit::course();
    $learner = Learner::factory()->create();
    $headers = PracticeKit::headers($learner);

    $session = $this->withHeaders($headers)->postJson('/api/twa/practice/sessions', ['mode' => 'collocation', 'course' => $course->slug])
        ->assertCreated()->json();
    config(['practice.modes.collocation' => false]);

    // Reading and answering go on ...
    $this->withHeaders($headers)->getJson("/api/twa/practice/sessions/{$session['id']}")->assertOk()->assertJsonPath('mode', 'collocation');
    $this->withHeaders($headers)->postJson("/api/twa/practice/sessions/{$session['id']}/answer", [
        'request_id' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d',
        'element_id' => $session['current']['id'],
        'answer' => ['skip' => true],
    ])->assertOk();

    // ... but a new session of that mode is refused, and the hub shows the mode as switched off.
    $this->withHeaders($headers)->postJson('/api/twa/practice/sessions', ['mode' => 'collocation', 'course' => $course->slug, 'replace_active' => true])
        ->assertStatus(409)->assertJsonPath('error.code', 'practice_mode_disabled')->assertJsonPath('error.details.mode', 'collocation');
    $modes = $this->withHeaders($headers)->getJson("/api/twa/practice/hub?course={$course->slug}")->assertOk()->json('modes');
    expect(array_column($modes, 'state', 'mode')['collocation'])->toBe('disabled');

    // The session that is on was not touched by the refused attempt.
    expect(PracticeSession::query()->where('learner_id', $learner->id)->where('status', 'active')->count())->toBe(1);
});

it('gates the battle by its own switch and by the access of the friend', function (): void {
    $access = app(PracticeAccess::class);

    config(['practice.battle.enabled' => true]);
    $access->assertBattleEnabled();
    config(['practice.battle.enabled' => false]);
    expect(fn () => $access->assertBattleEnabled())
        ->toThrow(fn (BattleException $e) => expect($e->errorCode)->toBe('battle_disabled')->and($e->httpStatus())->toBe(409));

    config(['practice.access' => 'pilot']);
    $access->assertFriendCanPractice(accessLearner(true, false));
    expect(fn () => $access->assertFriendCanPractice(accessLearner(false, false)))
        ->toThrow(fn (BattleException $e) => expect($e->errorCode)->toBe('battle_friend_no_access')->and($e->httpStatus())->toBe(409));

    // A friend who would meet the paywall cannot play either.
    config(['practice.access' => 'all', 'practice.plan' => 'pro']);
    expect(fn () => $access->assertFriendCanPractice(accessLearner(false, false)))->toThrow(BattleException::class);
    $access->assertFriendCanPractice(accessLearner(false, true));
});

it('checks the switch of a mode only where something is created', function (): void {
    $access = app(PracticeAccess::class);

    $access->assertModeEnabled(PracticeMode::Dictation);
    config(['practice.modes.dictation' => false]);
    expect(fn () => $access->assertModeEnabled(PracticeMode::Dictation))
        ->toThrow(fn (PracticeException $e) => expect($e->errorCode)->toBe('practice_mode_disabled')->and($e->details)->toBe(['mode' => 'dictation'])->and($e->httpStatus())->toBe(409));
    $access->assertModeEnabled(PracticeMode::Pairs);
});

it('describes the state with the entitlement it is given, without asking again', function (): void {
    config(['practice.access' => 'all', 'practice.plan' => 'pro']);
    $learner = Learner::factory()->create();
    $free = app(EntitlementResolver::class)->for($learner);

    expect(QueryLog::during(fn () => app(PracticeAccess::class)->describe($learner, $free)))->toBe([])
        ->and(app(PracticeAccess::class)->describe($learner, $free))->toBe(['available' => true, 'pro_required' => true, 'modes' => ['pairs', 'collocation', 'sentence', 'dictation'], 'battle' => true])
        ->and(PracticeAccessState::proRequired()->showsEntries())->toBeTrue()
        ->and(PracticeAccessState::off()->showsEntries())->toBeFalse();
});
