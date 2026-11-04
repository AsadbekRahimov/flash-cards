<?php

declare(strict_types=1);

/*
 * `throttle:*` in one Redis command per limiter (App\Http\Middleware\AtomicThrottle, docs/19 §5.1 R12i). The limits mean
 * what they meant: N requests in the window counted from the first hit, the (N+1)th is 429 with `Retry-After` and the
 * `X-RateLimit-*` headers, a refused request is not counted, the window resets after the decay. The keys and the values
 * are those of Laravel's RateLimiter, so `RateLimiter::hit()` (the way the suite exhausts a limiter) and `clear()` act on
 * the same counters. On a limiter store that is not Redis the parent middleware does the work, with the same answers.
 */

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

/** The test routes: three a minute by IP, and two a second by IP (the window reset without waiting a minute). */
function throttleRoutes(): void
{
    RateLimiter::for('r12i-three', fn (Request $request): Limit => Limit::perMinute(3)->by('r12i-three|'.$request->ip()));
    RateLimiter::for('r12i-burst', fn (Request $request): Limit => Limit::perSecond(2)->by('r12i-burst|'.$request->ip()));
    Route::middleware('throttle:r12i-three')->get('/_r12i/three', fn (): array => ['ok' => true]);
    Route::middleware('throttle:r12i-burst')->get('/_r12i/burst', fn (): array => ['ok' => true]);
}

function throttleKey(string $limiter, string $ip = '127.0.0.1'): string
{
    return md5($limiter.$limiter.'|'.$ip);
}

/** Whether the limiter store of this run is a reachable Redis (CI and the PostgreSQL runs); the sqlite run is on `array`. */
function throttleOnRedis(): bool
{
    if (! extension_loaded('redis') || env('CACHE_LIMITER_STORE') !== 'redis') {
        return false;
    }
    $socket = @fsockopen((string) env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379), $errno, $errstr, 0.2);
    if ($socket === false) {
        return false;
    }
    fclose($socket);

    return true;
}

beforeEach(function (): void {
    throttleRoutes();
    RateLimiter::clear(throttleKey('r12i-three'));
    RateLimiter::clear(throttleKey('r12i-burst'));
});

it('lets the limit through, counts the remaining attempts down and refuses the next request with Retry-After', function (): void {
    foreach ([2, 1, 0] as $remaining) {
        $this->getJson('/_r12i/three')->assertOk()
            ->assertHeader('X-RateLimit-Limit', '3')
            ->assertHeader('X-RateLimit-Remaining', (string) $remaining);
    }

    $refused = $this->getJson('/_r12i/three')->assertStatus(429)->assertHeader('X-RateLimit-Remaining', '0');
    $retryAfter = (int) $refused->headers->get('Retry-After');
    expect($retryAfter)->toBeGreaterThan(0)->toBeLessThanOrEqual(60)
        ->and((int) RateLimiter::attempts(throttleKey('r12i-three')))->toBe(3); // the refusal is not counted

    $this->getJson('/_r12i/three')->assertStatus(429);
    expect((int) RateLimiter::attempts(throttleKey('r12i-three')))->toBe(3);
});

it('shares the counters with the RateLimiter: hits made through it are refused by the middleware, clear() opens it again', function (): void {
    for ($i = 0; $i < 3; $i++) {
        RateLimiter::hit(throttleKey('r12i-three'), 60);
    }
    $this->getJson('/_r12i/three')->assertStatus(429)->assertHeader('Retry-After');

    RateLimiter::clear(throttleKey('r12i-three'));
    $this->getJson('/_r12i/three')->assertOk()->assertHeader('X-RateLimit-Remaining', '2');
    expect((int) RateLimiter::attempts(throttleKey('r12i-three')))->toBe(1)
        ->and(RateLimiter::availableIn(throttleKey('r12i-three')))->toBeGreaterThan(0);
});

it('opens the window again once the decay has passed', function (): void {
    $this->getJson('/_r12i/burst')->assertOk();
    $this->getJson('/_r12i/burst')->assertOk()->assertHeader('X-RateLimit-Remaining', '0');
    $this->getJson('/_r12i/burst')->assertStatus(429);

    usleep(1_200_000);

    $this->getJson('/_r12i/burst')->assertOk()->assertHeader('X-RateLimit-Remaining', '1');
});

it('spends exactly one Redis command on a limiter, whether the request is allowed or refused', function (): void {
    $commands = [];
    Event::listen(CommandExecuted::class, function (CommandExecuted $e) use (&$commands): void {
        $commands[] = $e->command;
    });
    Redis::enableEvents();
    foreach (Redis::connections() as $connection) {
        $connection->setEventDispatcher(app('events'));
    }

    foreach ([200, 200, 200, 429] as $status) {
        $commands = [];
        $this->getJson('/_r12i/three')->assertStatus($status);
        expect($commands)->toBe(['eval']);
    }
})->skip(! throttleOnRedis(), 'a Redis limiter store (CACHE_LIMITER_STORE=redis, reachable) is required');

/** Two limits on one route, as `twa-promo` (per learner and per IP): the tight one is the second. */
function twoLimitKeys(): array
{
    RateLimiter::for('r12j-two', fn (Request $request): array => [
        Limit::perMinute(10)->by('r12j-a|'.$request->ip()),
        Limit::perMinute(2)->by('r12j-b|'.$request->ip()),
    ]);
    Route::middleware('throttle:r12j-two')->get('/_r12j/two', fn (): array => ['ok' => true]);
    $keys = [md5('r12j-two'.'r12j-a|127.0.0.1'), md5('r12j-two'.'r12j-b|127.0.0.1')];
    foreach ($keys as $key) {
        RateLimiter::clear($key);
    }

    return $keys;
}

it('does not spend the first limit when the second one refuses', function (): void {
    [$a, $b] = twoLimitKeys();

    $this->getJson('/_r12j/two')->assertOk();
    $this->getJson('/_r12j/two')->assertOk();
    expect((int) RateLimiter::attempts($a))->toBe(2)->and((int) RateLimiter::attempts($b))->toBe(2);

    $this->getJson('/_r12j/two')->assertStatus(429)->assertHeader('Retry-After');
    $this->getJson('/_r12j/two')->assertStatus(429);

    expect((int) RateLimiter::attempts($a))->toBe(2)->and((int) RateLimiter::attempts($b))->toBe(2);
});
