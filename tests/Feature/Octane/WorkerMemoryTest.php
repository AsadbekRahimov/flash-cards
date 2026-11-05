<?php

declare(strict_types=1);

/*
 * The memory of an Octane worker must not grow with the requests it serves (docs/21 §3.7, §2.2 №34): 20 learners each
 * open the Mini App (/me, /today), run a short training (open, three reviews, finish) - 140 requests through one worker.
 * Measured after the 50th request, when the dictionaries, the translations and the lazily built services are loaded,
 * and after the last: a trap for anything in the application that accumulates per request, not a budget of Octane.
 */

use App\Models\Learner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HotPathFixtures as Fx;
use Tests\Support\OctaneHarness;

uses(RefreshDatabase::class);

it('does not accumulate memory across the requests of many learners', function (): void {
    $octane = OctaneHarness::boot($this->app);
    ['course' => $course, 'lessons' => $lessons] = Fx::course([1 => [1 => 30]]);
    $lessonId = (int) $lessons['1.1']->id;
    $learners = Learner::factory()->onboarded()->count(20)->create(['active_course_id' => $course->id]);

    $measure = static function (): int {
        gc_collect_cycles();

        return memory_get_usage();
    };
    $served = 0;
    $afterWarmUp = null;
    $handle = function (string $method, string $uri, Learner $learner, array $payload = []) use ($octane, $measure, &$served, &$afterWarmUp): array {
        $response = $octane->handle(OctaneHarness::request($method, $uri, $learner, $payload));
        expect($response->getStatusCode())->toBe(200, "{$method} {$uri}: ".$response->getContent());

        if (++$served === 50) {
            $afterWarmUp = $measure();
        }

        return OctaneHarness::json($response);
    };

    foreach ($learners as $learner) {
        $handle('GET', '/api/twa/me', $learner);
        $handle('GET', '/api/twa/today', $learner);

        $opened = $handle('POST', '/api/twa/training/sessions', $learner, ['mode' => 'lesson', 'lesson_id' => $lessonId, 'source' => 'self']);
        $card = $opened['card'];
        for ($i = 0; $i < 3; $i++) {
            $card = $handle('POST', "/api/twa/training/sessions/{$opened['session_id']}/review", $learner, [
                'word_id' => $card['word_id'], 'quality' => 4, 'time_spent_ms' => 3000, 'serve_token' => $card['serve_token'],
            ])['card'];
        }
        $handle('POST', "/api/twa/training/sessions/{$opened['session_id']}/finish", $learner);
    }

    $atEnd = $measure();

    expect($served)->toBe(140)
        ->and($afterWarmUp)->not->toBeNull()
        ->and(($atEnd - $afterWarmUp) / 1024 / 1024)->toBeLessThan(8.0, sprintf('%.1f MB grew over %d requests after the warm-up', ($atEnd - $afterWarmUp) / 1024 / 1024, $served - 50));
});
