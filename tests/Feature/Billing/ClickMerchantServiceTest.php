<?php

declare(strict_types=1);

use App\Domain\Billing\Providers\Click\ClickAmount;
use App\Domain\Billing\Providers\Click\ClickException;
use App\Domain\Billing\Providers\Click\ClickMerchantService;
use App\Domain\Billing\Providers\Click\ClickRequest;
use App\Domain\Billing\Providers\Click\ClickSignature;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\ProviderTransaction;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\ClickFixtures;

/*
 * ClickMerchantService (docs/13 §4.3): the signature, the request shape, the Click error codes and
 * the Prepare / Complete state machine of `provider_transactions` — every answer is the body Click
 * reads (`error`, `error_note`, `merchant_prepare_id` / `merchant_confirm_id`).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC'));
    ClickFixtures::configure();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function clickService(): ClickMerchantService
{
    return app(ClickMerchantService::class);
}

/** Prepares `$order` and returns `merchant_prepare_id`. */
function clickPrepared(PaymentOrder $order, string $clickTransId = ClickFixtures::CLICK_TRANS_ID): int
{
    $response = clickService()->prepare(ClickFixtures::prepare($order, clickTransId: $clickTransId));
    if ($response['error'] !== 0) {
        throw new RuntimeException('Prepare failed: '.json_encode($response));
    }

    return (int) $response['merchant_prepare_id'];
}

// --- signature -----------------------------------------------------------------------------------

it('signs the fixtures exactly like ClickSignature (Prepare without, Complete with merchant_prepare_id) and verifies with hash_equals', function (): void {
    $order = PaymentOrder::factory()->click()->create();
    $signature = new ClickSignature(ClickFixtures::SECRET, ClickFixtures::SERVICE_ID);

    $prepare = ClickFixtures::prepare($order);
    $complete = ClickFixtures::complete($order, 17);

    expect($prepare['sign_string'])->toBe(md5(ClickFixtures::CLICK_TRANS_ID.ClickFixtures::SERVICE_ID.ClickFixtures::SECRET.$order->token.'29000.00'.'0'.'2026-10-19 12:00:00'))
        ->and($complete['sign_string'])->toBe(md5(ClickFixtures::CLICK_TRANS_ID.ClickFixtures::SERVICE_ID.ClickFixtures::SECRET.$order->token.'17'.'29000.00'.'1'.'2026-10-19 12:00:00'))
        ->and($signature->verify(ClickRequest::parse($prepare, 0)))->toBeTrue()
        ->and($signature->verify(ClickRequest::parse($complete, 1)))->toBeTrue()
        ->and($signature->verify(ClickRequest::parse([...$prepare, 'sign_string' => strtoupper($prepare['sign_string'])], 0)))->toBeTrue()
        ->and($signature->verify(ClickRequest::parse([...$complete, 'merchant_prepare_id' => '18'], 1)))->toBeFalse()
        ->and($signature->verify(ClickRequest::parse([...$prepare, 'service_id' => '12346'], 0)))->toBeFalse()
        ->and((new ClickSignature('', ClickFixtures::SERVICE_ID))->verify(ClickRequest::parse($prepare, 0)))->toBeFalse()
        ->and((new ClickSignature(ClickFixtures::SECRET, ''))->verify(ClickRequest::parse($prepare, 0)))->toBeFalse();
});

it('answers -1 to a wrong signature, a wrong secret, a foreign service_id or no configured secret, without touching the database', function (): void {
    Log::spy();
    $order = PaymentOrder::factory()->click()->create();

    $cases = [
        'tampered' => ClickFixtures::prepare($order, after: ['sign_string' => md5('x')]),
        'other secret' => ClickFixtures::prepare($order, after: ['sign_string' => ClickFixtures::sign(ClickFixtures::prepare($order), 'another-secret')]),
        'amount changed after signing' => ClickFixtures::prepare($order, after: ['amount' => '1.00']),
        'foreign service_id' => ClickFixtures::prepare($order, ['service_id' => '99999']),
        'not a digest' => ClickFixtures::prepare($order, after: ['sign_string' => 'nope']),
    ];
    foreach ($cases as $name => $form) {
        $response = clickService()->prepare($form);
        expect($response['error'])->toBe(-1, $name)
            ->and($response['error_note'])->toBe('SIGN CHECK FAILED!')
            ->and($response['merchant_prepare_id'])->toBeNull()
            ->and($response['click_trans_id'])->toBe(1234567890)
            ->and($response['merchant_trans_id'])->toBe($order->token);
    }

    config()->set('billing.providers.click.secret_key', '');
    expect(clickService()->prepare(ClickFixtures::prepare($order))['error'])->toBe(-1);

    expect(ProviderTransaction::query()->count())->toBe(0);
    Log::shouldHaveReceived('warning')->with('billing.click.sign_failed', Mockery::type('array'))->times(6);
});

