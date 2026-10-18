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

function bindLearningPolicy(int $learnAheadMinutes = 20): void
{
    app()->instance(LearningPolicy::class, new LearningPolicy(
        learningStepsMinutes: [1, 10],
        relearningStepsMinutes: [10],
        graduatingIntervalDays: 1,
        easyIntervalDays: 4,
        learnAheadMinutes: $learnAheadMinutes,
    ));
}

it('shows a card graded "Трудно" again in the same session as a learning card', function (): void {
    bindLearningPolicy();
    $s = seedTrainingScenario(1);
    $headers = authHeaders($s['student']);
    $url = "/api/twa/training/sessions/{$s['session']->id}";

    $wordId = $this->withHeaders($headers)->getJson("{$url}/next")->assertOk()
        ->assertJsonPath('card.card_kind', 'new')
        ->json('card.word_id');

    // Again: restarts learning steps (1 min). Nothing else to show -> learn-ahead serves it immediately.
    $this->withHeaders($headers)
        ->postJson("{$url}/review", ['word_id' => $wordId, 'quality' => 2, 'time_spent_ms' => 2000])
        ->assertOk()
        ->assertJsonPath('new_interval_days', 0)
        ->assertJsonPath('card.word_id', $wordId)
        ->assertJsonPath('card.card_kind', 'learning')
        ->assertJsonPath('progress.done', 0);

    $rep = WordRepetition::query()->where('student_id', $s['student']->id)->where('word_id', $wordId)->firstOrFail();
    expect($rep->state)->toBe(RepetitionState::Learning)
        ->and($rep->is_hard)->toBeTrue()
        ->and($rep->served_ahead_at)->not->toBeNull()
        ->and($rep->next_review_at->isAfter(now()))->toBeTrue();
});

it('does not finish the session while learning cards remain, and finishes once they graduate', function (): void {
    bindLearningPolicy();
    $s = seedTrainingScenario(1);
    $headers = authHeaders($s['student']);
    $url = "/api/twa/training/sessions/{$s['session']->id}";

    $wordId = $this->withHeaders($headers)->getJson("{$url}/next")->json('card.word_id');

    // Good on a new card -> step 2 (10 min). Served ahead, not finished.
    $this->withHeaders($headers)
        ->postJson("{$url}/review", ['word_id' => $wordId, 'quality' => 4, 'time_spent_ms' => 1000])
        ->assertOk()
        ->assertJsonPath('card.word_id', $wordId)
        ->assertJsonPath('card.card_kind', 'learning');

    // Good on the last step -> graduates to 1 day. Now nothing is left: finished.
    $this->withHeaders($headers)
        ->postJson("{$url}/review", ['word_id' => $wordId, 'quality' => 4, 'time_spent_ms' => 1000])
        ->assertOk()
        ->assertJsonPath('new_interval_days', 1)
        ->assertJsonPath('card', null)
        ->assertJsonPath('progress.done', 1);

    $this->withHeaders($headers)->getJson("{$url}/next")->assertOk()->assertJsonPath('card', null);

    expect(TrainingReview::query()->where('training_session_id', $s['session']->id)->count())->toBe(2);
    expect(WordRepetition::query()->where('word_id', $wordId)->value('state'))->toBe(RepetitionState::Review);
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
            ->postJson("{$url}/review", ['word_id' => $card['word_id'], 'quality' => 4, 'time_spent_ms' => 1000])
            ->assertOk()
            ->assertJsonPath('easiness_factor', 2.5);
    }

    $efs = WordRepetition::query()->where('student_id', $s['student']->id)->pluck('easiness_factor')
        ->map(fn ($v): float => (float) $v)->all();
    expect($efs)->toBe([2.5, 2.5]);
    expect(WordRepetition::query()->where('student_id', $s['student']->id)->where('state', RepetitionState::Review->value)->count())->toBe(2);
});

