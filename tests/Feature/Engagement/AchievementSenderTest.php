<?php

declare(strict_types=1);

/*
 * The card DM of a new achievement (docs/13 §7.4): the PNG with a caption in the learner's language and a
 * "Share" URL button, at most once per achievement, quiet for blocked learners, Telegram 403, the job.
 */

use App\Domain\Engagement\Enums\AchievementKey;
use App\Domain\Engagement\Services\AchievementSender;
use App\Domain\Engagement\Services\AchievementService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Jobs\Middleware\ThrottleTelegramOutbound;
use App\Jobs\SendAchievementJob;
use App\Models\Achievement;
use App\Models\Learner;
use App\Models\NotificationLog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeTelegramClient;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    config(['telegram.bot_username' => 'LexiFlowBot']);
    $this->travelTo(CarbonImmutable::parse('2026-10-21 05:00:00', 'UTC'));
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

function sendAch(Achievement $achievement): string
{
    return app(AchievementSender::class)->send($achievement->id);
}

it('sends the PNG with a Russian caption and a share button with the learner invite link', function (): void {
    $learner = Learner::factory()->create(['interface_locale' => 'ru']);
    $achievement = Achievement::factory()->key(AchievementKey::Streak7)->create(['learner_id' => $learner->id]);

    expect(sendAch($achievement))->toBe('sent');

    $photo = $this->tg->photos[0];
    expect($this->tg->photos)->toHaveCount(1)
        ->and($photo['chat_id'])->toBe($learner->telegram_user_id)
        ->and($photo['size'])->toBeGreaterThan(8_000)
        ->and($photo['caption'])->toContain('Серия 7 дней')->toContain('Неделя занятий без пропусков');

    $button = $photo['markup']['inline_keyboard'][0][0];
    $link = 'https://t.me/LexiFlowBot?start=ref_'.$learner->fresh()?->referral_code;
    expect($button['text'])->toBe('Поделиться')
        ->and($button['url'])->toBe('https://t.me/share/url?url='.rawurlencode($link).'&text='.rawurlencode('Я получил(а) достижение «Серия 7 дней» в LexiFlow! Учим английский в Telegram.'));
});

it('speaks Uzbek and renders the Uzbek card', function (): void {
    $learner = Learner::factory()->create(['interface_locale' => 'uz']);
    $achievement = Achievement::factory()->key(AchievementKey::Words500)->create(['learner_id' => $learner->id]);

    sendAch($achievement);

    $photo = $this->tg->photos[0];
    expect($photo['caption'])->toContain("500 ta so'z")
        ->and($photo['markup']['inline_keyboard'][0][0]['text'])->toBe('Ulashish')
        ->and(basename($photo['path']))->toStartWith('words_500-uz-');
});

it('asks to forward the message when the bot username is not configured (no button)', function (): void {
    config(['telegram.bot_username' => '']);
    $achievement = Achievement::factory()->key(AchievementKey::FirstFriend)->create();

    sendAch($achievement);

    expect($this->tg->photos[0]['markup'])->toBeNull()
        ->and($this->tg->photos[0]['caption'])->toContain('Перешлите это сообщение');
});

it('sends at most once per achievement, also when the job is repeated', function (): void {
    $achievement = Achievement::factory()->create();

    expect(sendAch($achievement))->toBe('sent')
        ->and(sendAch($achievement))->toBe('skipped')
        ->and($this->tg->photos)->toHaveCount(1)
        ->and(NotificationLog::query()->where('type', 'achievement')->count())->toBe(1);

    (new SendAchievementJob($achievement->id))->handle(app(AchievementSender::class));
    expect($this->tg->photos)->toHaveCount(1);
});

it('skips a blocked learner, a learner whose DMs are closed, a deleted achievement and an unknown key', function (): void {
    $blocked = Achievement::factory()->create(['learner_id' => Learner::factory()->create(['blocked_at' => now()])->id]);
    $closed = Achievement::factory()->create(['learner_id' => Learner::factory()->create(['dm_blocked_at' => now()])->id]);
    $unknown = Achievement::factory()->create(['key' => 'course_completed:x']);

    expect(sendAch($blocked))->toBe('skipped')
        ->and(sendAch($closed))->toBe('skipped')
        ->and(sendAch($unknown))->toBe('skipped')
        ->and(app(AchievementSender::class)->send(999_999))->toBe('skipped')
        ->and($this->tg->photos)->toBe([]);
});

it('on a Telegram 403 deletes the marker, closes the DMs of the learner and ends without a retry', function (): void {
    $learner = Learner::factory()->create();
    $achievement = Achievement::factory()->create(['learner_id' => $learner->id]);
    $telegram = Mockery::mock(TelegramClient::class);
    $telegram->shouldReceive('sendPhoto')->once()->andThrow(new TelegramForbiddenException(TelegramForbiddenException::REASON_BOT_BLOCKED));
    $this->app->instance(TelegramClient::class, $telegram);

    expect(sendAch($achievement))->toBe('forbidden')
        ->and(NotificationLog::query()->where('type', 'achievement')->count())->toBe(0)
        ->and($learner->fresh()?->dm_blocked_at?->utc()->toDateTimeString())->toBe('2026-10-21 05:00:00');
});

it('leaves the marker after an ambiguous failure so that a retry does not duplicate, and fails the job', function (): void {
    $achievement = Achievement::factory()->create();
    $this->tg->failSends = 1;
    $this->tg->failSendsAsTransport = true;

    expect(fn () => sendAch($achievement))->toThrow(TelegramApiException::class)
        ->and(NotificationLog::query()->where('type', 'achievement')->count())->toBe(1);
});

it('does not send the card again on a retry after an ambiguous (non-403) failure', function (): void {
    $achievement = Achievement::factory()->create();
    $this->tg->failSends = 1; // a Bot API refusal that is not a 403: it is unknown whether the photo arrived

    expect(fn () => sendAch($achievement))->toThrow(TelegramApiException::class);

    $this->tg->failSends = 0;
    expect(sendAch($achievement))->toBe('skipped')
        ->and($this->tg->photos)->toHaveCount(0)
        ->and(NotificationLog::query()->where('type', 'achievement')->count())->toBe(1);
});

it('is dispatched by the unlock once and runs through the bot-wide send budget', function (): void {
    $job = new SendAchievementJob(1);

    expect($job->middleware()[0])->toBeInstanceOf(ThrottleTelegramOutbound::class)
        ->and($job->tries)->toBe(0);

    // End to end through the queue (sync in tests): unlock → job → photo.
    $learner = Learner::factory()->create();
    app(AchievementService::class)->unlock($learner, AchievementKey::Words100, now()->toImmutable());

    expect($this->tg->photos)->toHaveCount(1);
});
