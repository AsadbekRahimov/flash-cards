<?php

declare(strict_types=1);

use App\Domain\Operations\Services\QueueHeartbeat;
use App\Domain\Operations\Services\SchedulerHeartbeat;
use App\Jobs\QueueHeartbeatJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Heartbeats live in the cache store, the ShouldBeUnique locks of QueueHeartbeatJob in the cache *lock*
    // connection (redis: a separate DB, not touched by flush). With a persistent store (redis) they would
    // outlive the test (uniqueFor = 300 s) and make the next run see a held lock and push nothing.
    Cache::flush();
    foreach (['high', 'low'] as $queue) {
        Cache::lock('laravel_unique_job:'.QueueHeartbeatJob::class.':'.$queue)->forceRelease();
    }
});

it('returns 200 with ok checks when dependencies are healthy', function (): void {
    app(SchedulerHeartbeat::class)->beat();

    $this->getJson('/health/ready')
        ->assertOk()
        ->assertExactJson([
            'status' => 'ok',
            'checks' => ['db' => 'ok', 'scheduler' => 'ok', 'queue' => 'ok'] + (config('cache.stores.'.config('cache.default').'.driver') === 'redis' ? ['redis' => 'ok'] : []),
        ]);
});

it('keeps the framework /up endpoint working', function (): void {
    $this->get('/up')->assertOk();
});

it('returns 503 when the scheduler heartbeat is stale', function (): void {
    app(SchedulerHeartbeat::class)->beat();
    $this->travel(4)->minutes();

    $this->getJson('/health/ready')
        ->assertStatus(503)
        ->assertJsonPath('status', 'fail')
        ->assertJsonPath('checks.scheduler', 'fail')
        ->assertJsonPath('checks.db', 'ok');
});

it('returns 503 when the scheduler never wrote a heartbeat', function (): void {
    $this->getJson('/health/ready')
        ->assertStatus(503)
        ->assertJsonPath('checks.scheduler', 'fail');
});

it('returns 503 when a queue backlog exceeds the threshold', function (): void {
    app(SchedulerHeartbeat::class)->beat();
    Config::set('operations.readiness.queue_max_size', -1);

    $this->getJson('/health/ready')
        ->assertStatus(503)
        ->assertJsonPath('checks.queue', 'fail');
});

it('does not leak exception details when a check throws', function (): void {
    app(SchedulerHeartbeat::class)->beat();

    DB::partialMock()
        ->shouldReceive('select')
        ->andThrow(new RuntimeException('SQLSTATE[08006] could not connect to server db.internal.example:5432 password=hunter2'));

    $response = $this->getJson('/health/ready')
        ->assertStatus(503)
        ->assertJsonPath('status', 'fail')
        ->assertJsonPath('checks.db', 'fail');

    $body = (string) $response->getContent();
    expect($body)->not->toContain('SQLSTATE')
        ->and($body)->not->toContain('db.internal')
        ->and($body)->not->toContain('hunter2')
        ->and($body)->not->toContain('RuntimeException');
});

it('does not set session cookies on the readiness endpoint', function (): void {
    app(SchedulerHeartbeat::class)->beat();

    $this->getJson('/health/ready')->assertOk()->assertCookieMissing(config('session.cookie'));
});

it('is throttled', function (): void {
    app(SchedulerHeartbeat::class)->beat();

    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/health/ready')->assertOk();
    }

    $this->getJson('/health/ready')->assertStatus(429);
});

it('registers the scheduler heartbeat to run every minute', function (): void {
    $event = collect(Schedule::events())
        ->first(fn ($e): bool => $e->description === 'ops:scheduler-heartbeat');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *');
});

it('reads a heartbeat stored as a numeric string (Redis store)', function (): void {
    Cache::put(SchedulerHeartbeat::CACHE_KEY, (string) time(), 60);

    $beat = app(SchedulerHeartbeat::class)->lastBeatAt();

    expect($beat)->not->toBeNull()
        ->and($beat?->getTimestamp())->toBeGreaterThan(time() - 5);
});

