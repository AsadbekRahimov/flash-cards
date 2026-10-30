<?php

declare(strict_types=1);

use App\Domain\Practice\Enums\BattleDmKind;
use App\Domain\Practice\Enums\BattleStatus;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Services\Battle\BattleJanitor;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Services\BattleMessenger;
use App\Jobs\SendBattleDmJob;
use App\Models\NotificationLog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeTelegramClient;
use Tests\Support\Practice\BattleKit;

/*
 * docs/16 §8.7, §14.1 `BattleMessengerTest`: the DM that invites a friend to a battle - once (the marker of `notifications_log`
 * is written BEFORE the send), in the language of the recipient, with one web_app button that opens `{twa.base_url}/twa/battles/{id}`;
 * silence for an invitation that is no longer open (declined, withdrawn, taken, expired) and for a recipient who is blocked or
 * whose DM is closed; a Telegram 403 deletes the marker and closes the DM (`dm_blocked_at`).
 *
 * The queue is `sync` in tests: the invitation itself sends the DM right after its commit, which is also how the whole path is
 * checked. A test that wants the invitation NOT announced yet fakes the queue and calls the messenger as the worker would.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(BattleKit::T0);
    Storage::fake('practice_audio');
    config(['twa.base_url' => 'https://lexiflow.test']);
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** Delivers (or not) the DM of a battle the way the queue worker would. */
function battleDm(int $battleId): void
{
    app(BattleMessenger::class)->notify(BattleDmKind::Invited, $battleId);
}

it('stays silent when the practice is closed to the recipient by the time the DM would go: the button would only open a 403 (М5)', function (): void {
    Queue::fake();
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $battle = BattleKit::invite($me, $friendship, PracticeMode::Pairs, $course, $lesson);

    // Between the invitation and the worker the pilot closed to the friend (or the switch went off for everybody).
    config(['practice.access' => 'pilot']);
    $me->update(['practice_pilot_at' => now()]);
    battleDm($battle->id);
    expect($this->tg->sent)->toBe([])
        ->and(NotificationLog::query()->where('type', BattleMessenger::NOTIFICATION_TYPE)->count())->toBe(0);

    config(['practice.access' => 'off']);
    battleDm($battle->id);
    expect($this->tg->sent)->toBe([]);

    // Open again: the DM goes, once.
    config(['practice.access' => 'all']);
    battleDm($battle->id);
    battleDm($battle->id);
    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['chat_id'])->toBe($friend->telegram_user_id);
});

it('invites the friend with a DM once: their language, the format, the course and the lesson, one web_app button into the battle', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();

    $battle = BattleKit::invite($me, $friendship, PracticeMode::Pairs, $course, $lesson);

    // The invitation sends it by itself (sync queue): to the friend, in uz, with the button of the app.
    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['chat_id'])->toBe($friend->telegram_user_id)
        ->and($this->tg->sent[0]['text'])->toBe('Bek sizni bellashuvga chaqiryapti: Juftlarni toping, Ingliz tili A1 · 1-modul · 1-dars. Taklif 5 daqiqa amal qiladi.')
        ->and($this->tg->sent[0]['button'])->toBe('Bellashuvni ochish')
        ->and($this->tg->sent[0]['url'])->toBe("https://lexiflow.test/twa/battles/{$battle->id}")
        ->and($this->tg->sent[0]['markup'])->toBeNull();

    // The marker is the key of the idempotency: type `battle_dm`, `invited:<id>`, the recipient's.
    $marker = NotificationLog::query()->sole();
    expect($marker->type)->toBe('battle_dm')
        ->and($marker->learner_id)->toBe($friend->id)
        ->and($marker->payload)->toBe(['key' => "invited:{$battle->id}"]);

    // A job that runs again (a retry after a worker died, a duplicate) says nothing more.
    foreach (range(1, 3) as $_) {
        SendBattleDmJob::dispatchSync('invited', $battle->id);
    }
    expect($this->tg->sent)->toHaveCount(1)
        ->and(NotificationLog::query()->where('type', 'battle_dm')->count())->toBe(1);
});

it('speaks Russian to a Russian recipient, names a battle on the whole course by the course alone, and counts the minutes of the invitation in words', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();

    $first = BattleKit::service()->invite($friend, $friendship->id, PracticeMode::Collocation, $course->slug, null, null);

    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['chat_id'])->toBe($me->telegram_user_id)
        ->and($this->tg->sent[0]['text'])->toBe('Dilnoza зовёт вас на баттл: Подбери сочетание, Английский A1. Приглашение действует 5 минут.')
        ->and($this->tg->sent[0]['button'])->toBe('Открыть баттл');

    // The time is the time of the invitation (`practice.battle.invite_ttl_seconds`), not a figure written in the text.
    BattleKit::service()->cancel($friend, $first->id);
    Queue::fake();
    foreach ([60 => '1 минуту', 120 => '2 минуты', 600 => '10 минут'] as $seconds => $words) {
        config(['practice.battle.invite_ttl_seconds' => $seconds]);
        $this->tg->sent = [];
        $battle = BattleKit::invite($friend, $friendship, PracticeMode::Sentence, $course, $lesson);
        battleDm($battle->id);

        expect($this->tg->sent)->toHaveCount(1)
            ->and($this->tg->sent[0]['text'])->toBe("Dilnoza зовёт вас на баттл: Собери предложение, Английский A1 · Модуль 1 · Урок 1. Приглашение действует {$words}.");
        BattleKit::service()->cancel($friend, $battle->id);
    }
});

