<?php

declare(strict_types=1);

use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Learners\Services\GroupMembershipService;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Services\LearnerStatsService;
use App\Domain\Learning\Services\ReviewReminderLinkBuilder;
use App\Domain\Learning\Services\ReviewReminderService;
use App\Domain\Learning\Support\LearningPolicy;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Jobs\SendReviewReminderJob;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\NotificationLog;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\TrainingReview;
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

it('queues reminders only for active learners with enough due words and no recent reminder', function (): void {
    Bus::fake();

    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $learner = Learner::factory()->memberOf($group)->create();
    $recentlyNotified = Learner::factory()->memberOf($group)->create();
    $notEnoughDue = Learner::factory()->memberOf($group)->create();

    $words = Word::factory()->count(10)->create();
    foreach ($words->take(5) as $word) {
        WordRepetition::factory()->due()->create(['learner_id' => $learner->id, 'word_id' => $word->id]);
    }
    foreach ($words->skip(5)->take(5) as $word) {
        WordRepetition::factory()->due()->create(['learner_id' => $recentlyNotified->id, 'word_id' => $word->id]);
    }
    $singleDueWord = Word::factory()->create();
    WordRepetition::factory()->due()->create(['learner_id' => $notEnoughDue->id, 'word_id' => $singleDueWord->id]);

    NotificationLog::factory()->create([
        'learner_id' => $recentlyNotified->id,
        'type' => 'review_reminder',
        'sent_at' => now()->subHours(2),
    ]);

    $queued = app(ReviewReminderService::class)->queueDueReminders(5);

    expect($queued)->toBe(1);
    Bus::assertDispatched(SendReviewReminderJob::class, fn (SendReviewReminderJob $job): bool => $job->learnerId === $learner->id && $job->dueCount === 5);
});

it('queues ONE reminder for a learner who studies in two groups', function (): void {
    Bus::fake();

    $groupA = TelegramGroup::factory()->create(['status' => 'active']);
    $groupB = TelegramGroup::factory()->create(['status' => 'active']);
    $learner = Learner::factory()->memberOf($groupA)->memberOf($groupB)->create();

    foreach (Word::factory()->count(5)->create() as $word) {
        WordRepetition::factory()->due()->create(['learner_id' => $learner->id, 'word_id' => $word->id]);
    }

    expect(app(ReviewReminderService::class)->queueDueReminders(5))->toBe(1);
    Bus::assertDispatchedTimes(SendReviewReminderJob::class, 1);
    Bus::assertDispatched(SendReviewReminderJob::class, fn (SendReviewReminderJob $job): bool => $job->learnerId === $learner->id && $job->dueCount === 5);
});

it('does not remind learners without an active membership in an active group, or banned ones', function (): void {
    Bus::fake();

    $active = TelegramGroup::factory()->create(['status' => 'active']);
    $disabled = TelegramGroup::factory()->create(['status' => 'disabled']);
    $left = Learner::factory()->memberOf($active, 'left')->create();
    $removed = Learner::factory()->memberOf($active, 'removed')->create();
    $inDisabledGroup = Learner::factory()->memberOf($disabled)->create();
    $noGroup = Learner::factory()->create();
    $banned = Learner::factory()->memberOf($active)->create(['blocked_at' => now()]);
    $reachable = Learner::factory()->memberOf($active)->create();

    foreach ([$left, $removed, $inDisabledGroup, $noGroup, $banned, $reachable] as $learner) {
        foreach (Word::factory()->count(5)->create() as $word) {
            WordRepetition::factory()->due()->create(['learner_id' => $learner->id, 'word_id' => $word->id]);
        }
    }

    expect(app(ReviewReminderService::class)->queueDueReminders(5))->toBe(1);
    Bus::assertDispatched(SendReviewReminderJob::class, fn (SendReviewReminderJob $job): bool => $job->learnerId === $reachable->id);
});

