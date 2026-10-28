<?php

declare(strict_types=1);

use App\Domain\Billing\Services\BillingReportService;
use App\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use App\Filament\Widgets\RevenueLast30DaysWidget;
use App\Filament\Widgets\WebhookErrorsWidget;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\ProviderTransaction;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/*
 * 2A.7 dashboard widgets (revenue per currency, webhook errors) and the organization tier of the
 * manual Grant.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC'));
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('admin');
    $this->teacher = User::factory()->create(['is_active' => true]);
    $this->teacher->assignRole('teacher');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** @param array<string, mixed> $attributes */
function ledgerRow(string $currency, int $amount, array $attributes = []): Payment
{
    return Payment::factory()->create([
        'currency' => $currency,
        'amount' => $amount,
        'provider' => $currency === 'XTR' ? 'telegram_stars' : 'payme',
        'occurred_at' => now()->subDays(3),
        ...$attributes,
    ]);
}

// --- revenue ------------------------------------------------------------------

it('counts COHORT revenue of 30 days per currency as succeeded charges minus refunded ones', function (): void {
    // UZS (tiyin): 29 000 + 249 000 sum succeeded (one is a renewal-like second charge), 29 000 sum refunded.
    ledgerRow('UZS', 2_900_000);
    ledgerRow('UZS', 24_900_000, ['kind' => Payment::KIND_RENEWAL]);
    ledgerRow('UZS', 2_900_000, ['status' => Payment::STATUS_REFUNDED]);
    // XTR (Stars): 150 succeeded, 1 300 refunded.
    ledgerRow('XTR', 150);
    ledgerRow('XTR', 1_300, ['status' => Payment::STATUS_REFUNDED]);
    // Never counted: the refund bookkeeping rows, failed events, charges older than 30 days.
    ledgerRow('UZS', 2_900_000, ['kind' => Payment::KIND_REFUND]);
    ledgerRow('XTR', 1_300, ['kind' => Payment::KIND_REFUND]);
    ledgerRow('UZS', 9_900_000, ['status' => Payment::STATUS_FAILED]);
    ledgerRow('XTR', 777, ['occurred_at' => now()->subDays(31)]);
    ledgerRow('UZS', 5_500_000, ['occurred_at' => now()->subDays(31)]);

    expect(app(BillingReportService::class)->cohortRevenueLast30Days())->toBe([
        'UZS' => ['gross' => 30_700_000, 'refunded' => 2_900_000, 'net' => 27_800_000, 'count' => 3],
        'XTR' => ['gross' => 1_450, 'refunded' => 1_300, 'net' => 150, 'count' => 2],
    ]);

    app()->setLocale('en');
    $this->actingAs($this->admin);
    Livewire::test(RevenueLast30DaysWidget::class)
        ->assertSee('Net cash movement UZS')
        ->assertSee('Net cash movement XTR')
        ->assertSee('refunds (by refund date): 1, 1 300 XTR')
        ->assertSee('refunds (by refund date): 1, 29 000 UZS')
        ->assertSee('cohort net (this period&#039;s charges less their refunds): 150 XTR', false)
        ->assertSee('278 000 UZS');
});

it('reports cash flow by event date: a refund today for a purchase 40 days ago is in refunds and net cash, not in the cohort', function (): void {
    // Old purchase (outside the window), refunded today: the charge row flips to refunded, a refund row is booked now.
    $old = ledgerRow('UZS', 5_000_000, ['occurred_at' => now()->subDays(40), 'status' => Payment::STATUS_REFUNDED]);
    ledgerRow('UZS', 5_000_000, ['kind' => Payment::KIND_REFUND, 'occurred_at' => now(), 'refunded_payment_id' => $old->id]);
    // A recent purchase, not refunded; and Stars kept apart.
    ledgerRow('UZS', 2_000_000);
    ledgerRow('XTR', 300);
    // Failed events and refund rows older than the window do not count.
    ledgerRow('UZS', 9_900_000, ['status' => Payment::STATUS_FAILED]);
    ledgerRow('UZS', 1_000_000, ['kind' => Payment::KIND_REFUND, 'occurred_at' => now()->subDays(45)]);

    $report = app(BillingReportService::class);

    expect($report->cashFlowLast30Days())->toBe([
        'UZS' => ['gross' => 2_000_000, 'refunds' => 5_000_000, 'net' => -3_000_000, 'charge_count' => 1, 'refund_count' => 1],
        'XTR' => ['gross' => 300, 'refunds' => 0, 'net' => 300, 'charge_count' => 1, 'refund_count' => 0],
    ])->and($report->cohortRevenueLast30Days())->toBe([
        'UZS' => ['gross' => 2_000_000, 'refunded' => 0, 'net' => 2_000_000, 'count' => 1],
        'XTR' => ['gross' => 300, 'refunded' => 0, 'net' => 300, 'count' => 1],
    ]);

    app()->setLocale('en');
    $this->actingAs($this->admin);
    Livewire::test(RevenueLast30DaysWidget::class)->assertSee('-30 000 UZS')->assertSee('300 XTR');
});

