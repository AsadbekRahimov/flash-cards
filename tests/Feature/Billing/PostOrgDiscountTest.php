<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\PaymentApplyException;
use App\Domain\Billing\Providers\Payme\PaymeRpcException;
use App\Domain\Billing\Providers\Payme\PaymeRpcService;
use App\Domain\Billing\Services\BillingProviderRegistry;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Billing\Services\PaymentApplier;
use App\Domain\Organizations\Events\OrganizationMemberLeft;
use App\Domain\Organizations\Services\OrganizationMembershipService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Listeners\Organizations\GrantPostOrgDiscount;
use App\Models\Learner;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\ProductEvent;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBillingProvider;
use Tests\Support\FakeTelegramClient;
use Tests\Support\PaymeFixtures;

/*
 * B2B -> B2C bridge (docs/13 §6.5, 2B.7): losing a SEATED place gives a one-time 30 % offer and one DM; the
 * offer discounts the order on the server, the webhook is checked against that amount, and the offer is used
 * up only by a paid order.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
    config()->set('twa.base_url', 'https://twa.test');
    config()->set('billing.paywall.mode', 'purchase');
    config()->set('billing.offers.post_org_discount', ['pct' => 30, 'valid_days' => 14, 'period' => 'month']);

    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
    $this->stars = FakeBillingProvider::register(SubscriptionProvider::TelegramStars);
    $this->payme = FakeBillingProvider::register(SubscriptionProvider::Payme);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function pdCenter(string $name = 'Bright Center'): Organization
{
    $org = Organization::factory()->create(['name' => $name]);
    Subscription::factory()->forOrganization($org)->create(['current_period_ends_at' => null, 'tier' => 'tutor', 'seats_limit' => 30]);

    return $org;
}

/** A seated member of $org (the join DM is not what these tests look at). */
function pdSeated(Organization $org, int $tgId = 9001, string $locale = 'ru'): Learner
{
    $learner = Learner::factory()->onboarded()->create(['telegram_user_id' => $tgId, 'interface_locale' => $locale]);
    app(OrganizationMembershipService::class)->joinByAdmin($learner, $org, User::factory()->create());
    test()->tg->sent = [];

    return $learner;
}

function pdLeave(Learner $learner, Organization $org): void
{
    app(OrganizationMembershipService::class)->leave($learner, $org);
}

function pdOffers(Learner $learner): int
{
    return Offer::query()->where('learner_id', $learner->id)->count();
}

// --- the offer and the DM --------------------------------------------------------------------

it('gives a learner who lost a seated place one 30 % offer and one DM with the discounted pay buttons', function (): void {
    $org = pdCenter();
    $learner = pdSeated($org);

    pdLeave($learner, $org);

    $offer = Offer::query()->sole();
    expect($offer->learner_id)->toBe($learner->id)
        ->and($offer->kind)->toBe('post_org_discount')
        ->and($offer->discount_pct)->toBe(30)
        ->and($offer->period)->toBe('month')
        ->and($offer->source_organization_id)->toBe($org->id)
        ->and($offer->expires_at->toIso8601String())->toBe(CarbonImmutable::now()->addDays(14)->toIso8601String())
        ->and($offer->meta)->toHaveKey('dm_sent_at')
        ->and(ProductEvent::query()->where('name', 'offer.created')->count())->toBe(1);

    expect($this->tg->sent)->toHaveCount(1);
    $dm = $this->tg->sent[0];
    expect($dm['chat_id'])->toBe(9001)
        ->and($dm['text'])->toContain('Вы покинули центр «Bright Center»')
        ->and($dm['text'])->toContain('30%')
        ->and($dm['text'])->toContain('03.11.2026')
        ->and($this->tg->lastCallbackData())->toContain('pro1:buy:month:telegram_stars', 'pro1:buy:month:payme')
        ->and($dm['markup']['inline_keyboard'][0][0]['text'])->toContain('105'); // 150 Stars - 30 %
});

