<?php

declare(strict_types=1);

use App\Domain\Billing\Contracts\BillingProvider;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\PaymentApplyException;
use App\Domain\Billing\Exceptions\PaymentRefundException;
use App\Domain\Billing\Services\BillingProviderRegistry;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\PaymentApplier;
use App\Domain\Billing\Services\PaymentRefundService;
use App\Filament\Resources\PaymentOrderResource;
use App\Filament\Resources\PaymentOrderResource\Pages\ListPaymentOrders;
use App\Filament\Resources\PaymentOrderResource\Pages\ViewPaymentOrder;
use App\Filament\Resources\PaymentResource;
use App\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Filament\Resources\PaymentResource\Pages\ViewPayment;
use App\Filament\Resources\ProviderTransactionResource;
use App\Filament\Resources\ProviderTransactionResource\Pages\ListProviderTransactions;
use App\Filament\Resources\ProviderTransactionResource\Pages\ViewProviderTransaction;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\ProviderTransaction;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FakeBillingProvider;

/*
 * Payments / orders / provider transactions in the admin panel (docs/13 §11 2A.7): admin only,
 * read-only, secrets masked; "Refund" needs a note, is audited and never refunds twice.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('admin');
    $this->teacher = User::factory()->create(['is_active' => true]);
    $this->teacher->assignRole('teacher');
    $this->stars = FakeBillingProvider::register(SubscriptionProvider::TelegramStars);
    $this->payme = FakeBillingProvider::register(SubscriptionProvider::Payme);
});

/** A real succeeded charge (order -> applyCharge -> subscription) from the given fake provider. */
function adminPayCharge(FakeBillingProvider $via, ?Learner $learner = null, array $raw = []): Payment
{
    $order = app(CheckoutService::class)->create($learner ?? Learner::factory()->create(), Plan::Pro, 'month', $via->key(), null, 'twa');

    return app(PaymentApplier::class)->applyCharge($via->emitCharge($order, raw: $raw));
}

// --- access ----------------------------------------------------------------

it('closes payments, orders and provider transactions to teachers and inactive admins', function (): void {
    foreach (['payments', 'payment-orders', 'provider-transactions'] as $path) {
        $this->actingAs($this->teacher)->get('/admin/'.$path)->assertForbidden();
    }

    $inactive = User::factory()->create(['is_active' => false]);
    $inactive->assignRole('admin');
    $this->actingAs($inactive)->get('/admin/payments')->assertForbidden();

    $this->actingAs($this->teacher);
    expect(PaymentResource::canViewAny())->toBeFalse()
        ->and(PaymentOrderResource::canViewAny())->toBeFalse()
        ->and(ProviderTransactionResource::canViewAny())->toBeFalse();

    // An admin mounts them (the HTTP route additionally needs the 2FA step, covered by TwoFactorTest).
    $this->actingAs($this->admin);
    Livewire::test(ListPayments::class)->assertOk();
    Livewire::test(ListPaymentOrders::class)->assertOk();
    Livewire::test(ListProviderTransactions::class)->assertOk();
});

it('does not let a teacher mount the pages or open a record through Livewire', function (): void {
    $payment = adminPayCharge($this->stars);
    $order = PaymentOrder::query()->findOrFail($payment->payment_order_id);
    $transaction = ProviderTransaction::factory()->create();
    $this->actingAs($this->teacher);

    Livewire::test(ListPayments::class)->assertForbidden();
    Livewire::test(ViewPayment::class, ['record' => $payment->id])->assertForbidden();
    Livewire::test(ListPaymentOrders::class)->assertForbidden();
    Livewire::test(ViewPaymentOrder::class, ['record' => $order->id])->assertForbidden();
    Livewire::test(ListProviderTransactions::class)->assertForbidden();
    Livewire::test(ViewProviderTransaction::class, ['record' => $transaction->id])->assertForbidden();
    expect(PaymentResource::canCreate())->toBeFalse();
});

// --- screens ---------------------------------------------------------------

