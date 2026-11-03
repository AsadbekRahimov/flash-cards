<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\PaymentApplier;
use App\Domain\Billing\Support\PayerSnapshot;
use App\Domain\Learners\Services\LearnerPurgeService;
use App\Filament\Resources\PaymentOrderResource\Pages\ListPaymentOrders;
use App\Filament\Resources\PaymentOrderResource\Pages\ViewPaymentOrder;
use App\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Filament\Resources\PaymentResource\Pages\ViewPayment;
use App\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use App\Filament\Resources\Support\BillingDisplay;
use App\Models\Learner;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FakeBillingProvider;

/*
 * The billing screens of the admin for a payer whose learner row is deleted for good (docs/03 §8 rule 2): the payment,
 * the order and the subscription still open and still say who paid — from the payer snapshot.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-03 12:00:00', 'UTC'));
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('admin');
    $this->stars = FakeBillingProvider::register(SubscriptionProvider::TelegramStars);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('shows the payer of a deleted learner on the payments, orders and subscriptions screens', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 555_001, 'first_name' => 'Zinaida', 'last_name' => null, 'username' => 'zina']);
    $order = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa');
    $payment = app(PaymentApplier::class)->applyCharge($this->stars->emitCharge($order));
    $learner->delete();
    Learner::withTrashed()->whereKey($learner->id)->update(['deleted_at' => CarbonImmutable::now()->subDays(31)]);
    expect(app(LearnerPurgeService::class)->purge($learner->id))->toBeTrue();
    $this->actingAs($this->admin);

    Livewire::test(ListPayments::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$payment])
        ->assertSee('Zinaida @zina · tg:555001')
        ->assertSee('(удалён)')
        ->assertSee('150 XTR');
    Livewire::test(ViewPayment::class, ['record' => $payment->id])
        ->assertOk()
        ->assertSee('Zinaida @zina · tg:555001')
        ->assertSee('(удалён)');
    Livewire::test(ListPaymentOrders::class)
        ->assertOk()
        ->assertCanSeeTableRecords([PaymentOrder::query()->findOrFail($order->id)])
        ->assertSee('Zinaida @zina · tg:555001');
    Livewire::test(ViewPaymentOrder::class, ['record' => $order->id])
        ->assertOk()
        ->assertSee('Zinaida @zina · tg:555001');
    Livewire::test(ListSubscriptions::class)
        ->assertOk()
        ->assertSee('#'.$learner->id.' (удалён)');
});

it('labels a gone subscriber by the snapshot when there is one and by the type otherwise', function (): void {
    $payment = Payment::factory()->make(['subscriber_id' => 77, 'payer_telegram_user_id' => 1001, 'payer_name' => 'Gone Payer', 'payer_username' => null]);
    $bare = PaymentOrder::factory()->make(['subscriber_id' => 78, 'payer_telegram_user_id' => null, 'payer_name' => null, 'payer_username' => null]);

    expect(BillingDisplay::subscriber('learner', 77, null, PayerSnapshot::fromRecord($payment)))->toBe('Gone Payer · tg:1001 #77 (удалён)')
        ->and(BillingDisplay::subscriber('learner', 78, null, PayerSnapshot::fromRecord($bare)))->toBe('Ученик #78 (удалён)')
        ->and(BillingDisplay::subscriber('learner', 78, null))->toBe('Ученик #78 (удалён)');
});
