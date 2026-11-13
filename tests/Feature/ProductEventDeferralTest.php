<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventBuffer;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Game\Enums\LedgerKind;
use App\Domain\Game\Services\Economy\WalletService;
use App\Models\Learner;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Database\Seeders\GameItemSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\Game\GameKit;
use Tests\Support\OctaneHarness;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * The events of an API request are gathered and written with ONE INSERT after the response (ProductEventBuffer,
 * DeferProductEvents); everywhere else they are written at once, as before.
 */

uses(RefreshDatabase::class);

const PE_INSERT = '/^insert into ["`]product_events["`]/';

/** A learner id that no row has: its event breaks the foreign key. */
function peGhostLearner(): Learner
{
    $learner = new Learner;
    $learner->id = 987_654_321;

    return $learner;
}

it('writes the two events of an API purchase with one INSERT, the last statement of the request, the same rows as an immediate write', function (): void {
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    GameKit::openAccess();
    GameKit::noEvents();
    $this->seed(GameItemSeeder::class);
    $learner = Learner::factory()->create();
    DB::transaction(fn () => app(WalletService::class)->credit($learner->id, 200, LedgerKind::AdminGrant, "admin:seed:{$learner->id}"));
    $headers = PracticeKit::headers($learner);

    $queries = QueryLog::during(fn () => $this->withHeaders($headers)->postJson('/api/twa/game/shop/camel_bactrian/buy', ['request_id' => (string) Str::uuid()])->assertOk());
    $inserts = QueryLog::matching($queries, PE_INSERT);

    expect($inserts)->toHaveCount(1)
        ->and($queries[array_key_last($queries)])->toMatch(PE_INSERT);

    $events = ProductEvent::query()->orderBy('id')->get();
    expect($events->pluck('name')->all())->toEqualCanonicalizing(['game.item_bought', 'game.coins_spent'])
        ->and($events->pluck('learner_id')->unique()->all())->toBe([$learner->id])
        ->and($events->firstWhere('name', 'game.item_bought')?->props)->toEqual(['item_key' => 'camel_bactrian', 'kind' => 'cosmetic', 'price' => 150]) // jsonb orders keys
        ->and($events->pluck('occurred_at')->map->getTimestamp()->all())->toBe($events->pluck('occurred_at')->map->getTimestamp()->sort()->values()->all());

    // The row of a deferred write is what `create()` would have stored.
    $viaModel = ProductEvent::query()->create(['name' => 'x.y', 'learner_id' => $learner->id, 'props' => [], 'occurred_at' => now()]);
    $viaBuffer = (new ProductEvent(['name' => 'x.z', 'learner_id' => $learner->id, 'props' => [], 'occurred_at' => now()]))->getAttributes();
    expect($viaBuffer['props'])->toBe('[]')->and($viaModel->refresh()->props)->toBe([])
        ->and($viaBuffer['occurred_at'])->toBe($viaModel->occurred_at->format('Y-m-d H:i:s'));
    CarbonImmutable::setTestNow();
});

it('opens no transaction and runs no statement to record a deferred event', function (): void {
    $buffer = app(ProductEventBuffer::class);
    $buffer->defer();
    $begun = 0;
    Event::listen(TransactionBeginning::class, function () use (&$begun): void {
        $begun++;
    });
    $learner = Learner::factory()->create();
    $recorder = app(ProductEventRecorder::class);

    $queries = QueryLog::during(function () use ($recorder, $learner): void {
        $recorder->record(ProductEventName::GameCoinsSpent, ['amount' => 1], $learner);
        DB::transaction(fn () => $recorder->record(ProductEventName::GameItemBought, ['price' => 1], $learner));
    });

    expect($queries)->toBe([])->and($begun)->toBe(1); // the caller's own transaction only, no savepoint for the event
    $queries = QueryLog::during(fn () => $buffer->flush());
    expect(QueryLog::matching($queries, PE_INSERT))->toHaveCount(1)->and(ProductEvent::query()->count())->toBe(2);
});

