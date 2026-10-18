<?php

declare(strict_types=1);

use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Exceptions\ReviewSubmissionException;
use App\Domain\Learning\Services\ReviewSubmissionService;
use App\Domain\Learning\Support\LearningPolicy;
use App\Domain\Twa\Services\JwtService;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Student;
use App\Models\TelegramGroup;
use App\Models\TrainingReview;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\Word;
use App\Models\WordRepetition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function authHeaders(Student $student): array
{
    /** @var JwtService $jwt */
    $jwt = app(JwtService::class);
    $token = $jwt->issue($student->id, (int) $student->telegram_group_id)['token'];

    return ['Authorization' => 'Bearer '.$token];
}

/**
 * @return array{session: TrainingSession, student: Student, lesson: Lesson, words: Collection}
 */
function seedTrainingScenario(int $wordCount = 3): array
{
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $teacher = User::factory()->create();
    $student = Student::factory()->create(['telegram_group_id' => $group->id, 'is_active' => true]);

    $stage = Stage::factory()->create();
    $lesson = Lesson::factory()->for($stage)->create();
    $words = Word::factory()->count($wordCount)->for($lesson)->create();

    $session = TrainingSession::factory()->create([
        'telegram_group_id' => $group->id,
        'lesson_id' => $lesson->id,
        'started_by_user_id' => $teacher->id,
        'status' => 'open',
    ]);

    return compact('session', 'student', 'lesson', 'words');
}

it('GET /me returns student + group + stats', function (): void {
    $s = seedTrainingScenario();

    $this->withHeaders(authHeaders($s['student']))
        ->getJson('/api/twa/me')
        ->assertOk()
        ->assertJsonStructure(['student' => ['id', 'first_name'], 'group' => ['id', 'title'], 'stats']);
});

it('rejects requests without a Bearer token', function (): void {
    $this->getJson('/api/twa/me')->assertStatus(401);
});

it('rejects requests with an invalid token', function (): void {
    $this->withHeaders(['Authorization' => 'Bearer totally-bogus'])
        ->getJson('/api/twa/me')
        ->assertStatus(401);
});

it('starts a training session for an eligible student', function (): void {
    $s = seedTrainingScenario();

    $this->withHeaders(authHeaders($s['student']))
        ->postJson("/api/twa/training/sessions/{$s['session']->id}/start")
        ->assertOk()
        ->assertJsonStructure([
            'session_id',
            'lesson' => ['stage', 'lesson', 'title'],
            'total_words',
            'card',
            'progress' => ['done', 'total'],
        ])
        ->assertJsonPath('total_words', 3);
});

it('returns 403 when a student from another group tries to start', function (): void {
    $s = seedTrainingScenario();

    $otherGroup = TelegramGroup::factory()->create(['status' => 'active']);
    $intruder = Student::factory()->create(['telegram_group_id' => $otherGroup->id]);

    $this->withHeaders(authHeaders($intruder))
        ->postJson("/api/twa/training/sessions/{$s['session']->id}/start")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');
});

it('returns 410 for a closed training session', function (): void {
    $s = seedTrainingScenario();
    $s['session']->update(['status' => 'closed', 'ended_at' => now()]);

    $this->withHeaders(authHeaders($s['student']))
        ->postJson("/api/twa/training/sessions/{$s['session']->id}/start")
        ->assertStatus(410);
});

it('returns 404 when the session does not exist', function (): void {
    $s = seedTrainingScenario();

    $this->withHeaders(authHeaders($s['student']))
        ->getJson('/api/twa/training/sessions/99999/next')
        ->assertStatus(404);
});

it('GET /next returns a new card and creates an initial repetition row', function (): void {
    $s = seedTrainingScenario(2);

    $resp = $this->withHeaders(authHeaders($s['student']))
        ->getJson("/api/twa/training/sessions/{$s['session']->id}/next")
        ->assertOk()
        ->assertJsonPath('card.card_kind', 'new');

    $wordId = $resp->json('card.word_id');

    expect(WordRepetition::query()
        ->where('student_id', $s['student']->id)
        ->where('word_id', $wordId)
        ->exists())->toBeTrue();
});