it('calls a challenger without a name "your friend" in the language of the recipient', function (): void {
    Queue::fake();
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $me->forceFill(['first_name' => null])->save();

    $battle = BattleKit::invite($me, $friendship, PracticeMode::Dictation, $course);
    battleDm($battle->id);

    expect($this->tg->sent[0]['text'])->toStartWith("Do'stingiz sizni bellashuvga chaqiryapti: Eshiting va yozing, Ingliz tili A1.");
});

it('builds the URL of the button from `twa.base_url` whatever slashes it ends with', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    config(['twa.base_url' => 'https://app.lexiflow.test///']);

    $battle = BattleKit::invite($me, $friendship, PracticeMode::Pairs, $course);

    expect($this->tg->sent[0]['url'])->toBe("https://app.lexiflow.test/twa/battles/{$battle->id}");
});

it('is silent for an invitation that is no longer open: declined, withdrawn, taken, expired, or one that is not there', function (): void {
    Queue::fake();
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $service = BattleKit::service();

    $declined = BattleKit::invite($me, $friendship, PracticeMode::Pairs, $course);
    $service->decline($friend, $declined->id);
    battleDm($declined->id);

    $withdrawn = BattleKit::invite($me, $friendship, PracticeMode::Pairs, $course);
    $service->cancel($me, $withdrawn->id);
    battleDm($withdrawn->id);

    $taken = BattleKit::invite($me, $friendship, PracticeMode::Pairs, $course);
    $service->accept($friend, $taken->id);
    battleDm($taken->id);
    $service->cancel($me, $taken->id);

    // Past its time before anybody has looked at it (the row still says `invited`), and after the sweeper has closed it.
    $late = BattleKit::invite($me, $friendship, PracticeMode::Pairs, $course);
    CarbonImmutable::setTestNow('2026-10-30 10:05:00');
    expect($late->fresh()?->status)->toBe(BattleStatus::Invited);
    battleDm($late->id);
    expect(app(BattleJanitor::class)->sweep())->toBe(1)
        ->and($late->fresh()?->status)->toBe(BattleStatus::Closed);
    battleDm($late->id);

    battleDm(999_999);

    expect($this->tg->sent)->toHaveCount(0)
        ->and(NotificationLog::query()->count())->toBe(0);
});

it('is silent for a recipient who is blocked or whose DM is closed - and speaks again when the DM is open', function (): void {
    Queue::fake();
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $battle = BattleKit::invite($me, $friendship, PracticeMode::Pairs, $course);

    $friend->forceFill(['dm_blocked_at' => now()])->save();
    battleDm($battle->id);
    $friend->forceFill(['dm_blocked_at' => null, 'blocked_at' => now()])->save();
    battleDm($battle->id);

    expect($this->tg->sent)->toHaveCount(0)
        ->and(NotificationLog::query()->count())->toBe(0);

    $friend->forceFill(['blocked_at' => null])->save();
    battleDm($battle->id);

    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['chat_id'])->toBe($friend->telegram_user_id);
});

it('closes the DM of a recipient on a Telegram 403 - the marker goes (nothing was delivered), dm_blocked_at is set - and lets a later retry through', function (): void {
    Queue::fake();
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $battle = BattleKit::invite($me, $friendship, PracticeMode::Pairs, $course);

    $mock = Mockery::mock(TelegramClient::class);
    $mock->shouldReceive('sendWebAppButton')->once()->andThrow(new TelegramForbiddenException(TelegramForbiddenException::REASON_BOT_BLOCKED));
    $this->app->instance(TelegramClient::class, $mock);

    battleDm($battle->id);

    expect($friend->fresh()?->dm_blocked_at)->not->toBeNull()
        ->and(NotificationLog::query()->where('type', 'battle_dm')->count())->toBe(0);

    // The job that is retried finds the DM closed: no second call to Telegram (the mock allows exactly one).
    battleDm($battle->id);

    // The person writes to the bot: the DM is open again and the invitation, still open, is announced.
    $friend->forceFill(['dm_blocked_at' => null])->save();
    $this->app->instance(TelegramClient::class, $this->tg);
    battleDm($battle->id);

    expect($this->tg->sent)->toHaveCount(1)
        ->and(NotificationLog::query()->where('type', 'battle_dm')->count())->toBe(1);
});

it('keeps the marker when Telegram fails for another reason: the message may have gone out, so a retry does not repeat it', function (): void {
    Queue::fake();
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $battle = BattleKit::invite($me, $friendship, PracticeMode::Pairs, $course);
    $this->tg->failSends = 1;

    expect(fn () => battleDm($battle->id))->toThrow(TelegramApiException::class);
    battleDm($battle->id);

    expect($this->tg->sent)->toHaveCount(0)
        ->and(NotificationLog::query()->where('type', 'battle_dm')->count())->toBe(1)
        ->and($friend->fresh()?->dm_blocked_at)->toBeNull();
});

it('puts the job on the queue after the commit of the invitation, once, and ignores a kind it does not know', function (): void {
    Queue::fake();
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();

    $battle = BattleKit::invite($me, $friendship, PracticeMode::Pairs, $course);

    Queue::assertPushed(SendBattleDmJob::class, 1);
    Queue::assertPushed(SendBattleDmJob::class, fn (SendBattleDmJob $job): bool => $job->kind === 'invited' && $job->battleId === $battle->id);
    expect($this->tg->sent)->toHaveCount(0);

    (new SendBattleDmJob('result', $battle->id))->handle(app(BattleMessenger::class));
    expect($this->tg->sent)->toHaveCount(0)
        ->and(NotificationLog::query()->count())->toBe(0);
});