it('speaks the learner\'s language', function (): void {
    $org = pdCenter('Aniq Markaz');
    $learner = pdSeated($org, 9002, 'uz');

    pdLeave($learner, $org);

    expect($this->tg->sent[0]['text'])->toContain('«Aniq Markaz» markazidan chiqdingiz')->and($this->tg->sent[0]['text'])->toContain('30%');
});

it('does nothing for a waitlisted member, for a learner with a personal Pro, and when the learner is removed or left twice', function (): void {
    $org = pdCenter();

    $waiting = Learner::factory()->onboarded()->seatedIn($org, 'waitlisted')->create(['telegram_user_id' => 9003]);
    pdLeave($waiting, $org);
    expect(pdOffers($waiting))->toBe(0);

    $paying = pdSeated($org, 9004);
    Subscription::factory()->create(['subscriber_id' => $paying->id]);
    pdLeave($paying, $org);
    expect(pdOffers($paying))->toBe(0);

    expect($this->tg->sent)->toBe([]);
});

it('is one-time: a replayed event, a second leave and a rejoin create neither a second offer nor a second DM', function (): void {
    $org = pdCenter();
    $learner = pdSeated($org);
    pdLeave($learner, $org);

    $replay = new OrganizationMemberLeft($org->id, $learner->id, 1, 'left', true, null);
    app(GrantPostOrgDiscount::class)->handle($replay);
    event($replay);

    app(OrganizationMembershipService::class)->joinByAdmin($learner, $org, User::factory()->create());
    $this->tg->sent = [];
    pdLeave($learner, $org);

    expect(pdOffers($learner))->toBe(1)
        ->and($this->tg->sent)->toBe([])
        ->and(ProductEvent::query()->where('name', 'offer.created')->count())->toBe(1);
});

it('removal by an administrator also loses the seat', function (): void {
    $org = pdCenter();
    $learner = pdSeated($org);

    app(OrganizationMembershipService::class)->leave($learner, $org, 'removed', User::factory()->create());

    expect(pdOffers($learner))->toBe(1)->and($this->tg->sent)->toHaveCount(1);
});

it('does not offer a discount while the learner keeps Pro through a seat in another center, and does once that seat is lost too', function (): void {
    $a = pdCenter('Center A');
    $b = pdCenter('Center B');
    $learner = pdSeated($a);
    app(OrganizationMembershipService::class)->joinByAdmin($learner, $b, User::factory()->create());
    $this->tg->sent = [];

    // A warm entitlement cache must not matter: the offer decision reads the live state.
    expect(app(EntitlementResolver::class)->for($learner)->plan)->toBe(Plan::Pro);

    pdLeave($learner, $a);
    expect(pdOffers($learner))->toBe(0)
        ->and($this->tg->sent)->toBe([])
        ->and(app(EntitlementResolver::class)->for($learner)->source)->toBe('sponsor:organization:'.$b->id);

    // Losing the last seat is what loses Pro: the offer comes from that center.
    pdLeave($learner, $b);
    expect(pdOffers($learner))->toBe(1)
        ->and(Offer::query()->where('learner_id', $learner->id)->sole()->source_organization_id)->toBe($b->id)
        ->and($this->tg->sent)->toHaveCount(1);
});

it('keeps the offer but sends no DM to a blocked learner or a closed DM', function (string $column): void {
    $org = pdCenter();
    $learner = pdSeated($org);
    $learner->forceFill([$column => now()])->save();

    pdLeave($learner, $org);

    expect(pdOffers($learner))->toBe(1)->and($this->tg->sent)->toBe([]);
})->with(['dm_blocked_at', 'blocked_at']);

it('closes the DM when Telegram answers 403 and does not break the leave', function (): void {
    $org = pdCenter();
    $learner = pdSeated($org);

    $client = Mockery::mock(TelegramClient::class);
    $client->shouldReceive('sendMessage')->once()->andThrow(new TelegramForbiddenException(TelegramForbiddenException::REASON_BOT_BLOCKED));
    $this->app->instance(TelegramClient::class, $client);

    pdLeave($learner, $org);

    expect($learner->refresh()->dm_blocked_at)->not->toBeNull()
        ->and(Offer::query()->sole()->meta)->not->toHaveKey('dm_sent_at');
});