it('lists payments with filters and shows the details of a payment, an order and a transaction', function (): void {
    $learner = Learner::factory()->create(['first_name' => 'Zinaida', 'last_name' => null, 'username' => 'zina']);
    $stars = adminPayCharge($this->stars, $learner);
    $failed = Payment::factory()->failed()->create(['provider' => 'payme', 'currency' => 'UZS', 'amount' => 2_900_000]);
    $transaction = ProviderTransaction::factory()->performed()->create(['history' => [['method' => 'PerformTransaction', 'at' => now()->toIso8601String(), 'state_from' => 1, 'state_to' => 2]]]);
    $this->actingAs($this->admin);

    Livewire::test(ListPayments::class)
        ->assertCanSeeTableRecords([$stars, $failed])
        ->assertSee('Zinaida @zina')
        ->assertSee('150 XTR')
        ->assertSee('29 000 UZS')
        ->filterTable('status', 'failed')
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$stars])
        ->resetTableFilters()
        ->filterTable('currency', 'XTR')
        ->assertCanSeeTableRecords([$stars])
        ->assertCanNotSeeTableRecords([$failed]);

    Livewire::test(ViewPayment::class, ['record' => $stars->id])->assertOk()->assertSee('Zinaida @zina')->assertSee('Refund');
    Livewire::test(ListPaymentOrders::class)->assertCanSeeTableRecords([PaymentOrder::query()->findOrFail($stars->payment_order_id)]);
    Livewire::test(ViewPaymentOrder::class, ['record' => $stars->payment_order_id])->assertOk()->assertSee('150 XTR');
    Livewire::test(ListProviderTransactions::class)->assertCanSeeTableRecords([$transaction]);
    Livewire::test(ViewProviderTransaction::class, ['record' => $transaction->id])->assertOk()->assertSee('PerformTransaction')->assertSee('Performed');
});

// --- masking ----------------------------------------------------------------

it('never shows secrets from raw payloads or the full order token', function (): void {
    $botToken = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw';
    $payment = adminPayCharge($this->stars, raw: [
        'Authorization' => 'Basic UGF5Y29tOnNlY3JldC1rZXk=',
        'sign_string' => 'deadbeefsign',
        'secret_key' => 'sk-live-top-secret',
        'merchant' => ['key' => 'merchant-key-value', 'id' => 'merchant-visible-id'],
        'note' => 'url https://api.telegram.org/bot'.$botToken.'/refundStarPayment',
        'invoice_payload' => 'invoice-payload-token',
    ]);
    $order = PaymentOrder::query()->findOrFail($payment->payment_order_id);
    $order->update(['meta' => ['return_url' => 'https://t.me/x', 'api_key' => 'order-meta-secret'], 'provider_ref' => 'https://pay.test/'.$order->token]);
    $transaction = ProviderTransaction::factory()->create([
        'payment_order_id' => $order->id,
        'raw' => ['params' => ['id' => 'tx1'], 'sign_string' => 'tx-sign-value', 'password' => 'tx-password-value'],
    ]);
    $this->actingAs($this->admin);

    $secrets = ['Basic UGF5Y29tOnNlY3JldC1rZXk=', 'deadbeefsign', 'sk-live-top-secret', 'merchant-key-value', $botToken, 'invoice-payload-token', 'order-meta-secret', 'tx-sign-value', 'tx-password-value'];

    $payments = Livewire::test(ViewPayment::class, ['record' => $payment->id])->assertSee('merchant-visible-id')->assertSee('[redacted]');
    $orders = Livewire::test(ViewPaymentOrder::class, ['record' => $order->id])->assertSee('https://t.me/x');
    $transactions = Livewire::test(ViewProviderTransaction::class, ['record' => $transaction->id])->assertSee('tx1');
    $lists = [Livewire::test(ListPayments::class), Livewire::test(ListPaymentOrders::class), Livewire::test(ListProviderTransactions::class)];

    foreach ($secrets as $secret) {
        foreach ([$payments, $orders, $transactions, ...$lists] as $component) {
            $component->assertDontSee($secret, escape: false);
        }
    }

    // The order token (what the providers know the order by) is shortened on the order screens.
    $orders->assertSee(substr($order->token, 0, 4).'…'.substr($order->token, -4))->assertDontSee($order->token, escape: false);
    Livewire::test(ListPaymentOrders::class)->assertDontSee($order->token, escape: false);
});

// --- offer reservation markers (2B.7b) ---------------------------------------

it('shows the offer markers of an order as badges and filters the double discounts (admin only)', function (): void {
    $moved = PaymentOrder::factory()->create(['status' => PaymentOrder::STATUS_CANCELED, 'meta' => ['canceled_reason' => PaymentOrder::CANCEL_REASON_OFFER_MOVED, 'offer_moved_to_order_id' => 99]]);
    $late = PaymentOrder::factory()->create(['status' => PaymentOrder::STATUS_PAID, 'meta' => ['paid_after_cancel' => true]]);
    $double = PaymentOrder::factory()->create(['status' => PaymentOrder::STATUS_PAID, 'meta' => ['paid_after_cancel' => true, 'offer_double_discount' => true]]);
    $plain = PaymentOrder::factory()->create(['meta' => []]);

    $this->actingAs($this->admin);

    Livewire::test(ListPaymentOrders::class)
        ->assertCanSeeTableRecords([$moved, $late, $double, $plain])
        ->assertSee('offer_moved')
        ->assertSee('paid after cancel')
        ->assertSee('double discount')
        ->filterTable('offer_double_discount')
        ->assertCanSeeTableRecords([$double])
        ->assertCanNotSeeTableRecords([$moved, $late, $plain]);

    Livewire::test(ViewPaymentOrder::class, ['record' => $double->id])->assertSee('double discount')->assertSee('paid after cancel');

    $this->actingAs($this->teacher);
    Livewire::test(ListPaymentOrders::class)->assertForbidden();
    Livewire::test(ViewPaymentOrder::class, ['record' => $double->id])->assertForbidden();
});