it('ignores a garbage heartbeat value', function (): void {
    Cache::put(SchedulerHeartbeat::CACHE_KEY, 'not-a-timestamp', 60);

    expect(app(SchedulerHeartbeat::class)->lastBeatAt())->toBeNull();
});

describe('queue worker heartbeat', function (): void {
    beforeEach(function (): void {
        // A non-sync driver, so the worker heartbeat applies; the backlog check reads the `jobs` table.
        Config::set('queue.default', 'database');
        Config::set('operations.readiness.heartbeat_queues', ['high', 'low']);
        app(SchedulerHeartbeat::class)->beat();
    });

    it('is not ready until every monitored queue has a heartbeat', function (): void {
        app(QueueHeartbeat::class)->beat('high');

        $this->getJson('/health/ready')->assertStatus(503)->assertJsonPath('checks.queue', 'fail');
    });

    it('is ready when all heartbeats are fresh', function (): void {
        app(QueueHeartbeat::class)->beat('high');
        app(QueueHeartbeat::class)->beat('low');

        $this->getJson('/health/ready')->assertOk()->assertJsonPath('checks.queue', 'ok');
    });

    it('fails when a heartbeat is older than the limit', function (): void {
        app(QueueHeartbeat::class)->beat('high');
        app(QueueHeartbeat::class)->beat('low');
        $this->travel(6)->minutes();
        app(SchedulerHeartbeat::class)->beat();
        app(QueueHeartbeat::class)->beat('high');

        $this->getJson('/health/ready')->assertStatus(503)->assertJsonPath('checks.queue', 'fail');
    });

    it('ignores heartbeats for the sync driver', function (): void {
        Config::set('queue.default', 'sync');

        $this->getJson('/health/ready')->assertOk()->assertJsonPath('checks.queue', 'ok');
    });

    it('records the heartbeat only when the job runs', function (): void {
        expect(app(QueueHeartbeat::class)->lastBeatAt('low'))->toBeNull();

        (new QueueHeartbeatJob('low'))->handle(app(QueueHeartbeat::class));

        expect(app(QueueHeartbeat::class)->lastBeatAt('low'))->not->toBeNull()
            ->and(app(QueueHeartbeat::class)->lastBeatAt('high'))->toBeNull();
    });

    it('schedules a heartbeat job per monitored queue every minute', function (): void {
        Queue::fake();

        $event = collect(Schedule::events())->first(fn ($e): bool => $e->description === 'ops:queue-heartbeat');
        expect($event)->not->toBeNull()->and($event->expression)->toBe('* * * * *');

        $event->run(app());

        Queue::assertPushedOn('high', QueueHeartbeatJob::class);
        Queue::assertPushedOn('low', QueueHeartbeatJob::class);
    });
});

describe('production config check', function (): void {
    it('fails readiness in production when TELEGRAM_BOT_USERNAME is empty', function (): void {
        app()->detectEnvironment(fn (): string => 'production');
        Config::set('telegram.bot_username', '');
        app(SchedulerHeartbeat::class)->beat();

        $this->getJson('/health/ready')->assertStatus(503)->assertJsonPath('checks.config', 'fail');
    });

    it('is ready in production once the bot username is set', function (): void {
        app()->detectEnvironment(fn (): string => 'production');
        Config::set('telegram.bot_username', 'lexi_bot');
        app(SchedulerHeartbeat::class)->beat();

        $this->getJson('/health/ready')->assertOk()->assertJsonPath('checks.config', 'ok');
    });

    it('does not check it outside production', function (): void {
        Config::set('telegram.bot_username', '');
        app(SchedulerHeartbeat::class)->beat();

        $this->getJson('/health/ready')->assertOk()->assertJsonMissingPath('checks.config');
    });
});
