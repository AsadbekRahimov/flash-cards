<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureCupAccess;
use App\Http\Middleware\TwaAuth;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\Support\Cup\CupKit;
use Tests\Support\Practice\PracticeKit;

/*
 * docs/07 §6, docs/33 §7.2 (K7): the limiters of the routes of the cup, per learner, on top of `twa-api` - the reads that may call
 * Telegram 30 a minute, joining and the start 10, an answer 90, the administrator 10.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CupKit::open();
    CupKit::course();
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('puts every route of the cup behind twa-api, the switch and its own limiter', function (string $route, ?string $limiter): void {
    $middleware = Route::getRoutes()->getByName($route)?->gatherMiddleware() ?? [];

    expect($middleware)->toContain(EnsureCupAccess::class, TwaAuth::class);
    if ($limiter !== null) {
        expect($middleware)->toContain("throttle:{$limiter}");
    }
    if ($route !== 'twa.cup-rounds.audio') {
        expect($middleware)->toContain('throttle:twa-api');
    }
})->with([
    ['twa.cups.index', null],
    ['twa.cups.invite', 'twa-cup-read'],
    ['twa.cups.show', 'twa-cup-read'],
    ['twa.cups.today', 'twa-cup-read'],
    ['twa.cups.join', 'twa-cup-join'],
    ['twa.cups.rename', 'twa-cup-join'],
    ['twa.cups.leave', 'twa-cup-join'],
    ['twa.cups.start', 'twa-cup-start'],
    ['twa.cup-rounds.show', null],
    ['twa.cup-rounds.answer', 'twa-cup-answer'],
    ['twa.cup-rounds.audio', 'twa-practice-audio'],
    ['twa.cups.setup', 'twa-cup-admin'],
    ['twa.cups.publish', 'twa-cup-admin'],
    ['twa.cups.settings', 'twa-cup-admin'],
    ['twa.cups.cancel', 'twa-cup-admin'],
]);

it('sizes the limiters as docs/07 §6 says, keyed by the learner', function (string $name, int $perMinute): void {
    $request = Request::create('/api/twa/cups/1');
    $request->attributes->set('twa', ['learner_id' => 42]);
    $limit = RateLimiter::limiter($name)($request);

    expect($limit)->toBeInstanceOf(Limit::class)
        ->and($limit->maxAttempts)->toBe($perMinute)
        ->and($limit->decaySeconds)->toBe(60)
        ->and($limit->key)->toBe("{$name}|42");
})->with([
    ['twa-cup-read', 30],
    ['twa-cup-join', 10],
    ['twa-cup-start', 10],
    ['twa-cup-answer', 90],
    ['twa-cup-admin', 10],
]);

it('cuts the start of the round at 10 a minute and the table at 30, for that learner only', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b]] = CupKit::running(2);
    $headers = PracticeKit::headers($a);

    for ($i = 0; $i < 10; $i++) {
        test()->withHeaders($headers)->postJson("/api/twa/cups/{$cup->id}/rounds/today")->assertSuccessful();
    }
    test()->withHeaders($headers)->postJson("/api/twa/cups/{$cup->id}/rounds/today")->assertStatus(429);
    test()->withHeaders(PracticeKit::headers($b))->postJson("/api/twa/cups/{$cup->id}/rounds/today")->assertCreated();

    for ($i = 0; $i < 30; $i++) {
        test()->withHeaders($headers)->getJson("/api/twa/cups/{$cup->id}")->assertOk();
    }
    test()->withHeaders($headers)->getJson("/api/twa/cups/{$cup->id}")->assertStatus(429);
});

it('cuts a stranger probing the codes of the links at 30 a minute', function (): void {
    $stranger = Learner::factory()->create();
    CupKit::member($stranger, 'left', false);
    $headers = PracticeKit::headers($stranger);

    for ($i = 0; $i < 30; $i++) {
        test()->withHeaders($headers)->getJson('/api/twa/cups/by-code/'.str_pad((string) $i, 22, 'x'))->assertForbidden();
    }
    test()->withHeaders($headers)->getJson('/api/twa/cups/by-code/'.str_repeat('y', 22))->assertStatus(429);
});
