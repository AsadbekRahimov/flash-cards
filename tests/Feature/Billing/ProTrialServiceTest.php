<?php

declare(strict_types=1);

use App\Domain\Administration\Services\PlatformStatsService;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Billing\Services\LearnerBillingService;
use App\Domain\Billing\Services\PaymentApplier;
use App\Domain\Billing\Services\PromoCodeService;
use App\Domain\Billing\Services\ProTrialService;
use App\Domain\Billing\Services\RenewalReminderSender;
use App\Domain\Billing\Services\SubscriptionService;
use App\Domain\Learners\Services\LearnerDeletionService;
use App\Domain\Learners\Services\LearnerPurgeService;
use App\Domain\Learners\Services\LearnerRegistrar;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Domain\Twa\Services\InitDataValidator;
use App\Models\Learner;
use App\Models\PaymentOrder;
use App\Models\ProductEvent;
use App\Models\PromoCode;
use App\Models\ProTrial;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Tests\Support\FakeBillingProvider;
use Tests\Support\FakeTelegramClient;

/*
 * The welcome trial (docs/13 §7.1 "2C.9", owner's decision of 16.11.2026): 7 days of Pro for every learner created
 * after the launch, at their first own contact, once per Telegram account for good — through every registration path,
 * never for a learner of before, never twice (a deleted and re-registered account included), never two for parallel
 * contacts, never a failed registration because of it. What a trial does to a later promo, reward and payment; what it
 * is NOT (a paid subscription in the figures); the reminder before it ends; the one-shot notice for the bot.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16 10:00:00', 'UTC'));
    config(['billing.trial.days' => 7, 'telegram.bot_token' => 'test-bot-token-1234567890', 'twa.base_url' => 'https://twa.test']);
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function trialRegistrar(): LearnerRegistrar
{
    return app(LearnerRegistrar::class);
}

function trialService(): ProTrialService
{
    return app(ProTrialService::class);
}

function trialOf(Learner $learner): ?Subscription
{
    /** @var Subscription|null $row */
    $row = Subscription::query()->where('subscriber_type', 'learner')->where('subscriber_id', $learner->id)->orderBy('id')->first();

    return $row;
}

/** `/start` in a DM, through the dispatcher: the bot's registration path. */
function trialStart(int $tgId, string $text = '/start'): void
{
    app(TelegramDispatcher::class)->dispatch(['message' => [
        'chat' => ['id' => $tgId, 'type' => 'private'],
        'from' => ['id' => $tgId, 'first_name' => 'Ali', 'language_code' => 'ru'],
        'text' => $text,
    ]]);
}

function trialInitData(int $tgId): string
{
    return InitDataValidator::sign('test-bot-token-1234567890', [
        'query_id' => 'AAHdF6IQAAAAAN0XohDhrOrc',
        'user' => json_encode(['id' => $tgId, 'first_name' => 'Ali', 'language_code' => 'ru']),
        'auth_date' => (string) time(),
    ]);
}

/** The whole of a granted trial: the subscription, the book row, the marker gone, the notice, the events. */
function expectTrialGranted(Learner $learner, int $tgId): Subscription
{
    $trial = trialOf($learner);
    $now = CarbonImmutable::now();

    expect($trial)->not->toBeNull()
        ->and($trial?->provider)->toBe('trial')
        ->and($trial?->status)->toBe('trialing')
        ->and($trial?->plan)->toBe('pro')
        ->and($trial?->provider_ref)->toBe('trial')
        ->and($trial?->current_period_ends_at?->toIso8601String())->toBe($now->addDays(7)->toIso8601String())
        ->and(Subscription::query()->where('subscriber_id', $learner->id)->count())->toBe(1)
        ->and(app(EntitlementResolver::class)->resolve($learner)->plan)->toBe(Plan::Pro);

    $book = ProTrial::query()->where('telegram_hash', ProTrialService::hashFor($tgId))->sole();
    expect($book->learner_id)->toBe($learner->id)
        ->and($book->subscription_id)->toBe($trial?->id)
        ->and($book->days)->toBe(7)
        ->and($book->ends_at?->toIso8601String())->toBe($now->addDays(7)->toIso8601String())
        ->and(strlen($book->telegram_hash))->toBe(64)
        ->and($book->telegram_hash)->not->toContain((string) $tgId);

    $fresh = $learner->fresh();
    expect($fresh?->meta)->not->toHaveKey(ProTrialService::META_PENDING)
        ->and($fresh?->meta[ProTrialService::META_NOTICE] ?? null)->toBe($now->addDays(7)->toIso8601String())
        ->and($learner->meta)->toBe($fresh?->meta)
        ->and(ProductEvent::query()->where('name', 'trial.granted')->where('learner_id', $learner->id)->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'subscription.granted')->where('learner_id', $learner->id)->where('props->provider', 'trial')->count())->toBe(1);

    /** @var Subscription $trial */
    return $trial;
}