it('keeps the reminder one-per-day across groups when the job runs twice', function (): void {
    Config::set('twa.base_url', 'https://twa.test');
    $groupA = TelegramGroup::factory()->create(['status' => 'active']);
    $groupB = TelegramGroup::factory()->create(['status' => 'active']);
    $learner = Learner::factory()->memberOf($groupA)->memberOf($groupB)->create();
    WordRepetition::factory()->due()->create(['learner_id' => $learner->id, 'word_id' => Word::factory()->create()->id]);

    $telegram = Mockery::mock(TelegramClient::class);
    $telegram->shouldReceive('sendWebAppButton')->once();

    $run = fn () => (new SendReviewReminderJob($learner->id, 5))->handle(
        $telegram,
        app(ReviewReminderLinkBuilder::class),
        app(ProductEventRecorder::class),
        app(LocaleResolver::class),
        app(GroupMembershipService::class),
    );
    $run();
    $run();

    expect(NotificationLog::query()->where('learner_id', $learner->id)->count())->toBe(1);
});

it('does not send a reminder from the job once the learner has no active group any more', function (): void {
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $learner = Learner::factory()->memberOf($group, 'left')->create();

    $telegram = Mockery::mock(TelegramClient::class);
    $telegram->shouldNotReceive('sendWebAppButton');

    (new SendReviewReminderJob($learner->id, 5))->handle(
        $telegram,
        app(ReviewReminderLinkBuilder::class),
        app(ProductEventRecorder::class),
        app(LocaleResolver::class),
        app(GroupMembershipService::class),
    );

    expect(NotificationLog::count())->toBe(0);
});

