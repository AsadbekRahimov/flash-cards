<?php

declare(strict_types=1);

use App\Domain\Learning\Exceptions\ExamParticipationException;
use App\Domain\Learning\Services\ExamAnswerService;
use App\Domain\Learning\Services\ExamQuestionPersonalizer;
use App\Domain\Twa\Services\JwtService;
use App\Models\ExamAnswer;
use App\Models\ExamSession;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Student;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

uses(RefreshDatabase::class);

function examAuthHeaders(Student $student): array
{
    /** @var JwtService $jwt */
    $jwt = app(JwtService::class);
    $token = $jwt->issue($student->id, (int) $student->telegram_group_id)['token'];

    return ['Authorization' => 'Bearer '.$token];
}

function openExam(array $config = [], int $questionCount = 4): array
{
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $teacher = User::factory()->create();
    $stage = Stage::factory()->create();
    $lesson = Lesson::factory()->for($stage)->create();
    Word::factory()->count(max(8, $questionCount))->for($lesson)->create();

    // Canonical questions as ExamQuestionBuilder stores them: no correct_index,
    // the per-student order is derived at serving time.
    $questions = [];
    foreach (range(0, $questionCount - 1) as $i) {
        $correct = "correct_{$i}";
        $options = ["opt_a_{$i}", $correct, "opt_c_{$i}", "opt_d_{$i}"];
        $questions[] = [
            'word_id' => Word::where('lesson_id', $lesson->id)->orderBy('id')->skip($i)->first()->id,
            'word' => "word_{$i}",
            'correct_translation' => $correct,
            'options' => $options,
        ];
    }

    $session = ExamSession::factory()->create([
        'telegram_group_id' => $group->id,
        'lesson_id' => $lesson->id,
        'started_by_user_id' => $teacher->id,
        'status' => 'open',
        'started_at' => now()->subMinute(),
        'ends_at' => now()->addMinutes(5),
        'config' => array_merge([
            'total_questions' => $questionCount,
            'seconds_per_question' => 10,
            'duration_minutes' => 5,
            'question_order' => ExamQuestionPersonalizer::ORDER_PER_STUDENT,
            'questions' => $questions,
        ], $config),
    ]);

    $student = Student::factory()->create([
        'telegram_group_id' => $group->id,
        'is_active' => true,
    ]);

    return compact('group', 'teacher', 'stage', 'lesson', 'session', 'student');
}

/**
 * The question as THIS student sees it at personal position $index
 * (word_id, options in their order, and the matching correct_index).
 *
 * @return array{question_index:int, canonical_index:int, word_id:int, word:string, options:list<string>, correct_index:int, correct_translation:string}
 */
function personalQuestion(ExamSession $session, Student $student, int $index): array
{
    $q = app(ExamQuestionPersonalizer::class)->questionAt($session, $student, $index);
    expect($q)->not->toBeNull();

    return $q;
}

/** @return list<array{word_id:int, options:list<string>}> */
function fetchAllQuestions(TestCase $test, ExamSession $session, Student $student): array
{
    $seen = [];
    foreach (range(0, (int) $session->config['total_questions'] - 1) as $i) {
        $json = $test->withHeaders(examAuthHeaders($student))
            ->getJson("/api/twa/exam/sessions/{$session->id}/question?index={$i}")
            ->assertOk()
            ->json();
        $seen[] = ['word_id' => $json['word_id'], 'options' => $json['options']];
    }

    return $seen;
}

it('POST /exam/.../join returns session metadata', function (): void {
    $s = openExam();

    $this->withHeaders(examAuthHeaders($s['student']))
        ->postJson("/api/twa/exam/sessions/{$s['session']->id}/join")
        ->assertOk()
        ->assertJsonStructure([
            'session_id',
            'ends_at',
            'total_questions',
            'seconds_per_question',
            'question' => ['question_index', 'word_id', 'word', 'options', 'seconds_left'],
        ])
        ->assertJsonPath('total_questions', 4);
});