// --- who gets it ---------------------------------------------------------------

it('grants the trial to a learner created by /start in the bot: 7 days, status trialing, the book row, the notice', function (): void {
    trialStart(910_001);

    $learner = Learner::query()->where('telegram_user_id', 910_001)->sole();
    expectTrialGranted($learner, 910_001);
    expect(ksorted(ProductEvent::query()->where('name', 'trial.granted')->sole()->props))->toBe(ksorted(['days' => 7, 'subscription_id' => trialOf($learner)?->id, 'ends_at' => CarbonImmutable::now()->addDays(7)->toIso8601String(), 'extended' => false]));
});

it('grants the trial to a learner created by the Mini App /auth', function (): void {
    $this->postJson('/api/twa/auth', ['init_data' => trialInitData(910_002)])->assertOk();

    expectTrialGranted(Learner::query()->where('telegram_user_id', 910_002)->sole(), 910_002);
});

it('grants the trial to a learner created by any DM command, and only once however many contacts follow', function (): void {
    $learner = trialRegistrar()->fromTelegramUser(['id' => 910_003, 'first_name' => 'Ali']);
    expectTrialGranted($learner, 910_003);

    trialRegistrar()->fromTelegramUser(['id' => 910_003, 'first_name' => 'Ali']);
    $this->postJson('/api/twa/auth', ['init_data' => trialInitData(910_003)])->assertOk();
    trialStart(910_003);
    trialService()->offer($learner->fresh() ?? $learner);

    expect(Subscription::query()->where('subscriber_id', $learner->id)->count())->toBe(1)
        ->and(ProTrial::query()->count())->toBe(1)
        ->and(trialOf($learner)?->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(7)->toIso8601String())
        ->and(ProductEvent::query()->where('name', 'trial.granted')->count())->toBe(1);
});

it('leaves a learner created by a passive group event without a running trial until they show up themselves', function (): void {
    $observed = trialRegistrar()->observeTelegramUser(['id' => 910_004, 'first_name' => 'Bek'], 'group');

    expect($observed)->not->toBeNull()
        ->and($observed?->meta)->toBe([ProTrialService::META_PENDING => true])
        ->and(Subscription::query()->count())->toBe(0)
        ->and(ProTrial::query()->count())->toBe(0);

    // The same passive event again: still nothing. Their own contact a week later: the 7 days start now.
    trialRegistrar()->observeTelegramUser(['id' => 910_004, 'first_name' => 'Bek'], 'group');
    expect(Subscription::query()->count())->toBe(0);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(7));
    $learner = trialRegistrar()->fromTelegramUser(['id' => 910_004, 'first_name' => 'Bek']);
    expectTrialGranted($learner, 910_004);
});

it('gives nothing to a learner who existed before the launch (no marker on the row) on any contact', function (): void {
    $before = Learner::factory()->create(['telegram_user_id' => 910_005, 'meta' => []]);

    trialRegistrar()->fromTelegramUser(['id' => 910_005, 'first_name' => 'Old']);
    $this->postJson('/api/twa/auth', ['init_data' => trialInitData(910_005)])->assertOk();
    trialService()->offer($before->fresh() ?? $before);

    expect(Subscription::query()->count())->toBe(0)
        ->and(ProTrial::query()->count())->toBe(0)
        ->and($before->fresh()?->meta)->toBe([])
        ->and(app(EntitlementResolver::class)->resolve($before)->plan)->toBe(Plan::Free);
});