it('drops an event recorded in a transaction that rolled back and keeps one that committed', function (): void {
    $buffer = app(ProductEventBuffer::class);
    $buffer->defer();
    $learner = Learner::factory()->create();
    $recorder = app(ProductEventRecorder::class);

    try {
        DB::transaction(function () use ($recorder, $learner): void {
            $recorder->record(ProductEventName::GameCoinsSpent, ['amount' => 1], $learner);

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }
    DB::transaction(fn () => $recorder->record(ProductEventName::GameItemBought, ['price' => 2], $learner));
    $buffer->finish();

    expect(ProductEvent::query()->pluck('name')->all())->toBe(['game.item_bought']);
});

it('keeps the other rows when one row of the batch fails, warns, and does not touch the response', function (): void {
    Log::spy();
    $learner = Learner::factory()->create();
    $recorder = app(ProductEventRecorder::class);
    $inside = null;
    Route::middleware('api')->post('/api/__probe/events', function () use ($recorder, $learner, &$inside) {
        $recorder->record(ProductEventName::GameCoinsSpent, ['amount' => 1], $learner);
        $recorder->record(ProductEventName::GameItemBought, ['price' => 1], peGhostLearner());
        $recorder->record(ProductEventName::GameCoinsSpent, ['amount' => 2], $learner);
        $inside = ProductEvent::query()->count();

        return response()->json(['ok' => true]);
    });

    $queries = QueryLog::during(fn () => $this->postJson('/api/__probe/events')->assertOk()->assertJson(['ok' => true]));
    $inserts = QueryLog::matching($queries, PE_INSERT);

    // Nothing was written inside the request; after it the batch of three failed as a whole and the rows went one by one
    // (the log keeps the statements that succeeded: the two good rows).
    expect($inside)->toBe(0)->and($inserts)->toHaveCount(2)
        ->and(ProductEvent::query()->orderBy('id')->get()->map(fn (ProductEvent $e): array => (array) $e->props)->all())->toBe([['amount' => 1], ['amount' => 2]]);
    Log::shouldHaveReceived('warning')->once()->with('product_event.record_failed', Mockery::on(fn (array $c): bool => $c['event'] === 'game.item_bought'));
});

it('writes at once outside the API group: a web route, a direct call', function (): void {
    $learner = Learner::factory()->create();
    $recorder = app(ProductEventRecorder::class);
    $seen = null;
    Route::middleware('web')->post('/__probe/web-events', function () use ($recorder, $learner, &$seen) {
        $recorder->record(ProductEventName::GameCoinsSpent, ['amount' => 1], $learner);
        $seen = ProductEvent::query()->count();

        return response()->json(['ok' => true]);
    });

    $this->postJson('/__probe/web-events')->assertOk();
    expect($seen)->toBe(1);

    $recorder->record(ProductEventName::GameCoinsSpent, ['amount' => 2], $learner);
    expect(ProductEvent::query()->count())->toBe(2)->and(app(ProductEventBuffer::class)->isDeferring())->toBeFalse();
});

it('takes the buffer from the current container: a recorder made before the request fills the buffer of the request', function (): void {
    $recorder = app(ProductEventRecorder::class); // made before the request, like a service resolved at worker boot
    $learner = Learner::factory()->create();
    $inside = null;
    Route::middleware('api')->post('/api/__probe/early', function () use ($recorder, $learner, &$inside) {
        // What the middleware switched on is the buffer this recorder uses - whatever scoped instance existed before.
        $recorder->record(ProductEventName::GameCoinsSpent, ['amount' => 1], $learner);
        $inside = ProductEvent::query()->count();

        return response()->json(['ok' => true]);
    });
    app()->forgetScopedInstances(); // the old instance of the buffer (if any) is not the request's

    $this->postJson('/api/__probe/early')->assertOk();

    expect($inside)->toBe(0)->and(ProductEvent::query()->count())->toBe(1);
});

it('writes at once what is recorded after the buffer is finished, and never twice what was flushed', function (): void {
    $buffer = app(ProductEventBuffer::class);
    $recorder = app(ProductEventRecorder::class);
    $learner = Learner::factory()->create();
    $buffer->defer();

    $recorder->record(ProductEventName::GameCoinsSpent, ['amount' => 1], $learner);
    expect(ProductEvent::query()->count())->toBe(0);
    $buffer->finish();
    $buffer->flush();
    expect(ProductEvent::query()->count())->toBe(1)->and($buffer->isDeferring())->toBeFalse();

    // A terminating callback of a later middleware: written at once, not lost.
    $recorder->record(ProductEventName::GameCoinsSpent, ['amount' => 2], $learner);
    expect(ProductEvent::query()->count())->toBe(2);

    // A row handed to the buffer after `finish()` (an after-commit callback) has nobody to flush it later.
    $buffer->add((new ProductEvent(['name' => 'game.coins_spent', 'learner_id' => $learner->id, 'props' => ['amount' => 3], 'occurred_at' => now()]))->getAttributes());
    expect(ProductEvent::query()->count())->toBe(3);
});

it('writes the events of each Octane request once and keeps them out of the next request', function (): void {
    $octane = OctaneHarness::boot($this->app);
    $recorder = $this->app->make(ProductEventRecorder::class); // of the base application, as a service resolved at boot
    $learner = Learner::factory()->create();
    $counts = [];
    Route::middleware('api')->post('/api/__probe/octane', function () use ($recorder, $learner, &$counts) {
        $recorder->record(ProductEventName::GameCoinsSpent, ['amount' => count($counts) + 1], $learner);
        $counts[] = ProductEvent::query()->count();

        return response()->json(['ok' => true]);
    });

    expect($octane->handle(OctaneHarness::request('POST', '/api/__probe/octane'))->getStatusCode())->toBe(200)
        ->and(ProductEvent::query()->count())->toBe(1);
    expect($octane->handle(OctaneHarness::request('POST', '/api/__probe/octane'))->getStatusCode())->toBe(200)
        ->and(ProductEvent::query()->count())->toBe(2)
        ->and($counts)->toBe([0, 1]) // inside each request the event waited for the end; the first one was not written again
        ->and(ProductEvent::query()->orderBy('id')->get()->map(fn (ProductEvent $e): array => (array) $e->props)->all())->toBe([['amount' => 1], ['amount' => 2]]);
});