it('completes a full training cycle and updates intervals', function (): void {
    $s = seedTrainingScenario(2);
    $headers = authHeaders($s['student']);

    // start
    $this->withHeaders($headers)
        ->postJson("/api/twa/training/sessions/{$s['session']->id}/start")
        ->assertOk();

    // pull 2 new cards and review them
    for ($i = 0; $i < 2; $i++) {
        $nextResp = $this->withHeaders($headers)
            ->getJson("/api/twa/training/sessions/{$s['session']->id}/next")
            ->assertOk();

        $wordId = $nextResp->json('card.word_id');
        expect($wordId)->toBeInt();

        $this->withHeaders($headers)
            ->postJson("/api/twa/training/sessions/{$s['session']->id}/review", [
                'word_id' => $wordId,
                'quality' => 5,
                'time_spent_ms' => 3000,
            ])
            ->assertOk()
            ->assertJsonStructure([
                'next_review_at',
                'new_interval_days',
                'easiness_factor',
                'card',
                'progress' => ['done', 'total'],
            ]);
    }

    // after 2 successful "easy" reviews, each WordRepetition has repetitions >= 1
    expect(WordRepetition::query()
        ->where('student_id', $s['student']->id)
        ->where('repetitions', '>=', 1)
        ->count())->toBe(2);

    // training_reviews log has 2 rows
    expect(TrainingReview::query()
        ->where('student_id', $s['student']->id)
        ->count())->toBe(2);

    // No more "new" words -> card is null
    $this->withHeaders($headers)
        ->getJson("/api/twa/training/sessions/{$s['session']->id}/next")
        ->assertOk()
        ->assertJsonPath('card', null);
});

it('rejects review with quality out of range (validation 422)', function (): void {
    $s = seedTrainingScenario();
    $word = $s['words']->first();

    WordRepetition::factory()->create([
        'student_id' => $s['student']->id,
        'word_id' => $word->id,
    ]);

    $this->withHeaders(authHeaders($s['student']))
        ->postJson("/api/twa/training/sessions/{$s['session']->id}/review", [
            'word_id' => $word->id,
            'quality' => 99,
            'time_spent_ms' => 1000,
        ])
        ->assertStatus(422);
});

it('rejects review for another student\'s session (IDOR)', function (): void {
    $s = seedTrainingScenario();
    $word = $s['words']->first();

    WordRepetition::factory()->create([
        'student_id' => $s['student']->id,
        'word_id' => $word->id,
    ]);

    $otherGroup = TelegramGroup::factory()->create(['status' => 'active']);
    $intruder = Student::factory()->create(['telegram_group_id' => $otherGroup->id]);

    $this->withHeaders(authHeaders($intruder))
        ->postJson("/api/twa/training/sessions/{$s['session']->id}/review", [
            'word_id' => $word->id,
            'quality' => 5,
            'time_spent_ms' => 1000,
        ])
        ->assertStatus(403);
});

