<?php

declare(strict_types=1);

/*
 * Inventory of `GET /me` and `GET /today` (R12i): statements, Redis commands and CPU per request, for a learner the
 * client meets on every opening of the Mini App (an active course, a few learned words, a place in the league, a
 * session graded today). Run with INVENTORY=1; prints to stderr. Not a budget test.
 */

use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Tests\Support\HotPathFixtures as Fx;

uses(RefreshDatabase::class);

function invTodayMe(object $test, string $label, Closure $fn): mixed
{
    $redis = [];
    Event::listen(CommandExecuted::class, function (CommandExecuted $e) use (&$redis): void {
        $redis[] = $e->connectionName.':'.$e->command.' '.json_encode(array_slice($e->parameters, 0, 1));
    });
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
    Event::forget(CommandExecuted::class);

    $sqlMs = array_sum(array_map(fn ($e) => (float) $e['time'], $log));
    fwrite(STDERR, sprintf("\n=== %s: %d statements (%.1f ms sql), redis %d, wall %.1f ms, cpu %.1f ms\n", $label, count($log), $sqlMs, count($redis), $wall, $cpu));
    foreach ($log as $i => $e) {
        fwrite(STDERR, sprintf("  %2d. [%5.2f ms] %s\n", $i + 1, $e['time'], mb_substr($e['query'], 0, 240)));
    }
    foreach ($redis as $r) {
        fwrite(STDERR, "  redis: {$r}\n");
    }

    return $result;
}

it('inventories /me and /today', function (): void {
    if (! getenv('INVENTORY')) {
        $this->markTestSkipped('INVENTORY=1');
    }

    config()->set('practice.access', getenv('INVENTORY_PRACTICE') ?: 'off');
    config()->set('game.access', getenv('INVENTORY_GAME') ?: 'off');

    ['course' => $course, 'lessons' => $lessons] = Fx::course([1 => [1 => 25, 2 => 25]]);
    $lesson = $lessons['1.1'];
    $headersOf = fn (Learner $l) => ['Authorization' => 'Bearer '.app(JwtService::class)->issue($l->id)['token']];

    // Warm the shared caches with another learner.
    $warm = Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent', 'active_course_id' => $course->id]);
    $h = $headersOf($warm);
    $this->withHeaders($h)->getJson('/api/twa/me')->assertOk();
    $this->withHeaders($h)->getJson('/api/twa/today')->assertOk();

    $learner = Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent', 'active_course_id' => $course->id]);
    $h = $headersOf($learner);
    $opened = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $lesson->id, 'source' => 'self'])->assertOk()->json();
    $card = $opened['card'];
    for ($i = 0; $i < 5; $i++) {
        $card = $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$opened['session_id']}/review", ['word_id' => $card['word_id'], 'quality' => 4, 'time_spent_ms' => 3000, 'serve_token' => $card['serve_token']])->assertOk()->json('card');
    }
    $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$opened['session_id']}/finish")->assertOk();

    invTodayMe($this, 'me (first call of the learner)', fn () => $this->withHeaders($h)->getJson('/api/twa/me')->assertOk()->json());
    invTodayMe($this, 'me (second call)', fn () => $this->withHeaders($h)->getJson('/api/twa/me')->assertOk()->json());
    invTodayMe($this, 'today (first call of the learner)', fn () => $this->withHeaders($h)->getJson('/api/twa/today')->assertOk()->json());
    invTodayMe($this, 'today (second call)', fn () => $this->withHeaders($h)->getJson('/api/twa/today')->assertOk()->json());
});
