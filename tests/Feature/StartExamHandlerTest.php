<?php

declare(strict_types=1);

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\ExamSession;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('twa.base_url', 'https://twa.test');
    $this->api = Mockery::mock(TelegramClient::class);
    $this->app->instance(TelegramClient::class, $this->api);
});

function attachExamTeacher(User $user, TelegramGroup $group): void
{
    DB::table('teacher_groups')->insert([
        'user_id' => $user->id,
        'telegram_group_id' => $group->id,
        'is_primary' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** @return array{group: TelegramGroup, teacher: User, lesson: Lesson} */
function seedLessonWithWords(int $chatId = -1001, int $telegramUserId = 777): array
{
    $group = TelegramGroup::factory()->create(['chat_id' => $chatId, 'status' => 'active']);
    $teacher = User::factory()->create(['telegram_user_id' => $telegramUserId]);
    attachExamTeacher($teacher, $group);

    $stage = Stage::factory()->inDefaultCourse()->create(['number' => 1]);
    $lesson = Lesson::factory()->for($stage)->create(['number' => 1]);
    Word::factory()->count(10)->for($lesson)->create();

    // Distractor pool from another lesson
    $lesson2 = Lesson::factory()->for($stage)->create(['number' => 2]);
    Word::factory()->count(20)->for($lesson2)->create();

    return compact('group', 'teacher', 'lesson');
}

it('opens an exam session via /start_exam and posts WebApp button', function (): void {
    seedLessonWithWords();

    $this->api->shouldReceive('sendWebAppButton')
        ->once()
        ->withArgs(function (int $chatId, string $text, string $buttonText, string $url, ?string $parseMode): bool {
            return $chatId === -1001
                && str_contains($text, 'Экзамен запущен')
                && $buttonText === '🏁 Открыть экзамен'
                && str_starts_with($url, 'https://twa.test/twa/exam/')
                && $parseMode === 'HTML';
        });

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -1001, 'type' => 'supergroup'],
            'from' => ['id' => 777],
            'text' => '/start_exam 1 1 2',
        ],
    ]);

    $session = ExamSession::query()->first();
    expect($session)->not->toBeNull()
        ->and($session->status)->toBe('open')
        ->and($session->config['duration_minutes'])->toBe(2)
        ->and($session->config['total_questions'])->toBeGreaterThanOrEqual(4);

    // ends_at ≈ started_at + 2 min
    $diffSec = (int) abs($session->started_at->diffInSeconds($session->ends_at));
    expect($diffSec)->toBeGreaterThanOrEqual(110)->toBeLessThanOrEqual(130);
});

it('defaults to stage=1 lesson=1 minutes=10 when no arguments', function (): void {
    seedLessonWithWords();
    $this->api->shouldReceive('sendWebAppButton')->once();

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -1001, 'type' => 'supergroup'],
            'from' => ['id' => 777],
            'text' => '/start_exam',
        ],
    ]);

    $session = ExamSession::query()->sole();
    expect($session->config['duration_minutes'])->toBe(10);
});

it('rejects second /start_exam while one is already open', function (): void {
    seedLessonWithWords();
    $this->api->shouldReceive('sendWebAppButton')->once();                 // 1st call ok
    $this->api->shouldReceive('sendMessage')->once()                       // 2nd call: error
        ->with(-1001, Mockery::pattern('/уже идёт/u'));

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -1001, 'type' => 'supergroup'],
            'from' => ['id' => 777],
            'text' => '/start_exam 1 1 2',
        ],
    ]);

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -1001, 'type' => 'supergroup'],
            'from' => ['id' => 777],
            'text' => '/start_exam 1 1 2',
        ],
    ]);

    expect(ExamSession::query()->where('status', 'open')->count())->toBe(1);
});

it('rejects non-teacher user', function (): void {
    TelegramGroup::factory()->create(['chat_id' => -2002, 'status' => 'active']);
    User::factory()->create(['telegram_user_id' => 555]);

    $this->api->shouldReceive('sendMessage')
        ->once()
        ->with(-2002, Mockery::pattern('/учитель/'));

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -2002, 'type' => 'supergroup'],
            'from' => ['id' => 555],
            'text' => '/start_exam 1 1 2',
        ],
    ]);

    expect(ExamSession::query()->count())->toBe(0);
});