it('finishes the DM on a retry after a Telegram outage, once', function (): void {
    Queue::fake();
    $org = pdCenter();
    $learner = pdSeated($org);
    pdLeave($learner, $org);
    expect(pdOffers($learner))->toBe(0); // the listener is queued, not run

    $event = new OrganizationMemberLeft($org->id, $learner->id, 1, 'left', true, null);
    $listener = app(GrantPostOrgDiscount::class);
    $this->tg->failSends = 1;

    expect(fn () => $listener->handle($event))->toThrow(TelegramApiException::class);
    expect(pdOffers($learner))->toBe(1)->and($this->tg->sent)->toBe([]);

    $listener->handle($event);
    $listener->handle($event);

    expect(pdOffers($learner))->toBe(1)->and($this->tg->sent)->toHaveCount(1);
});

it('does not message while nothing is for sale (the offer waits in the catalog)', function (): void {
    config()->set('billing.paywall.mode', 'coming_soon');
    $org = pdCenter();
    $learner = pdSeated($org);

    pdLeave($learner, $org);

    expect(pdOffers($learner))->toBe(1)->and($this->tg->sent)->toBe([]);
});

// --- checkout, webhook, redemption ------------------------------------------------------------

function pdOfferFor(Learner $learner): Offer
{
    return Offer::factory()->create(['learner_id' => $learner->id, 'expires_at' => CarbonImmutable::now()->addDays(14)]);
}

it('prices the order at list - 30 % with the currency\'s rounding and minimum', function (): void {
    $learner = Learner::factory()->create();
    pdOfferFor($learner);

    config()->set('billing.prices.pro.month.XTR', 155); // 108.5 -> 109
    $stars = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa');
    expect($stars->amount)->toBe(109)->and($stars->list_amount)->toBe(155);

    config()->set('billing.prices.pro.month.UZS', 2_900_001); // 2 030 000.7 tiyin -> 2 030 001
    $payme = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::Payme, null, 'twa');
    expect($payme->amount)->toBe(2_030_001);

    config()->set('billing.prices.pro.month.XTR', 1);
    $cheap = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa');
    expect($cheap->amount)->toBe(1);
});

it('does not apply an expired offer, in the catalog or in the order', function (): void {
    $learner = Learner::factory()->create();
    $offer = pdOfferFor($learner);
    $later = CarbonImmutable::now()->addDays(15);

    $catalog = app(CheckoutService::class)->catalog($learner, $later);
    $order = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa', null, $later);

    expect($catalog->offer)->toBeNull()
        ->and($order->offer_id)->toBeNull()
        ->and($order->amount)->toBe(150);

    $offer->forceFill(['expires_at' => CarbonImmutable::now()->subSecond()])->save();
    expect(app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::Payme, null, 'twa')->offer_id)->toBeNull();
});