it('shows zero revenue in both currencies when there are no payments', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(RevenueLast30DaysWidget::class)->assertSee('0 UZS')->assertSee('0 XTR');
});

it('keeps the window boundary: a charge exactly 30 days old still counts, 30 days and a second does not', function (): void {
    ledgerRow('XTR', 100, ['occurred_at' => now()->subDays(30)]);
    ledgerRow('XTR', 200, ['occurred_at' => now()->subDays(30)->subSecond()]);

    expect(app(BillingReportService::class)->cohortRevenueLast30Days()['XTR']['net'])->toBe(100);
});

// --- webhook errors --------------------------------------------------------------

it('counts failed payments and canceled provider transactions of the last 24 hours', function (): void {
    ledgerRow('UZS', 2_900_000, ['status' => Payment::STATUS_FAILED, 'created_at' => now()->subHours(2)]);
    ledgerRow('UZS', 2_900_000, ['status' => Payment::STATUS_FAILED, 'created_at' => now()->subHours(5), 'provider' => 'click']);
    ledgerRow('XTR', 150, ['status' => Payment::STATUS_FAILED, 'created_at' => now()->subHours(1)]);
    ledgerRow('XTR', 150, ['status' => Payment::STATUS_FAILED, 'created_at' => now()->subHours(25)]);
    ledgerRow('XTR', 150, ['created_at' => now()->subHours(1)]);

    ProviderTransaction::factory()->create(['state' => ProviderTransaction::STATE_CANCELED, 'canceled_at' => now()->subHours(3)]);
    ProviderTransaction::factory()->create(['state' => ProviderTransaction::STATE_CANCELED_AFTER_PERFORM, 'canceled_at' => now()->subHours(1)]);
    ProviderTransaction::factory()->create(['state' => ProviderTransaction::STATE_CANCELED, 'canceled_at' => now()->subHours(30)]);
    ProviderTransaction::factory()->performed()->create();

    expect(app(BillingReportService::class)->webhookErrorsLast24Hours())->toBe([
        'failed_payments' => 3,
        'failed_by_provider' => ['click' => 1, 'payme' => 1, 'telegram_stars' => 1],
        'canceled_transactions' => 2,
        'double_discounts' => 0,
        'tier_mismatches' => 0,
        'stars_double_subscriptions' => 0,
    ]);

    $this->actingAs($this->admin);
    Livewire::test(WebhookErrorsWidget::class)
        ->assertSee('Неудачные платежи')
        ->assertSee('Click: 1')
        ->assertSee('Отменённые транзакции провайдеров')
        ->assertSee('Двойные скидки')
        ->assertSee('Несовпадения тарифа');
});

it('counts only the double-discount orders paid in the last 24 hours', function (): void {
    $mk = fn (array $meta, $paidAt) => PaymentOrder::factory()->create(['status' => PaymentOrder::STATUS_PAID, 'paid_at' => $paidAt, 'meta' => $meta]);

    $mk(['offer_double_discount' => true], now()->subHours(2));
    $mk(['offer_double_discount' => true, 'paid_after_cancel' => true], now()->subHours(23));
    $mk(['offer_double_discount' => true], now()->subHours(30));
    $mk(['offer_double_discount' => false], now()->subHours(1));
    $mk(['paid_after_cancel' => true], now()->subHours(1));
    $mk([], now()->subHours(1));

    expect(app(BillingReportService::class)->webhookErrorsLast24Hours()['double_discounts'])->toBe(2);

    $this->actingAs($this->admin);
    Livewire::test(WebhookErrorsWidget::class)->assertSee('Двойные скидки')->assertSeeText('2');
});

