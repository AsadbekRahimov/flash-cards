<?php

declare(strict_types=1);

/*
 * Wall and CPU time of `GET /me` and `GET /today` per request, through the HTTP kernel of the test client (R12i "before" /
 * "after"): the second and later calls of several Free learners with an active course, a few learned words, a place in
 * the league and a session graded today - what the client meets on every opening of the Mini App and on every return
 * to "Today". Prints medians and p90 to stderr; not a test of anything. Run with TIMING=1, PostgreSQL + Redis.
 */

use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HotPathFixtures as Fx;

uses(RefreshDatabase::class);

/** @return array{wall: float, cpu: float, sql: int} */
function timedTodayMeCall(object $test, array $headers, string $uri): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $ru0 = getrusage();
    $t0 = hrtime(true);
    $test->withHeaders($headers)->getJson($uri)->assertOk();
    $wall = (hrtime(true) - $t0) / 1e6;
    $ru1 = getrusage();
    $sql = count(DB::getQueryLog());
    DB::disableQueryLog();
    $cpu = (($ru1['ru_utime.tv_sec'] - $ru0['ru_utime.tv_sec']) * 1e3 + ($ru1['ru_utime.tv_usec'] - $ru0['ru_utime.tv_usec']) / 1e3)
        + (($ru1['ru_stime.tv_sec'] - $ru0['ru_stime.tv_sec']) * 1e3 + ($ru1['ru_stime.tv_usec'] - $ru0['ru_stime.tv_usec']) / 1e3);

    return ['wall' => $wall, 'cpu' => $cpu, 'sql' => $sql];
}

/** @param  list<float>  $values */
function todayMePct(array $values, float $p): float
{
    sort($values);

    return $values === [] ? 0.0 : $values[(int) min(count($values) - 1, floor($p * count($values)))];
}

it('times /me and /today', function (): void {
    if (! getenv('TIMING')) {
        $this->markTestSkipped('TIMING=1');
    }

    ['course' => $course, 'lessons' => $lessons] = Fx::course([1 => [1 => 30, 2 => 30]]);
    $lessonId = (int) $lessons['1.1']->id;
    $learners = (int) (getenv('TIMING_LEARNERS') ?: 12);
    $rounds = (int) (getenv('TIMING_ROUNDS') ?: 5);
    $samples = ['me' => [], 'today' => []];

    for ($n = 0; $n < $learners; $n++) {
        $learner = Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent', 'active_course_id' => $course->id]);
        $h = ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
        $this->withHeaders($h)->getJson('/api/twa/me')->assertOk();

        // A session graded today: learned words, a day row, a place in the league.
        $opened = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $lessonId, 'source' => 'self'])->assertOk()->json();
        $card = $opened['card'];
        for ($i = 0; $i < 5; $i++) {
            $card = $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$opened['session_id']}/review", ['word_id' => $card['word_id'], 'quality' => 4, 'time_spent_ms' => 3000, 'serve_token' => $card['serve_token']])->assertOk()->json('card');
        }
        $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$opened['session_id']}/finish")->assertOk();

        // The first call after the session is the client's return to "Today"; the later ones are the openings of the app.
        for ($r = 0; $r < $rounds; $r++) {
            $me = timedTodayMeCall($this, $h, '/api/twa/me');
            $today = timedTodayMeCall($this, $h, '/api/twa/today');
            if ($n > 0) {
                $samples['me'][] = $me;
                $samples['today'][] = $today;
            }
        }
    }

    fwrite(STDERR, "\n=== /me and /today timing (".($learners - 1)." learners x {$rounds} rounds, ms) ===\n");
    foreach ($samples as $label => $rows) {
        $wall = array_column($rows, 'wall');
        $cpu = array_column($rows, 'cpu');
        $sql = array_column($rows, 'sql');
        fwrite(STDERR, sprintf(
            "  %-6s n=%3d  wall p50 %6.1f p90 %6.1f  cpu p50 %6.1f p90 %6.1f  sql p50 %d max %d\n",
            $label, count($rows), todayMePct($wall, 0.5), todayMePct($wall, 0.9), todayMePct($cpu, 0.5), todayMePct($cpu, 0.9), (int) todayMePct($sql, 0.5), $sql === [] ? 0 : max($sql),
        ));
    }
});
