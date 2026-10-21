<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Learners\Enums\ReminderOutcome;
use App\Domain\Learners\Services\ReminderDispatcher;
use App\Domain\Learners\Services\ReminderSender;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Services\LearnerStatsService;
use App\Domain\Learning\Support\LearningPolicy;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Jobs\SendLearnerReminderJob;
use App\Models\Learner;
use App\Models\NotificationLog;
use App\Models\ProductEvent;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Tests\Support\FakeTelegramClient;

/*
 * Personal reminders, the sending half (docs/12 §7.5, docs/06 §6): what the job says,
 * when it stays silent, how it stays idempotent and how a Telegram 403 is handled.
 * The claim of `next_reminder_at` is covered in ReminderDispatcherTest.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['twa.base_url' => 'https://twa.test']);
    // 2026-10-18 14:05 UTC = 19:05 in Tashkent (the factory default zone), Sunday.
    $this->travelTo(CarbonImmutable::parse('2026-10-18 14:05:00', 'UTC'));

    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

/** @param array<string, mixed> $attributes */
function rmLearner(array $attributes = []): Learner
{
    return Learner::factory()->create([
        'telegram_user_id' => 777001,
        'interface_locale' => 'ru',
        'reminder_time' => '19:00',
        'next_reminder_at' => CarbonImmutable::parse('2026-10-19 14:00:00', 'UTC'), // already moved by the dispatcher
        ...$attributes,
    ]);
}

function rmDue(Learner $learner, int $count): void
{
    foreach (Word::factory()->count($count)->create() as $word) {
        WordRepetition::factory()->due()->create(['learner_id' => $learner->id, 'word_id' => $word->id]);
    }
}

function rmSend(Learner $learner, ?CarbonImmutable $scheduledFor = null): ReminderOutcome
{
    return app(ReminderSender::class)->send($learner->id, $scheduledFor ?? CarbonImmutable::parse('2026-10-18 14:00:00', 'UTC'));
}

/** Yesterday's goal was met, today's not yet: the streak is at risk. */
function rmStreakAtRisk(Learner $learner, int $days): void
{
    $learner->forceFill([
        'streak_current' => $days,
        'streak_best' => $days,
        'streak_last_date' => '2026-10-17',
    ])->save();
}

// --- what the reminder says -----------------------------------------------------------

it('sends "N words to review" with the TWA button to the Today screen and logs it', function (): void {
    $learner = rmLearner();
    rmDue($learner, 5);

    expect(rmSend($learner))->toBe(ReminderOutcome::Sent)
        ->and($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['chat_id'])->toBe(777001)
        ->and($this->tg->sent[0]['text'])->toBe('Пора повторить 5 слов в LexiFlow.')
        ->and($this->tg->sent[0]['button'])->toBe('Открыть LexiFlow')
        ->and($this->tg->sent[0]['url'])->toBe('https://twa.test/twa/?from=reminder');

    $log = NotificationLog::query()->where('learner_id', $learner->id)->sole();
    expect($log->type)->toBe('review_reminder')
        ->and($log->payload['kind'])->toBe('review')
        ->and($log->payload['due_count'])->toBe(5)
        ->and($log->payload['scheduled_for'])->toBe('2026-10-18T14:00:00+00:00')
        ->and($log->sent_at?->utc()->toDateTimeString())->toBe('2026-10-18 14:05:00');

    $event = ProductEvent::query()->where('name', ProductEventName::ReminderSent->value)->sole();
    expect($event->learner_id)->toBe($learner->id)
        ->and(ksorted($event->props))->toBe(ksorted(['kind' => 'review', 'due_count' => 5]));
});

it('reminds about a single due word — the threshold is 1 (docs/12 §7.5)', function (): void {
    expect(config('learning.reminders.due_threshold'))->toBe(1);
    $learner = rmLearner();
    rmDue($learner, 1);

    expect(rmSend($learner))->toBe(ReminderOutcome::Sent)
        ->and($this->tg->sent[0]['text'])->toBe('Пора повторить 1 слово в LexiFlow.');
});