it('GET /exam/.../question?index=N returns word + options (no correct_index leakage)', function (): void {
    $s = openExam();

    $resp = $this->withHeaders(examAuthHeaders($s['student']))
        ->getJson("/api/twa/exam/sessions/{$s['session']->id}/question?index=0")
        ->assertOk()
        ->assertJsonStructure(['question_index', 'word_id', 'word', 'options', 'seconds_left']);

    expect($resp->json('options'))->toHaveCount(4);
    expect($resp->json())->not->toHaveKey('correct_index')
        ->and($resp->json())->not->toHaveKey('correct_translation')
        ->and($resp->json())->not->toHaveKey('canonical_index');

    $resp = $this->withHeaders(examAuthHeaders($s['student']))
        ->postJson("/api/twa/exam/sessions/{$s['session']->id}/join")
        ->assertOk();
    expect($resp->json('question'))->not->toHaveKey('correct_index')
        ->and($resp->json('question'))->not->toHaveKey('correct_translation');
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
    $q = personalQuestion($s['session'], $s['student'], 0);
    $headers = examAuthHeaders($s['student']);

    $this->withHeaders($headers)
        ->getJson("/api/twa/exam/sessions/{$s['session']->id}/question?index=0")
        ->assertOk();

    $resp = $this->withHeaders($headers)
        ->postJson("/api/twa/exam/sessions/{$s['session']->id}/answer", [
            'question_index' => 0,
            'word_id' => $q['word_id'],
            'selected_option_index' => $q['correct_index'],
            'time_spent_ms' => 1500,
        ])
        ->assertOk()
        ->assertJsonPath('is_correct', true)
        ->assertJsonPath('correct_option', $q['correct_index'])
        ->assertJsonPath('has_next', true)
        ->assertJsonPath('next_question', null);

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
    $q = personalQuestion($s['session'], $s['student'], 0);
    $wrongIndex = ($q['correct_index'] + 1) % 4;
    $headers = examAuthHeaders($s['student']);

    $this->withHeaders($headers)
        ->getJson("/api/twa/exam/sessions/{$s['session']->id}/question?index=0")
        ->assertOk();

    $this->withHeaders($headers)
        ->postJson("/api/twa/exam/sessions/{$s['session']->id}/answer", [
            'question_index' => 0,
            'word_id' => $q['word_id'],
            'selected_option_index' => $wrongIndex,
            'time_spent_ms' => 3000,
        ])
        ->assertOk()
        ->assertJsonPath('is_correct', false)
        ->assertJsonPath('score_earned', 0);
});

it('POST answer with null selection (timeout) gives 0 points', function (): void {
    $s = openExam();
    $q = personalQuestion($s['session'], $s['student'], 0);
    $headers = examAuthHeaders($s['student']);

    $this->withHeaders($headers)
        ->getJson("/api/twa/exam/sessions/{$s['session']->id}/question?index=0")
        ->assertOk();

    $this->withHeaders($headers)
        ->postJson("/api/twa/exam/sessions/{$s['session']->id}/answer", [
            'question_index' => 0,
            'word_id' => $q['word_id'],
            'selected_option_index' => null,
            'time_spent_ms' => 10000,
        ])
        ->assertOk()
        ->assertJsonPath('is_correct', false);
});

