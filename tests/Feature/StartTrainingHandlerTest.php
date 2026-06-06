<?php

declare(strict_types=1);

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\StartTrainingHandler;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('twa.base_url', 'https://twa.test');
    $this->api = Mockery::mock(TelegramClient::class);
    $this->app->instance(TelegramClient::class, $this->api);
});

function attachTeacher(User $user, TelegramGroup $group): void
{
    DB::table('teacher_groups')->insert([
        'user_id' => $user->id,
        'telegram_group_id' => $group->id,
        'is_primary' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('opens a training session and posts a WebApp button for the teacher', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => -1001, 'status' => 'active']);
    $teacher = User::factory()->create(['telegram_user_id' => 777]);
    attachTeacher($teacher, $group);

    $stage = Stage::factory()->create(['number' => 1]);
    $lesson = Lesson::factory()->for($stage)->create(['number' => 1]);

    $this->api->shouldReceive('sendWebAppButton')
        ->once()
        ->withArgs(function (int $chatId, string $text, string $buttonText, string $url, ?string $parseMode = null): bool {
            return $chatId === -1001
                && str_contains($text, 'Stage 1')
                && $buttonText === '🎯 Открыть тренировку'
                && str_starts_with($url, 'https://twa.test/twa/training/')
                && $parseMode === null;
        });

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -1001, 'type' => 'supergroup'],
            'from' => ['id' => 777],
            'text' => '/start_training 1 1',
        ],
    ]);

    expect(TrainingSession::query()
        ->where('telegram_group_id', $group->id)
        ->where('lesson_id', $lesson->id)
        ->where('status', 'open')
        ->count())->toBe(1);
});

it('sends a lesson picker when no arguments are provided', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => -2002, 'status' => 'active']);
    $teacher = User::factory()->create(['telegram_user_id' => 888]);
    attachTeacher($teacher, $group);
    $stage = Stage::factory()->create(['number' => 1]);
    Lesson::factory()->for($stage)->create(['number' => 1]);
    Lesson::factory()->for($stage)->create(['number' => 2]);

    $this->api->shouldReceive('sendMessage')
        ->once()
        ->withArgs(function (int $chatId, string $text, ?string $parseMode, ?array $replyMarkup): bool {
            return $chatId === -2002
                && str_contains($text, 'Выберите урок')
                && is_array($replyMarkup['inline_keyboard'] ?? null)
                && str_starts_with($replyMarkup['inline_keyboard'][0][0]['callback_data'], StartTrainingHandler::CALLBACK_PREFIX);
        });

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -2002, 'type' => 'supergroup'],
            'from' => ['id' => 888],
            'text' => '/start_training',
        ],
    ]);

    expect(TrainingSession::query()->where('status', 'open')->count())->toBe(0);
});

it('stays silent on the lesson picker path in a non-active group (Group Lock)', function (): void {
    // Regression: previously the no-args picker bypassed the active-group check
    // (only the service validated status), so the bot replied in pending groups.
    $group = TelegramGroup::factory()->create(['chat_id' => -2009, 'status' => 'pending']);
    $teacher = User::factory()->create(['telegram_user_id' => 889]);
    attachTeacher($teacher, $group);
    $stage = Stage::factory()->create(['number' => 1]);
    Lesson::factory()->for($stage)->create(['number' => 1]);

    $this->api->shouldReceive('sendMessage')->never();
    $this->api->shouldReceive('sendWebAppButton')->never();

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -2009, 'type' => 'supergroup'],
            'from' => ['id' => 889],
            'text' => '/start_training',
        ],
    ]);
});