it('checks the webhook against the discounted order amount and redeems the offer only on a successful payment', function (): void {
    $learner = Learner::factory()->create();
    $offer = pdOfferFor($learner);
    $order = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa');
    expect($order->offer_id)->toBe($offer->id)->and($order->amount)->toBe(105);

    // The list price is not what this order costs: the charge is refused, nothing is bought or redeemed.
    $wrong = app(PaymentApplier::class)->applyCharge($this->stars->emitCharge($order, amount: 150));
    expect($wrong->status)->toBe(Payment::STATUS_FAILED)
        ->and($offer->refresh()->redeemed_at)->toBeNull()
        ->and($offer->payment_order_id)->toBeNull()
        ->and(Subscription::query()->where('subscriber_id', $learner->id)->count())->toBe(0);

    $paid = app(PaymentApplier::class)->applyCharge($this->stars->emitCharge($order, eventId: 'ok-1'));
    expect($paid->status)->toBe(Payment::STATUS_SUCCEEDED)
        ->and($paid->amount)->toBe(105)
        ->and($offer->refresh()->redeemed_at)->not->toBeNull()
        ->and($offer->payment_order_id)->toBe($order->id)
        ->and(ProductEvent::query()->where('name', 'offer.redeemed')->count())->toBe(1);

    // Used up: the next order is at the list price (the Stars renewal that just started would block any order).
    Subscription::query()->update(['status' => 'canceled', 'canceled_at' => CarbonImmutable::now()]);
    $next = app(CheckoutService::class)->create($learner->refresh(), Plan::Pro, 'month', SubscriptionProvider::Payme, null, 'twa');
    expect($next->offer_id)->toBeNull()->and($next->amount)->toBe(2_900_000);
});

it('leaves the offer open when its order expires, is canceled, or is paid too late, until the offer\'s own deadline', function (): void {
    $learner = Learner::factory()->create();
    $offer = pdOfferFor($learner);
    $order = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::Payme, null, 'twa');

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDay()->addMinute());
    expect(app(CheckoutService::class)->expirePending())->toBe(1);

    $late = fn () => app(PaymentApplier::class)->applyCharge($this->payme->emitCharge($order));
    expect($late)->toThrow(PaymentApplyException::class);

    expect($offer->refresh()->redeemed_at)->toBeNull()
        ->and($offer->payment_order_id)->toBeNull();

    $again = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::Payme, null, 'twa');
    expect($again->offer_id)->toBe($offer->id)->and($again->amount)->toBe(2_030_000);

    $again->forceFill(['status' => PaymentOrder::STATUS_CANCELED])->save();
    expect($offer->refresh()->redeemed_at)->toBeNull()
        ->and(app(CheckoutService::class)->catalog($learner)->offer['discount_pct'] ?? null)->toBe(30);
});

it('pays a discounted order over the real Payme webhook: the list amount is refused (-31001), the discounted one pays and redeems', function (): void {
    config()->set('billing.providers.payme.enabled', true);
    config()->set('billing.providers.payme.merchant_id', '5e730e8e0b852a417aa49ceb');
    config()->set('billing.providers.payme.key', 'live-merchant-key-0123456789abcdef');
    config()->set('billing.providers.payme.ip_allowlist', '');
    config()->set('billing.providers.payme.fiscal.ikpu', '');
    // The real adapter, not the fake one registered in beforeEach.
    $this->app->forgetInstance(BillingProviderRegistry::class);

    $learner = Learner::factory()->create();
    $offer = pdOfferFor($learner);
    $order = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::Payme, null, 'twa');
    expect($order->amount)->toBe(2_030_000)->and($order->list_amount)->toBe(2_900_000);

    $rpc = app(PaymeRpcService::class);
    $refused = $rpc->handle(PaymeFixtures::for('CheckPerformTransaction', $order, ['amount' => 2_900_000]));
    expect($refused['error']['code'])->toBe(PaymeRpcException::WRONG_AMOUNT);

    expect($rpc->handle(PaymeFixtures::for('CheckPerformTransaction', $order))['result'])->toBe(['allow' => true]);
    expect($rpc->handle(PaymeFixtures::for('CreateTransaction', $order))['result']['state'])->toBe(1);
    expect($offer->refresh()->redeemed_at)->toBeNull(); // reserved by the order, not used yet

    expect($rpc->handle(PaymeFixtures::for('PerformTransaction', $order))['result']['state'])->toBe(2);
    $rpc->handle(PaymeFixtures::for('PerformTransaction', $order));

    expect(Payment::query()->where('status', 'succeeded')->count())->toBe(1)
        ->and(Payment::query()->sole()->amount)->toBe(2_030_000)
        ->and($offer->refresh()->redeemed_at)->not->toBeNull()
        ->and($offer->payment_order_id)->toBe($order->id);
});