it('rejects duplicate answer for the same word (409)', function (): void {
    $s = openExam();
    $q = personalQuestion($s['session'], $s['student'], 0);
    $headers = examAuthHeaders($s['student']);

    $this->withHeaders($headers)
        ->getJson("/api/twa/exam/sessions/{$s['session']->id}/question?index=0")
        ->assertOk();

    $this->withHeaders($headers)->postJson("/api/twa/exam/sessions/{$s['session']->id}/answer", [
        'question_index' => 0,
        'word_id' => $q['word_id'],
        'selected_option_index' => $q['correct_index'],
        'time_spent_ms' => 1000,
    ])->assertOk();

    $this->withHeaders($headers)->postJson("/api/twa/exam/sessions/{$s['session']->id}/answer", [
        'question_index' => 0,
        'word_id' => $q['word_id'],
        'selected_option_index' => $q['correct_index'],
        'time_spent_ms' => 1000,
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'already_answered');
});

it('rejects answer when word_id does not match the question index (422)', function (): void {
    $s = openExam();
    $q0 = personalQuestion($s['session'], $s['student'], 0);
    $q1 = personalQuestion($s['session'], $s['student'], 1);

    $this->withHeaders(examAuthHeaders($s['student']))
        ->postJson("/api/twa/exam/sessions/{$s['session']->id}/answer", [
            'question_index' => 0,
            'word_id' => $q1['word_id'],  // wrong word for index 0
            'selected_option_index' => 0,
            'time_spent_ms' => 1000,
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'word_mismatch');
});

it('rejects IDOR from student in another group (403)', function (): void {
    $s = openExam();

    $otherGroup = TelegramGroup::factory()->create(['status' => 'active']);
    $intruder = Student::factory()->create([
        'telegram_group_id' => $otherGroup->id,
        'is_active' => true,
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

it('returns 410 immediately when an open exam has passed ends_at', function (): void {
    $s = openExam();
    $s['session']->update(['ends_at' => now()->subSecond()]);

    $this->withHeaders(examAuthHeaders($s['student']))
        ->postJson("/api/twa/exam/sessions/{$s['session']->id}/join")
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'session_closed');
});

it('requires a question to be served before accepting its answer', function (): void {
    $s = openExam();
    $question = personalQuestion($s['session'], $s['student'], 0);

    $this->withHeaders(examAuthHeaders($s['student']))
        ->postJson("/api/twa/exam/sessions/{$s['session']->id}/answer", [
            'question_index' => 0,
            'word_id' => $question['word_id'],
            'selected_option_index' => $question['correct_index'],
            'time_spent_ms' => 0,
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'question_not_served');
});

it('uses server timing and does not restart it when a question is reloaded', function (): void {
    $s = openExam();
    $question = personalQuestion($s['session'], $s['student'], 0);
    $headers = examAuthHeaders($s['student']);
    $url = "/api/twa/exam/sessions/{$s['session']->id}/question?index=0";

    $this->withHeaders($headers)->getJson($url)->assertOk();
    $this->travel(8)->seconds();
    $this->withHeaders($headers)->getJson($url)->assertOk();

    $response = $this->withHeaders($headers)
        ->postJson("/api/twa/exam/sessions/{$s['session']->id}/answer", [
            'question_index' => 0,
            'word_id' => $question['word_id'],
            'selected_option_index' => $question['correct_index'],
            'time_spent_ms' => 0,
        ])
        ->assertOk()
        ->assertJsonPath('is_correct', true);

    expect($response->json('score_earned'))->toBeLessThan(20);
    expect(ExamAnswer::query()->latest('id')->value('time_spent_ms'))->toBeGreaterThanOrEqual(8000);
});

it('marks an answer after the per-question deadline as incorrect', function (): void {
    $s = openExam(['seconds_per_question' => 1]);
    $question = personalQuestion($s['session'], $s['student'], 0);
    $headers = examAuthHeaders($s['student']);

    $this->withHeaders($headers)
        ->getJson("/api/twa/exam/sessions/{$s['session']->id}/question?index=0")
        ->assertOk();
    $this->travel(2)->seconds();

    $this->withHeaders($headers)
        ->postJson("/api/twa/exam/sessions/{$s['session']->id}/answer", [
            'question_index' => 0,
            'word_id' => $question['word_id'],
            'selected_option_index' => $question['correct_index'],
            'time_spent_ms' => 0,
        ])
        ->assertOk()
        ->assertJsonPath('is_correct', false)
        ->assertJsonPath('score_earned', 0);
});

it('rejects an answer when a stale session model was closed concurrently', function (): void {
    $s = openExam();
    $session = $s['session']->fresh();
    $question = personalQuestion($session, $s['student'], 0);
    $answers = app(ExamAnswerService::class);

    $answers->serve($session, $s['student'], 0);

    ExamSession::query()
        ->whereKey($session->id)
        ->update(['status' => 'closed', 'closed_at' => now()]);

    expect(fn () => $answers->submit(
        $session,
        $s['student'],
        0,
        $question['word_id'],
        $question['correct_index'],
    ))->toThrow(ExamParticipationException::class);

    expect(ExamAnswer::query()->where('exam_session_id', $session->id)->count())->toBe(0);
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
    $q0['correct_index'] = array_search($q0['correct_translation'], $q0['options'], true);

    // Two students answer differently
    $alice = Student::factory()->create(['telegram_group_id' => $s['group']->id]);
    $bob = Student::factory()->create(['telegram_group_id' => $s['group']->id]);

    ExamAnswer::query()->create([
        'exam_session_id' => $session->id,
        'student_id' => $alice->id,
        'word_id' => $q0['word_id'],
        'selected_translation' => $q0['options'][$q0['correct_index']],
        'is_correct' => true,
        'score' => 20,
        'time_spent_ms' => 1000,
        'answered_at' => now(),
    ]);
    ExamAnswer::query()->create([
        'exam_session_id' => $session->id,
        'student_id' => $bob->id,
        'word_id' => $q0['word_id'],
        'selected_translation' => $q0['options'][0],
        'is_correct' => false,
        'score' => 0,
        'time_spent_ms' => 3000,
        'answered_at' => now(),
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

it('serves two students different question/option orders while each sees a consistent sequence', function (): void {
    $s = openExam(questionCount: 10);
    $alice = $s['student'];
    $bob = Student::factory()->create(['telegram_group_id' => $s['group']->id, 'is_active' => true]);

    $aliceView = fetchAllQuestions($this, $s['session'], $alice);
    $bobView = fetchAllQuestions($this, $s['session'], $bob);

    // Same set of words, different sequence for the two students.
    $aliceWords = array_column($aliceView, 'word_id');
    $bobWords = array_column($bobView, 'word_id');
    expect(count(array_unique($aliceWords)))->toBe(10)
        ->and(collect($aliceWords)->sort()->values()->all())->toBe(collect($bobWords)->sort()->values()->all())
        ->and($aliceView)->not->toBe($bobView);

    // Every served option list is a permutation of the canonical options for that word.
    $canonicalByWord = collect($s['session']->config['questions'])->keyBy('word_id');
    foreach ([...$aliceView, ...$bobView] as $served) {
        $expected = $canonicalByWord[$served['word_id']]['options'];
        sort($expected);
        $actual = $served['options'];
        sort($actual);
        expect($actual)->toBe($expected);
    }

    // Re-fetching gives exactly the same view (order and options).
    expect(fetchAllQuestions($this, $s['session'], $alice))->toBe($aliceView);
});

it('scores both students when each answers correctly according to their own order', function (): void {
    $s = openExam(questionCount: 6);
    $alice = $s['student'];
    $bob = Student::factory()->create(['telegram_group_id' => $s['group']->id, 'is_active' => true]);

    foreach ([$alice, $bob] as $student) {
        $headers = examAuthHeaders($student);
        foreach (range(0, 5) as $i) {
            $served = $this->withHeaders($headers)
                ->getJson("/api/twa/exam/sessions/{$s['session']->id}/question?index={$i}")
                ->assertOk()
                ->json();

            // The student picks the option that IS the correct translation on THEIR screen.
            $pq = personalQuestion($s['session'], $student, $i);
            expect($served['word_id'])->toBe($pq['word_id'])
                ->and($served['options'])->toBe($pq['options']);
            $chosen = array_search($pq['correct_translation'], $served['options'], true);
            expect($chosen)->not->toBeFalse();

            $this->withHeaders($headers)
                ->postJson("/api/twa/exam/sessions/{$s['session']->id}/answer", [
                    'question_index' => $i,
                    'word_id' => $served['word_id'],
                    'selected_option_index' => $chosen,
                    'time_spent_ms' => 500,
                ])
                ->assertOk()
                ->assertJsonPath('is_correct', true)
                ->assertJsonPath('correct_option', $chosen)
                ->assertJsonPath('has_next', $i < 5);
        }
    }

    foreach ([$alice, $bob] as $student) {
        $answers = ExamAnswer::query()
            ->where('exam_session_id', $s['session']->id)
            ->where('student_id', $student->id)
            ->get();

        expect($answers)->toHaveCount(6)
            ->and($answers->every(fn (ExamAnswer $a): bool => $a->is_correct))->toBeTrue()
            ->and($answers->sum('score'))->toBeGreaterThan(0);

        // selected_translation holds the real text the student tapped, i.e. the correct one.
        $byWord = collect($s['session']->config['questions'])->keyBy('word_id');
        foreach ($answers as $a) {
            expect($a->selected_translation)->toBe($byWord[$a->word_id]['correct_translation']);
        }
    }
});

it('does not accept an answer index copied from a classmate whose option order differs', function (): void {
    $s = openExam(questionCount: 10);
    $alice = $s['student'];
    $bob = Student::factory()->create(['telegram_group_id' => $s['group']->id, 'is_active' => true]);

    // Find a word where Alice's and Bob's correct positions differ (exists with overwhelming
    // probability across 10 questions; we assert it to keep the test honest).
    $pair = null;
    foreach (range(0, 9) as $i) {
        $aq = personalQuestion($s['session'], $alice, $i);
        foreach (range(0, 9) as $j) {
            $bq = personalQuestion($s['session'], $bob, $j);
            if ($bq['word_id'] === $aq['word_id'] && $bq['correct_index'] !== $aq['correct_index']) {
                $pair = [$i, $aq, $j, $bq];
                break 2;
            }
        }
    }
    expect($pair)->not->toBeNull();
    [$aliceIndex, $aq, $bobIndex, $bq] = $pair;

    // Alice answers correctly; Bob hears "it's option N" and copies Alice's N for the same word.
    $this->withHeaders(examAuthHeaders($alice))
        ->getJson("/api/twa/exam/sessions/{$s['session']->id}/question?index={$aliceIndex}")
        ->assertOk();
    $this->withHeaders(examAuthHeaders($alice))
        ->postJson("/api/twa/exam/sessions/{$s['session']->id}/answer", [
            'question_index' => $aliceIndex,
            'word_id' => $aq['word_id'],
            'selected_option_index' => $aq['correct_index'],
            'time_spent_ms' => 500,
        ])
        ->assertOk()
        ->assertJsonPath('is_correct', true);

    $this->withHeaders(examAuthHeaders($bob))
        ->getJson("/api/twa/exam/sessions/{$s['session']->id}/question?index={$bobIndex}")
        ->assertOk();
    $this->withHeaders(examAuthHeaders($bob))
        ->postJson("/api/twa/exam/sessions/{$s['session']->id}/answer", [
            'question_index' => $bobIndex,
            'word_id' => $bq['word_id'],
            'selected_option_index' => $aq['correct_index'],
            'time_spent_ms' => 500,
        ])
        ->assertOk()
        ->assertJsonPath('is_correct', false)
        ->assertJsonPath('score_earned', 0)
        ->assertJsonPath('correct_option', $bq['correct_index']);

    // And copying Alice's question_index (a different word for Bob) fails the word check.
    if ($aliceIndex !== $bobIndex) {
        $this->withHeaders(examAuthHeaders($bob))
            ->postJson("/api/twa/exam/sessions/{$s['session']->id}/answer", [
                'question_index' => $aliceIndex,
                'word_id' => $aq['word_id'],
                'selected_option_index' => $aq['correct_index'],
                'time_spent_ms' => 500,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'word_mismatch');
    }

    $bobAnswer = ExamAnswer::query()->where('student_id', $bob->id)->sole();
    expect($bobAnswer->is_correct)->toBeFalse()
        ->and($bobAnswer->selected_translation)->toBe($bq['options'][$aq['correct_index']]);
});

it('keeps the per-student view stable and the server timer running across repeated GETs', function (): void {
    $s = openExam(questionCount: 6);
    $headers = examAuthHeaders($s['student']);
    $url = "/api/twa/exam/sessions/{$s['session']->id}/question?index=2";

    $first = $this->withHeaders($headers)->getJson($url)->assertOk()->json();
    $this->travel(4)->seconds();
    $second = $this->withHeaders($headers)->getJson($url)->assertOk()->json();

    expect($second['word_id'])->toBe($first['word_id'])
        ->and($second['options'])->toBe($first['options'])
        ->and($second['question_index'])->toBe(2)
        ->and($second['seconds_left'])->toBeLessThanOrEqual($first['seconds_left'] - 3);
});

it('plays legacy sessions (shared order with stored correct_index) exactly as created', function (): void {
    $s = openExam();
    // Simulate the pre-per-student payload: stored correct_index, no question_order flag.
    $config = $s['session']->config;
    unset($config['question_order']);
    foreach ($config['questions'] as $i => $q) {
        $config['questions'][$i]['correct_index'] = 1;
    }
    $s['session']->update(['config' => $config]);
    $session = $s['session']->fresh();

    $alice = $s['student'];
    $bob = Student::factory()->create(['telegram_group_id' => $s['group']->id, 'is_active' => true]);

    foreach ([$alice, $bob] as $student) {
        foreach (range(0, 3) as $i) {
            $served = $this->withHeaders(examAuthHeaders($student))
                ->getJson("/api/twa/exam/sessions/{$session->id}/question?index={$i}")
                ->assertOk()
                ->json();

            expect($served['word_id'])->toBe($config['questions'][$i]['word_id'])
                ->and($served['options'])->toBe($config['questions'][$i]['options']);
        }
    }

    $this->withHeaders(examAuthHeaders($alice))
        ->postJson("/api/twa/exam/sessions/{$session->id}/answer", [
            'question_index' => 0,
            'word_id' => $config['questions'][0]['word_id'],
            'selected_option_index' => 1,
            'time_spent_ms' => 500,
        ])
        ->assertOk()
        ->assertJsonPath('is_correct', true)
        ->assertJsonPath('correct_option', 1);
});
