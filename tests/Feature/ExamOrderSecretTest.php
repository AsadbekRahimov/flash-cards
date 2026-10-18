<?php

declare(strict_types=1);

use App\Domain\Learning\Services\ExamQuestionPersonalizer;
use App\Domain\Twa\Services\JwtService;
use App\Filament\Resources\ExamSessionResource;
use App\Models\ExamSession;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Student;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Models\Word;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const ORDER_SECRET_MARKER = 'ordersecret-marker-4f9a0c1e77b2d3a8';

/** @return array{session: ExamSession, student: Student, group: TelegramGroup} */
function secretExam(): array
{
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $stage = Stage::factory()->create();
    $lesson = Lesson::factory()->for($stage)->create();
    $words = Word::factory()->count(4)->for($lesson)->create();

    $questions = [];
    foreach ($words as $i => $word) {
        $questions[] = [
            'word_id' => $word->id,
            'word' => "word_{$i}",
            'correct_translation' => "correct_{$i}",
            'options' => ["opt_a_{$i}", "correct_{$i}", "opt_c_{$i}", "opt_d_{$i}"],
        ];
    }

    $session = ExamSession::factory()->create([
        'telegram_group_id' => $group->id,
        'lesson_id' => $lesson->id,
        'status' => 'open',
        'started_at' => now()->subMinute(),
        'ends_at' => now()->addMinutes(5),
        'config' => [
            'total_questions' => 4,
            'seconds_per_question' => 10,
            'duration_minutes' => 5,
            'question_order' => ExamQuestionPersonalizer::ORDER_PER_STUDENT,
            'order_secret' => ORDER_SECRET_MARKER,
            'questions' => $questions,
        ],
    ]);
    $student = Student::factory()->create(['telegram_group_id' => $group->id, 'is_active' => true]);

    return compact('session', 'student', 'group');
}

it('never exposes order_secret through the TWA exam API', function (): void {
    $s = secretExam();
    $token = app(JwtService::class)->issue($s['student']->id, (int) $s['group']->id)['token'];
    $headers = ['Authorization' => 'Bearer '.$token];
    $base = "/api/twa/exam/sessions/{$s['session']->id}";

    $responses = [
        $this->withHeaders($headers)->postJson("{$base}/join"),
        $this->withHeaders($headers)->getJson("{$base}/question?index=0"),
    ];

    $q = app(ExamQuestionPersonalizer::class)->questionAt($s['session'], $s['student'], 0);
    $responses[] = $this->withHeaders($headers)->postJson("{$base}/answer", [
        'question_index' => 0,
        'word_id' => $q['word_id'],
        'selected_option_index' => $q['correct_index'],
        'time_spent_ms' => 1500,
    ]);

    $s['session']->update(['status' => 'closed', 'closed_at' => now()]);
    $responses[] = $this->withHeaders($headers)->getJson("{$base}/result");

    foreach ($responses as $response) {
        expect($response->getStatusCode())->toBeLessThan(500)
            ->and($response->getContent())->not->toContain(ORDER_SECRET_MARKER)
            ->and($response->getContent())->not->toContain('order_secret');
    }
});

it('hides the exam config (answer key, order secret) from model serialization', function (): void {
    $s = secretExam();

    expect($s['session']->toJson())->not->toContain(ORDER_SECRET_MARKER)
        ->and($s['session']->toArray())->not->toHaveKey('config')
        ->and($s['session']->config['order_secret'])->toBe(ORDER_SECRET_MARKER);
});

it('does not render order_secret in the Filament exam pages', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $s = secretExam();
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');
    $teacher->telegramGroups()->attach($s['group']->id);

    $this->actingAs($teacher);

    $this->get(ExamSessionResource::getUrl('view', ['record' => $s['session']]))
        ->assertOk()
        ->assertDontSee(ORDER_SECRET_MARKER)
        ->assertDontSee('order_secret');
    $this->get(ExamSessionResource::getUrl('index'))
        ->assertOk()
        ->assertDontSee(ORDER_SECRET_MARKER);
});
