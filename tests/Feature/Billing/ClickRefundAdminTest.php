<?php

declare(strict_types=1);

use App\Domain\Billing\Exceptions\PaymentApplyException;
use App\Domain\Billing\Exceptions\PaymentRefundException;
use App\Domain\Billing\Providers\Click\ClickMerchantService;
use App\Domain\Billing\Services\PaymentRefundService;
use App\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Filament\Resources\PaymentResource\Pages\ViewPayment;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ClickFixtures;

/*
 * Manual refund of a Click payment in the admin panel (docs/13 §4.3): Click sends no refund
 * notification and cannot be refunded by us, so the superadmin returns the money in the Click
 * cabinet and records it here with "returned by hand" — note required, audited, once.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC'));
    ClickFixtures::configure();
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('admin');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** A Click order paid through the real Prepare -> Complete flow; returns the succeeded charge. */
function clickPaidCharge(?Learner $learner = null): Payment
{
    $order = PaymentOrder::factory()->click()->create($learner === null ? [] : ['subscriber_id' => $learner->id]);
    $service = app(ClickMerchantService::class);
    $prepare = $service->prepare(ClickFixtures::prepare($order));
    $complete = $service->complete(ClickFixtures::complete($order, (int) $prepare['merchant_prepare_id']));
    if ($complete['error'] !== 0) {
        throw new RuntimeException('Complete failed: '.json_encode($complete));
    }

    return Payment::query()->findOrFail($complete['merchant_confirm_id']);
}

it('records a Click refund only as "returned by hand": note required, audited with the note and IP, subscription ended, once', function (): void {
    $learner = Learner::factory()->create();
    $charge = clickPaidCharge($learner);
    expect(Subscription::query()->sole()->status)->toBe('active');
    $this->actingAs($this->admin);

    // Note is required; the toggle alone changes nothing.
    Livewire::test(ListPayments::class)->callTableAction('refund', $charge, ['note' => '', 'returned_by_hand' => true])->assertHasTableActionErrors(['note' => 'required']);
    expect(Payment::query()->where('kind', 'refund')->count())->toBe(0);

    // Without the toggle Click cannot be called: refused, nothing changes.
    Livewire::test(ListPayments::class)
        ->callTableAction('refund', $charge, ['note' => 'Customer asked', 'returned_by_hand' => false])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Возврат не выполнен');
    expect(Payment::query()->where('kind', 'refund')->count())->toBe(0)
        ->and($charge->refresh()->status)->toBe('succeeded')
        ->and(Subscription::query()->sole()->status)->toBe('active');

    // Returned in the Click cabinet, recorded here.
    $component = Livewire::test(ListPayments::class)
        ->callTableAction('refund', $charge, ['note' => 'Returned in Click cabinet, ticket #7', 'returned_by_hand' => true])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Платёж возвращён');

    $refund = Payment::query()->where('kind', 'refund')->sole();
    expect($refund->provider)->toBe('click')
        ->and($refund->provider_event_id)->toBe('admin-refund:'.$charge->id)
        ->and($refund->provider_charge_id)->toBe('1234567890')
        ->and($refund->refunded_payment_id)->toBe($charge->id)
        ->and($refund->amount)->toBe(2_900_000)
        ->and($refund->raw['returned_by_hand'])->toBeTrue()
        ->and($refund->raw['note'])->toBe('Returned in Click cabinet, ticket #7')
        ->and($charge->refresh()->status)->toBe('refunded')
        ->and(Subscription::query()->sole()->status)->toBe('expired')
        ->and($learner->refresh()->subscriptions()->whereIn('status', ['active', 'past_due', 'canceled'])->count())->toBe(0);

    $audit = AuditLog::query()->where('action', 'payment.refunded')->sole();
    expect($audit->user_id)->toBe($this->admin->id)
        ->and($audit->entity_id)->toBe($charge->id)
        ->and($audit->meta['note'])->toBe('Returned in Click cabinet, ticket #7')
        ->and($audit->meta['provider'])->toBe('click')
        ->and($audit->meta['subscription_ended'])->toBeTrue()
        ->and($audit->ip)->not->toBeNull();

    // Once: the button is gone, a second attempt is `already_refunded`, no second row.
    $component->assertTableActionHidden('refund', $charge);
    try {
        app(PaymentRefundService::class)->refund(Payment::query()->findOrFail($charge->id), $this->admin, 'again', true);
        $this->fail('A second refund went through.');
    } catch (PaymentApplyException $e) {
        expect($e->errorCode)->toBe(PaymentApplyException::ALREADY_REFUNDED);
    }
    expect(Payment::query()->where('kind', 'refund')->count())->toBe(1);
});

it('never pre-selects "returned by hand" for a Click payment: the admin must switch it on explicitly, from the payment page too', function (): void {
    $charge = clickPaidCharge();
    $this->actingAs($this->admin);

    Livewire::test(ViewPayment::class, ['record' => $charge->id])
        ->assertOk()
        ->callAction('refund', ['note' => ''])
        ->assertHasActionErrors(['note' => 'required']);

    // No explicit toggle value: the default is off, so the adapter is asked, refuses, and nothing changes.
    Livewire::test(ViewPayment::class, ['record' => $charge->id])
        ->callAction('refund', ['note' => 'Returned in Click cabinet'])
        ->assertHasNoActionErrors()
        ->assertNotified('Возврат не выполнен');
    expect($charge->refresh()->status)->toBe('succeeded')
        ->and(Payment::query()->where('kind', 'refund')->count())->toBe(0);

    // Switched on by hand: the local record is made.
    Livewire::test(ViewPayment::class, ['record' => $charge->id])
        ->callAction('refund', ['note' => 'Returned in Click cabinet', 'returned_by_hand' => true])
        ->assertHasNoActionErrors()
        ->assertNotified('Платёж возвращён');

    expect($charge->refresh()->status)->toBe('refunded')
        ->and(Payment::query()->where('kind', 'refund')->sole()->raw['returned_by_hand'])->toBeTrue();
});

it('reports provider_cannot_refund from the service when the Click adapter is asked to move money', function (): void {
    $charge = clickPaidCharge();

    try {
        app(PaymentRefundService::class)->refund($charge, $this->admin, 'no toggle');
        $this->fail('Click refunded by itself.');
    } catch (PaymentRefundException $e) {
        expect($e->errorCode)->toBe(PaymentRefundException::PROVIDER_CANNOT_REFUND);
    }
    expect($charge->refresh()->status)->toBe('succeeded');
});