// --- request shape -------------------------------------------------------------------------------

it('answers -8 to a missing or malformed parameter and -3 to the wrong action, before the signature', function (): void {
    $order = PaymentOrder::factory()->click()->create();
    $prepare = ClickFixtures::prepare($order);

    foreach (['click_trans_id', 'service_id', 'merchant_trans_id', 'amount', 'action', 'sign_time'] as $field) {
        $copy = $prepare;
        unset($copy[$field]);
        expect(clickService()->prepare($copy)['error'])->toBe(-8, "missing {$field}");
    }
    expect(clickService()->prepare(ClickFixtures::prepare($order, after: ['sign_string' => null]))['error'])->toBe(-8)
        ->and(clickService()->prepare(ClickFixtures::prepare($order, ['sign_time' => '19.10.2026 12:00']))['error'])->toBe(-8)
        ->and(clickService()->prepare(ClickFixtures::prepare($order, ['action' => 'x']))['error'])->toBe(-8)
        ->and(clickService()->prepare(ClickFixtures::prepare($order, ['click_trans_id' => 'a b']))['error'])->toBe(-8)
        ->and(clickService()->prepare(ClickFixtures::prepare($order, ['error' => 'none']))['error'])->toBe(-8)
        ->and(clickService()->prepare(['click_trans_id' => ['nested']])['error'])->toBe(-8)
        ->and(clickService()->prepare([]))->toBe(['click_trans_id' => null, 'merchant_trans_id' => null, 'merchant_prepare_id' => null, 'error' => -8, 'error_note' => 'Error in request from click']);

    // The other endpoint's action (signed correctly for that action).
    expect(clickService()->prepare(ClickFixtures::prepare($order, ['action' => '1']))['error'])->toBe(-3)
        ->and(clickService()->complete(ClickFixtures::complete($order, 1, ['action' => '0']))['error'])->toBe(-3)
        ->and(clickService()->complete(ClickFixtures::complete($order, 1, after: ['merchant_prepare_id' => null]))['error'])->toBe(-8)
        ->and(clickService()->complete(ClickFixtures::complete($order, 'abc'))['error'])->toBe(-8);

    expect(ProviderTransaction::query()->count())->toBe(0);
});

// --- Prepare -------------------------------------------------------------------------------------

it('prepares a learner order: state 1, merchant_prepare_id = provider_transactions.id, raw without sign_string; a repeat returns the same id', function (): void {
    $order = PaymentOrder::factory()->click()->create();
    $form = ClickFixtures::prepare($order);

    $response = clickService()->prepare($form);
    $transaction = ProviderTransaction::query()->sole();

    expect($response)->toBe([
        'click_trans_id' => 1234567890,
        'merchant_trans_id' => $order->token,
        'merchant_prepare_id' => $transaction->id,
        'error' => 0,
        'error_note' => 'Success',
    ])
        ->and($transaction->provider)->toBe('click')
        ->and($transaction->provider_transaction_id)->toBe('1234567890')
        ->and($transaction->payment_order_id)->toBe($order->id)
        ->and($transaction->state)->toBe(1)
        ->and($transaction->amount)->toBe(2_900_000)
        ->and($transaction->raw['method'])->toBe('prepare')
        ->and($transaction->raw['params'])->not->toHaveKey('sign_string')
        ->and($transaction->raw['params']['click_paydoc_id'])->toBe('9876543210')
        ->and($transaction->history)->toHaveCount(1)
        ->and($order->refresh()->status)->toBe('pending');

    // Same click_trans_id again (Click retried): the same answer, one row.
    expect(clickService()->prepare(ClickFixtures::prepare($order, ['sign_time' => '2026-10-19 12:00:30'])))->toBe($response)
        ->and(ProviderTransaction::query()->count())->toBe(1);

    // A payer retrying with a new click_trans_id is not blocked by the stale open one (Click has no timeout).
    $second = clickService()->prepare(ClickFixtures::prepare($order, clickTransId: '1234567891'));
    expect($second['error'])->toBe(0)
        ->and($second['merchant_prepare_id'])->not->toBe($transaction->id)
        ->and(ProviderTransaction::query()->where('state', 1)->count())->toBe(2);
});