it('returns 409 when reviewing a card that was never served', function (): void {
    $s = seedTrainingScenario();
    $word = $s['words']->first();

    $this->withHeaders(authHeaders($s['student']))
        ->postJson("/api/twa/training/sessions/{$s['session']->id}/review", [
            'word_id' => $word->id,
            'quality' => 5,
            'time_spent_ms' => 1000,
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'card_not_started');
});

it('returns 422 when reviewing a word from another lesson', function (): void {
    $s = seedTrainingScenario();

    // word from a different lesson in the same stage
    $otherLesson = Lesson::factory()->for(Stage::factory())->create();
    $otherWord = Word::factory()->for($otherLesson)->create();

    WordRepetition::factory()->create([
        'student_id' => $s['student']->id,
        'word_id' => $otherWord->id,
    ]);

    $this->withHeaders(authHeaders($s['student']))
        ->postJson("/api/twa/training/sessions/{$s['session']->id}/review", [
            'word_id' => $otherWord->id,
            'quality' => 5,
            'time_spent_ms' => 1000,
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'wrong_lesson');
});

it('does not apply the same card review more than once', function (): void {
    $s = seedTrainingScenario(1);
    $headers = authHeaders($s['student']);

    $wordId = $this->withHeaders($headers)
        ->getJson("/api/twa/training/sessions/{$s['session']->id}/next")
        ->assertOk()
        ->json('card.word_id');

    $payload = [
        'word_id' => $wordId,
        'quality' => 5,
        'time_spent_ms' => 1000,
    ];

    $this->withHeaders($headers)
        ->postJson("/api/twa/training/sessions/{$s['session']->id}/review", $payload)
        ->assertOk();

    $this->withHeaders($headers)
        ->postJson("/api/twa/training/sessions/{$s['session']->id}/review", $payload)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'card_not_due');

    expect(TrainingReview::query()->where('student_id', $s['student']->id)->count())->toBe(1);
    expect(WordRepetition::query()->where('student_id', $s['student']->id)->value('repetitions'))->toBe(1);
});

it('rejects a review when a stale session model was closed concurrently', function (): void {
    $s = seedTrainingScenario(1);
    $session = $s['session']->fresh();
    $word = $s['words']->first();

    WordRepetition::factory()->create([
        'student_id' => $s['student']->id,
        'word_id' => $word->id,
        'next_review_at' => now()->subMinute(),
    ]);

    TrainingSession::query()
        ->whereKey($session->id)
        ->update(['status' => 'closed', 'ended_at' => now()]);

    expect(fn () => app(ReviewSubmissionService::class)->submit(
        $session,
        $s['student'],
        (int) $word->id,
        5,
        1000,
    ))->toThrow(ReviewSubmissionException::class);

    expect(TrainingReview::query()->where('training_session_id', $session->id)->count())->toBe(0);
});

it('stops serving cards at the configured per-session limit', function (): void {
    config(['twa.training.max_cards_per_session' => 1]);

    $s = seedTrainingScenario(2);
    $headers = authHeaders($s['student']);
    $wordId = $this->withHeaders($headers)
        ->getJson("/api/twa/training/sessions/{$s['session']->id}/next")
        ->assertOk()
        ->json('card.word_id');

    $this->withHeaders($headers)
        ->postJson("/api/twa/training/sessions/{$s['session']->id}/review", [
            'word_id' => $wordId,
            'quality' => 5,
            'time_spent_ms' => 1000,
        ])
        ->assertOk()
        ->assertJsonPath('card', null);

    $this->withHeaders($headers)
        ->getJson("/api/twa/training/sessions/{$s['session']->id}/next")
        ->assertOk()
        ->assertJsonPath('card', null);

    expect(TrainingReview::query()->where('training_session_id', $s['session']->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Learning steps inside a session (docs/06 §3, §5)
// ---------------------------------------------------------------------------

function bindLearningPolicy(int $learnAheadMinutes = 20, array $learningSteps = [1, 10], array $relearningSteps = [10]): void
{
    app()->instance(LearningPolicy::class, new LearningPolicy(
        learningStepsMinutes: $learningSteps,
        relearningStepsMinutes: $relearningSteps,
        graduatingIntervalDays: 1,
        easyIntervalDays: 4,
        learnAheadMinutes: $learnAheadMinutes,
    ));
}

/**
 * Builds the POST /review body the TWA client sends for a card from /next, /start or /review.
 *
 * @param  array{word_id:int, serve_token?:string|null}  $card
 * @return array{word_id:int, quality:int, time_spent_ms:int, serve_token:string|null}
 */
function reviewPayload(array $card, int $quality): array
{
    return [
        'word_id' => $card['word_id'],
        'quality' => $quality,
        'time_spent_ms' => 1000,
        'serve_token' => $card['serve_token'] ?? null,
    ];
}

it('shows a card graded "Трудно" again in the same session as a learning card', function (): void {
    bindLearningPolicy();
    $s = seedTrainingScenario(1);
    $headers = authHeaders($s['student']);
    $url = "/api/twa/training/sessions/{$s['session']->id}";

    $card = $this->withHeaders($headers)->getJson("{$url}/next")->assertOk()
        ->assertJsonPath('card.card_kind', 'new')
        ->assertJsonPath('card.serve_token', null)
        ->json('card');

    // Again: restarts learning steps (1 min). Nothing else to show -> learn-ahead serves it immediately.
    $resp = $this->withHeaders($headers)
        ->postJson("{$url}/review", reviewPayload($card, 2))
        ->assertOk()
        ->assertJsonPath('new_interval_days', 0)
        ->assertJsonPath('card.word_id', $card['word_id'])
        ->assertJsonPath('card.card_kind', 'learning')
        ->assertJsonPath('progress.done', 0);

    $rep = WordRepetition::query()->where('student_id', $s['student']->id)->where('word_id', $card['word_id'])->firstOrFail();
    expect($rep->state)->toBe(RepetitionState::Learning)
        ->and($rep->is_hard)->toBeTrue()
        ->and($rep->served_ahead_at)->not->toBeNull()
        ->and($rep->served_ahead_token)->toBeString()->toHaveLength(32)
        ->and($resp->json('card.serve_token'))->toBe($rep->served_ahead_token)
        ->and($rep->next_review_at->isAfter(now()))->toBeTrue();
});

it('does not finish the session while learning cards remain, and finishes once they graduate', function (): void {
    bindLearningPolicy();
    $s = seedTrainingScenario(1);
    $headers = authHeaders($s['student']);
    $url = "/api/twa/training/sessions/{$s['session']->id}";

    $card = $this->withHeaders($headers)->getJson("{$url}/next")->json('card');

    // Good on a new card -> step 2 (10 min). Served ahead, not finished.
    $card = $this->withHeaders($headers)
        ->postJson("{$url}/review", reviewPayload($card, 4))
        ->assertOk()
        ->assertJsonPath('card.word_id', $card['word_id'])
        ->assertJsonPath('card.card_kind', 'learning')
        ->json('card');
    expect($card['serve_token'])->toBeString();

    // Good on the last step -> graduates to 1 day. Now nothing is left: finished.
    $this->withHeaders($headers)
        ->postJson("{$url}/review", reviewPayload($card, 4))
        ->assertOk()
        ->assertJsonPath('new_interval_days', 1)
        ->assertJsonPath('card', null)
        ->assertJsonPath('progress.done', 1);

    $this->withHeaders($headers)->getJson("{$url}/next")->assertOk()->assertJsonPath('card', null);

    expect(TrainingReview::query()->where('training_session_id', $s['session']->id)->count())->toBe(2);
    $rep = WordRepetition::query()->where('word_id', $card['word_id'])->firstOrFail();
    expect($rep->state)->toBe(RepetitionState::Review)
        ->and($rep->served_ahead_token)->toBeNull();
});

it('"Средне" (q=4) keeps EF at 2.50 across the whole lesson', function (): void {
    bindLearningPolicy();
    $s = seedTrainingScenario(2);
    $headers = authHeaders($s['student']);
    $url = "/api/twa/training/sessions/{$s['session']->id}";

    for ($i = 0; $i < 10; $i++) {
        $card = $this->withHeaders($headers)->getJson("{$url}/next")->assertOk()->json('card');
        if ($card === null) {
            break;
        }
        $this->withHeaders($headers)
            ->postJson("{$url}/review", reviewPayload($card, 4))
            ->assertOk()
            ->assertJsonPath('easiness_factor', 2.5);
    }

    $efs = WordRepetition::query()->where('student_id', $s['student']->id)->pluck('easiness_factor')
        ->map(fn ($v): float => (float) $v)->all();
    expect($efs)->toBe([2.5, 2.5]);
    expect(WordRepetition::query()->where('student_id', $s['student']->id)->where('state', RepetitionState::Review->value)->count())->toBe(2);
});

it('rejects a replayed review when the same learn-ahead card was re-served in the response (409 card_not_due)', function (): void {
    // Review finding A: lesson of 1 word -> /next -> review(q=4) re-serves the same
    // card ahead with a new ticket. Replaying the identical request must fail.
    bindLearningPolicy();
    $s = seedTrainingScenario(1);
    $headers = authHeaders($s['student']);
    $url = "/api/twa/training/sessions/{$s['session']->id}";

    $card = $this->withHeaders($headers)->getJson("{$url}/next")->assertOk()->json('card');
    $payload = reviewPayload($card, 4);

    $reserved = $this->withHeaders($headers)->postJson("{$url}/review", $payload)->assertOk()
        ->assertJsonPath('card.word_id', $card['word_id'])
        ->assertJsonPath('card.card_kind', 'learning')
        ->json('card');
    expect($reserved['serve_token'])->toBeString()->not->toBe($payload['serve_token']);

    // Exact replay (same body: no token, because the first serve was a due "new" card).
    $this->withHeaders($headers)->postJson("{$url}/review", $payload)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'card_not_due');

    // Replay with a forged / foreign token is rejected too.
    $this->withHeaders($headers)->postJson("{$url}/review", [...$payload, 'serve_token' => str_repeat('a', 32)])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'card_not_due');

    $rep = WordRepetition::query()->where('word_id', $card['word_id'])->firstOrFail();
    expect(TrainingReview::query()->where('training_session_id', $s['session']->id)->count())->toBe(1)
        ->and($rep->state)->toBe(RepetitionState::Learning)
        ->and($rep->learning_step)->toBe(1);

    // The legitimate second answer (with the re-served ticket) graduates the card...
    $this->withHeaders($headers)->postJson("{$url}/review", reviewPayload($reserved, 4))
        ->assertOk()
        ->assertJsonPath('new_interval_days', 1)
        ->assertJsonPath('card', null);

    // ...and replaying *that* one fails as well: the ticket was consumed.
    $this->withHeaders($headers)->postJson("{$url}/review", reviewPayload($reserved, 4))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'card_not_due');

    expect(TrainingReview::query()->where('training_session_id', $s['session']->id)->count())->toBe(2);
});

it('still rejects a replayed review of a learning card that was not served again (409 card_not_due)', function (): void {
    bindLearningPolicy();
    $s = seedTrainingScenario(2);
    $headers = authHeaders($s['student']);
    $url = "/api/twa/training/sessions/{$s['session']->id}";

    $first = $this->withHeaders($headers)->getJson("{$url}/next")->json('card');
    $payload = reviewPayload($first, 4);

    // Response serves the second (new) word, so the first is pending for 10 min with no ticket.
    $second = $this->withHeaders($headers)->postJson("{$url}/review", $payload)->assertOk()
        ->assertJsonPath('card.card_kind', 'new')
        ->json('card.word_id');
    expect($second)->not->toBe($first['word_id']);

    $this->withHeaders($headers)->postJson("{$url}/review", $payload)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'card_not_due');

    expect(TrainingReview::query()->where('training_session_id', $s['session']->id)->count())->toBe(1);
});

it('accepts a learn-ahead served card exactly once even when two clients fetched it concurrently', function (): void {
    bindLearningPolicy();
    $s = seedTrainingScenario(2);
    $headers = authHeaders($s['student']);
    $url = "/api/twa/training/sessions/{$s['session']->id}";

    // Put both words into learning (step 2, due in 10 min).
    $a = $this->withHeaders($headers)->getJson("{$url}/next")->json('card');
    $b = $this->withHeaders($headers)
        ->postJson("{$url}/review", reviewPayload($a, 4))
        ->json('card');
    $ahead = $this->withHeaders($headers)
        ->postJson("{$url}/review", reviewPayload($b, 4))
        ->assertJsonPath('card.card_kind', 'learning')
        ->json('card');
    expect($ahead['word_id'])->toBe($a['word_id']);

    // Two "tabs" fetch /next: both get A served ahead with the *same* outstanding ticket.
    $tab1 = $this->withHeaders($headers)->getJson("{$url}/next")->assertJsonPath('card.word_id', $a['word_id'])->json('card');
    $tab2 = $this->withHeaders($headers)->getJson("{$url}/next")->assertJsonPath('card.word_id', $a['word_id'])->json('card');
    expect($tab1['serve_token'])->toBe($ahead['serve_token'])
        ->and($tab2['serve_token'])->toBe($ahead['serve_token']);

    // First tab graduates A (1 day); B is served ahead.
    $this->withHeaders($headers)->postJson("{$url}/review", reviewPayload($tab1, 4))->assertOk()
        ->assertJsonPath('new_interval_days', 1)
        ->assertJsonPath('card.word_id', $b['word_id']);

    // Second tab answers the same serve: A is a review card due tomorrow -> rejected.
    $this->withHeaders($headers)->postJson("{$url}/review", reviewPayload($tab2, 4))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'card_not_due');

    expect(TrainingReview::query()->where('training_session_id', $s['session']->id)->where('word_id', $a['word_id'])->count())->toBe(2);
});

