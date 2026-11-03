<?php

declare(strict_types=1);

use App\Domain\Game\Enums\RunFinishReason;
use App\Domain\Game\Enums\RunStatus;
use App\Models\GameCoinLedgerEntry;
use App\Models\GameRun;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * docs/18 §12.2.1, §12.5.1: `game:close-expired` (every 15 minutes) closes the runs past `expires_at` as finished / expired, lets the
 * learner go of the "one active run", raises `game.run_finished` once and pays nothing. The concurrency with an answer is
 * GameRunConcurrencyTest's.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    config(['game.access' => 'all']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('is scheduled every 15 minutes, without overlapping', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'game:close-expired'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/15 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('closes the runs past their time as finished / expired, lets the learners go, pays nothing, and leaves the others alone', function (): void {
    $old = GameRun::factory()->expired()->create();
    $older = GameRun::factory()->expired()->create();
    $fresh = GameRun::factory()->create();
    $edge = GameRun::factory()->create(['expires_at' => '2026-11-05 10:00:00']);   // `expires_at <= now` is past
    $nearly = GameRun::factory()->create(['expires_at' => '2026-11-05 10:00:01']); // a second to go
    $done = GameRun::factory()->finished(RunFinishReason::Completed)->create();

    $this->artisan('game:close-expired')->expectsOutput('Game runs closed: 3')->assertSuccessful();

    foreach ([$old, $older, $edge] as $run) {
        $row = $run->fresh();
        expect($row?->status)->toBe(RunStatus::Finished)
            ->and($row?->finish_reason)->toBe(RunFinishReason::Expired)
            ->and($row?->active_learner_id)->toBeNull()
            ->and($row?->stars)->toBeNull()
            ->and($row?->finished_at?->toDateTimeString())->toBe('2026-11-05 10:00:00');
    }
    foreach ([$fresh, $nearly] as $run) {
        expect($run->fresh()?->status)->toBe(RunStatus::Active)->and($run->fresh()?->active_learner_id)->toBe($run->learner_id);
    }
    expect($done->fresh()?->finish_reason)->toBe(RunFinishReason::Completed)
        ->and(ProductEvent::query()->where('name', 'game.run_finished')->count())->toBe(3)
        ->and(GameCoinLedgerEntry::query()->count())->toBe(0);
});

it('does nothing the second time and closes nothing when there is nothing to close', function (): void {
    GameRun::factory()->expired()->create();

    $this->artisan('game:close-expired')->expectsOutput('Game runs closed: 1')->assertSuccessful();
    $this->artisan('game:close-expired')->expectsOutput('Game runs closed: 0')->assertSuccessful();

    expect(ProductEvent::query()->where('name', 'game.run_finished')->count())->toBe(1);
});

it('lets the learner start again once the old run is closed by the scheduler', function (): void {
    $run = GameRun::factory()->expired()->create();
    expect($run->active_learner_id)->not->toBeNull();

    $this->artisan('game:close-expired')->assertSuccessful();

    expect(GameRun::query()->where('active_learner_id', $run->learner_id)->exists())->toBeFalse();
});
