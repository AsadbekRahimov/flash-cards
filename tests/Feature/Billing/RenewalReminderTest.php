<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Services\RenewalReminderSender;
use App\Domain\Billing\Services\SubscriptionService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Twa\Services\JwtService;
use App\Jobs\RenewalReminderJob;
use App\Mail\SubscriptionRenewalDueMail;
use App\Models\Learner;
use App\Models\NotificationLog;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeTelegramClient;

/*
 * Renewal reminders (docs/13 §4.4, task 2A.6): the daily command, the listener -> job hop, the learner DM,
 * the e-mail + DM to the owners of an organization, at-most-once delivery and the `/me` "Pro ended" hint.
 * Which subscriptions are due at all (and the per-(subscription, N days) markers) is SubscriptionServiceTest.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'twa.base_url' => 'https://twa.test',
        'app.url' => 'https://lexi.test',
        'billing.checkout.renewal_reminder_days' => [3, 1],
        'billing.paywall.contact' => '@lexi_pay',
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 06:00:00', 'UTC'));

    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

/** @param array<string, mixed> $attributes */
function rrSubscription(array $attributes = []): Subscription
{
    return Subscription::factory()->create([
        'provider' => 'payme',
        'current_period_ends_at' => CarbonImmutable::now()->addDays(3)->subHours(2),
        ...$attributes,
    ])->refresh();
}

function rrSend(Subscription $subscription, int $days = 3): int
{
    return app(RenewalReminderSender::class)->send($subscription->id, $days, CarbonImmutable::instance($subscription->current_period_ends_at));
}

/** @return array{0: Organization, 1: Subscription} */
function rrOrganization(string $locale = 'ru'): array
{
    $organization = Organization::factory()->create(['name' => 'Best Center', 'locale' => $locale, 'timezone' => 'Asia/Tashkent']);
    $subscription = rrSubscription(['subscriber_type' => 'organization', 'subscriber_id' => $organization->id]);

    return [$organization, $subscription];
}

function rrStaff(Organization $organization, bool $owner = true, array $user = []): User
{
    $account = User::factory()->create($user);
    $row = OrganizationUser::factory()->create(['organization_id' => $organization->id, 'user_id' => $account->id]);

    if (! $owner) {
        $row->update(['role' => 'teacher']);
    }

    return $account;
}

// --- command, listener, job ---------------------------------------------------

it('schedules subscriptions:remind-expiring daily', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'subscriptions:remind-expiring'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 6 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('queues one job per due subscription and never again on a repeated run', function (): void {
    Queue::fake();

    $payme = rrSubscription();
    $canceledStars = rrSubscription(['provider' => 'telegram_stars', 'status' => 'canceled', 'canceled_at' => now()->subDay()]);
    // Not reminded: grants, an auto-renewing Stars subscription.
    foreach (['manual', 'promo', 'reward'] as $grant) {
        rrSubscription(['provider' => $grant]);
    }
    rrSubscription(['provider' => 'telegram_stars']);

    $this->artisan('subscriptions:remind-expiring')->expectsOutputToContain('Renewal reminders queued: 2')->assertSuccessful();

    Queue::assertPushed(RenewalReminderJob::class, 2);
    Queue::assertPushed(RenewalReminderJob::class, fn (RenewalReminderJob $j): bool => $j->subscriptionId === $payme->id && $j->daysLeft === 3);
    Queue::assertPushed(RenewalReminderJob::class, fn (RenewalReminderJob $j): bool => $j->subscriptionId === $canceledStars->id && $j->daysLeft === 3);

    $this->artisan('subscriptions:remind-expiring')->expectsOutputToContain('Renewal reminders queued: 0')->assertSuccessful();
    Queue::assertPushed(RenewalReminderJob::class, 2);
});

it('spreads the daily batch over seconds by the send rate', function (): void {
    Queue::fake();
    config(['learning.reminders.send_rate_per_second' => 1]);

    rrSubscription();
    rrSubscription();
    rrSubscription();

    app(SubscriptionService::class)->remindExpiring();

    $delays = collect(Queue::pushedJobs()[RenewalReminderJob::class])->map(fn (array $p) => $p['job']->delay)->all();
    expect($delays)->toBe([0, 1, 2]);
});

it('sends exactly one DM per (subscription, N days) through the whole chain, also when the command runs twice', function (): void {
    Mail::fake();
    $learner = Learner::factory()->create(['telegram_user_id' => 555001, 'interface_locale' => 'ru']);
    $sub = rrSubscription(['subscriber_id' => $learner->id]);

    Artisan::call('subscriptions:remind-expiring');
    Artisan::call('subscriptions:remind-expiring');

    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['chat_id'])->toBe(555001)
        ->and($this->tg->sent[0]['button'])->toBe('Продлить Pro')
        ->and($this->tg->sent[0]['url'])->toBe('https://twa.test/twa/billing?from=renewal')
        ->and($this->tg->sent[0]['text'])->toContain('заканчивается через 3 дн.')
        ->and(NotificationLog::query()->where('type', 'renewal_reminder')->where('learner_id', $learner->id)->count())->toBe(1);

    // Two days later the 1-day reminder follows (a different marker), and only once.
    $this->travelTo(now()->addDays(2));
    Artisan::call('subscriptions:remind-expiring');
    Artisan::call('subscriptions:remind-expiring');

    expect($this->tg->sent)->toHaveCount(2)
        ->and($this->tg->sent[1]['text'])->toContain('завтра')
        ->and(NotificationLog::query()->where('type', 'renewal_reminder')->count())->toBe(2);
    Mail::assertNothingSent();
    expect($sub->refresh()->meta['renewal_reminders'])->toHaveCount(2);
});

