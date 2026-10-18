<?php

declare(strict_types=1);

/*
 * JSON contract of the TWA API (resources/twa/src/api/types.ts).
 * Exact key structure, no `data` envelope. If a test here fails, the TWA
 * client breaks — change the client and docs/04_API_AND_BOT.md together.
 */

use App\Domain\Learning\DTO\ServedExamQuestion;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Services\ExamQuestionPersonalizer;
use App\Domain\Twa\Services\InitDataValidator;
use App\Domain\Twa\Services\JwtService;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\ExamQuestionResource;
use App\Models\ExamAnswer;
use App\Models\ExamSession;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Student;
use App\Models\TelegramGroup;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\Word;
use App\Models\WordRepetition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

const CONTRACT_PROGRESS = ['done', 'total'];
const CONTRACT_CARD = ['word_id', 'word', 'translation', 'example', 'transcription', 'card_kind', 'serve_token'];
const CONTRACT_EXAM_QUESTION = ['question_index', 'word_id', 'word', 'options', 'seconds_left'];
const CONTRACT_FORBIDDEN_EXAM_KEYS = ['correct_index', 'correct_translation', 'canonical_index'];

function contractHeaders(Student $student): array
{
    $token = app(JwtService::class)->issue($student->id, (int) $student->telegram_group_id)['token'];

    return ['Authorization' => 'Bearer '.$token];
}

/**
 * @return array{student: Student, group: TelegramGroup, lesson: Lesson, session: TrainingSession}
 */
function contractTraining(int $words = 3): array
{
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $student = Student::factory()->create(['telegram_group_id' => $group->id, 'is_active' => true]);
    $lesson = Lesson::factory()->for(Stage::factory()->create())->create();
    Word::factory()->count($words)->for($lesson)->create();
    $session = TrainingSession::factory()->create([
        'telegram_group_id' => $group->id,
        'lesson_id' => $lesson->id,
        'started_by_user_id' => User::factory()->create()->id,
        'status' => 'open',
    ]);

    return compact('student', 'group', 'lesson', 'session');
}

/**
 * @return array{student: Student, group: TelegramGroup, session: ExamSession}
 */
function contractExam(int $questions = 4): array
{
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $session = ExamSession::factory()->withQuestions($questions)->create([
        'telegram_group_id' => $group->id,
        'started_at' => now()->subMinute(),
        'ends_at' => now()->addMinutes(5),
    ]);
    $student = Student::factory()->create(['telegram_group_id' => $group->id, 'is_active' => true]);

    return compact('student', 'group', 'session');
}

// --- auth / me ------------------------------------------------------------

it('POST /auth keeps its exact JSON shape', function (): void {
    Config::set('telegram.bot_token', 'test-bot-token-1234567890');
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    Student::factory()->create(['telegram_group_id' => $group->id, 'telegram_user_id' => 777, 'is_active' => true]);

    $initData = InitDataValidator::sign('test-bot-token-1234567890', [
        'user' => json_encode(['id' => 777, 'first_name' => 'Alex']),
        'auth_date' => (string) time(),
    ]);

    $response = $this->postJson('/api/twa/auth', ['init_data' => $initData])
        ->assertOk()
        ->assertExactJsonStructure([
            'token',
            'expires_in',
            'student' => ['id', 'first_name', 'username', 'telegram_group_id'],
        ]);

    expect($response->json('expires_in'))->toBeInt()
        ->and($response->json('student.telegram_group_id'))->toBe($group->id);
});

it('GET /me keeps its exact JSON shape', function (): void {
    $s = contractTraining();

    $response = $this->withHeaders(contractHeaders($s['student']))
        ->getJson('/api/twa/me')
        ->assertOk()
        ->assertExactJsonStructure([
            'student' => ['id', 'first_name', 'username'],
            'group' => ['id', 'title'],
            'stats' => ['words_learned', 'words_due_today'],
        ]);

    expect($response->json('stats'))->toBe(['words_learned' => 0, 'words_due_today' => 0]);
});