it('warns about the streak when nothing is due but yesterday\'s streak has not been extended today', function (): void {
    $learner = rmLearner();
    rmStreakAtRisk($learner, 3);

    expect(rmSend($learner))->toBe(ReminderOutcome::Sent)
        ->and($this->tg->sent[0]['text'])->toBe('Ваша серия — 3 дня. Выполните цель дня, чтобы не прервать её.')
        ->and($this->tg->sent[0]['url'])->toBe('https://twa.test/twa/?from=reminder');

    $log = NotificationLog::query()->where('learner_id', $learner->id)->sole();
    expect($log->type)->toBe('streak_reminder')
        ->and($log->payload['kind'])->toBe('streak_at_risk')
        ->and($log->payload['due_count'])->toBe(0)
        ->and($log->payload['streak'])->toBe(3);

    $event = ProductEvent::query()->where('name', ProductEventName::ReminderSent->value)->sole();
    expect(ksorted($event->props))->toBe(ksorted(['kind' => 'streak_at_risk', 'due_count' => 0]));
});

it('prefers the review text over the streak warning when both apply', function (): void {
    $learner = rmLearner();
    rmDue($learner, 2);
    rmStreakAtRisk($learner, 10);

    rmSend($learner);

    expect($this->tg->sent[0]['text'])->toBe('Пора повторить 2 слова в LexiFlow.')
        ->and(NotificationLog::query()->where('learner_id', $learner->id)->value('type'))->toBe('review_reminder');
});

it('stays silent when nothing is due and the streak is safe or absent', function (): void {
    $noStreak = rmLearner();
    $metToday = rmLearner(['telegram_user_id' => 777002, 'streak_current' => 4, 'streak_best' => 4, 'streak_last_date' => '2026-10-18']);
    $broken = rmLearner(['telegram_user_id' => 777003, 'streak_current' => 4, 'streak_best' => 4, 'streak_last_date' => '2026-10-15']);

    expect(rmSend($noStreak))->toBe(ReminderOutcome::SkippedNothingToSay)
        ->and(rmSend($metToday))->toBe(ReminderOutcome::SkippedNothingToSay)
        ->and(rmSend($broken))->toBe(ReminderOutcome::SkippedNothingToSay)
        ->and($this->tg->sent)->toBe([])
        ->and(NotificationLog::count())->toBe(0)
        ->and(ProductEvent::count())->toBe(0);
});

it('speaks the learner\'s interface language', function (): void {
    $learner = rmLearner(['interface_locale' => 'uz']);
    rmDue($learner, 21);

    rmSend($learner);
    expect($this->tg->sent[0]['text'])->toBe("LexiFlow'da 21 ta so'zni takrorlash vaqti keldi.")
        ->and($this->tg->sent[0]['button'])->toBe("LexiFlow'ni ochish");

    $streak = rmLearner(['telegram_user_id' => 777004, 'interface_locale' => 'uz']);
    rmStreakAtRisk($streak, 7);
    rmSend($streak);
    expect($this->tg->sent[1]['text'])->toBe('Seriyangiz — 7 kun. Uni uzmaslik uchun bugungi maqsadni bajaring.');
});

it('reminds a learner who never finished the onboarding — the DM decides, not the state', function (): void {
    $learner = rmLearner(['onboarding_state' => 'new']);
    rmDue($learner, 1);

    expect(rmSend($learner))->toBe(ReminderOutcome::Sent);
});

// --- idempotency ------------------------------------------------------------------