// --- learner DM ---------------------------------------------------------------

it('writes the DM in the learner language and logs it', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 555002, 'interface_locale' => 'uz', 'timezone' => 'Asia/Tashkent']);
    $sub = rrSubscription(['subscriber_id' => $learner->id]);

    expect(rrSend($sub))->toBe(1);

    expect($this->tg->sent[0]['button'])->toBe('Pro ni yangilash')
        ->and($this->tg->sent[0]['text'])->toContain('3 kundan keyin tugaydi')
        ->and($this->tg->sent[0]['text'])->toContain(CarbonImmutable::instance($sub->current_period_ends_at)->setTimezone('Asia/Tashkent')->format('d.m.Y'));
    $log = NotificationLog::query()->firstOrFail();
    expect($log->type)->toBe('renewal_reminder')
        ->and($log->learner_id)->toBe($learner->id)
        ->and($log->payload['key'])->toStartWith($sub->id.':3@');
});

it('hints at the stopped auto-renewal for a canceled Stars subscription', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 555003, 'interface_locale' => 'ru']);
    $sub = rrSubscription(['subscriber_id' => $learner->id, 'provider' => 'telegram_stars', 'status' => 'canceled', 'canceled_at' => now()->subDay()]);

    expect(rrSend($sub))->toBe(1)
        ->and($this->tg->sent[0]['text'])->toContain('Автопродление отключено');
});

it('is at most once: a repeated or retried send does not duplicate, even after a Bot API error', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 555004]);
    $sub = rrSubscription(['subscriber_id' => $learner->id]);

    expect(rrSend($sub))->toBe(1)
        ->and(rrSend($sub))->toBe(0)
        ->and($this->tg->sent)->toHaveCount(1);

    // An ambiguous error keeps the marker: the retry meets it and stays silent.
    $other = Learner::factory()->create(['telegram_user_id' => 555005]);
    $second = rrSubscription(['subscriber_id' => $other->id]);
    $this->tg->failSends = 1;

    expect(fn () => rrSend($second))->toThrow(TelegramApiException::class);
    expect(rrSend($second))->toBe(0)
        ->and($this->tg->sent)->toHaveCount(1);
});

it('does not write to a blocked learner or one whose DM is closed', function (): void {
    $blocked = Learner::factory()->create(['telegram_user_id' => 555006, 'blocked_at' => now()->subDay()]);
    $closed = Learner::factory()->create(['telegram_user_id' => 555007, 'dm_blocked_at' => now()->subDay()]);

    expect(rrSend(rrSubscription(['subscriber_id' => $blocked->id])))->toBe(0)
        ->and(rrSend(rrSubscription(['subscriber_id' => $closed->id])))->toBe(0)
        ->and($this->tg->sent)->toBe([])
        ->and(NotificationLog::query()->count())->toBe(0);
});

it('sets dm_blocked_at on a Telegram 403 and does not count the reminder', function (): void {
    $client = Mockery::mock(TelegramClient::class);
    $client->shouldReceive('sendWebAppButton')->once()->andThrow(new TelegramForbiddenException(TelegramForbiddenException::REASON_BOT_BLOCKED));
    $this->app->instance(TelegramClient::class, $client);

    $learner = Learner::factory()->create(['telegram_user_id' => 555008]);
    $sub = rrSubscription(['subscriber_id' => $learner->id]);

    expect(rrSend($sub))->toBe(0)
        ->and($learner->refresh()->dm_blocked_at)->not->toBeNull()
        ->and(NotificationLog::query()->count())->toBe(0);
});