it('shows the tier mismatch of an order as a badge with the live tier and filters it (admin only)', function (): void {
    $mismatch = PaymentOrder::factory()->create(['status' => PaymentOrder::STATUS_PAID, 'meta' => ['tier_mismatch' => true, 'tier_mismatch_live_tier' => 'center_m']]);
    $plain = PaymentOrder::factory()->create(['status' => PaymentOrder::STATUS_PAID, 'meta' => []]);

    $this->actingAs($this->admin);

    Livewire::test(ListPaymentOrders::class)
        ->assertCanSeeTableRecords([$mismatch, $plain])
        ->assertSee('tier mismatch (live: center_m)')
        ->filterTable('tier_mismatch')
        ->assertCanSeeTableRecords([$mismatch])
        ->assertCanNotSeeTableRecords([$plain]);

    Livewire::test(ViewPaymentOrder::class, ['record' => $mismatch->id])->assertSee('tier mismatch (live: center_m)');

    $this->actingAs($this->teacher);
    Livewire::test(ListPaymentOrders::class)->assertForbidden();
    Livewire::test(ViewPaymentOrder::class, ['record' => $mismatch->id])->assertForbidden();
});

// --- refund -----------------------------------------------------------------

it('refunds a Stars payment from the table: needs a note, calls the provider once, audits, ends the subscription', function (): void {
    $learner = Learner::factory()->create();
    $charge = adminPayCharge($this->stars, $learner);
    $this->actingAs($this->admin);

    Livewire::test(ListPayments::class)->callTableAction('refund', $charge, ['note' => ''])->assertHasTableActionErrors(['note' => 'required']);
    expect($this->stars->refunded)->toBe([])
        ->and(Payment::query()->where('kind', 'refund')->count())->toBe(0);

    $component = Livewire::test(ListPayments::class)
        ->callTableAction('refund', $charge, ['note' => 'Duplicate purchase, ticket #42'])
        ->assertHasNoTableActionErrors();

    $refunds = Payment::query()->where('kind', 'refund')->get();
    expect($this->stars->refunded)->toBe([$charge->id])
        ->and($refunds)->toHaveCount(1)
        ->and($refunds->sole()->refunded_payment_id)->toBe($charge->id)
        ->and($refunds->sole()->provider_event_id)->toBe('admin-refund:'.$charge->id)
        ->and($refunds->sole()->raw['note'])->toBe('Duplicate purchase, ticket #42')
        ->and($charge->refresh()->status)->toBe('refunded')
        ->and(Subscription::query()->sole()->status)->toBe('expired');

    $audit = AuditLog::query()->where('action', 'payment.refunded')->sole();
    expect($audit->user_id)->toBe($this->admin->id)
        ->and($audit->entity_id)->toBe($charge->id)
        ->and($audit->meta['note'])->toBe('Duplicate purchase, ticket #42')
        ->and($audit->meta['subscription_ended'])->toBeTrue()
        ->and($audit->ip)->not->toBeNull();

    // The refunded charge offers no second refund.
    $component->assertTableActionHidden('refund', $charge);
});

it('refunds from the payment page too', function (): void {
    $charge = adminPayCharge($this->stars);
    $this->actingAs($this->admin);

    Livewire::test(ViewPayment::class, ['record' => $charge->id])
        ->callAction('refund', ['note' => 'Customer request'])
        ->assertHasNoActionErrors();

    expect($this->stars->refunded)->toBe([$charge->id])
        ->and($charge->refresh()->status)->toBe('refunded');
});

