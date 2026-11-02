<?php

declare(strict_types=1);

use App\Domain\Operations\Support\CacheCircuitBreaker;
use Tests\Support\PureCacheStandIn;

/*
 * The protocol of the breaker on its marker file (docs/19 §4.1), with two instances over the same directory standing for
 * two processes of the host (php-fpm workers, a queue worker): one opening per outage, one prober at a time, a window that
 * ends, a probe that closes it. Time travels, the file carries whole seconds.
 */

const BREAKER_STORE = 'lru_redis';

beforeEach(function (): void {
    $this->dir = PureCacheStandIn::markerDirectory();
    $this->a = new CacheCircuitBreaker($this->dir, 10);
    $this->b = new CacheCircuitBreaker($this->dir, 10);
});

afterEach(function (): void {
    PureCacheStandIn::removeDirectory($this->dir);
});

it('is closed without a marker and stays closed after a success', function (): void {
    expect($this->a->allows(BREAKER_STORE))->toBeTrue()
        ->and($this->a->succeeded(BREAKER_STORE))->toBeFalse()
        ->and($this->a->allows(BREAKER_STORE))->toBeTrue()
        ->and(file_exists($this->a->markerPath(BREAKER_STORE)))->toBeFalse();
});

it('opens on the first failure for exactly one process and keeps every process out for the window', function (): void {
    expect($this->a->allows(BREAKER_STORE))->toBeTrue()
        ->and($this->b->allows(BREAKER_STORE))->toBeTrue(); // both were in flight when the instance hung

    expect($this->a->failed(BREAKER_STORE))->toBeTrue('the first failure opens it and reports')
        ->and($this->b->failed(BREAKER_STORE))->toBeFalse('the second failure finds it open: nothing to report')
        ->and(file_exists($this->a->markerPath(BREAKER_STORE)))->toBeTrue();

    $c = new CacheCircuitBreaker($this->dir, 10); // a process that starts now
    expect($this->a->allows(BREAKER_STORE))->toBeFalse()
        ->and($this->b->allows(BREAKER_STORE))->toBeFalse()
        ->and($c->allows(BREAKER_STORE))->toBeFalse();

    $this->travel(9)->seconds();
    expect($this->a->allows(BREAKER_STORE))->toBeFalse()->and($c->allows(BREAKER_STORE))->toBeFalse();
});

it('lets one process probe after the window while the others keep skipping, and a failed probe opens another window', function (): void {
    $this->a->allows(BREAKER_STORE);
    $this->a->failed(BREAKER_STORE);
    $openedAt = filemtime($this->a->markerPath(BREAKER_STORE));

    $this->travel(10)->seconds();
    expect($this->a->allows(BREAKER_STORE))->toBeTrue('the window is over: the first to look probes')
        ->and($this->b->allows(BREAKER_STORE))->toBeFalse('while the probe is in flight the others skip')
        ->and($this->a->failed(BREAKER_STORE))->toBeTrue('a failed probe re-opens and reports again')
        ->and(filemtime($this->a->markerPath(BREAKER_STORE)))->toBe($openedAt + 10)
        ->and($this->a->allows(BREAKER_STORE))->toBeFalse();

    $this->travel(1)->seconds();
    expect($this->b->allows(BREAKER_STORE))->toBeFalse('a fresh window for everybody');
});

it('closes when a probe succeeds: the marker is gone and every process is back on the member', function (): void {
    $this->a->allows(BREAKER_STORE);
    $this->a->failed(BREAKER_STORE);
    $this->b->allows(BREAKER_STORE);

    $this->travel(10)->seconds();
    expect($this->b->allows(BREAKER_STORE))->toBeTrue()
        ->and($this->a->allows(BREAKER_STORE))->toBeFalse()
        ->and($this->b->succeeded(BREAKER_STORE))->toBeTrue('the probe that closes it reports the recovery')
        ->and(file_exists($this->b->markerPath(BREAKER_STORE)))->toBeFalse()
        ->and($this->b->allows(BREAKER_STORE))->toBeTrue();

    // A's memory of the window ends with it: it looks at the file again and finds none.
    $this->travel(1)->seconds();
    expect($this->a->allows(BREAKER_STORE))->toBeTrue()->and($this->a->succeeded(BREAKER_STORE))->toBeFalse();
});

it('renews a stale marker nobody probes - a prober that died - from the next failure', function (): void {
    $this->a->allows(BREAKER_STORE);
    $this->a->failed(BREAKER_STORE);

    $this->travel(30)->seconds();
    // B looked before the window ended (its memory says open), then its own call failed after the window.
    expect($this->b->failed(BREAKER_STORE))->toBeTrue()
        ->and(filemtime($this->b->markerPath(BREAKER_STORE)))->toBe(now()->getTimestamp())
        ->and($this->a->allows(BREAKER_STORE))->toBeFalse();
});

it('keeps a window per process and still reports when the marker directory cannot be written', function (): void {
    $broken = new CacheCircuitBreaker('/proc/lexiflow-no-such-dir/circuits', 10);

    expect($broken->allows(BREAKER_STORE))->toBeTrue()
        ->and($broken->failed(BREAKER_STORE))->toBeTrue()
        ->and($broken->allows(BREAKER_STORE))->toBeFalse();

    $this->travel(10)->seconds();
    expect($broken->allows(BREAKER_STORE))->toBeTrue()
        ->and($broken->failed(BREAKER_STORE))->toBeTrue('no shared marker: every process reports its own failures');
});

it('guards every store by its own marker and forgets one on reset', function (): void {
    $this->a->allows('one');
    $this->a->failed('one');

    expect($this->a->allows('one'))->toBeFalse()
        ->and($this->a->allows('two'))->toBeTrue()
        ->and($this->a->markerPath('one'))->toBe($this->dir.DIRECTORY_SEPARATOR.'one');

    $this->a->reset('one');
    expect($this->a->allows('one'))->toBeTrue()->and(file_exists($this->a->markerPath('one')))->toBeFalse();
});
