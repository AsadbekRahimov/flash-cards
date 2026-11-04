<?php

declare(strict_types=1);

/*
 * Wall and CPU time of the training cycle per request, through the HTTP kernel of the test client (R12g "before" /
 * "after"): open, next, review of a new card, review once the Free quota is spent (learn-ahead), finish. Several Free
 * learners, caches warm, the first grade of the day of every learner left out (it places in the league and checks the
 * first achievement). Prints medians and p90 to stderr; not a test of anything. Run with TIMING=1, PostgreSQL + Redis.
 */

use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HotPathFixtures as Fx;

uses(RefreshDatabase::class);

/** @return array{wall: float, cpu: float, json: array<string, mixed>} */
function timedCall(object $test, array $headers, string $method, string $uri, array $body = []): array
{
    $ru0 = getrusage();
    $t0 = hrtime(true);
    $json = $test->withHeaders($headers)->json($method, $uri, $body)->assertOk()->json();
    $wall = (hrtime(true) - $t0) / 1e6;
    $ru1 = getrusage();
    $cpu = (($ru1['ru_utime.tv_sec'] - $ru0['ru_utime.tv_sec']) * 1e3 + ($ru1['ru_utime.tv_usec'] - $ru0['ru_utime.tv_usec']) / 1e3)
        + (($ru1['ru_stime.tv_sec'] - $ru0['ru_stime.tv_sec']) * 1e3 + ($ru1['ru_stime.tv_usec'] - $ru0['ru_stime.tv_usec']) / 1e3);

    return ['wall' => $wall, 'cpu' => $cpu, 'json' => $json];
}

/** @param  list<float>  $values */
function pct(array $values, float $p): float
{
    sort($values);

    return $values === [] ? 0.0 : $values[(int) min(count($values) - 1, floor($p * count($values)))];
}

it('times the training cycle', function (): void {
    if (! getenv('TIMING')) {
        $this->markTestSkipped('TIMING=1');
    }

    ['lessons' => $lessons] = Fx::course([1 => [1 => 30]]);
    $lessonId = (int) $lessons['1.1']->id;
    $learners = (int) (getenv('TIMING_LEARNERS') ?: 12);
    $samples = ['open' => [], 'next' => [], 'review_new' => [], 'review_ahead' => [], 'finish' => []];

    for ($n = 0; $n < $learners; $n++) {
        $learner = Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent']);
        $h = ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
        $this->withHeaders($h)->getJson('/api/twa/me')->assertOk();

        $r = timedCall($this, $h, 'POST', '/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $lessonId, 'source' => 'self']);
        if ($n > 0) {
            $samples['open'][] = $r;
        }
        $base = "/api/twa/training/sessions/{$r['json']['session_id']}";

        $r = timedCall($this, $h, 'GET', "{$base}/next");
        if ($n > 0) {
            $samples['next'][] = $r;
        }
        $card = $r['json']['card'];

        for ($i = 0; $i < 24; $i++) {
            $r = timedCall($this, $h, 'POST', "{$base}/review", ['word_id' => $card['word_id'], 'quality' => 4, 'time_spent_ms' => 3000, 'serve_token' => $card['serve_token']]);
            $kind = $card['card_kind'];
            $card = $r['json']['card'];
            if ($n > 0 && $i > 0) {
                $samples[$kind === 'new' && $i < 19 ? 'review_new' : 'review_ahead'][] = $r;
            }
        }

        $r = timedCall($this, $h, 'POST', "{$base}/finish");
        if ($n > 0) {
            $samples['finish'][] = $r;
        }
    }

    fwrite(STDERR, sprintf("\n%-14s %5s  %8s %8s  %8s %8s\n", 'request', 'n', 'wall p50', 'wall p90', 'cpu p50', 'cpu p90'));
    foreach ($samples as $label => $rows) {
        $wall = array_column($rows, 'wall');
        $cpu = array_column($rows, 'cpu');
        fwrite(STDERR, sprintf("%-14s %5d  %8.1f %8.1f  %8.1f %8.1f\n", $label, count($rows), pct($wall, 0.5), pct($wall, 0.9), pct($cpu, 0.5), pct($cpu, 0.9)));
    }
});