it('sends one message however often the job is retried or delivered', function (): void {
    $learner = rmLearner();
    rmDue($learner, 3);

    expect(rmSend($learner))->toBe(ReminderOutcome::Sent)
        ->and(rmSend($learner))->toBe(ReminderOutcome::SkippedRecent)
        ->and(rmSend($learner, CarbonImmutable::parse('2026-10-18 14:03:00', 'UTC')))->toBe(ReminderOutcome::SkippedRecent)
        ->and($this->tg->sent)->toHaveCount(1)
        ->and(NotificationLog::query()->where('learner_id', $learner->id)->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', ProductEventName::ReminderSent->value)->count())->toBe(1);
});

it('counts a streak warning and a review reminder against the same once-per-local-day budget', function (): void {
    $learner = rmLearner();
    rmStreakAtRisk($learner, 2);
    expect(rmSend($learner))->toBe(ReminderOutcome::Sent);

    // 21:30 the same local day (Tashkent): well past the 2 h floor, but it is the same day.
    $this->travelTo(CarbonImmutable::parse('2026-10-18 16:30:00', 'UTC'));
    rmDue($learner, 4);
    expect(rmSend($learner, CarbonImmutable::parse('2026-10-18 16:25:00', 'UTC')))->toBe(ReminderOutcome::SkippedRecent);

    // Next local day's reminder goes out.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 14:05:00', 'UTC'));
    expect(rmSend($learner, CarbonImmutable::parse('2026-10-19 14:00:00', 'UTC')))->toBe(ReminderOutcome::Sent)
        ->and($this->tg->sent)->toHaveCount(2);
});

it('lets a learner who moved the reminder from 19:00 to 08:00 hear from us the next morning (not a 20 h window)', function (): void {
    $learner = rmLearner();
    rmDue($learner, 3);
    expect(rmSend($learner))->toBe(ReminderOutcome::Sent);   // 19:05 Tashkent

    $learner->forceFill(['reminder_time' => '08:00'])->save();

    // 08:00 Tashkent the next day = 13 h later.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 03:00:30', 'UTC'));
    expect(rmSend($learner, CarbonImmutable::parse('2026-10-19 03:00:00', 'UTC')))->toBe(ReminderOutcome::Sent)
        ->and($this->tg->sent)->toHaveCount(2);
});

it('keeps a floor of min_interval_hours between any two reminders, even across local midnight', function (): void {
    expect(config('learning.reminders.min_interval_hours'))->toBe(2);
    $learner = rmLearner(['reminder_time' => '23:50']);
    rmDue($learner, 3);

    // 23:50 Tashkent.
    $this->travelTo(CarbonImmutable::parse('2026-10-18 18:50:00', 'UTC'));
    expect(rmSend($learner, CarbonImmutable::parse('2026-10-18 18:50:00', 'UTC')))->toBe(ReminderOutcome::Sent);

    // 00:10 on the next local day: a new day, but only 20 minutes later.
    $this->travelTo(CarbonImmutable::parse('2026-10-18 19:10:00', 'UTC'));
    expect(rmSend($learner, CarbonImmutable::parse('2026-10-18 19:10:00', 'UTC')))->toBe(ReminderOutcome::SkippedRecent);

    // 02:00: two hours passed.
    $this->travelTo(CarbonImmutable::parse('2026-10-18 20:55:00', 'UTC'));
    expect(rmSend($learner, CarbonImmutable::parse('2026-10-18 20:55:00', 'UTC')))->toBe(ReminderOutcome::Sent);
});

it('reads the local day in the learner\'s zone: 23:30 UTC is already tomorrow in Tashkent', function (): void {
    $learner = rmLearner();
    rmDue($learner, 3);

    // 2026-10-18 21:30 UTC = 02:30 on the 19th in Tashkent.
    $this->travelTo(CarbonImmutable::parse('2026-10-18 21:30:00', 'UTC'));
    expect(rmSend($learner, CarbonImmutable::parse('2026-10-18 21:30:00', 'UTC')))->toBe(ReminderOutcome::Sent);

    // 05:40 Tashkent the same (new) day: 8 h later, same local day -> skipped.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 00:40:00', 'UTC'));
    expect(rmSend($learner, CarbonImmutable::parse('2026-10-19 00:40:00', 'UTC')))->toBe(ReminderOutcome::SkippedRecent);
});

it('drops a claim the queue delivered too late', function (): void {
    config(['learning.reminders.max_delay_minutes' => 120]);
    $learner = rmLearner();
    rmDue($learner, 3);

    expect(rmSend($learner, CarbonImmutable::parse('2026-10-18 12:04:00', 'UTC')))->toBe(ReminderOutcome::SkippedStale)
        ->and(rmSend($learner, CarbonImmutable::parse('2026-10-18 12:05:00', 'UTC')))->toBe(ReminderOutcome::Sent);
});

// --- unreachable learners -------------------------------------------------------------

it('marks the DM blocked on a Telegram 403, records reminder.failed and does not retry', function (): void {
    Log::spy();
    $learner = rmLearner();
    rmDue($learner, 7);

    $telegram = Mockery::mock(TelegramClient::class);
    $telegram->shouldReceive('sendWebAppButton')
        ->once()
        ->andThrow(new TelegramForbiddenException(TelegramForbiddenException::REASON_BOT_BLOCKED));
    $this->app->instance(TelegramClient::class, $telegram);

    expect(rmSend($learner))->toBe(ReminderOutcome::Forbidden)
        ->and($learner->fresh()?->dm_blocked_at?->utc()->toDateTimeString())->toBe('2026-10-18 14:05:00')
        ->and(NotificationLog::count())->toBe(0);

    $event = ProductEvent::query()->where('name', ProductEventName::ReminderFailed->value)->sole();
    expect($event->learner_id)->toBe($learner->id)
        ->and(ksorted($event->props))->toBe(ksorted(['reason' => 'bot_blocked', 'kind' => 'review']))
        ->and(ProductEvent::query()->where('name', ProductEventName::ReminderSent->value)->exists())->toBeFalse();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'telegram.reminder_forbidden'
        && $context === ['learner_id' => $learner->id, 'reason' => 'bot_blocked'])->once();

    // From now on the dispatcher leaves the learner alone, and a retried job does too.
    Bus::fake();
    Learner::query()->whereKey($learner->id)->update(['next_reminder_at' => '2026-10-18 14:00:00']);
    expect(app(ReminderDispatcher::class)->dispatchDue(CarbonImmutable::parse('2026-10-18 14:05:00', 'UTC'))->claimed)->toBe(0);
    Bus::assertNothingDispatched();
    expect(rmSend($learner))->toBe(ReminderOutcome::SkippedUnreachable);
});