it('rejects a learn-ahead answer without a ticket, with a stale ticket or outside the window', function (): void {
    bindLearningPolicy(learnAheadMinutes: 5, learningSteps: [1, 3], relearningSteps: [3]);
    $s = seedTrainingScenario(1);
    $word = $s['words']->first();

    $rep = WordRepetition::factory()->learning(step: 1)->create([
        'student_id' => $s['student']->id,
        'word_id' => $word->id,
        'next_review_at' => now()->addMinutes(3),
        'served_ahead_at' => null,
        'served_ahead_token' => null,
    ]);

    $service = app(ReviewSubmissionService::class);
    $submit = fn (?string $token) => $service->submit($s['session'], $s['student'], (int) $word->id, 4, 1000, $token);

    // Inside window but never served ahead -> not acceptable, whatever the client sends.
    expect(fn () => $submit(null))->toThrow(ReviewSubmissionException::class, 'not due');
    expect(fn () => $submit('deadbeef'))->toThrow(ReviewSubmissionException::class, 'not due');

    // Served ahead, but the client has no / a different token (old client, replay) -> rejected.
    $rep->forceFill(['served_ahead_at' => now(), 'served_ahead_token' => 't-current'])->save();
    expect(fn () => $submit(null))->toThrow(ReviewSubmissionException::class, 'not due');
    expect(fn () => $submit(''))->toThrow(ReviewSubmissionException::class, 'not due');
    expect(fn () => $submit('t-stale'))->toThrow(ReviewSubmissionException::class, 'not due');

    // Right token but beyond the window -> not acceptable.
    $rep->forceFill(['next_review_at' => now()->addMinutes(30)])->save();
    expect(fn () => $submit('t-current'))->toThrow(ReviewSubmissionException::class, 'not due');

    // Right token inside the window -> accepted, ticket consumed.
    $rep->forceFill(['next_review_at' => now()->addMinutes(3)])->save();
    $submit('t-current');
    $fresh = $rep->fresh();
    expect($fresh->served_ahead_at)->toBeNull()
        ->and($fresh->served_ahead_token)->toBeNull();
    expect(TrainingReview::query()->where('training_session_id', $s['session']->id)->count())->toBe(1);
});