it('is switched off by billing.trial.days = 0: the marker stays and the trial comes once it is switched on again', function (): void {
    config(['billing.trial.days' => 0]);
    $learner = trialRegistrar()->fromTelegramUser(['id' => 910_006, 'first_name' => 'Ali']);

    expect(Subscription::query()->count())->toBe(0)
        ->and($learner->meta)->toBe([ProTrialService::META_PENDING => true])
        ->and(trialService()->enabled())->toBeFalse();

    config(['billing.trial.days' => 7]);
    $learner = trialRegistrar()->fromTelegramUser(['id' => 910_006, 'first_name' => 'Ali']);
    expectTrialGranted($learner, 910_006);
});

it('does not grant to a blocked learner and keeps the marker for when the block is lifted', function (): void {
    $learner = trialRegistrar()->observeTelegramUser(['id' => 910_007, 'first_name' => 'Ali'], 'group');
    Learner::query()->whereKey($learner?->id)->update(['blocked_at' => now()]);

    $blocked = trialRegistrar()->fromTelegramUser(['id' => 910_007, 'first_name' => 'Ali']);
    expect(Subscription::query()->count())->toBe(0)
        ->and($blocked->meta)->toBe([ProTrialService::META_PENDING => true]);

    Learner::query()->whereKey($blocked->id)->update(['blocked_at' => null]);
    expectTrialGranted(trialRegistrar()->fromTelegramUser(['id' => 910_007, 'first_name' => 'Ali']), 910_007);
});

// --- once per Telegram account, for good ----------------------------------------

it('gives no second trial to an account that deleted itself and came back within the retention period', function (): void {
    $learner = trialRegistrar()->fromTelegramUser(['id' => 910_010, 'first_name' => 'Ali']);
    expectTrialGranted($learner, 910_010);

    app(LearnerDeletionService::class)->delete($learner);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(10));
    $back = trialRegistrar()->fromTelegramUser(['id' => 910_010, 'first_name' => 'Ali']);

    // The same row, revived; its trial ran out meanwhile and nothing new was given.
    expect($back->id)->toBe($learner->id)
        ->and(Subscription::query()->where('subscriber_id', $learner->id)->count())->toBe(1)
        ->and(ProTrial::query()->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'trial.granted')->count())->toBe(1);
});

it('refuses a second trial to an account that deleted itself, was purged and registered again: the book remembers the hash', function (): void {
    $learner = trialRegistrar()->fromTelegramUser(['id' => 910_011, 'first_name' => 'Ali']);
    expectTrialGranted($learner, 910_011);
    $oldId = $learner->id;

    app(LearnerDeletionService::class)->delete($learner);
    Learner::withTrashed()->whereKey($oldId)->update(['deleted_at' => CarbonImmutable::now()->subDays(40)]);
    expect(app(LearnerPurgeService::class)->purgeDue()->purged)->toBe(1)
        ->and(Learner::withTrashed()->whereKey($oldId)->exists())->toBeFalse()
        // The book outlives the learner: the reference is nulled, the hash stays, the raw id is nowhere in it.
        ->and(ProTrial::query()->sole()->learner_id)->toBeNull()
        ->and(DB::table('pro_trials')->where('telegram_hash', 'like', '%910011%')->exists())->toBeFalse();

    foreach ([fn () => trialStart(910_011), fn () => $this->postJson('/api/twa/auth', ['init_data' => trialInitData(910_011)])->assertOk()] as $i => $contact) {
        $contact();
        $fresh = Learner::query()->where('telegram_user_id', 910_011)->sole();

        expect($fresh->id)->not->toBe($oldId)
            ->and(Subscription::query()->where('subscriber_id', $fresh->id)->count())->toBe(0)
            ->and(app(EntitlementResolver::class)->resolve($fresh)->plan)->toBe(Plan::Free)
            ->and(ProTrial::query()->count())->toBe(1)
            ->and($fresh->meta)->not->toHaveKey(ProTrialService::META_PENDING)
            ->and($fresh->meta)->not->toHaveKey(ProTrialService::META_NOTICE)
            // Decided once, on the first contact of the new row: the second contact has no marker to decide.
            ->and(ProductEvent::query()->where('name', 'trial.refused')->where('learner_id', $fresh->id)->count())->toBe(1);
    }

    expect(ProductEvent::query()->where('name', 'trial.refused')->sole()->props)->toBe(['reason' => 'already_used']);
});

