<?php

declare(strict_types=1);

/*
 * The query budget of the training cycle (R12g): `POST /training/sessions` (open), `GET /next`, `POST /review` and
 * `POST /finish` of a Free learner on a lesson, with the caches warm (the plan, the lesson's word count), the way a
 * client under load meets them. The real client takes the next card from the review answer and asks `/next` only at
 * the start and after a conflict, so `/review` is the request that matters.
 *
 * What each step must NOT do any more (the numbers are the statements on PostgreSQL and sqlite alike):
 *   - read the plan more than once a request (the entitlement is resolved once and handed down);
 *   - read the repetition and then its word (one joined read; the join is the scope);
 *   - count the session's grades and the lesson's progress in two statements;
 *   - count the quota twice (the decision under the lock, the display from it);
 *   - `INSERT ... ON CONFLICT` the day row and read the goal on every grade (only on the first grade of the day);
 *   - look at `achievements` on every new card (once a day, on the first grade).
 */

use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use App\Models\TrainingSession;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CardAnswers;
use Tests\Support\HotPathFixtures as Fx;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

/**
 * A Free learner with warm caches and an open lesson session of 25 words who has graded one card today already.
 *
 * @return array{learner: Learner, headers: array<string, string>, lesson_id: int, base: string, card: array<string, mixed>}
 */