it('refuses Prepare for an unknown, foreign-provider, paid, expired, canceled or failed order with -5 / -4 / -9', function (): void {
    $service = clickService();
    $code = static fn (PaymentOrder $order, array $overrides = []): int => $service->prepare(ClickFixtures::prepare($order, $overrides))['error'];

    $ghost = PaymentOrder::factory()->click()->make(['token' => str_repeat('Z', 32)]);
    expect($code($ghost))->toBe(-5)
        ->and($code(PaymentOrder::factory()->payme()->create()))->toBe(-5)
        ->and($code(PaymentOrder::factory()->create(['currency' => 'UZS', 'amount' => 2_900_000])))->toBe(-5)
        ->and($code(PaymentOrder::factory()->click()->paid()->create()))->toBe(-4)
        ->and($code(PaymentOrder::factory()->click()->expired()->create()))->toBe(-9)
        ->and($code(PaymentOrder::factory()->click()->create(['expires_at' => CarbonImmutable::now()->subMinute()])))->toBe(-9)
        ->and($code(PaymentOrder::factory()->click()->create(['status' => PaymentOrder::STATUS_CANCELED])))->toBe(-9)
        ->and($code(PaymentOrder::factory()->click()->create(['status' => PaymentOrder::STATUS_FAILED])))->toBe(-9)
        ->and(ProviderTransaction::query()->count())->toBe(0);

    // A token longer than 32 chars can never be an order.
    expect($code(PaymentOrder::factory()->click()->make(['token' => str_repeat('a', 40)])))->toBe(-5);
});

it('answers -2 to an amount that differs from the order or is not a decimal, and converts kopecks to tiyin exactly', function (): void {
    $order = PaymentOrder::factory()->click()->create(['amount' => 2_900_050, 'list_amount' => 2_900_050]);

    foreach (['29000.00', '29000.49', '29000.51', '29000.500001', 'abc', '-29000.50', '29000,50', '1e4'] as $amount) {
        expect(clickService()->prepare(ClickFixtures::prepare($order, ['amount' => $amount]))['error'])->toBe(-2, $amount);
    }
    expect(ProviderTransaction::query()->count())->toBe(0);

    // Value equality, not spelling: '29000.5' and '29000.500' are the same tiyin (the signature covers the exact string anyway).
    foreach (['29000.50', '29000.5', '29000.500', '29000.5000'] as $amount) {
        expect(clickService()->prepare(ClickFixtures::prepare($order, ['amount' => $amount]))['error'])->toBe(0, $amount);
    }
    expect(ProviderTransaction::query()->sole()->amount)->toBe(2_900_050);

    // The parser itself: string arithmetic, no float rounding.
    expect(ClickAmount::toTiyin('29000.50'))->toBe(2_900_050)
        ->and(ClickAmount::toTiyin('0.1'))->toBe(10)
        ->and(ClickAmount::toTiyin('0.07'))->toBe(7)
        ->and(ClickAmount::toTiyin('1.10'))->toBe(110)
        ->and(ClickAmount::toTiyin('4.35'))->toBe(435)
        ->and(ClickAmount::toTiyin('29000'))->toBe(2_900_000)
        ->and(ClickAmount::toTiyin(' 29000.00 '))->toBe(2_900_000)
        ->and(ClickAmount::toTiyin('123456789012345.99'))->toBe(12_345_678_901_234_599)
        ->and(ClickAmount::toTiyin('29000.001'))->toBeNull()
        ->and(ClickAmount::toTiyin('29000.'))->toBeNull()
        ->and(ClickAmount::toTiyin('.5'))->toBeNull()
        ->and(ClickAmount::toTiyin(''))->toBeNull()
        ->and(ClickAmount::format(2_900_050))->toBe('29000.50')
        ->and(ClickAmount::format(7))->toBe('0.07')
        ->and(ClickAmount::format(100))->toBe('1.00');
});