it('accepts a due card with or without a serve_token (ticket only matters for learn-ahead)', function (): void {
    bindLearningPolicy();
    $s = seedTrainingScenario(2);
    $headers = authHeaders($s['student']);
    $url = "/api/twa/training/sessions/{$s['session']->id}";
    [$a, $b] = $s['words']->pluck('id')->all();

    WordRepetition::factory()->review()->create([
        'student_id' => $s['student']->id,
        'word_id' => $a,
        'next_review_at' => now()->subDay(),
    ]);
    WordRepetition::factory()->review()->create([
        'student_id' => $s['student']->id,
        'word_id' => $b,
        'next_review_at' => now()->subDay(),
    ]);

    // Old client: no serve_token field at all.
    $this->withHeaders($headers)
        ->postJson("{$url}/review", ['word_id' => $a, 'quality' => 4, 'time_spent_ms' => 1000])
        ->assertOk();

    // New client echoing a garbage token on a due card: still accepted (due wins).
    $this->withHeaders($headers)
        ->postJson("{$url}/review", ['word_id' => $b, 'quality' => 4, 'time_spent_ms' => 1000, 'serve_token' => 'whatever'])
        ->assertOk();

    // Oversized token is a validation error, not a 500.
    $this->withHeaders($headers)
        ->postJson("{$url}/review", ['word_id' => $b, 'quality' => 4, 'time_spent_ms' => 1000, 'serve_token' => str_repeat('x', 65)])
        ->assertStatus(422);
});