it('hides both widgets from a teacher and forbids mounting them', function (): void {
    $this->actingAs($this->teacher);

    expect(RevenueLast30DaysWidget::canView())->toBeFalse()
        ->and(WebhookErrorsWidget::canView())->toBeFalse();

    Livewire::test(RevenueLast30DaysWidget::class)->assertForbidden();
    Livewire::test(WebhookErrorsWidget::class)->assertForbidden();

    $this->actingAs($this->admin);
    expect(RevenueLast30DaysWidget::canView())->toBeTrue()
        ->and(WebhookErrorsWidget::canView())->toBeTrue();
});

// --- Grant with a tier --------------------------------------------------------------

it('grants Pro to an organization with a tier: tier and seats limit come from the tariff table', function (): void {
    $org = Organization::factory()->create();
    $this->actingAs($this->admin);

    Livewire::test(ListSubscriptions::class)
        ->callAction('grantPro', [
            'subscriber_type' => 'organization',
            'organization_id' => $org->id,
            'tier' => 'center_s',
            'perpetual' => true,
            'note' => 'Signed contract #7',
        ])
        ->assertHasNoActionErrors();

    $subscription = Subscription::query()->where('subscriber_type', 'organization')->where('subscriber_id', $org->id)->sole();
    expect($subscription->tier)->toBe('center_s')
        ->and($subscription->seats_limit)->toBe(100)
        ->and($subscription->plan)->toBe('pro')
        ->and($subscription->provider)->toBe('manual');

    expect(AuditLog::query()->where('action', 'subscription.granted')->sole()->meta['tier'])->toBe('center_s');

    // A higher tier on top of the live grant moves the same row.
    Livewire::test(ListSubscriptions::class)
        ->callAction('grantPro', [
            'subscriber_type' => 'organization',
            'organization_id' => $org->id,
            'tier' => 'center_l',
            'perpetual' => true,
            'note' => 'Upgrade',
        ]);

    expect(Subscription::query()->count())->toBe(1)
        ->and($subscription->refresh()->tier)->toBe('center_l')
        ->and($subscription->seats_limit)->toBeNull();
});

it('rejects a tier that is not in the tariff table and ignores a tier sent for a learner', function (): void {
    $org = Organization::factory()->create();
    $learner = Learner::factory()->create();
    $this->actingAs($this->admin);

    Livewire::test(ListSubscriptions::class)
        ->callAction('grantPro', ['subscriber_type' => 'organization', 'organization_id' => $org->id, 'tier' => 'enterprise', 'perpetual' => true, 'note' => 'x'])
        ->assertHasActionErrors(['tier']);
    expect(Subscription::query()->count())->toBe(0);

    Livewire::test(ListSubscriptions::class)
        ->callAction('grantPro', ['subscriber_type' => 'learner', 'learner_id' => $learner->id, 'tier' => 'center_s', 'perpetual' => true, 'note' => 'x'])
        ->assertHasNoActionErrors();

    $subscription = Subscription::query()->sole();
    expect($subscription->tier)->toBeNull()
        ->and($subscription->seats_limit)->toBeNull();
});

it('still grants an organization without a tier (the previous behaviour)', function (): void {
    $org = Organization::factory()->create();
    $this->actingAs($this->admin);

    Livewire::test(ListSubscriptions::class)
        ->callAction('grantPro', ['subscriber_type' => 'organization', 'organization_id' => $org->id, 'perpetual' => true, 'note' => 'Legacy'])
        ->assertHasNoActionErrors();

    expect(Subscription::query()->sole()->tier)->toBeNull();
});

it('counts only the tier-mismatch orders paid in the last 24 hours', function (): void {
    $mk = fn (array $meta, $paidAt) => PaymentOrder::factory()->create(['status' => PaymentOrder::STATUS_PAID, 'paid_at' => $paidAt, 'meta' => $meta]);

    $mk(['tier_mismatch' => true, 'tier_mismatch_live_tier' => 'tutor'], now()->subHours(2));
    $mk(['tier_mismatch' => true], now()->subHours(23));
    $mk(['tier_mismatch' => true], now()->subHours(30));
    $mk(['tier_mismatch' => false], now()->subHours(1));
    $mk(['offer_double_discount' => true], now()->subHours(1));

    expect(app(BillingReportService::class)->webhookErrorsLast24Hours()['tier_mismatches'])->toBe(2);

    $this->actingAs($this->admin);
    Livewire::test(WebhookErrorsWidget::class)->assertSee('Несовпадения тарифа');
});