it('skips a reminder that no longer matches the subscription', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 555009]);
    $sub = rrSubscription(['subscriber_id' => $learner->id]);
    $oldEnd = CarbonImmutable::instance($sub->current_period_ends_at);

    // Renewed between the event and the job.
    $sub->update(['current_period_ends_at' => $oldEnd->addDays(30)]);
    expect(app(RenewalReminderSender::class)->send($sub->id, 3, $oldEnd))->toBe(0);

    // Ended, or turned into a grant, or gone.
    $sub->update(['current_period_ends_at' => $oldEnd, 'provider' => SubscriptionProvider::Manual->value]);
    expect(app(RenewalReminderSender::class)->send($sub->id, 3, $oldEnd))->toBe(0);
    $sub->update(['provider' => 'payme', 'status' => 'expired']);
    expect(app(RenewalReminderSender::class)->send($sub->id, 3, $oldEnd))->toBe(0)
        ->and(app(RenewalReminderSender::class)->send(999_999, 3, $oldEnd))->toBe(0)
        ->and($this->tg->sent)->toBe([]);
});

// --- organizations ------------------------------------------------------------

it('e-mails every active owner of an organization in its language and DMs the ones linked to Telegram', function (): void {
    Mail::fake();
    [$organization, $sub] = rrOrganization('uz');
    $linked = rrStaff($organization, user: ['email' => 'linked@center.test', 'telegram_user_id' => 880001]);
    $mailOnly = rrStaff($organization, user: ['email' => 'mailonly@center.test', 'telegram_user_id' => null]);
    rrStaff($organization, owner: false, user: ['email' => 'teacher@center.test']);
    rrStaff($organization, user: ['email' => 'inactive@center.test', 'is_active' => false]);
    $other = rrStaff(Organization::factory()->create(), user: ['email' => 'other@center.test']);

    expect(rrSend($sub))->toBe(3); // 2 e-mails + 1 DM

    Mail::assertSent(SubscriptionRenewalDueMail::class, 2);
    Mail::assertSent(SubscriptionRenewalDueMail::class, fn (SubscriptionRenewalDueMail $m): bool => $m->hasTo($linked->email) && $m->daysLeft === 3 && $m->language === 'uz');
    Mail::assertSent(SubscriptionRenewalDueMail::class, fn (SubscriptionRenewalDueMail $m): bool => $m->hasTo($mailOnly->email));
    Mail::assertNotSent(SubscriptionRenewalDueMail::class, fn (SubscriptionRenewalDueMail $m): bool => $m->hasTo('teacher@center.test') || $m->hasTo('inactive@center.test') || $m->hasTo($other->email));

    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['chat_id'])->toBe(880001)
        ->and($this->tg->sent[0]['text'])->toContain('«Best Center» markazining obunasi 3 kundan keyin tugaydi')
        ->and($this->tg->sent[0]['markup']['inline_keyboard'][0][0]['url'])->toBe('https://lexi.test/org');
});

it('renders the e-mail with the center, the date and the contact', function (): void {
    [$organization, $sub] = rrOrganization('ru');
    $mail = new SubscriptionRenewalDueMail('Best Center', 1, '21.10.2026', 'https://lexi.test/org', '@lexi_pay', 'ru');

    $html = $mail->render();

    expect($mail->envelope()->subject)->toBe('Подписка центра «Best Center» заканчивается завтра')
        ->and($html)->toContain('Best Center')
        ->and($html)->toContain('21.10.2026')
        ->and($html)->toContain('https://lexi.test/org')
        ->and($html)->toContain('@lexi_pay');
});

it('sends the owner e-mail and DM once however often the job runs', function (): void {
    Mail::fake();
    [$organization, $sub] = rrOrganization();
    rrStaff($organization, user: ['email' => 'owner@center.test', 'telegram_user_id' => 880002]);

    expect(rrSend($sub))->toBe(2)
        ->and(rrSend($sub))->toBe(0);
    Mail::assertSent(SubscriptionRenewalDueMail::class, 1);
    expect($this->tg->sent)->toHaveCount(1);

    // The 1-day reminder is a new (subscription, N) pair.
    expect(rrSend($sub, 1))->toBe(2);
    Mail::assertSent(SubscriptionRenewalDueMail::class, 2);
});

it('uses the owner learner row for DM reachability when there is one', function (): void {
    Mail::fake();
    [$organization, $sub] = rrOrganization();
    rrStaff($organization, user: ['email' => 'owner@center.test', 'telegram_user_id' => 880003]);
    Learner::factory()->create(['telegram_user_id' => 880003, 'dm_blocked_at' => now()->subDay()]);

    expect(rrSend($sub))->toBe(1); // e-mail only
    expect($this->tg->sent)->toBe([]);
});

