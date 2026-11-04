<?php

declare(strict_types=1);

/*
 * Inventory of the training cycle (R12g): statements, transactions, Redis commands and CPU per request.
 * Run with INVENTORY=1; prints to stderr. Not a budget test.
 */

use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Tests\Support\HotPathFixtures as Fx;

uses(RefreshDatabase::class);

function inv(object $test, string $label, Closure $fn): mixed
{
    $tx = ['begin' => 0, 'commit' => 0, 'rollback' => 0];
    $redis = [];
    $listeners = [
        TransactionBeginning::class => function () use (&$tx): void {
            $tx['begin']++;
        },
        TransactionCommitted::class => function () use (&$tx): void {
            $tx['commit']++;
        },
        TransactionRolledBack::class => function () use (&$tx): void {
            $tx['rollback']++;
        },
        CommandExecuted::class => function (CommandExecuted $e) use (&$redis): void {
            $redis[] = $e->connectionName.':'.$e->command.' '.json_encode(array_slice($e->parameters, 0, 1));
        },
    ];
    foreach ($listeners as $event => $cb) {
        Event::listen($event, $cb);
    }
    Redis::enableEvents();
    foreach (Redis::connections() as $connection) {
        $connection->setEventDispatcher(app('events'));
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $ru0 = getrusage();
    $t0 = hrtime(true);
    $result = $fn();
    $wall = (hrtime(true) - $t0) / 1e6;
    $ru1 = getrusage();
    $cpu = (($ru1['ru_utime.tv_sec'] - $ru0['ru_utime.tv_sec']) * 1e3 + ($ru1['ru_utime.tv_usec'] - $ru0['ru_utime.tv_usec']) / 1e3)
        + (($ru1['ru_stime.tv_sec'] - $ru0['ru_stime.tv_sec']) * 1e3 + ($ru1['ru_stime.tv_usec'] - $ru0['ru_stime.tv_usec']) / 1e3);
    $log = DB::getQueryLog();
    DB::disableQueryLog();
    foreach ($listeners as $event => $cb) {
        Event::forget($event);
    }

    $sqlMs = array_sum(array_map(fn ($e) => (float) $e['time'], $log));
    fwrite(STDERR, sprintf("\n=== %s: %d statements (%.1f ms sql), tx begin %d / commit %d / rollback %d, redis %d, wall %.1f ms, cpu %.1f ms\n", $label, count($log), $sqlMs, $tx['begin'], $tx['commit'], $tx['rollback'], count($redis), $wall, $cpu));
    foreach ($log as $i => $e) {
        fwrite(STDERR, sprintf("  %2d. [%5.2f ms] %s\n", $i + 1, $e['time'], mb_substr($e['query'], 0, 220)));
    }
    foreach ($redis as $r) {
        fwrite(STDERR, "  redis: {$r}\n");
    }

    return $result;
}

it('inventories open, next, review and finish', function (): void {
    if (! getenv('INVENTORY')) {
        $this->markTestSkipped('INVENTORY=1');
    }

    ['lessons' => $lessons] = Fx::course([1 => [1 => 25, 2 => 25]]);
    $lesson = $lessons['1.1'];
    $headersOf = fn (Learner $l) => ['Authorization' => 'Bearer '.app(JwtService::class)->issue($l->id)['token']];

    // Warm the global caches with another learner.
    $warm = Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent']);
    $h = $headersOf($warm);
    $this->withHeaders($h)->getJson('/api/twa/me')->assertOk();
    $opened = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $lesson->id, 'source' => 'self'])->assertOk()->json();
    $card = $this->withHeaders($h)->getJson("/api/twa/training/sessions/{$opened['session_id']}/next")->assertOk()->json('card');
    $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$opened['session_id']}/review", ['word_id' => $card['word_id'], 'quality' => 4, 'time_spent_ms' => 100])->assertOk();
    $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$opened['session_id']}/finish")->assertOk();

    $learner = Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent']);
    $h = $headersOf($learner);
    $this->withHeaders($h)->getJson('/api/twa/me')->assertOk();
    $this->withHeaders($h)->getJson('/api/twa/today')->assertOk();

    $opened = inv($this, 'open', fn () => $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $lesson->id, 'source' => 'self'])->assertOk()->json());
    $base = "/api/twa/training/sessions/{$opened['session_id']}";
    $card = $opened['card'];

    $res = inv($this, 'next (card already served = due new)', fn () => $this->withHeaders($h)->getJson("{$base}/next")->assertOk()->json());
    $card = $res['card'];

    $res = inv($this, 'review #1 (first of day: new card)', fn () => $this->withHeaders($h)->postJson("{$base}/review", ['word_id' => $card['word_id'], 'quality' => 4, 'time_spent_ms' => 3000, 'serve_token' => $card['serve_token']])->assertOk()->json());
    $card = $res['card'];
    $res = inv($this, 'review #2 (new card)', fn () => $this->withHeaders($h)->postJson("{$base}/review", ['word_id' => $card['word_id'], 'quality' => 4, 'time_spent_ms' => 3000, 'serve_token' => $card['serve_token']])->assertOk()->json());
    $card = $res['card'];
    for ($i = 0; $i < 18; $i++) {
        $res = $this->withHeaders($h)->postJson("{$base}/review", ['word_id' => $card['word_id'], 'quality' => 4, 'time_spent_ms' => 3000, 'serve_token' => $card['serve_token']])->assertOk()->json();
        $card = $res['card'];
    }
    fwrite(STDERR, "\n  card after 20 reviews: kind={$card['card_kind']} token=".($card['serve_token'] ?? 'null').' quota='.json_encode($res['quota'])."\n");
    $res = inv($this, 'review #21 (quota exhausted -> learn-ahead card)', fn () => $this->withHeaders($h)->postJson("{$base}/review", ['word_id' => $card['word_id'], 'quality' => 4, 'time_spent_ms' => 3000, 'serve_token' => $card['serve_token']])->assertOk()->json());
    $card = $res['card'];
    $res = inv($this, 'review #22 (learn-ahead card)', fn () => $this->withHeaders($h)->postJson("{$base}/review", ['word_id' => $card['word_id'], 'quality' => 4, 'time_spent_ms' => 3000, 'serve_token' => $card['serve_token']])->assertOk()->json());
    inv($this, 'next (learn-ahead)', fn () => $this->withHeaders($h)->getJson("{$base}/next")->assertOk()->json());

    inv($this, 'finish', fn () => $this->withHeaders($h)->postJson("{$base}/finish")->assertOk()->json());
});