it('rejects invalid duration', function (): void {
    seedLessonWithWords();

    $this->api->shouldReceive('sendMessage')
        ->once()
        ->with(-1001, Mockery::pattern('/от 1 до 30/u'));

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -1001, 'type' => 'supergroup'],
            'from' => ['id' => 777],
            'text' => '/start_exam 1 1 99',
        ],
    ]);

    expect(ExamSession::query()->count())->toBe(0);
});

it('rejects lesson with not enough words', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => -3003, 'status' => 'active']);
    $teacher = User::factory()->create(['telegram_user_id' => 888]);
    attachExamTeacher($teacher, $group);
    $stage = Stage::factory()->inDefaultCourse()->create(['number' => 1]);
    $lesson = Lesson::factory()->for($stage)->create(['number' => 1]);
    Word::factory()->count(2)->for($lesson)->create(); // less than min 4

    $this->api->shouldReceive('sendMessage')
        ->once()
        ->with(-3003, Mockery::pattern('/недостаточно/u'));

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -3003, 'type' => 'supergroup'],
            'from' => ['id' => 888],
            'text' => '/start_exam 1 1 2',
        ],
    ]);

    expect(ExamSession::query()->count())->toBe(0);
});

it('rejects a lesson whose words have no real distractors and leaves no session behind', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => -4004, 'status' => 'active']);
    $teacher = User::factory()->create(['telegram_user_id' => 999]);
    attachExamTeacher($teacher, $group);
    $stage = Stage::factory()->inDefaultCourse()->create(['number' => 1]);
    $lesson = Lesson::factory()->for($stage)->create(['number' => 1]);
    // 4 words, all with the same translation and nothing else in the library.
    Word::factory()->count(4)->for($lesson)->create(['translation' => 'одно и то же']);

    $this->api->shouldReceive('sendMessage')
        ->once()
        ->with(-4004, Mockery::pattern('/вариантов ответа/u'));

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -4004, 'type' => 'supergroup'],
            'from' => ['id' => 999],
            'text' => '/start_exam 1 1 2',
        ],
    ]);

    expect(ExamSession::query()->count())->toBe(0);
});

it('stores canonical questions with per-learner ordering enabled and no correct_index', function (): void {
    seedLessonWithWords();
    $this->api->shouldReceive('sendWebAppButton')->once();

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -1001, 'type' => 'supergroup'],
            'from' => ['id' => 777],
            'text' => '/start_exam 1 1 2',
        ],
    ]);

    $session = ExamSession::query()->sole();
    expect($session->config['question_order'])->toBe('per_learner')
        ->and($session->config['order_secret'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($session->config['total_questions'])->toBe(count($session->config['questions']));
    foreach ($session->config['questions'] as $q) {
        expect($q)->toHaveKeys(['word_id', 'word', 'correct_translation', 'options'])
            ->and($q)->not->toHaveKey('correct_index')
            ->and(array_keys($q['options'], $q['correct_translation'], true))->toHaveCount(1);
        foreach ($q['options'] as $o) {
            expect($o)->not->toContain('filler');
        }
    }
});

it('counts only active words towards the exam minimum', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => -3004, 'status' => 'active']);
    $teacher = User::factory()->create(['telegram_user_id' => 889]);
    attachExamTeacher($teacher, $group);
    $stage = Stage::factory()->inDefaultCourse()->create(['number' => 1]);
    $lesson = Lesson::factory()->for($stage)->create(['number' => 1]);
    Word::factory()->count(3)->for($lesson)->create();
    Word::factory()->count(6)->for($lesson)->create(['is_active' => false]); // 9 rows, only 3 live

    $this->api->shouldReceive('sendMessage')
        ->once()
        ->with(-3004, Mockery::pattern('/недостаточно/u'));

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -3004, 'type' => 'supergroup'],
            'from' => ['id' => 889],
            'text' => '/start_exam 1 1 2',
        ],
    ]);

    expect(ExamSession::query()->count())->toBe(0);
});
