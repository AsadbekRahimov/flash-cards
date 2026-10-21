<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\CheckoutException;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\PaymentApplier;
use App\Domain\Billing\Services\SubscriptionService;
use App\Domain\Billing\Support\OrgTiers;
use App\Models\Organization;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeBillingProvider;

/*
 * Annual payment for learning centers (docs/13 §6.5, "Реализация 2B.9"): the `year` period for Payme / Click
 * organization orders — the price derived from the month (discount from env) or set explicitly, the period
 * added to the end of a live one, a refund taking back exactly the paid year, reminders as for a month.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-21 12:00:00', 'UTC'));
    config()->set('billing.org_tiers.center_s.prices', ['month' => ['UZS' => 34_900_000]]);
    config()->set('billing.org_year_discount_percent', 0);
    $this->payme = FakeBillingProvider::register(SubscriptionProvider::Payme);
    $this->click = FakeBillingProvider::register(SubscriptionProvider::Click);
    $this->org = Organization::factory()->create();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function oaOrder(Organization $org, string $period = 'year', SubscriptionProvider $provider = SubscriptionProvider::Payme, string $tier = 'center_s'): PaymentOrder
{
    return app(CheckoutService::class)->create($org, Plan::Pro, $period, $provider, $tier, CheckoutService::VIA_ORG_PANEL);
}

it('derives the year price from the month: x12 without a discount, minus the configured percent, in whole sums', function (): void {
    expect(OrgTiers::price('center_s', 'year', 'UZS'))->toBe(34_900_000 * 12)
        ->and(OrgTiers::yearSavings('center_s', 'UZS'))->toBe(0);

    config()->set('billing.org_year_discount_percent', 20);

    expect(OrgTiers::price('center_s', 'year', 'UZS'))->toBe(335_040_000)
        ->and(OrgTiers::yearSavings('center_s', 'UZS'))->toBe(34_900_000 * 12 - 335_040_000);

    config()->set('billing.org_year_discount_percent', 33);
    expect(OrgTiers::price('center_s', 'year', 'UZS') % 100)->toBe(0);
});

it('prefers an explicit year price and sells no year for a tier without a month price', function (): void {
    config()->set('billing.org_tiers.center_s.prices.year', ['UZS' => 300_000_000]);

    expect(OrgTiers::price('center_s', 'year', 'UZS'))->toBe(300_000_000)
        ->and(OrgTiers::yearSavings('center_s', 'UZS'))->toBe(34_900_000 * 12 - 300_000_000)
        ->and(OrgTiers::price('center_l', 'year', 'UZS'))->toBeNull()
        ->and(OrgTiers::price('center_s', 'year', 'USD'))->toBeNull();
});

it('creates a year order for Payme and for Click at the year price', function (): void {
    config()->set('billing.org_year_discount_percent', 10);

    foreach ([SubscriptionProvider::Payme, SubscriptionProvider::Click] as $provider) {
        $order = oaOrder($this->org, 'year', $provider);

        expect($order->period)->toBe('year')
            ->and($order->provider)->toBe($provider->value)
            ->and($order->amount)->toBe(376_920_000)
            ->and($order->tier)->toBe('center_s')
            ->and($order->subscriber_type)->toBe('organization');
    }
});

it('applies a paid year: the subscription lasts 365 days with the seats of the tier', function (): void {
    $order = oaOrder($this->org);

    $charge = app(PaymentApplier::class)->applyCharge($this->payme->emitCharge($order));

    $sub = Subscription::query()->sole();
    expect($sub->tier)->toBe('center_s')
        ->and($sub->seats_limit)->toBe(100)
        ->and($sub->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(365)->toIso8601String())
        ->and($charge->period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(365)->toIso8601String());
});

it('adds a year to the end of a live month period instead of from today', function (): void {
    $month = oaOrder($this->org, 'month');
    app(PaymentApplier::class)->applyCharge($this->payme->emitCharge($month));

    $year = oaOrder($this->org, 'year', SubscriptionProvider::Click);
    app(PaymentApplier::class)->applyCharge($this->click->emitCharge($year));

    expect(Subscription::query()->sole()->current_period_ends_at?->toIso8601String())
        ->toBe(CarbonImmutable::now()->addDays(30 + 365)->toIso8601String());
});

it('refunds the paid year only: the month paid before it stays', function (): void {
    $applier = app(PaymentApplier::class);
    $applier->applyCharge($this->payme->emitCharge(oaOrder($this->org, 'month')));
    $yearCharge = $applier->applyCharge($this->click->emitCharge(oaOrder($this->org, 'year', SubscriptionProvider::Click)));

    $applier->applyRefund($this->click->emitRefund($yearCharge));

    $sub = Subscription::query()->sole();
    expect($sub->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(30)->toIso8601String())
        ->and($sub->status)->toBe('active');
});

it('ends the subscription when the only paid year is refunded', function (): void {
    $applier = app(PaymentApplier::class);
    $charge = $applier->applyCharge($this->payme->emitCharge(oaOrder($this->org)));

    $applier->applyRefund($this->payme->emitRefund($charge));

    expect(Subscription::query()->sole()->status)->toBe('expired');
});

it('still refuses another tier over a live year and keeps the year order renewing the same tier', function (): void {
    app(PaymentApplier::class)->applyCharge($this->payme->emitCharge(oaOrder($this->org)));

    try {
        oaOrder($this->org, 'year', SubscriptionProvider::Payme, 'center_m');
        $code = null;
    } catch (CheckoutException $e) {
        $code = $e->errorCode;
    }

    expect($code)->toBe(CheckoutException::TIER_CHANGE_REQUIRES_SUPPORT)
        ->and(oaOrder($this->org)->period)->toBe('year');
});

it('refuses Stars, an unknown period and a tier without a month price for the year', function (): void {
    FakeBillingProvider::register(SubscriptionProvider::TelegramStars);

    $refused = static function (callable $call): ?string {
        try {
            $call();
        } catch (CheckoutException $e) {
            return $e->errorCode;
        }

        return null;
    };

    expect($refused(fn () => oaOrder($this->org, 'year', SubscriptionProvider::TelegramStars)))->toBe(CheckoutException::PROVIDER_NOT_SUPPORTED)
        ->and($refused(fn () => oaOrder($this->org, 'decade')))->toBe(CheckoutException::INVALID_PERIOD)
        ->and($refused(fn () => oaOrder($this->org, 'year', SubscriptionProvider::Payme, 'center_l')))->toBe(CheckoutException::PRICE_NOT_CONFIGURED)
        ->and(PaymentOrder::query()->count())->toBe(0);
});

it('lists the year in the organization catalog next to the month', function (): void {
    $items = collect(app(CheckoutService::class)->catalog($this->org)->items)->where('tier', 'center_s');

    expect($items->pluck('period')->all())->toBe(['month', 'year'])
        ->and($items->firstWhere('period', 'year')['prices'])->toBe(['UZS' => 34_900_000 * 12]);
});

it('reminds the center about the end of a paid year like about a month', function (): void {
    app(PaymentApplier::class)->applyCharge($this->payme->emitCharge(oaOrder($this->org)));
    $service = app(SubscriptionService::class);

    expect($service->remindExpiring(CarbonImmutable::now()->addDays(300)))->toBe(0)
        ->and($service->remindExpiring(CarbonImmutable::now()->addDays(363)))->toBe(1);
});