it('ends the session early when learn-ahead is disabled (card returns on its own schedule)', function (): void {
    bindLearningPolicy(learnAheadMinutes: 0);
    $s = seedTrainingScenario(1);
    $headers = authHeaders($s['student']);
    $url = "/api/twa/training/sessions/{$s['session']->id}";

    $card = $this->withHeaders($headers)->getJson("{$url}/next")->json('card');

    $this->withHeaders($headers)
        ->postJson("{$url}/review", reviewPayload($card, 2))
        ->assertOk()
        ->assertJsonPath('card', null);

    // Once the step elapses the card is due and is served as a learning card (no ticket needed).
    $this->travel(2)->minutes();
    $this->withHeaders($headers)->getJson("{$url}/next")->assertOk()
        ->assertJsonPath('card.word_id', $card['word_id'])
        ->assertJsonPath('card.card_kind', 'learning')
        ->assertJsonPath('card.serve_token', null);
});

it('prefers due cards over new ones and labels graduated cards as due', function (): void {
    bindLearningPolicy();
    $s = seedTrainingScenario(2);
    $headers = authHeaders($s['student']);
    $url = "/api/twa/training/sessions/{$s['session']->id}";
    [$a, $b] = $s['words']->pluck('id')->all();

    WordRepetition::factory()->review()->create([
        'student_id' => $s['student']->id,
        'word_id' => $b,
        'next_review_at' => now()->subDay(),
        'is_hard' => false,
    ]);

    // B is due (review) and must win over the unseen new word A.
    $this->withHeaders($headers)->getJson("{$url}/next")->assertOk()
        ->assertJsonPath('card.word_id', $b)
        ->assertJsonPath('card.card_kind', 'due');
});

