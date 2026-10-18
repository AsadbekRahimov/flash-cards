<?php

declare(strict_types=1);

use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Services\ReviewReminderLinkBuilder;
use App\Domain\Learning\Services\ReviewReminderService;
use App\Domain\Learning\Services\StudentStatsService;
use App\Domain\Learning\Support\LearningPolicy;
use App\Domain\Localization\Services\LocaleResolver;
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
use Illuminate\Database\Migrations\Migration;
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

it('ignores minute-based learning cards when counting due words for reminders', function (): void {
    Bus::fake();

    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $student = Student::factory()->create(['telegram_group_id' => $group->id]);

    // 4 graduated due cards + 3 learning cards whose step already elapsed (abandoned session).
    foreach (Word::factory()->count(4)->create() as $word) {
        WordRepetition::factory()->due()->create(['student_id' => $student->id, 'word_id' => $word->id]);
    }
    foreach (Word::factory()->count(3)->create() as $word) {
        WordRepetition::factory()->learning(step: 1)->create([
            'student_id' => $student->id,
            'word_id' => $word->id,
            'next_review_at' => now()->subMinutes(5),
        ]);
    }

    expect(app(ReviewReminderService::class)->queueDueReminders(5))->toBe(0);
    Bus::assertNothingDispatched();

    // One more graduated due card crosses the threshold; learning cards are still not counted.
    WordRepetition::factory()->due()->create(['student_id' => $student->id, 'word_id' => Word::factory()->create()->id]);

    expect(app(ReviewReminderService::class)->queueDueReminders(5))->toBe(1);
    Bus::assertDispatched(SendReviewReminderJob::class, fn (SendReviewReminderJob $job): bool => $job->dueCount === 5);
});

it('counts (re)learning cards abandoned longer than abandoned_after_hours, but not fresh ones', function (): void {
    Bus::fake();
    app()->instance(LearningPolicy::class, new LearningPolicy(
        learningStepsMinutes: [1, 10],
        relearningStepsMinutes: [10],
        graduatingIntervalDays: 1,
        easyIntervalDays: 4,
        learnAheadMinutes: 20,
        abandonedAfterHours: 12,
    ));

    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $student = Student::factory()->create(['telegram_group_id' => $group->id]);

    // 3 graduated due cards + 1 relearning card lapsed and abandoned yesterday evening
    // + 1 learning card abandoned a day ago = 5 -> reaches the threshold.
    foreach (Word::factory()->count(3)->create() as $word) {
        WordRepetition::factory()->due()->create(['student_id' => $student->id, 'word_id' => $word->id]);
    }
    WordRepetition::factory()->relearning()->create([
        'student_id' => $student->id,
        'word_id' => Word::factory()->create()->id,
        'next_review_at' => now()->subHours(12)->subMinute(),
    ]);
    WordRepetition::factory()->learning(step: 1)->create([
        'student_id' => $student->id,
        'word_id' => Word::factory()->create()->id,
        'next_review_at' => now()->subDay(),
    ]);
    // Fresh in-session cards (step elapsed minutes / a few hours ago) must not count.
    WordRepetition::factory()->relearning()->create([
        'student_id' => $student->id,
        'word_id' => Word::factory()->create()->id,
        'next_review_at' => now()->subMinutes(3),
    ]);
    WordRepetition::factory()->learning()->create([
        'student_id' => $student->id,
        'word_id' => Word::factory()->create()->id,
        'next_review_at' => now()->subHours(11),
    ]);
    // A `new` row shown long ago but never answered is not "to review" either.
    WordRepetition::factory()->create([
        'student_id' => $student->id,
        'word_id' => Word::factory()->create()->id,
        'state' => RepetitionState::New->value,
        'repetitions' => 0,
        'next_review_at' => now()->subDays(2),
    ]);

    expect(app(ReviewReminderService::class)->queueDueReminders(5))->toBe(1);
    Bus::assertDispatched(SendReviewReminderJob::class, fn (SendReviewReminderJob $job): bool => $job->studentId === $student->id && $job->dueCount === 5);

    // Same number the student sees in GET /me.
    expect(app(StudentStatsService::class)->forStudent($student)->wordsDueToday)->toBe(5);
});

