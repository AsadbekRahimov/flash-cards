<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\PaymentApplyException;
use App\Domain\Billing\Providers\TelegramStarsProvider;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\PaymentApplier;
use App\Domain\Billing\Support\PayerSnapshot;
use App\Domain\Learners\Services\LearnerPurgeService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentOrder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeBillingProvider;
use Tests\Support\FakeTelegramClient;

/*
 * The payer snapshot of orders and ledger rows (docs/03 §2.27, §2.29; docs/07 §10): who paid, as they were at the
 * time — the only thing that still names the payer once the learner is deleted for good (docs/03 §8 rule 2).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-03 12:00:00', 'UTC'));
    $this->stars = FakeBillingProvider::register(SubscriptionProvider::TelegramStars);
    $this->payme = FakeBillingProvider::register(SubscriptionProvider::Payme);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** Deletes the learner for good right now (soft-delete dated past the retention). */
function snapshotPurge(Learner $learner): void
{
    $learner->delete();
    Learner::withTrashed()->whereKey($learner->id)->update(['deleted_at' => CarbonImmutable::now()->subDays(31)]);
    expect(app(LearnerPurgeService::class)->purge($learner->id))->toBeTrue();
}

it('writes the payer into the order at checkout: a learner with Telegram id, name and username, an organization by name', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 900_001, 'first_name' => 'Anvar', 'last_name' => null, 'username' => 'anvar_uz']);
    $organization = Organization::factory()->create(['name' => 'Smart Center']);

    $personal = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa');
    $corporate = app(CheckoutService::class)->create($organization, Plan::Pro, 'month', SubscriptionProvider::Payme, 'tutor', 'org_panel');

    expect($personal->payer_telegram_user_id)->toBe(900_001)
        ->and($personal->payer_name)->toBe('Anvar')
        ->and($personal->payer_username)->toBe('anvar_uz')
        ->and($corporate->payer_telegram_user_id)->toBeNull()
        ->and($corporate->payer_name)->toBe('Smart Center')
        ->and($corporate->payer_username)->toBeNull();
});

it('copies the payer from the live learner row into every ledger row, as of the payment', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 900_002, 'first_name' => 'Dina', 'last_name' => 'A', 'username' => null]);
    $order = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa');

    $charge = app(PaymentApplier::class)->applyCharge($this->stars->emitCharge($order));
    // The learner renames themself before the renewal: the renewal records the new name, the first charge keeps the old one.
    $learner->update(['first_name' => 'Madina', 'username' => 'madina']);
    $renewal = app(PaymentApplier::class)->applyCharge($this->stars->emitCharge($order, renewal: true, periodEndsAt: CarbonImmutable::now()->addDays(60)));

    expect($charge->payer_telegram_user_id)->toBe(900_002)
        ->and($charge->payer_name)->toBe('Dina A')
        ->and($charge->payer_username)->toBeNull()
        ->and($renewal->payer_name)->toBe('Madina A')
        ->and($renewal->payer_username)->toBe('madina')
        ->and(Payment::query()->findOrFail($charge->id)->payer_name)->toBe('Dina A');
});

it('keeps naming the payer after the learner is deleted for good: a renewal falls back to the order, a refund to the charge', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 900_003, 'first_name' => 'Bek', 'last_name' => null, 'username' => 'bek']);
    $order = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa');
    $charge = app(PaymentApplier::class)->applyCharge($this->stars->emitCharge($order));
    snapshotPurge($learner);

    // Telegram still sends the next month (the renewal was not stopped): the money is recorded as failed, with its payer.
    try {
        app(PaymentApplier::class)->applyCharge($this->stars->emitCharge($order, renewal: true, periodEndsAt: CarbonImmutable::now()->addDays(60)));
        $this->fail('A renewal for a deleted subscriber must not be credited.');
    } catch (PaymentApplyException $e) {
        expect($e->getMessage())->toContain('no longer exists');
    }
    $failed = Payment::query()->where('status', Payment::STATUS_FAILED)->firstOrFail();
    expect($failed->subscriber_id)->toBe($learner->id)
        ->and($failed->raw['failure_reason'])->toBe('subscriber_missing')
        ->and($failed->payer_telegram_user_id)->toBe(900_003)
        ->and($failed->payer_name)->toBe('Bek')
        ->and($failed->payer_username)->toBe('bek');

    // The support refunds the first charge by hand: the refund row names the same payer.
    $refund = app(PaymentApplier::class)->applyRefund($this->stars->emitRefund($charge));
    expect($refund->kind)->toBe(Payment::KIND_REFUND)
        ->and($refund->refunded_payment_id)->toBe($charge->id)
        ->and($refund->payer_telegram_user_id)->toBe(900_003)
        ->and($refund->payer_name)->toBe('Bek')
        ->and($refund->payer_username)->toBe('bek')
        ->and(Payment::query()->findOrFail($charge->id)->status)->toBe(Payment::STATUS_REFUNDED);
});