// --- Complete ------------------------------------------------------------------------------------

it('completes a prepared learner order: one payment, Pro for 30 days, order paid, state 2, merchant_confirm_id = payments.id; a repeat answers identically', function (): void {
    $learner = Learner::factory()->create();
    $order = PaymentOrder::factory()->click()->create(['subscriber_id' => $learner->id]);
    $prepareId = clickPrepared($order);

    $form = ClickFixtures::complete($order, $prepareId);
    $response = clickService()->complete($form);

    $payment = Payment::query()->sole();
    $transaction = ProviderTransaction::query()->sole();
    $subscription = Subscription::query()->sole();

    expect($response)->toBe([
        'click_trans_id' => 1234567890,
        'merchant_trans_id' => $order->token,
        'merchant_confirm_id' => $payment->id,
        'error' => 0,
        'error_note' => 'Success',
    ])
        ->and($payment->provider)->toBe('click')
        ->and($payment->provider_event_id)->toBe('1234567890:complete')
        ->and($payment->provider_charge_id)->toBe('1234567890')
        ->and($payment->provider_transaction_id)->toBe($transaction->id)
        ->and($payment->status)->toBe('succeeded')
        ->and($payment->kind)->toBe('charge')
        ->and($payment->amount)->toBe(2_900_000)
        ->and($payment->currency)->toBe('UZS')
        ->and($payment->raw)->not->toHaveKey('sign_string')
        ->and($transaction->state)->toBe(2)
        ->and($transaction->perform_time)->toBe(CarbonImmutable::now()->getTimestampMs())
        ->and($transaction->raw['method'])->toBe('complete')
        ->and($transaction->history)->toHaveCount(2)
        ->and($order->refresh()->status)->toBe('paid')
        ->and($order->subscription_id)->toBe($subscription->id)
        ->and($subscription->provider)->toBe('click')
        ->and($subscription->status)->toBe('active')
        ->and($subscription->subscriber_id)->toBe($learner->id)
        ->and(CarbonImmutable::instance($subscription->current_period_ends_at)->toIso8601String())->toBe(CarbonImmutable::now()->addDays(30)->toIso8601String());

    // Delivered again (Click retried): the same body, nothing more in the ledger.
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(5));
    $repeat = clickService()->complete(ClickFixtures::complete($order, $prepareId));
    expect($repeat)->toBe($response)
        ->and($repeat['error'])->toBe(ClickMerchantService::REPEATED_COMPLETE_ERROR)
        ->and(Payment::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and(ProviderTransaction::query()->sole()->perform_time)->toBe($transaction->perform_time);

    // A new Prepare for the paid order: -4.
    expect(clickService()->prepare(ClickFixtures::prepare($order, clickTransId: '555'))['error'])->toBe(-4);
});

it('completes an organization order into an organization subscription with the tier of the order', function (): void {
    $organization = Organization::factory()->create();
    $order = PaymentOrder::factory()->forOrganization($organization, 'center_s')->create(['provider' => 'click', 'amount' => 34_900_000, 'list_amount' => 34_900_000]);
    $prepareId = clickPrepared($order);

    expect(clickService()->complete(ClickFixtures::complete($order, $prepareId))['error'])->toBe(0);

    $subscription = Subscription::query()->sole();
    expect($subscription->subscriber_type)->toBe('organization')
        ->and($subscription->subscriber_id)->toBe($organization->id)
        ->and($subscription->tier)->toBe('center_s')
        ->and(Payment::query()->sole()->subscriber_type)->toBe('organization');
});

