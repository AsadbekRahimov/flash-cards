<?php

declare(strict_types=1);

use App\Domain\Operations\Services\SchedulerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

uses(RefreshDatabase::class);

it('returns 200 with ok checks when dependencies are healthy', function (): void {
    app(SchedulerHeartbeat::class)->beat();

    $this->getJson('/health/ready')
        ->assertOk()
        ->assertExactJson([
            'status' => 'ok',
            'checks' => ['db' => 'ok', 'scheduler' => 'ok', 'queue' => 'ok'],
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