it('refunds the Stars of a deleted payer to the Telegram id of the snapshot', function (): void {
    /** @var FakeTelegramClient $telegram */
    $telegram = app(TelegramClient::class);
    config()->set('billing.providers.telegram_stars.enabled', true);
    $learner = Learner::factory()->create(['telegram_user_id' => 900_004]);
    $payment = Payment::factory()->create(['subscriber_id' => $learner->id, 'provider' => 'telegram_stars', 'provider_charge_id' => 'ch_900004', 'payer_telegram_user_id' => 900_004]);
    snapshotPurge($learner);

    app(TelegramStarsProvider::class)->refund(Payment::query()->findOrFail($payment->id));

    expect($telegram->starRefunds)->toBe([['user_id' => 900_004, 'charge_id' => 'ch_900004']]);
});

it('backfills the snapshot of the rows written before the columns existed, soft-deleted learners included', function (): void {
    $migration = require database_path('migrations/2026_11_03_200001_add_payer_snapshot_to_payment_tables.php');
    $learner = Learner::factory()->create(['telegram_user_id' => 900_005, 'first_name' => 'Old', 'last_name' => 'Payer', 'username' => 'old']);
    $trashed = Learner::factory()->create(['telegram_user_id' => 900_006, 'first_name' => null, 'last_name' => null, 'username' => null]);
    $trashed->delete();
    $organization = Organization::factory()->create(['name' => 'Backfill Center']);
    $orderId = PaymentOrder::factory()->create(['subscriber_id' => $learner->id])->id;
    $paymentId = Payment::factory()->create(['subscriber_id' => $learner->id])->id;
    $trashedPaymentId = Payment::factory()->create(['subscriber_id' => $trashed->id])->id;
    $orgPaymentId = Payment::factory()->create(['subscriber_type' => 'organization', 'subscriber_id' => $organization->id])->id;
    $orphanPaymentId = Payment::factory()->create(['subscriber_id' => 999_999])->id;

    // The rows above were written with the snapshot; take the columns away and let the migration fill them again.
    $migration->down();
    $migration->up();

    $order = DB::table('payment_orders')->where('id', $orderId)->first();
    $payment = DB::table('payments')->where('id', $paymentId)->first();
    $trashedPayment = DB::table('payments')->where('id', $trashedPaymentId)->first();
    $orgPayment = DB::table('payments')->where('id', $orgPaymentId)->first();
    $orphan = DB::table('payments')->where('id', $orphanPaymentId)->first();

    expect((int) $order->payer_telegram_user_id)->toBe(900_005)
        ->and($order->payer_name)->toBe('Old Payer')
        ->and($order->payer_username)->toBe('old')
        ->and((int) $payment->payer_telegram_user_id)->toBe(900_005)
        ->and($payment->payer_name)->toBe('Old Payer')
        ->and((int) $trashedPayment->payer_telegram_user_id)->toBe(900_006)
        ->and($trashedPayment->payer_name)->toBeNull()
        ->and($trashedPayment->payer_username)->toBeNull()
        ->and($orgPayment->payer_telegram_user_id)->toBeNull()
        ->and($orgPayment->payer_name)->toBe('Backfill Center')
        ->and($orphan->payer_telegram_user_id)->toBeNull()
        ->and($orphan->payer_name)->toBeNull();
});

it('labels the payer for the admin and clips over-long values to the columns', function (): void {
    $learner = Learner::factory()->make(['telegram_user_id' => 42, 'first_name' => str_repeat('A', 100), 'last_name' => str_repeat('B', 100), 'username' => str_repeat('u', 100)]);

    $snapshot = PayerSnapshot::fromLearner($learner);

    expect(mb_strlen((string) $snapshot->name))->toBe(201)
        ->and(mb_strlen((string) $snapshot->username))->toBe(100)
        ->and(PayerSnapshot::of(null)->isEmpty())->toBeTrue()
        ->and(PayerSnapshot::of(null)->label())->toBeNull()
        ->and(PayerSnapshot::empty()->or($snapshot))->toBe($snapshot)
        ->and((new PayerSnapshot(42, 'Zina', 'zina'))->label())->toBe('Zina @zina · tg:42')
        ->and((new PayerSnapshot(42, null, null))->label())->toBe('tg:42')
        ->and((new PayerSnapshot(null, 'Smart Center', null))->label())->toBe('Smart Center')
        ->and((new PayerSnapshot(null, 'Smart Center', null))->toColumns())->toBe(['payer_telegram_user_id' => null, 'payer_name' => 'Smart Center', 'payer_username' => null]);
});