it('answers -6 to a Complete without Prepare, with a wrong merchant_prepare_id or for another order', function (): void {
    $order = PaymentOrder::factory()->click()->create();
    expect(clickService()->complete(ClickFixtures::complete($order, 1))['error'])->toBe(-6)
        ->and(clickService()->complete(ClickFixtures::complete($order, 1))['merchant_confirm_id'])->toBeNull();

    $prepareId = clickPrepared($order);
    $other = PaymentOrder::factory()->click()->create();

    expect(clickService()->complete(ClickFixtures::complete($order, $prepareId + 1))['error'])->toBe(-6)
        ->and(clickService()->complete(ClickFixtures::complete($other, $prepareId))['error'])->toBe(-6)
        ->and(clickService()->complete(ClickFixtures::complete($order, $prepareId, clickTransId: '42'))['error'])->toBe(-6)
        ->and(Payment::query()->count())->toBe(0)
        ->and(ProviderTransaction::query()->sole()->state)->toBe(1)
        ->and($order->refresh()->status)->toBe('pending');
});

it('cancels only the transaction on a Complete with error < 0: state -1 with the Click code, the order stays pending, answer -9; repeats answer -9; the same click_trans_id stays -9', function (): void {
    Log::spy();
    $order = PaymentOrder::factory()->click()->create();
    $prepareId = clickPrepared($order);

    $response = clickService()->complete(ClickFixtures::complete($order, $prepareId, ['error' => '-5017', 'error_note' => 'Insufficient funds']));
    $transaction = ProviderTransaction::query()->sole();

    expect($response['error'])->toBe(-9)
        ->and($response['error_note'])->toBe('Transaction cancelled')
        ->and($response['merchant_confirm_id'])->toBeNull()
        ->and($transaction->state)->toBe(-1)
        ->and($transaction->cancel_reason)->toBe(-5017)
        ->and($transaction->canceled_at?->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String())
        ->and($transaction->cancel_time)->toBe(CarbonImmutable::now()->getTimestampMs())
        ->and($transaction->raw['params']['error_note'])->toBe('Insufficient funds')
        ->and($transaction->history)->toHaveCount(2)
        ->and($order->refresh()->status)->toBe('pending')
        ->and(Payment::query()->count())->toBe(0)
        ->and(Subscription::query()->count())->toBe(0);
    Log::shouldHaveReceived('info')->with('billing.click.canceled', Mockery::type('array'))->once();

    // Delivered again, and a successful Complete after the cancel: still -9, nothing credited.
    expect(clickService()->complete(ClickFixtures::complete($order, $prepareId, ['error' => '-5017']))['error'])->toBe(-9)
        ->and(clickService()->complete(ClickFixtures::complete($order, $prepareId))['error'])->toBe(-9)
        ->and(Payment::query()->count())->toBe(0)
        ->and(ProviderTransaction::query()->sole()->cancel_reason)->toBe(-5017);

    // The same click_trans_id prepared again: -9; a new attempt on the still-pending order is allowed.
    expect(clickService()->prepare(ClickFixtures::prepare($order))['error'])->toBe(-9)
        ->and(clickService()->prepare(ClickFixtures::prepare($order, clickTransId: '777'))['error'])->toBe(0)
        ->and(ProviderTransaction::query()->count())->toBe(2);
});

it('H3: a failed attempt (Complete error < 0 on 111) does not close the order — the successful Complete on 222 is credited', function (): void {
    $order = PaymentOrder::factory()->click()->create();
    $first = clickPrepared($order, '111');
    $second = clickPrepared($order, '222');

    expect(clickService()->complete(ClickFixtures::complete($order, $first, ['error' => '-5017'], clickTransId: '111'))['error'])->toBe(-9)
        ->and($order->refresh()->status)->toBe('pending');

    $response = clickService()->complete(ClickFixtures::complete($order, $second, clickTransId: '222'));

    expect($response['error'])->toBe(0)
        ->and($response['merchant_confirm_id'])->toBe(Payment::query()->sole()->id)
        ->and(Payment::query()->where('status', 'failed')->count())->toBe(0)
        ->and($order->refresh()->status)->toBe('paid')
        ->and(ProviderTransaction::query()->where('provider_transaction_id', '111')->sole()->state)->toBe(-1)
        ->and(ProviderTransaction::query()->where('provider_transaction_id', '222')->sole()->state)->toBe(2);
});