it('does not remind about a relearning card abandoned less than abandoned_after_hours ago', function (): void {
    Bus::fake();

    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $student = Student::factory()->create(['telegram_group_id' => $group->id]);

    foreach (Word::factory()->count(4)->create() as $word) {
        WordRepetition::factory()->due()->create(['student_id' => $student->id, 'word_id' => $word->id]);
    }
    $abandoned = WordRepetition::factory()->relearning()->create([
        'student_id' => $student->id,
        'word_id' => Word::factory()->create()->id,
        'next_review_at' => now()->subHours(11),
    ]);

    expect(app(ReviewReminderService::class)->queueDueReminders(5))->toBe(0);
    Bus::assertNothingDispatched();

    // Two hours later the same card crosses the 12h line.
    $this->travel(2)->hours();
    expect(app(ReviewReminderService::class)->queueDueReminders(5))->toBe(1);
    Bus::assertDispatched(SendReviewReminderJob::class, fn (SendReviewReminderJob $job): bool => $job->dueCount === 5);
    expect($abandoned->fresh()->state)->toBe(RepetitionState::Relearning);
});

it('deep-links the reminder to the lesson of an abandoned relearning card', function (): void {
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
    $student = Student::factory()->create(['telegram_group_id' => $group->id]);
    $lesson = Lesson::factory()->for(Stage::factory()->create(['number' => 2]))->create(['number' => 5]);
    WordRepetition::factory()->relearning()->create([
        'student_id' => $student->id,
        'word_id' => Word::factory()->for($lesson)->create()->id,
        'next_review_at' => now()->subDay(),
    ]);

    $url = app(ReviewReminderLinkBuilder::class)->buildTrainingUrl($student);

    $session = TrainingSession::query()->firstOrFail();
    expect($url)->toBe("https://twa.test/twa/training/{$session->id}")
        ->and($session->lesson_id)->toBe($lesson->id);
});

it('keeps legacy failed cards (backfilled to relearning) in reminders once their day has passed', function (): void {
    Bus::fake();

    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $student = Student::factory()->create(['telegram_group_id' => $group->id]);
    $words = Word::factory()->count(5)->create();

    /** @var Migration $migration */
    $migration = require base_path('database/migrations/2026_10_18_000002_add_learning_state_to_word_repetitions.php');
    $migration->down();

    // Legacy SM-2: failed cards had repetitions = 0, interval 1 day, next_review_at = fail time + 1 day.
    // Simulate rows whose legacy due moment is already 13 hours in the past.
    foreach ($words as $word) {
        DB::table('word_repetitions')->insert([
            'student_id' => $student->id,
            'word_id' => $word->id,
            'repetitions' => 0,
            'interval_days' => 1,
            'easiness_factor' => 2.5,
            'last_quality' => 2,
            'last_reviewed_at' => now()->subHours(37),
            'next_review_at' => now()->subHours(13),
            'is_hard' => true,
            'meta' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $migration->up();

    expect(WordRepetition::query()->where('student_id', $student->id)->pluck('state')->unique()->all())
        ->toBe([RepetitionState::Relearning]);

    expect(app(ReviewReminderService::class)->queueDueReminders(5))->toBe(1);
    Bus::assertDispatched(SendReviewReminderJob::class, fn (SendReviewReminderJob $job): bool => $job->studentId === $student->id && $job->dueCount === 5);
    expect(app(StudentStatsService::class)->forStudent($student)->wordsDueToday)->toBe(5);
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
        'language_code' => 'ru',
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

    (new SendReviewReminderJob($student->id, 5))->handle($api, app(ReviewReminderLinkBuilder::class), app(ProductEventRecorder::class), app(LocaleResolver::class));

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