it('GET /me counts due graduated cards and abandoned (re)learning cards as due today', function (): void {
    $s = contractTraining(0);
    $words = Word::factory()->count(7)->for($s['lesson'])->create();
    $make = fn (Word $w, RepetitionState $state, int $reps, string $due) => WordRepetition::factory()->create([
        'student_id' => $s['student']->id,
        'word_id' => $w->id,
        'state' => $state->value,
        'repetitions' => $reps,
        'next_review_at' => match ($due) {
            'past' => now()->subHour(),
            'abandoned' => now()->subHours(13),
            default => now()->addDays(2),
        },
    ]);

    $make($words[0], RepetitionState::Review, 3, 'past');           // due review
    $make($words[1], RepetitionState::Review, 3, 'future');         // not yet due
    $make($words[2], RepetitionState::New, 0, 'past');              // just-shown new card
    $make($words[3], RepetitionState::Learning, 0, 'past');         // minute step elapsed an hour ago (session may still be on)
    $make($words[4], RepetitionState::Relearning, 1, 'past');       // lapsed, relearning, fresh
    $make($words[5], RepetitionState::Relearning, 0, 'abandoned');  // lapsed and left for > abandoned_after_hours
    $make($words[6], RepetitionState::New, 0, 'abandoned');         // shown long ago, never answered: not "to review"

    $this->withHeaders(contractHeaders($s['student']))
        ->getJson('/api/twa/me')
        ->assertOk()
        ->assertJsonPath('stats.words_due_today', 2)
        ->assertJsonPath('stats.words_learned', 3);
});

// --- training -------------------------------------------------------------

it('POST /training/start keeps its exact JSON shape', function (): void {
    $s = contractTraining();

    $this->withHeaders(contractHeaders($s['student']))
        ->postJson("/api/twa/training/sessions/{$s['session']->id}/start")
        ->assertOk()
        ->assertExactJsonStructure([
            'session_id',
            'lesson' => ['stage', 'lesson', 'title'],
            'total_words',
            'card' => CONTRACT_CARD,
            'progress' => CONTRACT_PROGRESS,
        ])
        ->assertJsonPath('card.card_kind', 'new');
});

it('GET /training/next keeps its exact JSON shape, with and without a card', function (): void {
    $s = contractTraining(1);
    $headers = contractHeaders($s['student']);

    $this->withHeaders($headers)
        ->getJson("/api/twa/training/sessions/{$s['session']->id}/next")
        ->assertOk()
        ->assertExactJsonStructure(['card' => CONTRACT_CARD, 'progress' => CONTRACT_PROGRESS]);

    // Pretend the only card was already graduated and is not due: nothing to show.
    WordRepetition::query()->where('student_id', $s['student']->id)->update([
        'state' => RepetitionState::Review->value,
        'repetitions' => 1,
        'next_review_at' => now()->addDays(3),
    ]);

    $response = $this->withHeaders($headers)
        ->getJson("/api/twa/training/sessions/{$s['session']->id}/next")
        ->assertOk()
        ->assertExactJsonStructure(['card', 'progress' => CONTRACT_PROGRESS]);

    expect($response->json('card'))->toBeNull();
});

it('POST /training/review keeps its exact JSON shape', function (): void {
    $s = contractTraining();
    $headers = contractHeaders($s['student']);

    $card = $this->withHeaders($headers)
        ->getJson("/api/twa/training/sessions/{$s['session']->id}/next")
        ->json('card');

    $response = $this->withHeaders($headers)
        ->postJson("/api/twa/training/sessions/{$s['session']->id}/review", [
            'word_id' => $card['word_id'],
            'quality' => 5,
            'time_spent_ms' => 1200,
        ])
        ->assertOk()
        ->assertExactJsonStructure([
            'next_review_at',
            'new_interval_days',
            'easiness_factor',
            'card' => CONTRACT_CARD,
            'progress' => CONTRACT_PROGRESS,
        ]);

    expect($response->json('new_interval_days'))->toBeInt()
        ->and($response->json('easiness_factor'))->toBeFloat()
        ->and($response->json('next_review_at'))->toBeString();
});

it('wraps every API error in the same envelope', function (): void {
    $s = contractTraining();
    $headers = contractHeaders($s['student']);
    $openExam = contractExam();
    $closedExam = contractExam();
    $closedExam['session']->update(['status' => 'closed', 'closed_at' => now()]);

    $errors = [
        401 => $this->getJson('/api/twa/me'),
        404 => $this->withHeaders($headers)->postJson('/api/twa/training/sessions/999999/start'),
        410 => $this->withHeaders(contractHeaders($closedExam['student']))
            ->postJson("/api/twa/exam/sessions/{$closedExam['session']->id}/join"),
        409 => $this->withHeaders(contractHeaders($openExam['student']))
            ->getJson("/api/twa/exam/sessions/{$openExam['session']->id}/result"),
    ];

    foreach ($errors as $status => $response) {
        $response->assertStatus($status)->assertExactJsonStructure(['error' => ['code', 'message']]);
    }
});