it('does not undo a completed transaction when Click later reports error < 0: answers -4 and keeps the payment', function (): void {
    Log::spy();
    $order = PaymentOrder::factory()->click()->create();
    $prepareId = clickPrepared($order);
    clickService()->complete(ClickFixtures::complete($order, $prepareId));

    $response = clickService()->complete(ClickFixtures::complete($order, $prepareId, ['error' => '-1']));

    expect($response['error'])->toBe(-4)
        ->and(ProviderTransaction::query()->sole()->state)->toBe(2)
        ->and(Payment::query()->sole()->status)->toBe('succeeded')
        ->and($order->refresh()->status)->toBe('paid');
    Log::shouldHaveReceived('warning')->with('billing.click.cancel_after_complete', Mockery::type('array'))->once();
});

it('answers -2 to a Complete whose amount differs from the prepared one, without crediting', function (): void {
    $order = PaymentOrder::factory()->click()->create();
    $prepareId = clickPrepared($order);

    expect(clickService()->complete(ClickFixtures::complete($order, $prepareId, ['amount' => '29001.00']))['error'])->toBe(-2)
        ->and(clickService()->complete(ClickFixtures::complete($order, $prepareId, ['amount' => 'x']))['error'])->toBe(-2)
        ->and(Payment::query()->count())->toBe(0)
        ->and(ProviderTransaction::query()->sole()->state)->toBe(1);
});

it('records a failed ledger row and answers -9 when the order died between Prepare and Complete; the transaction stays prepared and repeats answer alike', function (): void {
    Log::spy();
    $order = PaymentOrder::factory()->click()->create();
    $prepareId = clickPrepared($order);
    $order->update(['status' => PaymentOrder::STATUS_EXPIRED]);

    $first = clickService()->complete(ClickFixtures::complete($order, $prepareId));
    $second = clickService()->complete(ClickFixtures::complete($order, $prepareId));

    expect($first['error'])->toBe(-9)
        ->and($second)->toBe($first)
        ->and(Payment::query()->count())->toBe(1)
        ->and(Payment::query()->sole()->status)->toBe('failed')
        ->and(Payment::query()->sole()->raw['failure_reason'])->toBe('order_expired')
        ->and(Subscription::query()->count())->toBe(0)
        ->and(ProviderTransaction::query()->sole()->state)->toBe(1);
    Log::shouldHaveReceived('warning')->with('billing.click.complete_refused', Mockery::type('array'))->twice();
});

it('answers -4 with a failed ledger row when a second prepared transaction completes an order the first one already paid', function (): void {
    $order = PaymentOrder::factory()->click()->create();
    $first = clickPrepared($order, '1000');
    $second = clickPrepared($order, '2000');

    expect(clickService()->complete(ClickFixtures::complete($order, $first, clickTransId: '1000'))['error'])->toBe(0);
    $response = clickService()->complete(ClickFixtures::complete($order, $second, clickTransId: '2000'));

    expect($response['error'])->toBe(-4)
        ->and(Payment::query()->where('status', 'succeeded')->count())->toBe(1)
        ->and(Payment::query()->where('status', 'failed')->sole()->raw['failure_reason'])->toBe('order_already_paid')
        ->and(Subscription::query()->count())->toBe(1)
        ->and(ProviderTransaction::query()->where('provider_transaction_id', '2000')->sole()->state)->toBe(1);
});

it('keeps the code table of docs/13 §4.3 and an error note for every code', function (): void {
    expect(ClickException::SIGN_CHECK_FAILED)->toBe(-1)
        ->and(ClickException::INCORRECT_AMOUNT)->toBe(-2)
        ->and(ClickException::ACTION_NOT_FOUND)->toBe(-3)
        ->and(ClickException::ALREADY_PAID)->toBe(-4)
        ->and(ClickException::ORDER_NOT_FOUND)->toBe(-5)
        ->and(ClickException::TRANSACTION_NOT_FOUND)->toBe(-6)
        ->and(ClickException::UPDATE_FAILED)->toBe(-7)
        ->and(ClickException::REQUEST_ERROR)->toBe(-8)
        ->and(ClickException::TRANSACTION_CANCELED)->toBe(-9)
        ->and(array_keys(ClickException::NOTES))->toBe([0, -1, -2, -3, -4, -5, -6, -7, -8, -9])
        ->and(ClickException::note(-42))->toBe(ClickException::NOTES[-7])
        ->and((new ClickException(-2, 'why'))->getMessage())->toBe('Click error -2: Incorrect parameter amount (why)');
});
