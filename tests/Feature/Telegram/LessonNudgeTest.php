<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Learners\Services\LessonNudgeScheduler;
use App\Domain\Learners\Services\LessonNudgeSender;
use App\Domain\Learners\Services\StudyMessageLimit;
use App\Domain\Learning\Events\LessonWithNewWordsFinished;
use App\Domain\Learning\Services\TrainingSessionService;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Enums\SessionStatus;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramRateLimitedException;
use App\Domain\Telegram\Services\BotButtons;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Domain\Telegram\Support\TwaScreen;
use App\Domain\Twa\Services\JwtService;
use App\Jobs\Middleware\ThrottleTelegramOutbound;
use App\Jobs\SendLessonNudgeJob;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\NotificationLog;
use App\Models\PracticeSession;
use App\Models\ProductEvent;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CardAnswers;
use Tests\Support\FakeTelegramClient;
use Tests\Support\HotPathFixtures as Fx;

/*
 * The bot's reminder after a lesson (docs/31 §5б, G1-G3): queued once a local day at the `/finish` of a lesson with new words, sent
 * later only while the game on its words is still not played, the learner is reachable, the reminders are on, it is not night and
 * no other message about studies went out that day; the marker under docs/34 §4в п. 8.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // 15:00 in Tashkent; the local day is [2026-11-24 19:00, 2026-11-25 19:00) UTC.
    $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00:00', 'UTC'));
    config([
        'twa.base_url' => 'https://twa.test',
        'learning.reminders.lesson_nudge' => ['enabled' => true, 'delay_minutes' => 45, 'queue' => 'default', 'quiet_from_hour' => 22, 'quiet_to_hour' => 8],
        'learning.lesson_review' => ['enabled' => true, 'max_words' => 20, 'max_serves_per_word' => 3],
        'billing.plans.free.limits.new_words_per_day' => 20,
        'practice.access' => 'all', 'practice.plan' => 'all', 'practice.modes.pairs_timed' => true, 'game.access' => 'off',
    ]);

    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

/** @return array{learner: Learner, course: Course, lessons: array<string, Lesson>, headers: array<string, string>} */
function lnWorld(string $locale = 'ru', array $learner = []): array
{
    ['course' => $course, 'lessons' => $lessons] = Fx::course([1 => [1 => 5, 2 => 6]]);
    $lessons['1.1']->update(['title' => 'Напитки', 'title_uz' => 'Ichimliklar']);
    $owner = Learner::factory()->onboarded()->create([
        'timezone' => 'Asia/Tashkent', 'active_course_id' => $course->id, 'daily_goal' => 20, 'interface_locale' => $locale,
        'reminder_time' => '19:00', ...$learner,
    ]);

    return ['learner' => $owner, 'course' => $course, 'lessons' => $lessons, 'headers' => ['Authorization' => 'Bearer '.app(JwtService::class)->issue($owner->id)['token']]];
}

