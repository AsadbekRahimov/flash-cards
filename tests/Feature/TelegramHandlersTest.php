<?php

declare(strict_types=1);

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\Student;
use App\Models\TelegramGroup;
use App\Models\TrainingReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->api = Mockery::mock(TelegramClient::class);
    $this->app->instance(TelegramClient::class, $this->api);
});

it('creates a pending TelegramGroup on my_chat_member into a group', function (): void {
    app(TelegramDispatcher::class)->dispatch([
        'my_chat_member' => [
            'chat' => ['id' => -10012345, 'type' => 'supergroup', 'title' => 'Class 1'],
            'new_chat_member' => ['status' => 'member'],
        ],
    ]);

    $group = TelegramGroup::where('chat_id', -10012345)->firstOrFail();
    expect($group->status)->toBe('pending');
    expect($group->title)->toBe('Class 1');
});

it('marks group as disabled when bot is kicked', function (): void {
    TelegramGroup::factory()->create(['chat_id' => -9999, 'status' => 'active']);

    app(TelegramDispatcher::class)->dispatch([
        'my_chat_member' => [
            'chat' => ['id' => -9999, 'type' => 'supergroup', 'title' => 'X'],
            'new_chat_member' => ['status' => 'kicked'],
        ],
    ]);

    expect(TelegramGroup::where('chat_id', -9999)->value('status'))->toBe('disabled');
});

it('upserts students on new_chat_members', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => -7777, 'status' => 'active']);

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -7777, 'type' => 'supergroup'],
            'new_chat_members' => [
                ['id' => 111, 'first_name' => 'Alice', 'is_bot' => false],
                ['id' => 222, 'first_name' => 'Bob', 'username' => 'bobby', 'is_bot' => false],
                ['id' => 333, 'first_name' => 'Bot', 'is_bot' => true],
            ],
        ],
    ]);

    expect(Student::where('telegram_group_id', $group->id)->count())->toBe(2);
    expect(Student::where('telegram_user_id', 222)->value('username'))->toBe('bobby');
});

it('responds to /help in private chat', function (): void {
    $this->api->shouldReceive('sendMessage')
        ->once()
        ->with(500, Mockery::pattern('/LexiFlow/'));

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => 500, 'type' => 'private'],
            'text' => '/help',
        ],
    ]);
});

it('responds to /help in an active whitelisted group', function (): void {
    TelegramGroup::factory()->create(['chat_id' => -5001, 'status' => 'active']);

    $this->api->shouldReceive('sendMessage')
        ->once()
        ->with(-5001, Mockery::pattern('/LexiFlow Bot в группе/u'));

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -5001, 'type' => 'supergroup'],
            'text' => '/help',
        ],
    ]);
});

it('stays silent on /help in an unknown or inactive group (group lock)', function (): void {
    TelegramGroup::factory()->create(['chat_id' => -5002, 'status' => 'pending']);

    $this->api->shouldReceive('sendMessage')->never();

    // Inactive (pending) group.
    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -5002, 'type' => 'supergroup'],
            'text' => '/help',
        ],
    ]);

    // Completely unknown group.
    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -9090, 'type' => 'supergroup'],
            'text' => '/help',
        ],
    ]);
});

it('binds a teacher on /start in DM when telegram_user_id matches', function (): void {
    $teacher = User::factory()->create(['telegram_user_id' => 12345, 'last_login_at' => null]);

    $this->api->shouldReceive('sendMessage')
        ->once()
        ->with(12345, Mockery::pattern('/Привет/'));

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => 12345, 'type' => 'private'],
            'from' => ['id' => 12345],
            'text' => '/start',
        ],
    ]);

    expect($teacher->fresh()->last_login_at)->not->toBeNull();
});

it('tells unknown TG user their ID on /start', function (): void {
    $this->api->shouldReceive('sendMessage')
        ->once()
        ->with(999, Mockery::pattern('/999/'));

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => 999, 'type' => 'private'],
            'from' => ['id' => 999],
            'text' => '/start',
        ],
    ]);
});

it('sends a seven-day stats report to a teacher in DM', function (): void {
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $teacher = User::factory()->create(['telegram_user_id' => 54321]);
    DB::table('teacher_groups')->insert([
        'user_id' => $teacher->id,
        'telegram_group_id' => $group->id,
        'is_primary' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $student = Student::factory()->create(['telegram_group_id' => $group->id]);
    TrainingReview::factory()->count(3)->create([
        'student_id' => $student->id,
        'created_at' => now()->subDays(2),
    ]);

    $api = new class implements TelegramClient
    {
        /** @var list<array{chat_id:int|string,text:string}> */
        public array $messages = [];

        /** @param array<string, mixed>|null $replyMarkup */
        public function sendMessage(
            int|string $chatId,
            string $text,
            ?string $parseMode = null,
            ?array $replyMarkup = null,
        ): void {
            $this->messages[] = ['chat_id' => $chatId, 'text' => $text];
        }

        public function sendWebAppButton(
            int|string $chatId,
            string $text,
            string $buttonText,
            string $url,
            ?string $parseMode = null,
        ): void {
            $this->messages[] = ['chat_id' => $chatId, 'text' => $text];
        }

        public function answerCallbackQuery(string $callbackQueryId): void {}

        public function setWebhook(string $url, string $secretHeader): bool
        {
            return true;
        }

        public function deleteWebhook(): bool
        {
            return true;
        }
    };

    app()->instance(TelegramClient::class, $api);

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => 54321, 'type' => 'private'],
            'from' => ['id' => 54321],
            'text' => '/stats',
        ],
    ]);

    expect($api->messages)->toHaveCount(1);
    expect($api->messages[0]['chat_id'])->toBe(54321);
    expect($api->messages[0]['text'])->toMatch('/Статистика LexiFlow/u');
});