it('is the hash of the Telegram id with the application key: stable, different per account and per key', function (): void {
    $a = ProTrialService::hashFor(1);
    expect($a)->toBe(ProTrialService::hashFor(1))
        ->and($a)->not->toBe(ProTrialService::hashFor(2))
        ->and($a)->toMatch('/^[0-9a-f]{64}$/');

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    expect(ProTrialService::hashFor(1))->not->toBe($a);
});

it('keeps the hash when APP_KEY rotates once billing.trial.hash_key is set, and equals the APP_KEY one without it', function (): void {
    $withAppKey = ProTrialService::hashFor(7);
    expect($withAppKey)->toBe(hash_hmac('sha256', 'pro_trial:7', (string) config('app.key')));

    config(['billing.trial.hash_key' => (string) config('app.key')]);
    $pinned = ProTrialService::hashFor(7);
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    expect($pinned)->toBe($withAppKey)
        ->and(ProTrialService::hashFor(7))->toBe($withAppKey);
});

it('grants one trial when two contacts carry the pending marker at the same time: the second finds the marker gone', function (): void {
    $passive = trialRegistrar()->observeTelegramUser(['id' => 910_012, 'first_name' => 'Ali'], 'group');
    // Two in-memory copies of the row, both still "pending" — the bot's /start and the Mini App's /auth of one instant.
    $bot = Learner::query()->findOrFail($passive?->id);
    $app = Learner::query()->findOrFail($passive?->id);

    $first = trialService()->offer($bot);
    $second = trialService()->offer($app);

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and(Subscription::query()->where('subscriber_id', $bot->id)->count())->toBe(1)
        ->and(ProTrial::query()->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'trial.granted')->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'trial.refused')->count())->toBe(0)
        ->and($app->meta)->not->toHaveKey(ProTrialService::META_PENDING);
});

// --- it never fails the registration --------------------------------------------

it('does not fail the registration when the grant dies: the learner is created, the marker stays, the next contact grants', function (): void {
    Exceptions::fake();
    $poison = true;
    Subscription::creating(function () use (&$poison): void {
        if ($poison) {
            throw new RuntimeException('simulated outage');
        }
    });

    $learner = trialRegistrar()->fromTelegramUser(['id' => 910_020, 'first_name' => 'Ali']);

    expect($learner->exists)->toBeTrue()
        ->and($learner->meta)->toBe([ProTrialService::META_PENDING => true])
        ->and($learner->fresh()?->meta)->toBe([ProTrialService::META_PENDING => true])
        ->and(Subscription::query()->count())->toBe(0)
        // The whole decision rolled back: no book row either, so the retry is a clean first attempt.
        ->and(ProTrial::query()->count())->toBe(0);
    Exceptions::assertReported(RuntimeException::class);

    $poison = false;
    expectTrialGranted(trialRegistrar()->fromTelegramUser(['id' => 910_020, 'first_name' => 'Ali']), 910_020);
});

// --- what a trial does to the rest of billing ---------------------------------------

it('is extended by a promo code and stops being a trial: provider promo, status active, the promo days after the trial', function (): void {
    $learner = trialRegistrar()->fromTelegramUser(['id' => 910_030, 'first_name' => 'Ali']);
    $trial = expectTrialGranted($learner, 910_030);
    PromoCode::factory()->create(['code' => 'WELCOME30', 'plan' => 'pro', 'duration_days' => 30]);

    $sub = app(PromoCodeService::class)->redeem($learner, 'WELCOME30');

    expect($sub->id)->toBe($trial->id)
        ->and($sub->provider)->toBe('promo')
        ->and($sub->status)->toBe('active')
        ->and($sub->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(37)->toIso8601String())
        ->and($sub->meta['history'][0]['from'])->toBe(['plan' => 'pro', 'status' => 'trialing', 'provider' => 'trial', 'until' => CarbonImmutable::now()->addDays(7)->toIso8601String()])
        ->and(Subscription::query()->where('subscriber_id', $learner->id)->count())->toBe(1);
});