/** Plays a `lesson` session to its end and calls `/finish`. */
function lnPlay(object $test, array $headers, int $lessonId): void
{
    $opened = $test->withHeaders($headers)->postJson('/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $lessonId])->assertOk()->json();
    $card = $opened['card'];
    for ($guard = 0; $card !== null && $guard < 100; $guard++) {
        $card = $test->withHeaders($headers)->postJson("/api/twa/training/sessions/{$opened['session_id']}/review", CardAnswers::body($card))->assertOk()->json('card');
    }
    $test->withHeaders($headers)->postJson("/api/twa/training/sessions/{$opened['session_id']}/finish")->assertOk();
}

/** The world with lesson 1.1 played today (the job of the reminder is held by a fake queue). */
function lnPlayed(object $test, string $locale = 'ru', array $learner = []): array
{
    $world = lnWorld($locale, $learner);
    Queue::fake([SendLessonNudgeJob::class]);
    lnPlay($test, $world['headers'], $world['lessons']['1.1']->id);

    return $world;
}

function lnSend(Learner $learner, ?string $date = null, ?CarbonImmutable $now = null): bool
{
    return app(LessonNudgeSender::class)->send($learner->id, $date ?? '2026-11-25', $now);
}

function lnLogs(Learner $learner): int
{
    return NotificationLog::query()->where('learner_id', $learner->id)->where('type', LessonNudgeSender::NOTIFICATION_TYPE)->count();
}

it('queues one delayed job at the finish of a lesson with new words, and sends the reminder with the game of the lesson', function (): void {
    ['learner' => $learner, 'course' => $course, 'lessons' => $lessons] = lnPlayed($this);
    $lessonId = $lessons['1.1']->id;

    Queue::assertPushed(SendLessonNudgeJob::class, 1);
    Queue::assertPushed(SendLessonNudgeJob::class, fn (SendLessonNudgeJob $job): bool => $job->learnerId === $learner->id
        && $job->localDate === '2026-11-25'
        && $job->queue === 'default'
        && $job->delay instanceof DateTimeInterface
        && CarbonImmutable::instance($job->delay)->equalTo(CarbonImmutable::parse('2026-11-25 10:45:00', 'UTC')));
    expect(Cache::has(LessonNudgeScheduler::markerKey($learner->id, '2026-11-25')))->toBeTrue();

    // 45 minutes later the job runs.
    $this->travel(45)->minutes();
    $job = new SendLessonNudgeJob($learner->id, '2026-11-25');
    $job->handle(app(LessonNudgeSender::class));

    expect($this->tg->sent)->toHaveCount(1);
    $message = $this->tg->sent[0];
    expect($message['chat_id'])->toBe($learner->telegram_user_id)
        ->and($message['text'])->toContain('Урок «Напитки» пройден: 5 новых слов')
        ->and($message['text'])->toContain('Закрепите их в игре «Пары на время»')
        ->and(mb_strtolower($message['text']))->not->toContain('очк')
        ->and(mb_strtolower($message['text']))->not->toContain('балл')
        ->and($message['markup'])->toBe(['inline_keyboard' => [[[
            'text' => '🎮 Закрепить в игре',
            'web_app' => ['url' => "https://twa.test/twa/practice/{$course->slug}?lesson={$lessonId}&play=pairs_timed&from=lesson_nudge"],
        ]]]]);

    expect(lnLogs($learner))->toBe(1)
        ->and(ProductEvent::query()->where('name', ProductEventName::LessonNudgeSent->value)->sole()->props)->toEqual(['lesson_id' => $lessonId, 'words' => 5]);

    // A repeated delivery of the job stays quiet.
    expect(lnSend($learner))->toBeFalse()->and($this->tg->sent)->toHaveCount(1);
});

it('opens the game in the course of the lesson, not in the active course', function (): void {
    ['learner' => $learner, 'course' => $active, 'headers' => $h] = lnWorld();
    ['course' => $other, 'lessons' => $otherLessons] = Fx::course([1 => [1 => 4]]);
    Queue::fake([SendLessonNudgeJob::class]);
    lnPlay($this, $h, $otherLessons['1.1']->id);

    expect(lnSend($learner))->toBeTrue()
        ->and($other->slug)->not->toBe($active->slug)
        ->and($this->tg->sent[0]['markup']['inline_keyboard'][0][0]['web_app']['url'])
        ->toBe("https://twa.test/twa/practice/{$other->slug}?lesson={$otherLessons['1.1']->id}&play=pairs_timed&from=lesson_nudge");
});

it('writes the Uzbek text with the Uzbek title of the lesson', function (): void {
    ['learner' => $learner] = lnPlayed($this, 'uz');

    expect(lnSend($learner))->toBeTrue()
        ->and($this->tg->sent[0]['text'])->toStartWith("«Ichimliklar» darsi tugadi: 5 ta yangi so'z. Ularni «Vaqtga juftlar» o'yinida mustahkamlang")
        ->and($this->tg->sent[0]['markup']['inline_keyboard'][0][0]['text'])->toBe("🎮 O'yinda mustahkamlash");
});

it('names the word in the singular for one new word', function (): void {
    ['learner' => $learner] = lnPlayed($this);
    config(['learning.lesson_review.max_words' => 1]);

    expect(lnSend($learner))->toBeTrue()
        ->and($this->tg->sent[0]['text'])->toContain('пройден: 1 новое слово. Закрепите его');
});

it('queues the job once for two finishes in a row on the same local day', function (): void {
    ['lessons' => $lessons, 'headers' => $h] = lnPlayed($this);
    lnPlay($this, $h, $lessons['1.2']->id);

    Queue::assertPushed(SendLessonNudgeJob::class, 1);
});

it('raises no event for a lesson session without new words', function (): void {
    ['learner' => $learner, 'lessons' => $lessons] = lnWorld();
    Event::fake([LessonWithNewWordsFinished::class]);
    $session = TrainingSession::factory()->create(['learner_id' => $learner->id, 'mode' => TrainingSession::MODE_LESSON, 'lesson_id' => $lessons['1.1']->id, 'status' => 'open']);

    app(TrainingSessionService::class)->finish($session, learner: $learner);

    Event::assertNotDispatched(LessonWithNewWordsFinished::class);
    expect(lnSend($learner))->toBeFalse()->and($this->tg->sent)->toBe([]);
});

it('queues nothing while switched off or for a learner it would not reach', function (array $attributes, bool $enabled): void {
    ['learner' => $learner] = lnWorld(learner: $attributes);
    config(['learning.reminders.lesson_nudge.enabled' => $enabled]);
    Queue::fake([SendLessonNudgeJob::class]);

    expect(app(LessonNudgeScheduler::class)->schedule($learner))->toBeFalse();
    Queue::assertNothingPushed();
    expect(Cache::has(LessonNudgeScheduler::markerKey($learner->id, '2026-11-25')))->toBeFalse();
})->with([
    'switched off' => [[], false],
    'reminders off' => [['reminder_time' => null], true],
    'DM closed' => [['dm_blocked_at' => '2026-11-20 10:00:00', 'dm_blocked_reason' => 'bot_blocked'], true],
    'banned' => [['blocked_at' => '2026-11-20 10:00:00'], true],
]);

it('keeps one marker per local day of the learner', function (): void {
    ['learner' => $learner] = lnWorld();
    Queue::fake([SendLessonNudgeJob::class]);
    $scheduler = app(LessonNudgeScheduler::class);

    // 23:30 and 23:50 in Tashkent: one day; 00:10 of the next local day (19:10 UTC): another.
    expect($scheduler->schedule($learner, CarbonImmutable::parse('2026-11-25 18:30:00', 'UTC')))->toBeTrue()
        ->and($scheduler->schedule($learner, CarbonImmutable::parse('2026-11-25 18:50:00', 'UTC')))->toBeFalse()
        ->and($scheduler->schedule($learner, CarbonImmutable::parse('2026-11-25 19:10:00', 'UTC')))->toBeTrue();

    Queue::assertPushed(SendLessonNudgeJob::class, 2);
    Queue::assertPushed(SendLessonNudgeJob::class, fn (SendLessonNudgeJob $job): bool => $job->localDate === '2026-11-25');
    Queue::assertPushed(SendLessonNudgeJob::class, fn (SendLessonNudgeJob $job): bool => $job->localDate === '2026-11-26');
});

it('drops a job of another local day', function (): void {
    ['learner' => $learner] = lnPlayed($this);

    expect(lnSend($learner, '2026-11-24'))->toBeFalse()
        // The lesson of 15:00 belongs to the local day 2026-11-25; at 00:10 of the next day there is no lesson of today.
        ->and(lnSend($learner, '2026-11-26', CarbonImmutable::parse('2026-11-25 19:10:00', 'UTC')))->toBeFalse()
        ->and($this->tg->sent)->toBe([]);
});

it('sends nothing once a game on the words of the lesson was played', function (): void {
    ['learner' => $learner, 'course' => $course, 'lessons' => $lessons] = lnPlayed($this);
    PracticeSession::factory()->ofMode(PracticeMode::PairsTimed)->create([
        'learner_id' => $learner->id, 'course_id' => $course->id, 'lesson_id' => $lessons['1.1']->id, 'source' => PracticeSession::SOURCE_DEEPLINK,
        'status' => SessionStatus::Finished, 'active_learner_id' => null, 'answered' => 3, 'started_at' => now(), 'finished_at' => now(),
    ]);

    expect(lnSend($learner))->toBeFalse()->and($this->tg->sent)->toBe([])->and(lnLogs($learner))->toBe(0);
});

it('sends nothing without a lesson played today', function (): void {
    ['learner' => $learner] = lnWorld();

    expect(lnSend($learner))->toBeFalse()->and($this->tg->sent)->toBe([]);
});

it('sends nothing while the practice is closed to the learner', function (): void {
    ['learner' => $learner] = lnPlayed($this);
    config(['practice.access' => 'off']);

    expect(lnSend($learner))->toBeFalse()->and($this->tg->sent)->toBe([]);
});

it('drops the job at night of the learner, without moving it to the morning', function (string $utc): void {
    ['learner' => $learner] = lnPlayed($this);

    expect(lnSend($learner, now: CarbonImmutable::parse($utc, 'UTC')))->toBeFalse()
        ->and($this->tg->sent)->toBe([])
        ->and(lnLogs($learner))->toBe(0);

    // Not queued again: the job is gone, the marker of the day stays.
    expect(app(LessonNudgeScheduler::class)->schedule($learner->fresh()))->toBeFalse();
})->with([
    '22:00 in Tashkent' => ['2026-11-25 17:00:00'],
    '23:59 in Tashkent' => ['2026-11-25 18:59:00'],
]);

it('sends at 21:59 of the learner', function (): void {
    ['learner' => $learner] = lnPlayed($this);

    expect(lnSend($learner, now: CarbonImmutable::parse('2026-11-25 16:59:00', 'UTC')))->toBeTrue();
});

it('sends nothing to a learner who switched the reminders off, closed the DM or was banned', function (array $attributes): void {
    ['learner' => $learner] = lnPlayed($this);
    $learner->forceFill($attributes)->save();

    expect(lnSend($learner))->toBeFalse()->and($this->tg->sent)->toBe([]);
})->with([
    'reminders off' => [['reminder_time' => null]],
    'DM closed' => [['dm_blocked_at' => '2026-11-25 09:00:00', 'dm_blocked_reason' => 'bot_blocked']],
    'a reason without a date' => [['dm_blocked_reason' => 'user_deactivated']],
    'banned' => [['blocked_at' => '2026-11-25 09:00:00']],
]);

it('stays inside the common limit of one message about studies a day, both ways', function (): void {
    ['learner' => $learner] = lnPlayed($this);
    NotificationLog::query()->create(['learner_id' => $learner->id, 'type' => 'review_reminder', 'payload' => [], 'sent_at' => CarbonImmutable::parse('2026-11-25 04:00:00', 'UTC')]);

    expect(lnSend($learner))->toBeFalse()->and($this->tg->sent)->toBe([]);

    // The other way: after the reminder after a lesson the personal reminder of the day finds the limit reached.
    NotificationLog::query()->delete();
    expect(lnSend($learner))->toBeTrue()
        ->and(app(StudyMessageLimit::class)->reached($learner, CarbonImmutable::now()))->toBeTrue();
});

it('marks the DM unreachable on a 403 and drops the marker', function (): void {
    ['learner' => $learner] = lnPlayed($this);
    $this->tg->failSends = 1;
    $this->tg->failSendsForbidden = 'bot_blocked';

    expect(lnSend($learner))->toBeFalse()
        ->and(lnLogs($learner))->toBe(0)
        ->and($learner->fresh()?->dm_blocked_at)->not->toBeNull()
        ->and($learner->fresh()?->dm_blocked_reason)->toBe('bot_blocked')
        ->and(ProductEvent::query()->where('name', ProductEventName::LessonNudgeSent->value)->count())->toBe(0);
});

it('puts the job back on a 429 without the marker, and the retry delivers exactly one', function (): void {
    ['learner' => $learner] = lnPlayed($this);
    $this->tg->failSends = 1;
    $this->tg->failSendsRetryAfter = 12;

    expect(fn () => lnSend($learner))->toThrow(TelegramRateLimitedException::class)
        ->and(lnLogs($learner))->toBe(0);

    // The job as the worker runs it: the middleware turns the 429 into a release.
    $this->tg->failSends = 1;
    $queueJob = Mockery::mock(QueueJobContract::class);
    $queueJob->shouldReceive('release')->once()->with(Mockery::on(fn (int $delay): bool => $delay >= 12 && $delay <= 15));
    $job = new SendLessonNudgeJob($learner->id, '2026-11-25');
    $job->job = $queueJob;
    $handled = (new ThrottleTelegramOutbound(new TelegramMessageRateLimiter(globalPerSecond: 0)))
        ->handle($job, fn (SendLessonNudgeJob $j) => $j->handle(app(LessonNudgeSender::class)));

    expect($handled)->toBeNull()->and(lnLogs($learner))->toBe(0)->and($this->tg->sent)->toBe([]);

    expect(lnSend($learner))->toBeTrue()->and($this->tg->sent)->toHaveCount(1)->and(lnLogs($learner))->toBe(1);
});

it('removes the marker on another definite refusal and rethrows it', function (): void {
    ['learner' => $learner] = lnPlayed($this);
    $this->tg->failSends = 1;

    expect(fn () => lnSend($learner))->toThrow(TelegramApiException::class)
        ->and(lnLogs($learner))->toBe(0)
        ->and($learner->fresh()?->dm_blocked_at)->toBeNull();

    expect(lnSend($learner))->toBeTrue()->and($this->tg->sent)->toHaveCount(1);
});

it('keeps the marker on a timeout: the retry stays quiet', function (): void {
    ['learner' => $learner] = lnPlayed($this);
    $this->tg->failSends = 1;
    $this->tg->failSendsAsTransport = true;

    expect(fn () => lnSend($learner))->toThrow(TelegramApiException::class)
        ->and(lnLogs($learner))->toBe(1);

    expect(lnSend($learner))->toBeFalse()->and($this->tg->sent)->toBe([]);
});

it('builds the address of the practice hub of a lesson', function (): void {
    expect(app(BotButtons::class)->twaUrl(
        TwaScreen::PracticeHub,
        ['slug' => 'english-a1'],
        ['lesson' => 12, 'play' => 'pairs_timed'],
    ))->toBe('https://twa.test/twa/practice/english-a1?lesson=12&play=pairs_timed');
});