it('skips a learner who is banned, deleted, DM-blocked or switched reminders off since the claim', function (): void {
    $banned = rmLearner(['blocked_at' => now()]);
    $dmBlocked = rmLearner(['telegram_user_id' => 777002, 'dm_blocked_at' => now()]);
    $off = rmLearner(['telegram_user_id' => 777003, 'reminder_time' => null, 'next_reminder_at' => null]);
    $deleted = rmLearner(['telegram_user_id' => 777004]);
    foreach ([$banned, $dmBlocked, $off, $deleted] as $learner) {
        rmDue($learner, 3);
    }
    $deleted->delete();

    expect(rmSend($banned))->toBe(ReminderOutcome::SkippedUnreachable)
        ->and(rmSend($dmBlocked))->toBe(ReminderOutcome::SkippedUnreachable)
        ->and(rmSend($off))->toBe(ReminderOutcome::SkippedUnreachable)
        ->and(rmSend($deleted))->toBe(ReminderOutcome::SkippedUnreachable)
        ->and(app(ReminderSender::class)->send(999_999, CarbonImmutable::now()))->toBe(ReminderOutcome::SkippedUnreachable)
        ->and($this->tg->sent)->toBe([])
        ->and(NotificationLog::count())->toBe(0);
});

it('logs the send before calling Telegram: an ambiguous failure propagates, and the retried job does not send a second time', function (): void {
    $learner = rmLearner();
    rmDue($learner, 3);
    $this->tg->failSends = 1;

    expect(fn () => rmSend($learner))->toThrow(TelegramApiException::class)
        // The row stays: Telegram may have delivered the message before the timeout.
        ->and(NotificationLog::query()->where('learner_id', $learner->id)->count())->toBe(1)
        ->and(ProductEvent::count())->toBe(0)
        ->and($learner->fresh()?->dm_blocked_at)->toBeNull();

    // The queue's retry sees the row and stays quiet: at most once.
    expect(rmSend($learner))->toBe(ReminderOutcome::SkippedRecent)
        ->and($this->tg->sent)->toBe([])
        ->and(NotificationLog::count())->toBe(1)
        ->and(ProductEvent::count())->toBe(0);
});

