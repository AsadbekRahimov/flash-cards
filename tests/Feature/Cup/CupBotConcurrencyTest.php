<?php

declare(strict_types=1);

use App\Domain\Cup\Enums\CupMessageStatus;
use App\Domain\Cup\Services\CupLifecycleService;
use App\Domain\Cup\Services\CupOutbox;
use App\Domain\Cup\Services\CupSummaries;
use App\Jobs\PostCupMessageJob;
use App\Models\Cup;
use App\Models\CupMessage;
use App\Models\CupParticipant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Cup\CupKit;
use Tests\Support\Game\GameRace;

/*
 * docs/33 §6.4 (K6): the outbox of a cup under real races - forked processes, each with its own PostgreSQL connection and its own
 * (fake) Telegram, released at the same instant (GameRace). A message is posted by ONE of them, whatever the number.
 */

if ((getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql') {
    uses(DatabaseTruncation::class);
}

beforeEach(function (): void {
    if (config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Needs PostgreSQL: real parallel connections and row locks.');
    }
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Needs the pcntl and posix extensions to fork workers.');
    }
    if (config('cache.default') !== 'redis') {
        $this->markTestSkipped('Needs a cache shared by the processes (Redis) for the slots of the chat.');
    }
    CupKit::open();
    CupKit::course();
    Storage::fake('public');
    Cache::flush();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    if (config('database.default') === 'pgsql') {
        DB::reconnect();
        $this->truncateTablesForAllConnections();
    }
});

/** @return array{0: Cup, 1: CupMessage} a finished cup and its `final` row, not sent yet */
function raceFinal(): array
{
    ['cup' => $cup, 'learners' => [$a]] = CupKit::running(2);
    CupParticipant::query()->where('learner_id', $a->id)->update(['best_total' => 10, 'best_round_points' => 10]);
    DB::table('cup_days')->where('cup_id', $cup->id)->update(['built_at' => now()]);
    $end = CarbonImmutable::instance($cup->ends_at)->addMinutes(31);
    CarbonImmutable::setTestNow($end);
    app(CupLifecycleService::class)->finish($cup->id, $end);

    return [$cup, CupMessage::query()->where('cup_id', $cup->id)->where('dedupe_key', 'final')->sole()];
}

it('posts the final once when four workers send the same row at the same instant', function (): void {
    [, $row] = raceFinal();
    $id = $row->id;

    $results = GameRace::run(array_fill(0, 4, static function () use ($id): int {
        app(CupOutbox::class)->deliver($id, CarbonImmutable::now());

        return count(CupKit::telegram()->photoMessages) + count(CupKit::telegram()->groupMessages);
    }));

    expect(GameRace::crashes($results))->toBe([])
        ->and(array_sum(array_column($results, 'value')))->toBe(1)
        ->and($row->fresh()->status)->toBe(CupMessageStatus::Sent)
        ->and($row->fresh()->attempts)->toBe(1);
});

/*
 * docs/33 §11.9.2 п. 1 (review of release 6): the summary took the row of the day, then the row of the cup; the cancel holds the cup
 * and closes the days never built - a day not built yet (its build failed) made a cycle. A BEFORE trigger holds the summary on the row
 * of the day while the cancel, a moment later, takes the cup.
 */
it('never deadlocks the summary of an unbuilt day against the cancel of the cup', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $start] = CupKit::running(2);
    $participant = CupParticipant::query()->where('learner_id', $a->id)->sole();
    DB::table('cup_day_scores')->insert(['cup_id' => $cup->id, 'participant_id' => $participant->id, 'day' => 1, 'round_points' => 4, 'updated_at' => $start]);
    $participant->forceFill(['best_total' => 4, 'best_round_points' => 4])->saveQuietly();
    DB::table('cup_days')->where('cup_id', $cup->id)->update(['built_at' => null]);
    $at = $start->addHours(21);
    $cupId = $cup->id;
    DB::unprepared('CREATE OR REPLACE FUNCTION test_slow_cup_day() RETURNS trigger AS $$ BEGIN IF NEW.summary_posted_at IS NOT NULL AND OLD.summary_posted_at IS NULL THEN PERFORM pg_sleep(0.4); END IF; RETURN NEW; END $$ LANGUAGE plpgsql');
    DB::unprepared('CREATE TRIGGER test_slow_cup_day BEFORE UPDATE ON cup_days FOR EACH ROW EXECUTE FUNCTION test_slow_cup_day()');

    try {
        $results = GameRace::run([
            static function () use ($at): int {
                Bus::fake([PostCupMessageJob::class]);

                return app(CupSummaries::class)->enqueueDue($at, 500)['queued'];
            },
            static function () use ($cupId, $at): bool {
                usleep(150_000);

                return app(CupLifecycleService::class)->cancelByPlatform(Cup::query()->with('telegramGroup')->findOrFail($cupId), $at);
            },
        ]);
    } finally {
        DB::reconnect();
        DB::unprepared('DROP TRIGGER IF EXISTS test_slow_cup_day ON cup_days');
        DB::unprepared('DROP FUNCTION IF EXISTS test_slow_cup_day()');
    }

    expect(GameRace::crashes($results))->toBe([])
        ->and(Cup::query()->findOrFail($cupId)->status->value)->toBe('canceled')
        ->and(DB::table('cup_days')->where('cup_id', $cupId)->whereNull('built_at')->count())->toBe(0);
})->repeat(max(1, (int) (getenv('CUP_DUEL_FINISH_RACE_REPEAT') ?: 3)));

it('queues one summary row when two schedulers decide the day at the same instant', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $start] = CupKit::running(2);
    $participant = CupParticipant::query()->where('learner_id', $a->id)->sole();
    DB::table('cup_day_scores')->insert(['cup_id' => $cup->id, 'participant_id' => $participant->id, 'day' => 1, 'round_points' => 4, 'updated_at' => $start]);
    $participant->forceFill(['best_total' => 4, 'best_round_points' => 4])->saveQuietly();
    $at = $start->addHours(21);

    $results = GameRace::run(array_fill(0, 3, static function () use ($at): int {
        Bus::fake([PostCupMessageJob::class]);

        return app(CupSummaries::class)->enqueueDue($at, 500)['queued'];
    }));

    expect(GameRace::crashes($results))->toBe([])
        ->and(array_sum(array_column($results, 'value')))->toBe(1)
        ->and(CupMessage::query()->where('cup_id', $cup->id)->where('kind', 'summary')->count())->toBe(1);
});