function trainingCycleScenario(object $test): array
{
    // The numbers below are counted against a Free quota of 20 new words a day, not the launch default.
    config()->set('billing.plans.free.limits.new_words_per_day', 20);
    ['lessons' => $lessons] = Fx::course([1 => [1 => 25]]);
    $lessonId = (int) $lessons['1.1']->id;

    // Another learner warms what is shared: the lesson's word count and the translations of its first cards.
    $other = Learner::factory()->onboarded()->create();
    $otherHeaders = ['Authorization' => 'Bearer '.app(JwtService::class)->issue($other->id)['token']];
    $opened = $test->withHeaders($otherHeaders)->postJson('/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $lessonId, 'source' => 'self'])->assertOk()->json();
    $card = $opened['card'];
    for ($i = 0; $i < 4; $i++) {
        $card = $test->withHeaders($otherHeaders)->postJson("/api/twa/training/sessions/{$opened['session_id']}/review", ['word_id' => $card['word_id'], 'quality' => 4, 'time_spent_ms' => 100, 'serve_token' => $card['serve_token']])->assertOk()->json('card');
    }

    $learner = Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent']);
    $headers = ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
    $test->withHeaders($headers)->getJson('/api/twa/me')->assertOk(); // warms the plan, as the client does before training

    return ['learner' => $learner, 'headers' => $headers, 'lesson_id' => $lessonId, 'base' => '', 'card' => []];
}

/** @return array<string, mixed> the JSON of the response */
function trainingCall(object $test, array $headers, string $method, string $uri, array $body = []): array
{
    return $test->withHeaders($headers)->json($method, $uri, $body)->assertOk()->json();
}

it('keeps every step of the training cycle inside its statement budget', function (): void {
    $s = trainingCycleScenario($this);
    $h = $s['headers'];

    $opened = null;
    $open = QueryLog::during(function () use (&$opened, $s, $h): void {
        $opened = trainingCall($this, $h, 'POST', '/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $s['lesson_id'], 'source' => 'self']);
    });
    $base = "/api/twa/training/sessions/{$opened['session_id']}";
    $card = $opened['card'];

    // open: learner (auth), the lesson with its stage and course in ONE read, the session lock + insert, the event,
    // the counters, the due pick, then the new-card transaction (learner lock, candidates with quota, insert): 10.
    expect(count($open))->toBeLessThanOrEqual(10)
        ->and(QueryLog::matching($open, '/from ["`]lessons["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($open, '/from ["`]subscriptions["`]/'))->toBe([]);

    // next, with the card already due: learner, session with access context, counters, due pick, displayed quota.
    $next = QueryLog::during(function () use (&$card, $base, $h): void {
        $card = trainingCall($this, $h, 'GET', "{$base}/next")['card'];
    });
    expect(count($next))->toBeLessThanOrEqual(5)
        ->and(QueryLog::matching($next, '/from ["`]words["`] where/'))->toBe([]); // the word rides with the repetition

    // The first grade of the day also places the learner in the league and checks the first achievement: not measured.
    $card = trainingCall($this, $h, 'POST', "{$base}/review", ['word_id' => $card['word_id'], 'quality' => 4, 'time_spent_ms' => 100, 'serve_token' => $card['serve_token']])['card'];

    // review of a new card, the next card new too: learner, session with access context | session lock, repetition+word,
    // UPDATE, INSERT review, day row (locked, with the goal), UPDATE day row | counters, due pick, learner lock,
    // candidates with quota, INSERT repetition, translation of a word nobody saw yet: at most 14.
    $review = QueryLog::during(function () use (&$card, $base, $h): void {
        $card = trainingCall($this, $h, 'POST', "{$base}/review", ['word_id' => $card['word_id'], 'quality' => 4, 'time_spent_ms' => 100, 'serve_token' => $card['serve_token']])['card'];
    });
    expect(count($review))->toBeLessThanOrEqual(14)
        ->and(QueryLog::matching($review, '/from ["`]subscriptions["`]/'))->toBe([])
        ->and(QueryLog::matching($review, '/from ["`]achievements["`]/'))->toBe([])
        ->and(QueryLog::matching($review, '/insert into ["`]learner_daily_stats["`]/'))->toBe([])
        ->and(QueryLog::matching($review, '/^select ["`]daily_goal["`]/'))->toBe([]) // it rides in the locked read of the day row
        ->and(QueryLog::matching($review, '/from ["`]learner_daily_stats["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($review, '/from ["`]training_sessions["`]/'))->toHaveCount(2) // the plain read, the lock
        ->and(QueryLog::matching($review, '/count\(\*\).*["`]created_at["`] >= \?/'))->toHaveCount(1) // the quota, once
        ->and(QueryLog::matching($review, '/from ["`]words["`] where/'))->toHaveCount(1); // the new-word candidates only

    if (DB::getDriverName() === 'pgsql') {
        // The session row and the day row are locked, and the repetition row ALONE - not the word it was joined with.
        expect(QueryLog::matching($review, '/for update$/'))->toHaveCount(2)
            ->and(QueryLog::matching($review, '/for update of "word_repetitions"$/'))->toHaveCount(1)
            ->and(QueryLog::matching($review, '/for no key update$/'))->toHaveCount(1);
    }

    // The same grade once the quota is spent and the next card comes from learn-ahead: no more than that either.
    for ($i = 0; $i < 18; $i++) {
        $card = trainingCall($this, $h, 'POST', "{$base}/review", ['word_id' => $card['word_id'], 'quality' => 4, 'time_spent_ms' => 100, 'serve_token' => $card['serve_token']])['card'];
    }
    // The learn-ahead card is a repeat: an objective (four-option) card under the early ladder (docs/06 §13.1). The body is
    // built outside the measured closure (the helper reads the word to pick the right option).
    $body = CardAnswers::body($card, 4, 100);
    $ahead = QueryLog::during(function () use (&$card, $base, $h, $body): void {
        $card = trainingCall($this, $h, 'POST', "{$base}/review", $body)['card'];
    });
    expect($card['card_kind'])->toBe('learning')
        ->and($card['serve_token'])->not->toBeNull()
        ->and(count($ahead))->toBeLessThanOrEqual(14);

    // finish: learner, session, the lock + UPDATE, the summary in ONE statement, the event, the referral, today: 9; +2 for the
    // invitation to the round of hard words (docs/06 §14): the hard sessions of the day window and the candidates: 11. No corrected
    // mistake is looked for outside the round of hard words: `training_reviews` is read once, by the summary.
    $finish = null;
    $finishLog = QueryLog::during(function () use (&$finish, $base, $h): void {
        $finish = trainingCall($this, $h, 'POST', "{$base}/finish");
    });
    expect(count($finishLog))->toBeLessThanOrEqual(11)
        ->and(QueryLog::matching($finishLog, '/from ["`]training_reviews["`]/'))->toHaveCount(1)
        ->and($finish['corrected'])->toBeNull();
});

it('keeps the finish of a round of hard words inside its budget: the summary and the corrected mistake, one statement each', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 05:00:00', 'UTC'));
    config(['learning.hard_round' => ['enabled' => true, 'words' => 10, 'min_words' => 6, 'per_day' => 1, 'lapses_from' => 2, 'due_within_days' => 2]]);
    ['course' => $course, 'words' => $ids] = Fx::course([1 => [1 => 30]]);
    $learner = Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent', 'active_course_id' => $course->id]);
    $h = ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
    $words = array_slice($ids['1.1']['active'], 0, 10);
    foreach ($words as $wordId) {
        WordRepetition::factory()->create([
            'learner_id' => $learner->id, 'word_id' => $wordId, 'state' => RepetitionState::Review->value, 'repetitions' => 4, 'interval_days' => 10,
            'easiness_factor' => 2.5, 'lapses' => 2, 'is_hard' => false, 'next_review_at' => now()->addDay(), 'last_reviewed_at' => now()->subDays(3),
            'last_quality' => 4, 'introduced_mode' => 'daily_review', 'served_ahead_token' => null, 'meta' => [], 'created_at' => now()->subDays(5),
        ]);
    }
    // A wrong typed answer to one of them three days ago: the finish finds it and tells it with its translation.
    $earlier = TrainingSession::factory()->personal($learner, 'daily_review')->closed()->create(['started_at' => now()->subDays(3)]);
    DB::table('training_reviews')->insert([
        'training_session_id' => $earlier->id, 'learner_id' => $learner->id, 'word_id' => $words[0], 'quality' => 1, 'exercise' => 'spelling',
        'answer_text' => 'wrnog', 'scheduler' => 'sm2', 'interval_before_days' => 4, 'time_spent_ms' => 2000, 'created_at' => now()->subDays(3),
    ]);

    $opened = trainingCall($this, $h, 'POST', '/api/twa/training/sessions', ['mode' => 'hard_review']);
    $base = "/api/twa/training/sessions/{$opened['session_id']}";
    $card = $opened['card'];
    while ($card !== null) {
        $card = trainingCall($this, $h, 'POST', "{$base}/review", CardAnswers::body($card, 4, 100))['card'];
    }

    // The finish of any session without the invitation (the round carries none): 9; +1 for the corrected mistake (CorrectedMistakeFinder:
    // ONE statement; the translation of the word is warm - its card was served in this round, in the same locale): 10.
    $finish = null;
    $finishLog = QueryLog::during(function () use (&$finish, $base, $h): void {
        $finish = trainingCall($this, $h, 'POST', "{$base}/finish");
    });
    expect(count($finishLog))->toBeLessThanOrEqual(10)
        ->and(QueryLog::matching($finishLog, '/from ["`]training_reviews["`]/'))->toHaveCount(2)
        ->and(QueryLog::matching($finishLog, '/from ["`]word_translations["`]/'))->toBe([])
        ->and($finish['corrected']['word_id'] ?? null)->toBe($words[0])
        ->and($finish['corrected']['answer'] ?? null)->toBe('wrnog');
});

it('answers the same numbers as before: progress, quota and the summary', function (): void {
    $s = trainingCycleScenario($this);
    $h = $s['headers'];

    $opened = trainingCall($this, $h, 'POST', '/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $s['lesson_id'], 'source' => 'self']);
    $base = "/api/twa/training/sessions/{$opened['session_id']}";
    expect($opened['progress'])->toBe(['done' => 0, 'total' => 25])
        ->and($opened['quota'])->toBe(['new_words_remaining' => 19]) // the card just served is spent
        ->and($opened['card']['card_kind'])->toBe('new');

    $card = $opened['card'];
    $res = trainingCall($this, $h, 'POST', "{$base}/review", ['word_id' => $card['word_id'], 'quality' => 5, 'time_spent_ms' => 100]);
    // "Easy" graduates the card (done 1); the next card is new (18 left).
    expect($res['progress'])->toBe(['done' => 1, 'total' => 25])
        ->and($res['quota'])->toBe(['new_words_remaining' => 18])
        ->and($res['card']['card_kind'])->toBe('new');

    // Asking again serves the same (due) card and spends nothing: the quota is read, not recounted after a pick.
    $again = trainingCall($this, $h, 'GET', "{$base}/next");
    expect($again['card']['word_id'])->toBe($res['card']['word_id'])
        ->and($again['quota'])->toBe(['new_words_remaining' => 18]);

    $summary = trainingCall($this, $h, 'POST', "{$base}/finish");
    expect($summary)->toMatchArray(['reviewed' => 1, 'correct' => 1, 'new_words' => 1]);
});