it('opens a training session via callback when a lesson is chosen from the picker', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => -2003, 'status' => 'active']);
    $teacher = User::factory()->create(['telegram_user_id' => 888]);
    attachTeacher($teacher, $group);
    $stage = Stage::factory()->create(['number' => 1]);
    Lesson::factory()->for($stage)->create(['number' => 1]);

    $this->api->shouldReceive('answerCallbackQuery')->once()->with('cq-id-123');
    $this->api->shouldReceive('sendWebAppButton')
        ->once()
        ->withArgs(function (int $chatId, string $text, string $buttonText, string $url): bool {
            return $chatId === -2003
                && str_contains($text, 'Stage 1')
                && str_starts_with($url, 'https://twa.test/twa/training/');
        });

    app(TelegramDispatcher::class)->dispatch([
        'callback_query' => [
            'id' => 'cq-id-123',
            'from' => ['id' => 888],
            'data' => StartTrainingHandler::CALLBACK_PREFIX.'1:1',
            'message' => ['chat' => ['id' => -2003, 'type' => 'supergroup']],
        ],
    ]);

    expect(TrainingSession::query()->where('status', 'open')->count())->toBe(1);
});

it('is idempotent: second invocation reuses the open session', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => -3003, 'status' => 'active']);
    $teacher = User::factory()->create(['telegram_user_id' => 999]);
    attachTeacher($teacher, $group);
    $stage = Stage::factory()->create(['number' => 2]);
    Lesson::factory()->for($stage)->create(['number' => 3]);

    $this->api->shouldReceive('sendWebAppButton')->twice();

    for ($i = 0; $i < 2; $i++) {
        app(TelegramDispatcher::class)->dispatch([
            'message' => [
                'chat' => ['id' => -3003, 'type' => 'supergroup'],
                'from' => ['id' => 999],
                'text' => '/start_training 2 3',
            ],
        ]);
    }

    expect(TrainingSession::query()->where('status', 'open')->count())->toBe(1);
});

it('rejects users that do not teach the group', function (): void {
    TelegramGroup::factory()->create(['chat_id' => -4004, 'status' => 'active']);
    $otherUser = User::factory()->create(['telegram_user_id' => 555]);
    // no teacher_groups row

    $this->api->shouldReceive('sendMessage')
        ->once()
        ->with(-4004, Mockery::pattern('/учитель/'));

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -4004, 'type' => 'supergroup'],
            'from' => ['id' => 555],
            'text' => '/start_training 1 1',
        ],
    ]);

    expect(TrainingSession::query()->count())->toBe(0);
});

it('stays silent when the group is not active (Group Lock)', function (): void {
    // FR-BOT-01: the bot must not reveal itself in non-whitelisted groups, so
    // even an authorised teacher's command is dropped silently by the dispatcher.
    $group = TelegramGroup::factory()->pending()->create(['chat_id' => -5005]);
    $teacher = User::factory()->create(['telegram_user_id' => 111]);
    attachTeacher($teacher, $group);

    $this->api->shouldReceive('sendMessage')->never();
    $this->api->shouldReceive('sendWebAppButton')->never();

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -5005, 'type' => 'supergroup'],
            'from' => ['id' => 111],
            'text' => '/start_training 1 1',
        ],
    ]);

    expect(TrainingSession::query()->where('status', 'open')->count())->toBe(0);
});

it('reports when a lesson does not exist', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => -6006, 'status' => 'active']);
    $teacher = User::factory()->create(['telegram_user_id' => 222]);
    attachTeacher($teacher, $group);
    $stage = Stage::factory()->create(['number' => 1]);
    Lesson::factory()->for($stage)->create(['number' => 1]);

    $this->api->shouldReceive('sendMessage')
        ->once()
        ->with(-6006, Mockery::pattern('/не найден/u'));

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -6006, 'type' => 'supergroup'],
            'from' => ['id' => 222],
            'text' => '/start_training 1 99',
        ],
    ]);
});

it('does not open a session when invoked in a private chat (handled by StartCommandHandler instead)', function (): void {
    // StartCommandHandler may send "not a teacher" reply — allow it.
    $this->api->shouldReceive('sendMessage')->zeroOrMoreTimes();

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => 123, 'type' => 'private'],
            'from' => ['id' => 1],
            'text' => '/start_training 1 1',
        ],
    ]);

    expect(TrainingSession::query()->count())->toBe(0);
});