it('is extended by a reward the same way, and a trial never extends a live subscription into a trial', function (): void {
    $learner = trialRegistrar()->fromTelegramUser(['id' => 910_031, 'first_name' => 'Ali']);
    $trial = expectTrialGranted($learner, 910_031);

    $sub = DB::transaction(fn () => app(SubscriptionService::class)->grantReward($learner, 7, 'referral_milestone:1:3', CarbonImmutable::now()));
    expect($sub->id)->toBe($trial->id)
        ->and($sub->provider)->toBe('reward')
        ->and($sub->provider_ref)->toBe('referral_milestone:1:3')
        ->and($sub->status)->toBe('active')
        ->and($sub->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(14)->toIso8601String());

    // A manual grant that landed before the first contact: the trial only extends it and the row stays manual.
    $granted = trialRegistrar()->observeTelegramUser(['id' => 910_032, 'first_name' => 'Bek'], 'group');
    Subscription::factory()->create(['subscriber_id' => $granted?->id, 'provider' => 'manual', 'current_period_ends_at' => CarbonImmutable::now()->addDays(10)]);
    $learner = trialRegistrar()->fromTelegramUser(['id' => 910_032, 'first_name' => 'Bek']);
    $live = trialOf($learner);
    expect(Subscription::query()->where('subscriber_id', $learner->id)->count())->toBe(1)
        ->and($live?->provider)->toBe('manual')
        ->and($live?->status)->toBe('active')
        ->and($live?->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(17)->toIso8601String())
        ->and(ProductEvent::query()->where('name', 'trial.granted')->where('learner_id', $learner->id)->sole()->props['extended'])->toBeTrue()
        ->and(ProTrial::query()->where('learner_id', $learner->id)->sole()->subscription_id)->toBe($live?->id);

    // Perpetual Pro: the trial is recorded as used and changes nothing.
    $forever = trialRegistrar()->observeTelegramUser(['id' => 910_033, 'first_name' => 'Zed'], 'group');
    Subscription::factory()->create(['subscriber_id' => $forever?->id, 'provider' => 'manual', 'current_period_ends_at' => null]);
    $learner = trialRegistrar()->fromTelegramUser(['id' => 910_033, 'first_name' => 'Zed']);
    expect(trialOf($learner)?->current_period_ends_at)->toBeNull()
        ->and(ProTrial::query()->where('learner_id', $learner->id)->sole()->ends_at)->toBeNull()
        ->and($learner->meta)->not->toHaveKey(ProTrialService::META_NOTICE);
});

it('keeps the rest of the trial when the learner pays during it: the paid period is appended, the row becomes the paid provider', function (): void {
    $payme = FakeBillingProvider::register(SubscriptionProvider::Payme);
    $learner = trialRegistrar()->fromTelegramUser(['id' => 910_034, 'first_name' => 'Ali']);
    $trial = expectTrialGranted($learner, 910_034);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(2));

    $order = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::Payme, null, 'twa');
    app(PaymentApplier::class)->applyCharge($payme->emitCharge($order));

    $sub = Subscription::query()->findOrFail($trial->id);
    expect(Subscription::query()->where('subscriber_id', $learner->id)->count())->toBe(1)
        ->and($sub->provider)->toBe('payme')
        ->and($sub->status)->toBe('active')
        // 5 days of trial left + 30 paid days.
        ->and($sub->current_period_ends_at?->toIso8601String())->toBe($trial->current_period_ends_at?->addDays(30)->toIso8601String())
        ->and(PaymentOrder::query()->findOrFail($order->id)->status)->toBe(PaymentOrder::STATUS_PAID);
});

it('is Pro for the learner and the admin list, but not a personal subscription of the platform figures', function (): void {
    $learner = trialRegistrar()->fromTelegramUser(['id' => 910_040, 'first_name' => 'Ali']);
    expectTrialGranted($learner, 910_040);
    Subscription::factory()->create(['provider' => 'payme']);

    $stats = app(PlatformStatsService::class)->subscriptions();
    expect($stats['pro_learners'])->toBe(2)
        ->and($stats['personal'])->toBe(1)
        ->and($stats['trials'])->toBe(1)
        ->and(app(LearnerBillingService::class)->renewal($learner))->toBe(['auto' => false, 'provider' => 'trial', 'ends_at' => CarbonImmutable::now()->addDays(7)->toIso8601String(), 'reminder_sent' => false]);
});

