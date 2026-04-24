<?php

declare(strict_types=1);

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

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
        ->assertJsonStructure(['session_id', 'lesson' => ['stage', 'lesson', 'title'], 'total_words'])
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
            ->assertJsonStructure(['next_review_at', 'new_interval_days', 'easiness_factor']);
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