it('writes the notifications_log row before the Telegram call, under the same lock', function (): void {
    $learner = rmLearner();
    rmDue($learner, 3);

    $seen = null;
    $telegram = Mockery::mock(TelegramClient::class);
    $telegram->shouldReceive('sendWebAppButton')->once()->andReturnUsing(function () use ($learner, &$seen): void {
        $seen = NotificationLog::query()->where('learner_id', $learner->id)->count();
    });
    $this->app->instance(TelegramClient::class, $telegram);

    expect(rmSend($learner))->toBe(ReminderOutcome::Sent)
        ->and($seen)->toBe(1);
});

it('removes the log row on a 403, so a "not sent" reminder does not count against the day', function (): void {
    $learner = rmLearner();
    rmDue($learner, 3);

    $telegram = Mockery::mock(TelegramClient::class);
    $telegram->shouldReceive('sendWebAppButton')->once()
        ->andThrow(new TelegramForbiddenException(TelegramForbiddenException::REASON_BOT_BLOCKED));
    $this->app->instance(TelegramClient::class, $telegram);

    expect(rmSend($learner))->toBe(ReminderOutcome::Forbidden)
        ->and(NotificationLog::count())->toBe(0)
        ->and($learner->fresh()?->dm_blocked_at)->not->toBeNull();
});

it('reports a vanished dialog (400 chat not found) as reminder.failed with reason chat_not_found and blocks the DM', function (): void {
    $learner = rmLearner();
    rmDue($learner, 3);

    $telegram = Mockery::mock(TelegramClient::class);
    $telegram->shouldReceive('sendWebAppButton')->once()
        ->andThrow(new TelegramForbiddenException(TelegramForbiddenException::REASON_CHAT_NOT_FOUND));
    $this->app->instance(TelegramClient::class, $telegram);

    expect(rmSend($learner))->toBe(ReminderOutcome::Forbidden)
        ->and($learner->fresh()?->dm_blocked_at)->not->toBeNull();

    $event = ProductEvent::query()->where('name', ProductEventName::ReminderFailed->value)->sole();
    expect($event->props['reason'])->toBe('chat_not_found');
});

it('reads max_delay_minutes below 15 as 15', function (): void {
    config(['learning.reminders.max_delay_minutes' => 0]);
    $learner = rmLearner();
    rmDue($learner, 3);

    // now = 14:05: a claim 16 minutes old is stale, one 14 minutes old is not.
    expect(rmSend($learner, CarbonImmutable::parse('2026-10-18 13:49:00', 'UTC')))->toBe(ReminderOutcome::SkippedStale)
        ->and(rmSend($learner, CarbonImmutable::parse('2026-10-18 13:51:00', 'UTC')))->toBe(ReminderOutcome::Sent);
});

it('reads min_interval_hours below 1 as 1', function (): void {
    config(['learning.reminders.min_interval_hours' => 0]);
    $learner = rmLearner(['reminder_time' => '23:50']);
    rmDue($learner, 3);

    $this->travelTo(CarbonImmutable::parse('2026-10-18 18:50:00', 'UTC'));
    expect(rmSend($learner, CarbonImmutable::parse('2026-10-18 18:50:00', 'UTC')))->toBe(ReminderOutcome::Sent);

    // A new local day, 20 minutes later: still inside the (clamped) 1 h floor.
    $this->travelTo(CarbonImmutable::parse('2026-10-18 19:10:00', 'UTC'));
    expect(rmSend($learner, CarbonImmutable::parse('2026-10-18 19:10:00', 'UTC')))->toBe(ReminderOutcome::SkippedRecent);
});