it('expires at its end without grace, and the lapsed hint of /me names the trial', function (): void {
    $learner = trialRegistrar()->fromTelegramUser(['id' => 910_041, 'first_name' => 'Ali']);
    $trial = expectTrialGranted($learner, 910_041);

    $later = CarbonImmutable::now()->addDays(7)->addMinute();
    expect(app(SubscriptionService::class)->expireDue($later))->toBe(1)
        ->and($trial->fresh()?->status)->toBe('expired')
        ->and(app(EntitlementResolver::class)->resolve($learner, $later)->plan)->toBe(Plan::Free);

    $entitlement = app(EntitlementResolver::class)->resolve($learner, $later);
    expect(app(LearnerBillingService::class)->lapsed($learner, $entitlement, $later))->toBe(['provider' => 'trial', 'ended_at' => $later->toIso8601String()]);
});

// --- the reminder before the end ----------------------------------------------------

it('is reminded before it ends with its own words, while a trial turned promo is not', function (): void {
    config(['billing.checkout.renewal_reminder_days' => [3, 1]]);
    $learner = trialRegistrar()->fromTelegramUser(['id' => 910_050, 'first_name' => 'Ali']);
    $learner->forceFill(['interface_locale' => 'ru', 'timezone' => 'Asia/Tashkent'])->save();
    $trial = expectTrialGranted($learner, 910_050);
    $other = trialRegistrar()->fromTelegramUser(['id' => 910_051, 'first_name' => 'Bek']);
    PromoCode::factory()->create(['code' => 'PROMO7', 'plan' => 'pro', 'duration_days' => 7]);
    app(PromoCodeService::class)->redeem($other, 'PROMO7');

    $inFiveDays = CarbonImmutable::now()->addDays(4)->addHours(12);
    $this->tg->sent = [];
    // The sync queue runs the listener → job → sender chain at once: one DM, the trial's own words.
    expect(app(SubscriptionService::class)->remindExpiring($inFiveDays))->toBe(1)
        ->and($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['chat_id'])->toBe(910_050)
        ->and($this->tg->sent[0]['text'])->toContain('Пробный Pro заканчивается через 3 дн.')
        ->and($this->tg->sent[0]['text'])->toContain('оформите подписку (/pro) или пригласите друзей (/invite)')
        ->and($this->tg->sent[0]['button'])->toBe('Продлить Pro')
        ->and(app(LearnerBillingService::class)->renewal($learner, $inFiveDays)['reminder_sent'] ?? null)->toBeTrue();

    // At most once: the same day again sends nothing; the sender itself refuses a repeat.
    expect(app(SubscriptionService::class)->remindExpiring($inFiveDays->addHour()))->toBe(0)
        ->and(app(RenewalReminderSender::class)->send($trial->id, 3, CarbonImmutable::instance($trial->current_period_ends_at), $inFiveDays))->toBe(0)
        ->and($this->tg->sent)->toHaveCount(1);
});

// --- the one-shot notice for the bot and the Mini App -----------------------------------

it('keeps the notice of the granted trial until one channel takes it', function (): void {
    $learner = trialRegistrar()->fromTelegramUser(['id' => 910_060, 'first_name' => 'Ali']);
    $endsAt = CarbonImmutable::now()->addDays(7);

    expect(trialService()->pendingNotice($learner)?->toIso8601String())->toBe($endsAt->toIso8601String())
        ->and(trialService()->consumeNotice($learner)?->toIso8601String())->toBe($endsAt->toIso8601String())
        ->and($learner->meta)->toBe([])
        ->and($learner->fresh()?->meta)->toBe([])
        ->and(trialService()->pendingNotice($learner))->toBeNull()
        ->and(trialService()->consumeNotice($learner))->toBeNull();

    // A learner without a trial has nothing to take.
    expect(trialService()->consumeNotice(Learner::factory()->create()))->toBeNull();
});