it('opens the reminder session in a group of the learner that has a teacher', function (): void {
    Config::set('twa.base_url', 'https://twa.test');

    $withoutTeacher = TelegramGroup::factory()->create(['status' => 'active']);
    $withTeacher = TelegramGroup::factory()->create(['status' => 'active']);
    $teacher = User::factory()->create();
    DB::table('teacher_groups')->insert([
        'user_id' => $teacher->id,
        'telegram_group_id' => $withTeacher->id,
        'is_primary' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $learner = Learner::factory()->memberOf($withoutTeacher)->memberOf($withTeacher)->create();
    $lesson = Lesson::factory()->for(Stage::factory()->create(['number' => 1]))->create(['number' => 1]);
    WordRepetition::factory()->due()->create([
        'learner_id' => $learner->id,
        'word_id' => Word::factory()->for($lesson)->create()->id,
    ]);

    $url = app(ReviewReminderLinkBuilder::class)->buildTrainingUrl($learner);

    $session = TrainingSession::query()->sole();
    expect($session->telegram_group_id)->toBe($withTeacher->id)
        ->and($url)->toBe("https://twa.test/twa/training/{$session->id}");
});

it('prefers the group where the learner already studied the due lesson and marks the session as a reminder', function (): void {
    Config::set('twa.base_url', 'https://twa.test');

    $first = TelegramGroup::factory()->create(['status' => 'active']);
    $studied = TelegramGroup::factory()->create(['status' => 'active']);
    foreach ([$first, $studied] as $i => $group) {
        DB::table('teacher_groups')->insert([
            'user_id' => User::factory()->create()->id,
            'telegram_group_id' => $group->id,
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    $learner = Learner::factory()->memberOf($first)->memberOf($studied)->create();
    $lesson = Lesson::factory()->for(Stage::factory()->create(['number' => 1]))->create(['number' => 1]);
    $otherLesson = Lesson::factory()->for($lesson->stage)->create(['number' => 2]);
    $word = Word::factory()->for($lesson)->create();
    WordRepetition::factory()->due()->create(['learner_id' => $learner->id, 'word_id' => $word->id]);

    // A review of ANOTHER lesson in the first group does not count; one of the due lesson in the second does.
    TrainingReview::factory()->create([
        'learner_id' => $learner->id,
        'training_session_id' => TrainingSession::factory()->create(['telegram_group_id' => $first->id, 'lesson_id' => $otherLesson->id, 'status' => 'closed'])->id,
    ]);
    TrainingReview::factory()->create([
        'learner_id' => $learner->id,
        'training_session_id' => TrainingSession::factory()->create(['telegram_group_id' => $studied->id, 'lesson_id' => $lesson->id, 'status' => 'closed'])->id,
    ]);

    app(ReviewReminderLinkBuilder::class)->buildTrainingUrl($learner);

    $session = TrainingSession::query()->where('status', 'open')->sole();
    expect($session->telegram_group_id)->toBe($studied->id)
        ->and($session->lesson_id)->toBe($lesson->id)
        ->and($session->source)->toBe('reminder');
});

it('falls back to the TWA root when no group of the learner has a teacher', function (): void {
    Config::set('twa.base_url', 'https://twa.test');
    $learner = Learner::factory()->memberOf(TelegramGroup::factory()->create(['status' => 'active']))->create();
    WordRepetition::factory()->due()->create(['learner_id' => $learner->id, 'word_id' => Word::factory()->create()->id]);

    expect(app(ReviewReminderLinkBuilder::class)->buildTrainingUrl($learner))->toBe('https://twa.test/twa/')
        ->and(TrainingSession::count())->toBe(0);
});

it('ignores minute-based learning cards when counting due words for reminders', function (): void {
    Bus::fake();

    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $learner = Learner::factory()->memberOf($group)->create();

    // 4 graduated due cards + 3 learning cards whose step already elapsed (abandoned session).
    foreach (Word::factory()->count(4)->create() as $word) {
        WordRepetition::factory()->due()->create(['learner_id' => $learner->id, 'word_id' => $word->id]);
    }
    foreach (Word::factory()->count(3)->create() as $word) {
        WordRepetition::factory()->learning(step: 1)->create([
            'learner_id' => $learner->id,
            'word_id' => $word->id,
            'next_review_at' => now()->subMinutes(5),
        ]);
    }

    expect(app(ReviewReminderService::class)->queueDueReminders(5))->toBe(0);
    Bus::assertNothingDispatched();

    // One more graduated due card crosses the threshold; learning cards are still not counted.
    WordRepetition::factory()->due()->create(['learner_id' => $learner->id, 'word_id' => Word::factory()->create()->id]);

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
    $learner = Learner::factory()->memberOf($group)->create();

    // 3 graduated due cards + 1 relearning card lapsed and abandoned yesterday evening
    // + 1 learning card abandoned a day ago = 5 -> reaches the threshold.
    foreach (Word::factory()->count(3)->create() as $word) {
        WordRepetition::factory()->due()->create(['learner_id' => $learner->id, 'word_id' => $word->id]);
    }
    WordRepetition::factory()->relearning()->create([
        'learner_id' => $learner->id,
        'word_id' => Word::factory()->create()->id,
        'next_review_at' => now()->subHours(12)->subMinute(),
    ]);
    WordRepetition::factory()->learning(step: 1)->create([
        'learner_id' => $learner->id,
        'word_id' => Word::factory()->create()->id,
        'next_review_at' => now()->subDay(),
    ]);
    // Fresh in-session cards (step elapsed minutes / a few hours ago) must not count.
    WordRepetition::factory()->relearning()->create([
        'learner_id' => $learner->id,
        'word_id' => Word::factory()->create()->id,
        'next_review_at' => now()->subMinutes(3),
    ]);
    WordRepetition::factory()->learning()->create([
        'learner_id' => $learner->id,
        'word_id' => Word::factory()->create()->id,
        'next_review_at' => now()->subHours(11),
    ]);
    // A `new` row shown long ago but never answered is not "to review" either.
    WordRepetition::factory()->create([
        'learner_id' => $learner->id,
        'word_id' => Word::factory()->create()->id,
        'state' => RepetitionState::New->value,
        'repetitions' => 0,
        'next_review_at' => now()->subDays(2),
    ]);

    expect(app(ReviewReminderService::class)->queueDueReminders(5))->toBe(1);
    Bus::assertDispatched(SendReviewReminderJob::class, fn (SendReviewReminderJob $job): bool => $job->learnerId === $learner->id && $job->dueCount === 5);

    // Same number the learner sees in GET /me.
    expect(app(LearnerStatsService::class)->forLearner($learner)->wordsDueToday)->toBe(5);
});

it('does not remind about a relearning card abandoned less than abandoned_after_hours ago', function (): void {
    Bus::fake();

    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $learner = Learner::factory()->memberOf($group)->create();

    foreach (Word::factory()->count(4)->create() as $word) {
        WordRepetition::factory()->due()->create(['learner_id' => $learner->id, 'word_id' => $word->id]);
    }
    $abandoned = WordRepetition::factory()->relearning()->create([
        'learner_id' => $learner->id,
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
    $learner = Learner::factory()->memberOf($group)->create();
    $lesson = Lesson::factory()->for(Stage::factory()->create(['number' => 2]))->create(['number' => 5]);
    WordRepetition::factory()->relearning()->create([
        'learner_id' => $learner->id,
        'word_id' => Word::factory()->for($lesson)->create()->id,
        'next_review_at' => now()->subDay(),
    ]);

    $url = app(ReviewReminderLinkBuilder::class)->buildTrainingUrl($learner);

    $session = TrainingSession::query()->firstOrFail();
    expect($url)->toBe("https://twa.test/twa/training/{$session->id}")
        ->and($session->lesson_id)->toBe($lesson->id);
});

it('keeps legacy failed cards (backfilled to relearning) in reminders once their day has passed', function (): void {
    Bus::fake();

    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $learner = Learner::factory()->memberOf($group)->create();
    $words = Word::factory()->count(5)->create();

    /** @var Migration $migration */
    $migration = require base_path('database/migrations/2026_10_18_000002_add_learning_state_to_word_repetitions.php');
    $migration->down();

    // Legacy SM-2: failed cards had repetitions = 0, interval 1 day, next_review_at = fail time + 1 day.
    // Simulate rows whose legacy due moment is already 13 hours in the past.
    foreach ($words as $word) {
        DB::table('word_repetitions')->insert([
            'learner_id' => $learner->id,
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

    expect(WordRepetition::query()->where('learner_id', $learner->id)->pluck('state')->unique()->all())
        ->toBe([RepetitionState::Relearning]);

    expect(app(ReviewReminderService::class)->queueDueReminders(5))->toBe(1);
    Bus::assertDispatched(SendReviewReminderJob::class, fn (SendReviewReminderJob $job): bool => $job->learnerId === $learner->id && $job->dueCount === 5);
    expect(app(LearnerStatsService::class)->forLearner($learner)->wordsDueToday)->toBe(5);
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
    $learner = Learner::factory()->memberOf($group)->create([
        'telegram_user_id' => 777001,
        'language_code' => 'ru']);
    $stage = Stage::factory()->create(['number' => 3]);
    $lesson = Lesson::factory()->for($stage)->create(['number' => 4]);
    $word = Word::factory()->for($lesson)->create();
    WordRepetition::factory()->due()->create([
        'learner_id' => $learner->id,
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

    (new SendReviewReminderJob($learner->id, 5))->handle($api, app(ReviewReminderLinkBuilder::class), app(ProductEventRecorder::class), app(LocaleResolver::class), app(GroupMembershipService::class));

    expect($api->messages)->toHaveCount(1);
    $message = $api->messages[0];

    expect($message['chat_id'])->toBe(777001);
    expect($message['text'])->toContain('5 слов');
    $session = TrainingSession::query()->firstOrFail();
    expect($message['button_text'])->toBe('Открыть LexiFlow');
    expect($message['url'])->toBe("https://twa.test/twa/training/{$session->id}");

    expect(NotificationLog::query()
        ->where('learner_id', $learner->id)
        ->where('type', 'review_reminder')
        ->exists())->toBeTrue();
});

it('opens the reminder session on the due lesson itself even if the group studies another course with the same numbers', function (): void {
    Config::set('twa.base_url', 'https://twa.test');

    $groupCourse = Course::factory()->published()->create();
    $dueCourse = Course::factory()->published()->create();
    // Both courses have "stage 1 / lesson 1": the numbers alone would pick the group's lesson.
    $groupLesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $groupCourse->id, 'number' => 1]))->create(['number' => 1]);
    $dueLesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $dueCourse->id, 'number' => 1]))->create(['number' => 1]);

    $group = TelegramGroup::factory()->create(['status' => 'active', 'course_id' => $groupCourse->id]);
    DB::table('teacher_groups')->insert([
        'user_id' => User::factory()->create()->id,
        'telegram_group_id' => $group->id,
        'is_primary' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $learner = Learner::factory()->memberOf($group)->create();
    WordRepetition::factory()->due()->create([
        'learner_id' => $learner->id,
        'word_id' => Word::factory()->for($dueLesson)->create()->id,
    ]);

    $url = app(ReviewReminderLinkBuilder::class)->buildTrainingUrl($learner);

    $session = TrainingSession::query()->sole();
    expect($session->lesson_id)->toBe($dueLesson->id)
        ->and($session->lesson_id)->not->toBe($groupLesson->id)
        ->and($session->source)->toBe('reminder')
        ->and($url)->toBe("https://twa.test/twa/training/{$session->id}");
});
