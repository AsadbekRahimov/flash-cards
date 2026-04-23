<?php

declare(strict_types=1);

use App\Domain\Twa\Services\JwtService;
use App\Models\ExamAnswer;
use App\Models\ExamResult;
use App\Models\ExamSession;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Student;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function examAuthHeaders(Student $student): array
{
    /** @var JwtService $jwt */
    $jwt = app(JwtService::class);
    $token = $jwt->issue($student->id, (int) $student->telegram_group_id)['token'];

    return ['Authorization' => 'Bearer '.$token];
}

function openExam(array $config = []): array
{
    $group   = TelegramGroup::factory()->create(['status' => 'active']);
    $teacher = User::factory()->create();
    $stage   = Stage::factory()->create();
    $lesson  = Lesson::factory()->for($stage)->create();
    Word::factory()->count(8)->for($lesson)->create();

    $questions = [];
    foreach (range(0, 3) as $i) {
        $correct = "correct_{$i}";
        $options = ["opt_a_{$i}", $correct, "opt_c_{$i}", "opt_d_{$i}"];
        $questions[] = [
            'word_id'             => Word::where('lesson_id', $lesson->id)->orderBy('id')->skip($i)->first()->id,
            'word'                => "word_{$i}",
            'correct_translation' => $correct,
            'correct_index'       => 1,
            'options'             => $options,
        ];
    }

    $session = ExamSession::factory()->create([
        'telegram_group_id'  => $group->id,
        'lesson_id'          => $lesson->id,
        'started_by_user_id' => $teacher->id,
        'status'             => 'open',
        'started_at'         => now()->subMinute(),
        'ends_at'            => now()->addMinutes(5),
        'config'             => array_merge([
            'total_questions'      => 4,
            'seconds_per_question' => 10,
            'duration_minutes'     => 5,
            'questions'            => $questions,
        ], $config),
    ]);

    $student = Student::factory()->create([
        'telegram_group_id' => $group->id,
        'is_active'         => true,
    ]);

    return compact('group', 'teacher', 'stage', 'lesson', 'session', 'student');
}

it('POST /exam/.../join returns session metadata', function (): void {
    $s = openExam();

    $this->withHeaders(examAuthHeaders($s['student']))
        ->postJson("/api/twa/exam/sessions/{$s['session']->id}/join")
        ->assertOk()
        ->assertJsonStructure(['session_id', 'ends_at', 'total_questions', 'seconds_per_question'])
        ->assertJsonPath('total_questions', 4);
});

it('GET /exam/.../question?index=N returns word + options (no correct_index leakage)', function (): void {
    $s = openExam();

    $resp = $this->withHeaders(examAuthHeaders($s['student']))
        ->getJson("/api/twa/exam/sessions/{$s['session']->id}/question?index=0")
        ->assertOk()
        ->assertJsonStructure(['question_index', 'word_id', 'word', 'options', 'seconds_left']);

    expect($resp->json('options'))->toHaveCount(4);
    expect($resp->json())->not->toHaveKey('correct_index');
});

it('rejects question with out-of-range index', function (): void {
    $s = openExam();

    $this->withHeaders(examAuthHeaders($s['student']))
        ->getJson("/api/twa/exam/sessions/{$s['session']->id}/question?index=999")
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'question_not_found');
});

it('POST answer with correct option gives points and records exam_answer', function (): void {
    $s = openExam();
    $q = $s['session']->config['questions'][0];

    $resp = $this->withHeaders(examAuthHeaders($s['student']))
        ->postJson("/api/twa/exam/sessions/{$s['session']->id}/answer", [
            'question_index'        => 0,
            'word_id'               => $q['word_id'],
            'selected_option_index' => $q['correct_index'],
            'time_spent_ms'         => 1500,
        ])
        ->assertOk()
        ->assertJsonPath('is_correct', true)
        ->assertJsonPath('correct_option', $q['correct_index'])
        ->assertJsonPath('has_next', true);

    expect($resp->json('score_earned'))->toBeGreaterThan(0);
    expect($resp->json('total_score'))->toBe($resp->json('score_earned'));

    expect(ExamAnswer::query()
        ->where('exam_session_id', $s['session']->id)
        ->where('student_id', $s['student']->id)
        ->where('is_correct', true)
        ->count())->toBe(1);
});

it('POST answer with wrong option gives 0 points', function (): void {
    $s = openExam();
    $q = $s['session']->config['questions'][0];
    $wrongIndex = ($q['correct_index'] + 1) % 4;

    $this->withHeaders(examAuthHeaders($s['student']))
        ->postJson("/api/twa/exam/sessions/{$s['session']->id}/answer", [
            'question_index'        => 0,
            'word_id'               => $q['word_id'],
            'selected_option_index' => $wrongIndex,
            'time_spent_ms'         => 3000,
        ])
        ->assertOk()
        ->assertJsonPath('is_correct', false)
        ->assertJsonPath('score_earned', 0);
});