it('ApiErrorResource builds the envelope without a data wrapper', function (): void {
    $response = ApiErrorResource::respond(418, 'teapot', 'Short and stout.');

    expect($response->getStatusCode())->toBe(418)
        ->and($response->getData(true))->toBe(['error' => ['code' => 'teapot', 'message' => 'Short and stout.']]);
});

// --- exam -----------------------------------------------------------------

it('POST /exam/join keeps its exact JSON shape and hides the answer key', function (): void {
    $s = contractExam();

    $response = $this->withHeaders(contractHeaders($s['student']))
        ->postJson("/api/twa/exam/sessions/{$s['session']->id}/join")
        ->assertOk()
        ->assertExactJsonStructure([
            'session_id',
            'ends_at',
            'total_questions',
            'seconds_per_question',
            'question' => CONTRACT_EXAM_QUESTION,
        ]);

    foreach (CONTRACT_FORBIDDEN_EXAM_KEYS as $key) {
        expect($response->getContent())->not->toContain($key);
    }
    expect($response->json('total_questions'))->toBe(4)
        ->and($response->json('seconds_per_question'))->toBe(10);
});

it('GET /exam/question keeps its exact JSON shape and hides the answer key', function (): void {
    $s = contractExam();

    $response = $this->withHeaders(contractHeaders($s['student']))
        ->getJson("/api/twa/exam/sessions/{$s['session']->id}/question?index=1")
        ->assertOk()
        ->assertExactJsonStructure(CONTRACT_EXAM_QUESTION);

    foreach (CONTRACT_FORBIDDEN_EXAM_KEYS as $key) {
        expect($response->getContent())->not->toContain($key);
    }
    expect($response->json('question_index'))->toBe(1)
        ->and($response->json('seconds_left'))->toBeInt();
});

it('POST /exam/answer keeps its exact JSON shape', function (): void {
    $s = contractExam();
    $headers = contractHeaders($s['student']);

    $question = $this->withHeaders($headers)
        ->getJson("/api/twa/exam/sessions/{$s['session']->id}/question?index=0")
        ->json();

    $response = $this->withHeaders($headers)
        ->postJson("/api/twa/exam/sessions/{$s['session']->id}/answer", [
            'question_index' => 0,
            'word_id' => $question['word_id'],
            'selected_option_index' => 0,
            'time_spent_ms' => 500,
        ])
        ->assertOk()
        ->assertExactJsonStructure([
            'is_correct',
            'correct_option',
            'score_earned',
            'total_score',
            'has_next',
            'next_question',
        ]);

    expect($response->json('correct_option'))->toBeInt()
        ->and($response->json('has_next'))->toBeTrue()
        ->and($response->json('next_question'))->toBeNull();
});

it('GET /exam/result keeps its exact JSON shape', function (): void {
    $s = contractExam();
    $question = app(ExamQuestionPersonalizer::class)->questionAt($s['session'], $s['student'], 0);

    ExamAnswer::query()->create([
        'exam_session_id' => $s['session']->id,
        'student_id' => $s['student']->id,
        'word_id' => $question['word_id'],
        'selected_translation' => $question['correct_translation'],
        'is_correct' => true,
        'score' => 18,
        'time_spent_ms' => 900,
        'answered_at' => now(),
    ]);
    $s['session']->update(['status' => 'closed', 'closed_at' => now()]);

    $response = $this->withHeaders(contractHeaders($s['student']))
        ->getJson("/api/twa/exam/sessions/{$s['session']->id}/result")
        ->assertOk()
        ->assertExactJsonStructure([
            'student_score',
            'rank',
            'total_participants',
            'leaderboard' => ['*' => ['rank', 'name', 'score']],
        ]);

    expect($response->json('student_score'))->toBe(18)
        ->and($response->json('rank'))->toBe(1)
        ->and($response->json('leaderboard'))->toBeArray()->toHaveCount(1);
});

it('ExamQuestionResource exposes only the allow-listed fields of the personalizer view', function (): void {
    $s = contractExam();
    $question = app(ExamQuestionPersonalizer::class)->questionAt($s['session'], $s['student'], 0);

    // The raw view really does carry the answer key ...
    expect($question)->toHaveKeys(CONTRACT_FORBIDDEN_EXAM_KEYS);

    $payload = (new ExamQuestionResource(new ServedExamQuestion($question, 7)))->toArray(Request::create('/'));

    // ... and the resource drops it.
    expect(array_keys($payload))->toBe(CONTRACT_EXAM_QUESTION)
        ->and($payload['seconds_left'])->toBe(7);
});