it('does not refund twice: a stale second click or a second admin is rejected before any provider call', function (): void {
    $charge = adminPayCharge($this->stars);
    $stale = Payment::query()->findOrFail($charge->id);   // the second admin's page still shows "succeeded"
    $service = app(PaymentRefundService::class);

    $service->refund($charge, $this->admin, 'first');

    $second = User::factory()->create(['is_active' => true]);
    $second->assignRole('admin');

    foreach ([$charge, $stale] as $copy) {
        try {
            $service->refund($copy, $second, 'again');
            $this->fail('A second refund went through.');
        } catch (PaymentApplyException $e) {
            expect($e->errorCode)->toBe(PaymentApplyException::ALREADY_REFUNDED);
        }
    }

    expect($this->stars->refunded)->toBe([$charge->id])
        ->and(Payment::query()->where('kind', 'refund')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'payment.refunded')->count())->toBe(1);
});

it('refunds only a succeeded charge or renewal and only for an active admin', function (): void {
    $charge = adminPayCharge($this->stars);
    $failed = Payment::factory()->failed()->create();
    $service = app(PaymentRefundService::class);

    $refund = $service->refund($charge, $this->admin, 'ok');
    $code = function (Payment $payment, User $by) use ($service): string {
        try {
            $service->refund($payment, $by, 'x');
        } catch (PaymentRefundException|PaymentApplyException $e) {
            return $e->errorCode;
        }

        return 'refunded';
    };

    expect($code($failed, $this->admin))->toBe(PaymentRefundException::NOT_REFUNDABLE)
        ->and($code($refund, $this->admin))->toBe(PaymentRefundException::NOT_REFUNDABLE)
        ->and($code(adminPayCharge($this->stars), $this->teacher))->toBe(PaymentRefundException::FORBIDDEN)
        ->and($code(Payment::factory()->create(['provider_charge_id' => null]), $this->admin))->toBe(PaymentRefundException::NOT_REFUNDABLE)
        ->and($this->stars->refunded)->toBe([$charge->id]);

    $this->actingAs($this->admin);
    Livewire::test(ListPayments::class)
        ->assertTableActionHidden('refund', $failed)
        ->assertTableActionHidden('refund', $refund);
});

it('does not let a teacher refund through Livewire', function (): void {
    $charge = adminPayCharge($this->stars);
    $this->actingAs($this->teacher);

    Livewire::test(ListPayments::class)->assertForbidden();

    expect($this->stars->refunded)->toBe([])
        ->and($charge->refresh()->status)->toBe('succeeded')
        ->and(AuditLog::query()->count())->toBe(0);
});

it('asks to refund Payme in the cabinet first, then records the refund done by hand', function (): void {
    $charge = adminPayCharge($this->payme);
    $this->actingAs($this->admin);

    Livewire::test(ListPayments::class)->callTableAction('refund', $charge, ['note' => 'Refund via Payme cabinet']);

    // The adapter cannot refund: nothing changed.
    expect($charge->refresh()->status)->toBe('succeeded')
        ->and(Payment::query()->where('kind', 'refund')->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);

    Livewire::test(ListPayments::class)->callTableAction('refund', $charge, ['note' => 'Refund via Payme cabinet', 'returned_by_hand' => true]);

    $refund = Payment::query()->where('kind', 'refund')->sole();
    expect($charge->refresh()->status)->toBe('refunded')
        ->and($refund->raw['returned_by_hand'])->toBeTrue()
        ->and(AuditLog::query()->where('action', 'payment.refunded')->sole()->meta['note'])->toBe('Refund via Payme cabinet');
});

it('treats a provider that is not deployed as "refund by hand" and a provider outage as a failed refund', function (): void {
    $charge = adminPayCharge($this->stars);

    // 1) The adapter is gone from the registry (not part of this build).
    $registry = new BillingProviderRegistry;
    app()->instance(BillingProviderRegistry::class, $registry);
    $unbound = app(PaymentRefundService::class);
    expect(fn () => $unbound->refund($charge, $this->admin, 'x'))->toThrow(PaymentRefundException::class);
    expect($charge->refresh()->status)->toBe('succeeded');

    // 2) The adapter throws: the ledger is untouched and the failure is reported.
    $broken = Mockery::mock(BillingProvider::class);
    $broken->shouldReceive('key')->andReturn(SubscriptionProvider::TelegramStars);
    $broken->shouldReceive('refund')->once()->andThrow(new RuntimeException('Bot API down'));
    $registry->register($broken);
    $failing = app(PaymentRefundService::class);

    try {
        $failing->refund($charge, $this->admin, 'x');
        $this->fail('The outage was swallowed.');
    } catch (PaymentRefundException $e) {
        expect($e->errorCode)->toBe(PaymentRefundException::PROVIDER_REFUND_FAILED);
    }

    expect($charge->refresh()->status)->toBe('succeeded')
        ->and(Payment::query()->where('kind', 'refund')->count())->toBe(0);
});