it('does not stop the other owners when one delivery fails, and retries only what is missing', function (): void {
    Mail::fake();
    [$organization, $sub] = rrOrganization();
    rrStaff($organization, user: ['email' => 'a@center.test', 'telegram_user_id' => 880004]);
    rrStaff($organization, user: ['email' => 'b@center.test', 'telegram_user_id' => 880005]);
    $this->tg->failSends = 1; // the first owner's DM

    expect(fn () => rrSend($sub))->toThrow(TelegramApiException::class);
    Mail::assertSent(SubscriptionRenewalDueMail::class, 2);
    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['chat_id'])->toBe(880005);

    // Retry: e-mails and the second DM are marked; the first DM's marker stays too (at most once).
    expect(rrSend($sub))->toBe(0);
    Mail::assertSent(SubscriptionRenewalDueMail::class, 2);
});

it('releases the e-mail marker when the transport fails so the retry can send it', function (): void {
    [$organization, $sub] = rrOrganization();
    rrStaff($organization, user: ['email' => 'owner@center.test', 'telegram_user_id' => null]);

    Event::listen(MessageSending::class, static function (): void {
        throw new RuntimeException('smtp down');
    });
    expect(fn () => rrSend($sub))->toThrow(RuntimeException::class, 'smtp down');

    Event::forget(MessageSending::class);
    expect(rrSend($sub))->toBe(1);
});

it('stays silent for a suspended organization and for an organization without owners', function (): void {
    Mail::fake();
    [$organization, $sub] = rrOrganization();
    rrStaff($organization, user: ['email' => 'owner@center.test']);
    $organization->update(['status' => 'suspended']);

    expect(rrSend($sub))->toBe(0);

    [, $orphan] = rrOrganization();
    expect(rrSend($orphan))->toBe(0);
    Mail::assertNothingSent();
});

// --- /me "Pro ended" ----------------------------------------------------------

/** @return array<string, string> */
function rrHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

it('reports lapsed in /me for a learner whose own subscription just expired', function (): void {
    $learner = Learner::factory()->create();
    Subscription::factory()->create([
        'subscriber_id' => $learner->id,
        'provider' => 'payme',
        'status' => 'expired',
        'current_period_ends_at' => now()->subDays(2),
        'ended_at' => now()->subDays(2),
    ]);

    $this->withHeaders(rrHeaders($learner))->getJson('/api/twa/me')
        ->assertOk()
        ->assertJsonPath('entitlement.plan', 'free')
        ->assertJsonPath('entitlement.lapsed.provider', 'payme')
        ->assertJsonPath('entitlement.lapsed.ended_at', now()->subDays(2)->toIso8601String());
});

it('has no lapsed hint with Pro in force, an old expiry, a manual end or no history', function (): void {
    $fresh = Learner::factory()->create();
    $live = Learner::factory()->create();
    $old = Learner::factory()->create();
    $refunded = Learner::factory()->create();

    Subscription::factory()->create(['subscriber_id' => $live->id, 'provider' => 'payme']);
    Subscription::factory()->create(['subscriber_id' => $old->id, 'provider' => 'payme', 'status' => 'expired', 'ended_at' => now()->subDays(15)]);
    $refund = Subscription::factory()->create(['subscriber_id' => $refunded->id, 'provider' => 'payme']);
    app(SubscriptionService::class)->endNow($refund, 'refund');

    foreach ([$fresh, $live, $old, $refunded] as $learner) {
        $this->withHeaders(rrHeaders($learner))->getJson('/api/twa/me')
            ->assertOk()
            ->assertJsonPath('entitlement.lapsed', null);
    }
});

it('has no lapsed hint when a center keeps the learner on Pro', function (): void {
    $organization = Organization::factory()->create();
    $learner = Learner::factory()->seatedIn($organization)->create();
    Subscription::factory()->forOrganization($organization)->create(['provider' => 'manual']);
    Subscription::factory()->create(['subscriber_id' => $learner->id, 'provider' => 'payme', 'status' => 'expired', 'ended_at' => now()->subDay()]);

    $this->withHeaders(rrHeaders($learner))->getJson('/api/twa/me')
        ->assertOk()
        ->assertJsonPath('entitlement.plan', 'pro')
        ->assertJsonPath('entitlement.lapsed', null);
});

it('turns the lapsed hint off with lapsed_banner_days = 0', function (): void {
    config(['billing.checkout.lapsed_banner_days' => 0]);
    $learner = Learner::factory()->create();
    Subscription::factory()->create(['subscriber_id' => $learner->id, 'provider' => 'payme', 'status' => 'expired', 'ended_at' => now()->subDay()]);

    $this->withHeaders(rrHeaders($learner))->getJson('/api/twa/me')->assertJsonPath('entitlement.lapsed', null);
});