it('POST answer with null selection (timeout) gives 0 points', function (): void {
    $s = openExam();
    $q = $s['session']->config['questions'][0];

    $this->withHeaders(examAuthHeaders($s['student']))
        ->postJson("/api/twa/exam/sessions/{$s['session']->id}/answer", [
            'question_index'        => 0,
            'word_id'               => $q['word_id'],
            'selected_option_index' => null,
            'time_spent_ms'         => 10000,
        ])
        ->assertOk()
        ->assertJsonPath('is_correct', false);
});

it('rejects duplicate answer for the same word (409)', function (): void {
    $s = openExam();
    $q = $s['session']->config['questions'][0];
    $headers = examAuthHeaders($s['student']);

    $this->withHeaders($headers)->postJson("/api/twa/exam/sessions/{$s['session']->id}/answer", [
        'question_index'        => 0,
        'word_id'               => $q['word_id'],
        'selected_option_index' => $q['correct_index'],
        'time_spent_ms'         => 1000,
    ])->assertOk();

    $this->withHeaders($headers)->postJson("/api/twa/exam/sessions/{$s['session']->id}/answer", [
        'question_index'        => 0,
        'word_id'               => $q['word_id'],
        'selected_option_index' => $q['correct_index'],
        'time_spent_ms'         => 1000,
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'already_answered');
});

it('rejects answer when word_id does not match the question index (422)', function (): void {
    $s = openExam();
    $q0 = $s['session']->config['questions'][0];
    $q1 = $s['session']->config['questions'][1];

    $this->withHeaders(examAuthHeaders($s['student']))
        ->postJson("/api/twa/exam/sessions/{$s['session']->id}/answer", [
            'question_index'        => 0,
            'word_id'               => $q1['word_id'],  // wrong word for index 0
            'selected_option_index' => 0,
            'time_spent_ms'         => 1000,
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'word_mismatch');
});

it('rejects IDOR from student in another group (403)', function (): void {
    $s = openExam();

    $otherGroup = TelegramGroup::factory()->create(['status' => 'active']);
    $intruder = Student::factory()->create([
        'telegram_group_id' => $otherGroup->id,
        'is_active'         => true,
    ]);

    $this->withHeaders(examAuthHeaders($intruder))
        ->postJson("/api/twa/exam/sessions/{$s['session']->id}/join")
        ->assertStatus(403);
});

it('returns 410 for join on a closed exam session', function (): void {
    $s = openExam();
    $s['session']->update(['status' => 'closed', 'closed_at' => now()]);

    $this->withHeaders(examAuthHeaders($s['student']))
        ->postJson("/api/twa/exam/sessions/{$s['session']->id}/join")
        ->assertStatus(410);
});

it('result is blocked while exam is open (409)', function (): void {
    $s = openExam();

    $this->withHeaders(examAuthHeaders($s['student']))
        ->getJson("/api/twa/exam/sessions/{$s['session']->id}/result")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'exam_still_open');
});

it('result returns rank + leaderboard once exam is closed', function (): void {
    $s = openExam();
    $session = $s['session'];
    $q0 = $session->config['questions'][0];

    // Two students answer differently
    $alice = Student::factory()->create(['telegram_group_id' => $s['group']->id]);
    $bob   = Student::factory()->create(['telegram_group_id' => $s['group']->id]);

    ExamAnswer::query()->create([
        'exam_session_id'      => $session->id,
        'student_id'           => $alice->id,
        'word_id'              => $q0['word_id'],
        'selected_translation' => $q0['options'][$q0['correct_index']],
        'is_correct'           => true,
        'score'                => 20,
        'time_spent_ms'        => 1000,
        'answered_at'          => now(),
    ]);
    ExamAnswer::query()->create([
        'exam_session_id'      => $session->id,
        'student_id'           => $bob->id,
        'word_id'              => $q0['word_id'],
        'selected_translation' => $q0['options'][0],
        'is_correct'           => false,
        'score'                => 0,
        'time_spent_ms'        => 3000,
        'answered_at'          => now(),
    ]);

    $session->update(['status' => 'closed', 'closed_at' => now()]);

    $resp = $this->withHeaders(examAuthHeaders($alice))
        ->getJson("/api/twa/exam/sessions/{$session->id}/result")
        ->assertOk()
        ->assertJsonStructure(['student_score', 'rank', 'total_participants', 'leaderboard']);

    expect($resp->json('rank'))->toBe(1);
    expect($resp->json('student_score'))->toBe(20);
    expect($resp->json('total_participants'))->toBe(2);

    $board = $resp->json('leaderboard');
    expect($board)->toHaveCount(2)
        ->and($board[0]['rank'])->toBe(1)
        ->and($board[1]['rank'])->toBe(2);
});