it('still rejects a replayed review of a learning card that was not served again (409 card_not_due)', function (): void {
    bindLearningPolicy();
    $s = seedTrainingScenario(2);
    $headers = authHeaders($s['student']);
    $url = "/api/twa/training/sessions/{$s['session']->id}";

    $first = $this->withHeaders($headers)->getJson("{$url}/next")->json('card.word_id');
    $payload = ['word_id' => $first, 'quality' => 4, 'time_spent_ms' => 1000];

    // Response serves the second (new) word, so the first is pending for 10 min with no ticket.
    $second = $this->withHeaders($headers)->postJson("{$url}/review", $payload)->assertOk()
        ->assertJsonPath('card.card_kind', 'new')
        ->json('card.word_id');
    expect($second)->not->toBe($first);

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
    $a = $this->withHeaders($headers)->getJson("{$url}/next")->json('card.word_id');
    $b = $this->withHeaders($headers)
        ->postJson("{$url}/review", ['word_id' => $a, 'quality' => 4, 'time_spent_ms' => 1000])
        ->json('card.word_id');
    $ahead = $this->withHeaders($headers)
        ->postJson("{$url}/review", ['word_id' => $b, 'quality' => 4, 'time_spent_ms' => 1000])
        ->assertJsonPath('card.card_kind', 'learning')
        ->json('card.word_id');
    expect($ahead)->toBe($a);

    // Two "tabs" fetch /next: both get A served ahead (same ticket).
    $this->withHeaders($headers)->getJson("{$url}/next")->assertJsonPath('card.word_id', $a);
    $this->withHeaders($headers)->getJson("{$url}/next")->assertJsonPath('card.word_id', $a);

    $payload = ['word_id' => $a, 'quality' => 4, 'time_spent_ms' => 1000];

    // First submit graduates A (1 day) and serves B ahead.
    $this->withHeaders($headers)->postJson("{$url}/review", $payload)->assertOk()
        ->assertJsonPath('new_interval_days', 1)
        ->assertJsonPath('card.word_id', $b);

    // Replay from the other tab: A is a review card due tomorrow -> rejected.
    $this->withHeaders($headers)->postJson("{$url}/review", $payload)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'card_not_due');

    expect(TrainingReview::query()->where('training_session_id', $s['session']->id)->where('word_id', $a)->count())->toBe(2);
});

it('rejects a learn-ahead answer for a card outside the window or without a ticket', function (): void {
    bindLearningPolicy(learnAheadMinutes: 5);
    $s = seedTrainingScenario(1);
    $word = $s['words']->first();

    $rep = WordRepetition::factory()->learning(step: 1)->create([
        'student_id' => $s['student']->id,
        'word_id' => $word->id,
        'next_review_at' => now()->addMinutes(3),
        'served_ahead_at' => null,
    ]);

    $service = app(ReviewSubmissionService::class);

    // Inside window but never served ahead -> not acceptable.
    expect(fn () => $service->submit($s['session'], $s['student'], (int) $word->id, 4, 1000))
        ->toThrow(ReviewSubmissionException::class, 'not due');

    // Served ahead but beyond the window -> not acceptable.
    $rep->forceFill(['served_ahead_at' => now(), 'next_review_at' => now()->addMinutes(30)])->save();
    expect(fn () => $service->submit($s['session'], $s['student'], (int) $word->id, 4, 1000))
        ->toThrow(ReviewSubmissionException::class, 'not due');

    // Served ahead inside the window -> accepted, ticket consumed.
    $rep->forceFill(['served_ahead_at' => now(), 'next_review_at' => now()->addMinutes(3)])->save();
    $service->submit($s['session'], $s['student'], (int) $word->id, 4, 1000);
    expect($rep->fresh()->served_ahead_at)->toBeNull();
});

it('ends the session early when learn-ahead is disabled (card returns on its own schedule)', function (): void {
    bindLearningPolicy(learnAheadMinutes: 0);
    $s = seedTrainingScenario(1);
    $headers = authHeaders($s['student']);
    $url = "/api/twa/training/sessions/{$s['session']->id}";

    $wordId = $this->withHeaders($headers)->getJson("{$url}/next")->json('card.word_id');

    $this->withHeaders($headers)
        ->postJson("{$url}/review", ['word_id' => $wordId, 'quality' => 2, 'time_spent_ms' => 1000])
        ->assertOk()
        ->assertJsonPath('card', null);

    // Once the step elapses the card is due and is served as a learning card.
    $this->travel(2)->minutes();
    $this->withHeaders($headers)->getJson("{$url}/next")->assertOk()
        ->assertJsonPath('card.word_id', $wordId)
        ->assertJsonPath('card.card_kind', 'learning');
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
    $migration = require base_path('database/migrations/2026_10_18_000001_add_learning_state_to_word_repetitions.php');

    $migration->down();
    expect(Schema::hasColumns('word_repetitions', ['state', 'learning_step', 'lapses', 'served_ahead_at']))->toBeFalse();

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

    $states = WordRepetition::query()->where('student_id', $s['student']->id)->pluck('state', 'word_id');
    expect($states[$graduated])->toBe(RepetitionState::Review)
        ->and($states[$failed])->toBe(RepetitionState::Relearning)
        ->and($states[$untouched])->toBe(RepetitionState::New);
});
