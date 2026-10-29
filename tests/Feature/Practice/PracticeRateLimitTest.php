<?php

declare(strict_types=1);

use App\Domain\Practice\Enums\PracticeMode;
use App\Models\Learner;
use App\Models\PracticeSession;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Practice\FakeEchoMode;
use Tests\Support\Practice\PracticeKit;

/*
 * docs/16 §7.5: six limiters, keyed by the learner (`twa-api` is the common one). Opening and answering sit on top of `twa-api`;
 * the audio lives OUTSIDE it, so that five clips loaded at once do not eat the budget of 60 requests a minute. The limiters of
 * the battle (invite, action, poll) are registered here; their routes arrive with 18.6b.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 10:00:00');
    PracticeKit::openAccess();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** The middleware of a named route. @return list<string> */
function limiterMiddleware(string $route): array
{
    $found = Route::getRoutes()->getByName($route);
    if ($found === null) {
        throw new RuntimeException("No route {$route}.");
    }

    return array_values(array_filter(
        array_map('strval', $found->gatherMiddleware()),
        static fn (string $name): bool => str_starts_with($name, 'throttle:'),
    ));
}

it('registers the limiters of docs/16 §7.5 with their budgets, one bucket per learner and the IP for nobody', function (): void {
    $budgets = [
        'twa-practice-open' => 10,
        'twa-practice-answer' => 90,
        'twa-practice-audio' => 60,
        'twa-battle-invite' => 5,
        'twa-battle-action' => 60,
        'twa-battle-poll' => 90,
    ];

    foreach ($budgets as $name => $perMinute) {
        $limiter = RateLimiter::limiter($name);
        expect($limiter)->not->toBeNull();

        $request = Request::create('/api/twa/practice/hub', 'GET', server: ['REMOTE_ADDR' => '10.1.2.3']);
        $anonymous = $limiter($request);
        $request->attributes->set('twa', ['learner_id' => 42]);
        $known = $limiter($request);

        expect($known)->toBeInstanceOf(Limit::class)
            ->and($known->maxAttempts)->toBe($perMinute)
            ->and($known->decaySeconds)->toBe(60)
            ->and($known->key)->toBe("{$name}|42")
            ->and($anonymous->key)->toBe("{$name}|10.1.2.3");
    }
});

it('puts each route behind the limiters the document names, and the audio outside `twa-api`', function (): void {
    expect(limiterMiddleware('twa.practice.hub'))->toBe(['throttle:twa-api'])
        ->and(limiterMiddleware('twa.practice.sessions.show'))->toBe(['throttle:twa-api'])
        ->and(limiterMiddleware('twa.practice.sessions.store'))->toBe(['throttle:twa-api', 'throttle:twa-practice-open'])
        ->and(limiterMiddleware('twa.practice.sessions.answer'))->toBe(['throttle:twa-api', 'throttle:twa-practice-answer'])
        ->and(limiterMiddleware('twa.practice.sessions.replace'))->toBe(['throttle:twa-api', 'throttle:twa-practice-answer'])
        ->and(limiterMiddleware('twa.practice.sessions.audio'))->toBe(['throttle:twa-practice-audio']);
});

it('refuses the 11th opening of a session in a minute with 429, for that learner only', function (): void {
    PracticeKit::fake(PracticeMode::Collocation);
    [$course] = PracticeKit::course();
    $learner = Learner::factory()->create();
    $headers = PracticeKit::headers($learner);
    $open = fn (array $headers) => $this->withHeaders($headers)->postJson('/api/twa/practice/sessions', ['mode' => 'collocation', 'course' => $course->slug]);

    $open($headers)->assertCreated();
    foreach (range(2, 10) as $_) {
        $open($headers)->assertStatus(409); // the session is on: refused, but it counts
    }
    $open($headers)->assertStatus(429)->assertHeader('Retry-After');

    $open(PracticeKit::headers(Learner::factory()->create()))->assertCreated();
    $this->withHeaders($headers)->getJson("/api/twa/practice/hub?course={$course->slug}")->assertOk();
    expect(PracticeSession::query()->count())->toBe(2);
});

it('lets 90 answers a minute through the bucket of the answers - `twa-api` stops at 60 first - and stops the 91st', function (): void {
    PracticeKit::fake(PracticeMode::Collocation);
    [$course] = PracticeKit::course();
    $learner = Learner::factory()->create();
    $headers = PracticeKit::headers($learner);
    $session = $this->withHeaders($headers)->postJson('/api/twa/practice/sessions', ['mode' => 'collocation', 'course' => $course->slug])->assertCreated()->json();
    $answer = ['request_id' => (string) Str::uuid(), 'element_id' => $session['current']['id'], 'answer' => ['skip' => true]];

    // The common bucket is full first (60), and says so for every route behind it.
    PracticeKit::exhaust('twa-api', $learner, 60);
    $this->withHeaders($headers)->getJson("/api/twa/practice/sessions/{$session['id']}")->assertStatus(429);
    $this->withHeaders($headers)->postJson("/api/twa/practice/sessions/{$session['id']}/answer", $answer)->assertStatus(429);
    PracticeKit::resetLimiters($learner);

    // The bucket of the answers is shared by `answer` and `replace`, and no other route is behind it.
    PracticeKit::exhaust('twa-practice-answer', $learner, 89);
    $this->withHeaders($headers)->postJson("/api/twa/practice/sessions/{$session['id']}/answer", $answer)->assertOk();
    $this->withHeaders($headers)->postJson("/api/twa/practice/sessions/{$session['id']}/answer", $answer)->assertStatus(429)->assertHeader('Retry-After');
    $this->withHeaders($headers)->postJson("/api/twa/practice/sessions/{$session['id']}/replace", ['request_id' => (string) Str::uuid(), 'element_id' => $answer['element_id'], 'reason' => 'audio_unavailable'])->assertStatus(429);
    $this->withHeaders($headers)->getJson("/api/twa/practice/sessions/{$session['id']}")->assertOk();
});

it('gives the audio a bucket of its own: 60 clips a minute, and `twa-api` is not touched by them', function (): void {
    PracticeKit::fake(PracticeMode::Dictation, candidates: 6);
    Storage::fake(FakeEchoMode::AUDIO_DISK);
    [$course] = PracticeKit::course();
    $learner = Learner::factory()->create();
    $headers = PracticeKit::headers($learner);
    $session = $this->withHeaders($headers)->postJson('/api/twa/practice/sessions', ['mode' => 'dictation', 'course' => $course->slug])->assertCreated()->json();

    foreach (range(1, 60) as $_) {
        $this->withHeaders($headers)->get($session['current']['audio_url'])->assertStatus(503); // no file: the limiter does not mind
    }
    $this->withHeaders($headers)->get($session['current']['audio_url'])->assertStatus(429)->assertHeader('Retry-After');

    // 60 clips and a refusal later the general budget is whole.
    $this->withHeaders($headers)->getJson("/api/twa/practice/sessions/{$session['id']}")->assertOk();
    $this->withHeaders($headers)->getJson("/api/twa/practice/hub?course={$course->slug}")->assertOk();
    $this->withHeaders(PracticeKit::headers(Learner::factory()->create()))->get($session['current']['audio_url'])->assertNotFound();
});
