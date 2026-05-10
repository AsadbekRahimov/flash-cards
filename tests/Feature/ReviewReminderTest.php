<?php

declare(strict_types=1);

use App\Domain\Learning\Services\ReviewReminderLinkBuilder;
use App\Domain\Learning\Services\ReviewReminderService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Jobs\SendReviewReminderJob;
use App\Models\Lesson;
use App\Models\NotificationLog;
use App\Models\Stage;
use App\Models\Student;
use App\Models\TelegramGroup;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\Word;
use App\Models\WordRepetition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('queues reminders only for active students with enough due words and no recent reminder', function (): void {
    Bus::fake();

    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $student = Student::factory()->create(['telegram_group_id' => $group->id]);
    $recentlyNotified = Student::factory()->create(['telegram_group_id' => $group->id]);
    $notEnoughDue = Student::factory()->create(['telegram_group_id' => $group->id]);

    $words = Word::factory()->count(10)->create();
    foreach ($words->take(5) as $word) {
        WordRepetition::factory()->due()->create(['student_id' => $student->id, 'word_id' => $word->id]);
    }
    foreach ($words->skip(5)->take(5) as $word) {
        WordRepetition::factory()->due()->create(['student_id' => $recentlyNotified->id, 'word_id' => $word->id]);
    }
    $singleDueWord = Word::factory()->create();
    WordRepetition::factory()->due()->create(['student_id' => $notEnoughDue->id, 'word_id' => $singleDueWord->id]);

    NotificationLog::factory()->create([
        'student_id' => $recentlyNotified->id,
        'type' => 'review_reminder',
        'sent_at' => now()->subHours(2),
    ]);

    $queued = app(ReviewReminderService::class)->queueDueReminders(5);

    expect($queued)->toBe(1);
    Bus::assertDispatched(SendReviewReminderJob::class, fn (SendReviewReminderJob $job): bool => $job->studentId === $student->id && $job->dueCount === 5);
});

it('sends a reminder message and writes notification log', function (): void {
    Config::set('twa.base_url', 'https://twa.test');

    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $teacher = User::factory()->create();
    DB::table('teacher_groups')->insert([
        'user_id' => $teacher->id,
        'telegram_group_id' => $group->id,
        'is_primary' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $student = Student::factory()->create([
        'telegram_group_id' => $group->id,
        'telegram_user_id' => 777001,
    ]);
    $stage = Stage::factory()->create(['number' => 3]);
    $lesson = Lesson::factory()->for($stage)->create(['number' => 4]);
    $word = Word::factory()->for($lesson)->create();
    WordRepetition::factory()->due()->create([
        'student_id' => $student->id,
        'word_id' => $word->id,
    ]);

    $api = new class implements TelegramClient
    {
        /** @var list<array{chat_id:int|string,text:string,parse_mode:string|null,button_text:string|null,url:string|null,reply_markup:array<string, mixed>|null}> */
        public array $messages = [];

        /** @param array<string, mixed>|null $replyMarkup */
        public function sendMessage(
            int|string $chatId,
            string $text,
            ?string $parseMode = null,
            ?array $replyMarkup = null,
        ): void {
            $this->messages[] = [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => $parseMode,
                'button_text' => null,
                'url' => null,
                'reply_markup' => $replyMarkup,
            ];
        }

        public function sendWebAppButton(
            int|string $chatId,
            string $text,
            string $buttonText,
            string $url,
            ?string $parseMode = null,
        ): void {
            $this->messages[] = [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => $parseMode,
                'button_text' => $buttonText,
                'url' => $url,
                'reply_markup' => null,
            ];
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

    (new SendReviewReminderJob($student->id, 5))->handle($api, app(ReviewReminderLinkBuilder::class));

    expect($api->messages)->toHaveCount(1);
    $message = $api->messages[0];

    expect($message['chat_id'])->toBe(777001);
    expect($message['text'])->toContain('5 слов');
    $session = TrainingSession::query()->firstOrFail();
    expect($message['button_text'])->toBe('Открыть LexiFlow');
    expect($message['url'])->toBe("https://twa.test/twa/training/{$session->id}");

    expect(NotificationLog::query()
        ->where('student_id', $student->id)
        ->where('type', 'review_reminder')
        ->exists())->toBeTrue();
});