it('runs the learning-state migration backfill and rollback cleanly', function (): void {
    $s = seedTrainingScenario(3);
    [$graduated, $failed, $untouched] = $s['words']->pluck('id')->all();

    /** @var Migration $migration */
    $migration = require base_path('database/migrations/2026_10_18_000002_add_learning_state_to_word_repetitions.php');

    $migration->down();
    expect(Schema::hasColumns('word_repetitions', ['state', 'learning_step', 'lapses', 'served_ahead_at', 'served_ahead_token']))->toBeFalse();

    $legacy = [
        'student_id' => $s['student']->id,
        'easiness_factor' => 2.5,
        'last_reviewed_at' => now(),
        'next_review_at' => now(),
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ];
    DB::table('word_repetitions')->insert([
        [...$legacy, 'word_id' => $graduated, 'repetitions' => 3, 'interval_days' => 6, 'last_quality' => 4, 'is_hard' => false],
        [...$legacy, 'word_id' => $failed, 'repetitions' => 0, 'interval_days' => 1, 'last_quality' => 2, 'is_hard' => true],
        [...$legacy, 'word_id' => $untouched, 'repetitions' => 0, 'interval_days' => 0, 'last_quality' => null, 'is_hard' => false],
    ]);

    $migration->up();
    expect(Schema::hasColumns('word_repetitions', ['state', 'learning_step', 'lapses', 'served_ahead_at', 'served_ahead_token']))->toBeTrue();

    $states = WordRepetition::query()->where('student_id', $s['student']->id)->pluck('state', 'word_id');
    expect($states[$graduated])->toBe(RepetitionState::Review)
        ->and($states[$failed])->toBe(RepetitionState::Relearning)
        ->and($states[$untouched])->toBe(RepetitionState::New);
});
