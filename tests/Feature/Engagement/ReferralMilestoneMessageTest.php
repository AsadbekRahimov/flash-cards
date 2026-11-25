<?php

declare(strict_types=1);

use App\Domain\Engagement\Events\ReferralMilestoneReached;
use App\Domain\Engagement\Services\ReferralMilestoneSender;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Exceptions\TelegramRateLimitedException;
use App\Listeners\Engagement\SendReferralMilestoneMessage;
use App\Models\Learner;
use App\Models\NotificationLog;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeTelegramClient;

/*
 * The DM to the inviter when a milestone is granted (docs/13 §7.1 "2C.9"): "N of your friends started — +7 days of Pro (until
 * DATE)", with the tail about the next reward except after the last milestone; nothing for a skipped grant; once per
 * (inviter, milestone) however often the event is raised; nothing for a blocked learner or a closed DM.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['referrals.milestones' => [3, 6], 'referrals.milestone_days' => 7]);
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

/** @return array{0: Learner, 1: Subscription} */
function msInviter(array $learner = []): array
{
    $inviter = Learner::factory()->create(['interface_locale' => 'ru', 'timezone' => 'Asia/Tashkent', ...$learner]);
    $subscription = Subscription::factory()->create([
        'subscriber_id' => $inviter->id,
        'provider' => 'reward',
        'current_period_ends_at' => now()->addDays(7),
    ]);

    return [$inviter, $subscription];
}

function msRaise(Learner $inviter, int $milestone, ?int $subscriptionId, ?string $skipped = null, int $days = 7): void
{
    event(new ReferralMilestoneReached($inviter->id, $milestone, $days, $subscriptionId, $skipped));
}

it('tells the inviter about the first milestone with the way to the next one', function (): void {
    [$inviter, $subscription] = msInviter();

    msRaise($inviter, 3, $subscription->id);

    $until = $subscription->current_period_ends_at->setTimezone('Asia/Tashkent')->format('d.m.Y');
    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['chat_id'])->toBe($inviter->telegram_user_id)
        ->and($this->tg->sent[0]['text'])->toBe("🎉 3 ваших друга начали заниматься — вам +7 дней Pro (до {$until}).\nДо следующей награды: ещё 3 друга.")
        ->and(NotificationLog::query()->where('learner_id', $inviter->id)->where('type', 'referral_milestone')->count())->toBe(1);
});

it('leaves the tail out after the last milestone', function (): void {
    [$inviter, $subscription] = msInviter();

    msRaise($inviter, 6, $subscription->id);

    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toStartWith('🎉 6 ваших друзей начали заниматься — вам +7 дней Pro (до ')
        ->and($this->tg->sent[0]['text'])->not->toContain('До следующей награды');
});

it('speaks Uzbek to an uz learner', function (): void {
    [$inviter, $subscription] = msInviter(['interface_locale' => 'uz']);

    msRaise($inviter, 3, $subscription->id);

    expect($this->tg->sent[0]['text'])->toContain("🎉 3 ta do'stingiz o'rganishni boshladi — sizga +7 kun Pro (")
        ->and($this->tg->sent[0]['text'])->toContain("Keyingi mukofotgacha yana 3 ta do'st qoldi.");
});

it('sends nothing for a skipped grant', function (string $reason): void {
    [$inviter] = msInviter();

    msRaise($inviter, 3, null, $reason);

    expect($this->tg->sent)->toBe([])
        ->and(NotificationLog::query()->count())->toBe(0);
})->with(['perpetual', 'learner_deleted', 'learner_blocked', 'learner_missing']);

it('sends one message per (learner, milestone) however often the event is raised, and one per milestone', function (): void {
    [$inviter, $subscription] = msInviter();

    msRaise($inviter, 3, $subscription->id);
    msRaise($inviter, 3, $subscription->id); // a retried job
    expect($this->tg->sent)->toHaveCount(1);

    msRaise($inviter, 6, $subscription->id);
    expect($this->tg->sent)->toHaveCount(2)
        ->and(NotificationLog::query()->where('learner_id', $inviter->id)->count())->toBe(2);
});

it('sends nothing to a blocked learner or one whose DM is closed', function (array $state): void {
    [$inviter, $subscription] = msInviter($state);

    msRaise($inviter, 3, $subscription->id);

    expect($this->tg->sent)->toBe([]);
})->with([
    'blocked' => [['blocked_at' => now()]],
    'dm closed' => [['dm_blocked_at' => now()]],
]);

it('closes the DM and keeps no marker when Telegram answers 403, so nothing is counted as sent', function (): void {
    [$inviter, $subscription] = msInviter();
    $telegram = Mockery::mock(TelegramClient::class);
    $telegram->shouldReceive('sendMessage')->once()->andThrow(new TelegramForbiddenException(TelegramForbiddenException::REASON_BOT_BLOCKED));
    $this->app->instance(TelegramClient::class, $telegram);

    msRaise($inviter, 3, $subscription->id);

    expect($inviter->fresh()?->dm_blocked_at)->not->toBeNull()
        ->and(NotificationLog::query()->count())->toBe(0);
});

it('drops the marker on a 429 so that the retry tells the inviter exactly once, and keeps it after a timeout', function (): void {
    [$inviter, $subscription] = msInviter();
    $sender = app(ReferralMilestoneSender::class);
    $this->tg->failSends = 1;
    $this->tg->failSendsRetryAfter = 2;

    expect(fn () => $sender->send($inviter->id, 3, 7, $subscription->id, null))->toThrow(TelegramRateLimitedException::class);
    expect(NotificationLog::query()->count())->toBe(0);

    expect($sender->send($inviter->id, 3, 7, $subscription->id, null))->toBe('sent')
        ->and($sender->send($inviter->id, 3, 7, $subscription->id, null))->toBe('skipped')
        ->and($this->tg->sent)->toHaveCount(1);

    // The next milestone times out: the marker stays, the retry is silent.
    $this->tg->failSends = 1;
    $this->tg->failSendsRetryAfter = null;
    $this->tg->failSendsAsTransport = true;
    expect(fn () => $sender->send($inviter->id, 6, 7, $subscription->id, null))->toThrow(TelegramApiException::class);
    expect($sender->send($inviter->id, 6, 7, $subscription->id, null))->toBe('skipped')
        ->and($this->tg->sent)->toHaveCount(1)
        ->and(NotificationLog::query()->count())->toBe(2);
});

it('is picked up by the event system without a manual registration', function (): void {
    Event::fake();
    Event::assertListening(ReferralMilestoneReached::class, SendReferralMilestoneMessage::class);
});

it('puts "Invite more" under the reward, to the Referrals screen (docs/36 §3а, W4)', function (): void {
    config(['twa.base_url' => 'https://twa.test']);
    [$inviter, $subscription] = msInviter();
    [$uz, $uzSubscription] = msInviter(['interface_locale' => 'uz']);

    msRaise($inviter, 3, $subscription->id);
    msRaise($uz, 3, $uzSubscription->id);

    expect($this->tg->sent[0]['markup'])->toBe(['inline_keyboard' => [[
        ['text' => '👥 Пригласить ещё', 'web_app' => ['url' => 'https://twa.test/twa/referrals?from=milestone']],
    ]]])
        ->and($this->tg->sent[1]['markup']['inline_keyboard'][0][0]['text'])->toBe('👥 Yana taklif qilish');
});