// --- end to end through the queue ---------------------------------------------------

it('goes from the dispatcher through the job to a Telegram message', function (): void {
    $learner = rmLearner(['next_reminder_at' => CarbonImmutable::parse('2026-10-18 14:00:00', 'UTC')]);
    rmDue($learner, 2);

    // QUEUE_CONNECTION=sync in tests: the job runs inline.
    $report = app(ReminderDispatcher::class)->dispatchDue(CarbonImmutable::now());

    expect($report->queued)->toBe(1)
        ->and($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toBe('Пора повторить 2 слова в LexiFlow.')
        ->and($learner->fresh()?->next_reminder_at?->utc()->toDateTimeString())->toBe('2026-10-19 14:00:00');
});

it('serialises the claimed instant into the job', function (): void {
    $job = new SendLearnerReminderJob(5, CarbonImmutable::parse('2026-10-18 14:00:00', 'UTC'));

    /** @var SendLearnerReminderJob $restored */
    $restored = unserialize(serialize($job));

    expect($restored->learnerId)->toBe(5)
        ->and($restored->scheduledFor->utc()->toDateTimeString())->toBe('2026-10-18 14:00:00')
        // Attempts are unlimited (a release for lack of a global Telegram slot uses one up); real exceptions are capped.
        ->and($job->tries)->toBe(0)
        ->and($job->maxExceptions)->toBe(3);
});

// --- which cards count as due (DueRepetitionCriteria, docs/06 §6) ----------------------------

it('ignores fresh minute-step learning cards when counting due words', function (): void {
    $learner = rmLearner();
    rmDue($learner, 4);
    foreach (Word::factory()->count(3)->create() as $word) {
        WordRepetition::factory()->learning(step: 1)->create([
            'learner_id' => $learner->id,
            'word_id' => $word->id,
            'next_review_at' => now()->subMinutes(5),
        ]);
    }

    rmSend($learner);

    expect($this->tg->sent[0]['text'])->toBe('Пора повторить 4 слова в LexiFlow.')
        ->and(app(LearnerStatsService::class)->dueCount($learner))->toBe(4);
});

it('counts (re)learning cards abandoned longer than abandoned_after_hours, but not fresh ones or never-answered cards', function (): void {
    app()->instance(LearningPolicy::class, new LearningPolicy(
        learningStepsMinutes: [1, 10],
        relearningStepsMinutes: [10],
        graduatingIntervalDays: 1,
        easyIntervalDays: 4,
        learnAheadMinutes: 20,
        abandonedAfterHours: 12,
    ));
    $learner = rmLearner();

    // 3 graduated due + 1 relearning abandoned 12h+ ago + 1 learning abandoned a day ago = 5.
    rmDue($learner, 3);
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
    // Fresh in-session cards and a `new` row shown long ago but never answered do not count.
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
    WordRepetition::factory()->create([
        'learner_id' => $learner->id,
        'word_id' => Word::factory()->create()->id,
        'state' => RepetitionState::New->value,
        'repetitions' => 0,
        'next_review_at' => now()->subDays(2),
    ]);

    rmSend($learner);

    expect($this->tg->sent[0]['text'])->toBe('Пора повторить 5 слов в LexiFlow.')
        // Same number the learner sees in GET /me and on the Today screen.
        ->and(app(LearnerStatsService::class)->forLearner($learner)->wordsDueToday)->toBe(5);
});

it('does not count a relearning card abandoned less than abandoned_after_hours ago until the line is crossed', function (): void {
    $learner = rmLearner();
    $abandoned = WordRepetition::factory()->relearning()->create([
        'learner_id' => $learner->id,
        'word_id' => Word::factory()->create()->id,
        'next_review_at' => now()->subHours(11),
    ]);

    expect(rmSend($learner))->toBe(ReminderOutcome::SkippedNothingToSay);

    // Two hours later the same card crosses the 12h line.
    $this->travel(2)->hours();
    expect(rmSend($learner, CarbonImmutable::now()->subMinutes(5)))->toBe(ReminderOutcome::Sent)
        ->and($this->tg->sent[0]['text'])->toBe('Пора повторить 1 слово в LexiFlow.')
        ->and($abandoned->fresh()?->state)->toBe(RepetitionState::Relearning);
});

it('keeps legacy failed cards (backfilled to relearning) in reminders once their day has passed', function (): void {
    $learner = rmLearner();
    $words = Word::factory()->count(5)->create();

    /** @var Migration $migration */
    $migration = require base_path('database/migrations/2026_10_18_000002_add_learning_state_to_word_repetitions.php');
    $migration->down();

    // Legacy SM-2: failed cards had repetitions = 0, interval 1 day, next_review_at = fail time + 1 day.
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

    rmSend($learner);
    expect($this->tg->sent[0]['text'])->toBe('Пора повторить 5 слов в LexiFlow.')
        ->and(app(LearnerStatsService::class)->forLearner($learner)->wordsDueToday)->toBe(5);
});

// --- retention ----------------------------------------------------------------------

it('prunes notifications_log rows older than the configured retention, daily with the product events', function (): void {
    expect(config('operations.notifications_log.retention_days'))->toBe(400);
    config(['operations.notifications_log.retention_days' => 30]);

    $learner = rmLearner();
    $old = NotificationLog::factory()->create(['learner_id' => $learner->id, 'sent_at' => now()->subDays(31)]);
    $fresh = NotificationLog::factory()->create(['learner_id' => $learner->id, 'sent_at' => now()->subDays(29)]);

    $this->artisan('model:prune', ['--model' => [NotificationLog::class]])->assertSuccessful();

    expect(NotificationLog::query()->pluck('id')->all())->toBe([$fresh->id])
        ->and(NotificationLog::query()->whereKey($old->id)->exists())->toBeFalse();

    $prune = collect(Schedule::events())->first(fn ($e): bool => str_contains((string) $e->command, 'model:prune'));
    expect((string) $prune?->command)->toContain(NotificationLog::class);
});

// --- bot-wide send budget (docs/13 §9, 2R.3) ----------------------------------------------

it('keeps a claimed reminder intact while the job waits for a Telegram slot, then sends it exactly once', function (): void {
    $this->app->instance(TelegramMessageRateLimiter::class, new TelegramMessageRateLimiter(globalPerSecond: 1));
    $learner = rmLearner();
    rmDue($learner, 2);

    $worker = function () use ($learner): bool {
        $queueJob = Mockery::mock(QueueJobContract::class);
        $released = false;
        $queueJob->shouldReceive('release')->andReturnUsing(function () use (&$released): void {
            $released = true;
        });
        $job = new SendLearnerReminderJob($learner->id, CarbonImmutable::parse('2026-10-18 14:00:00', 'UTC'));
        $job->job = $queueJob;

        foreach ($job->middleware() as $middleware) {
            $middleware->handle($job, fn () => $job->handle(app(ReminderSender::class)));
        }

        return $released;
    };

    // Another sender took this second's only slot.
    expect(app(TelegramMessageRateLimiter::class)->reserveGlobal())->toBeTrue()
        ->and($worker())->toBeTrue()
        ->and($this->tg->sent)->toBe([])
        ->and(NotificationLog::query()->count())->toBe(0)
        ->and($learner->fresh()?->dm_blocked_at)->toBeNull();

    $this->travel(2)->seconds();

    expect($worker())->toBeFalse()
        ->and($this->tg->sent)->toHaveCount(1)
        ->and(NotificationLog::query()->count())->toBe(1);
});
