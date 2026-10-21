<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\CheckoutException;
use App\Domain\Billing\Exceptions\DuplicatePaymentException;
use App\Domain\Billing\Exceptions\ProviderUnavailableException;
use App\Domain\Billing\Services\PaymentApplier;
use App\Domain\Billing\Services\SubscriptionService;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Exceptions\OrgBillingException;
use App\Domain\Organizations\Services\OrganizationMembershipService;
use App\Domain\Organizations\Services\OrgBillingService;
use App\Mail\OrgInvoiceRequestMail;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\OrganizationMember;
use App\Models\OrganizationUser;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakeBillingProvider;

/*
 * OrgBillingService (docs/13 §6.5, task 2B.6): the owner-only catalog / checkout / history / invoice /
 * invoice request of a center, and the payment of an organization order end to end —
 * `applyCharge` sets `seats_limit` from the tariff and `reseat` seats the waitlist AFTER the commit.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
    $this->payme = FakeBillingProvider::register(SubscriptionProvider::Payme);
    $this->org = Organization::factory()->create(['name' => 'Bright Center', 'slug' => 'bright']);
    $this->owner = obStaff($this->org, OrganizationRole::Owner);
    $this->billing = app(OrgBillingService::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function obStaff(Organization $organization, OrganizationRole $role): User
{
    $user = User::factory()->create(['is_active' => true]);
    OrganizationUser::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role->value]);

    return $user;
}

function obInvite(Organization $organization): OrganizationInvite
{
    return OrganizationInvite::factory()->create(['organization_id' => $organization->id]);
}

/** @param int $count learners that joined while the center had no seat (waitlisted, in join order) */
function obWaitlist(Organization $organization, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
        app(OrganizationMembershipService::class)->joinByInvite(Learner::factory()->create(), obInvite($organization)->code);
    }
}

function obSeatCount(Organization $organization, string $state): int
{
    return OrganizationMember::query()->where('organization_id', $organization->id)->where('seat_state', $state)->count();
}

// --- catalog --------------------------------------------------------------------------------------

it('shows the owner the tariffs with UZS prices; a tier without a price is not purchasable', function (): void {
    config()->set('billing.invoice.request_email', '');
    config()->set('site.legal.email', '');

    $page = $this->billing->page($this->owner, $this->org);
    $byKey = collect($page->tiers)->keyBy('key');

    expect(array_column($page->tiers, 'key'))->toBe(['tutor', 'center_s', 'center_m', 'center_l'])
        ->and($byKey['center_s']['seats'])->toBe(100)
        ->and($byKey['center_s']['prices'])->toBe(['month' => 34_900_000, 'year' => 34_900_000 * 12])
        ->and($byKey['center_s']['purchasable'])->toBeTrue()
        ->and($byKey['center_l']['purchasable'])->toBeFalse()
        ->and($byKey['center_l']['prices'])->toBe([])
        ->and($byKey['center_l']['seats'])->toBeNull()
        ->and($page->providers)->toBe(['payme'])
        ->and($page->canRequestInvoice)->toBeFalse()
        ->and($page->tier)->toBeNull()
        ->and($page->overBy)->toBe(0);
});

it('lists only enabled providers that serve organizations: Stars never, Click when switched on', function (): void {
    FakeBillingProvider::register(SubscriptionProvider::TelegramStars);
    expect($this->billing->page($this->owner, $this->org)->providers)->toBe(['payme']);

    FakeBillingProvider::register(SubscriptionProvider::Click);
    expect($this->billing->page($this->owner, $this->org)->providers)->toBe(['payme', 'click']);

    config()->set('billing.providers.payme.enabled', false);
    expect($this->billing->page($this->owner, $this->org)->providers)->toBe(['click']);
});

it('refuses everything to a teacher and to a stranger (not_owner)', function (): void {
    $teacher = obStaff($this->org, OrganizationRole::Teacher);
    $stranger = User::factory()->create();
    $order = PaymentOrder::factory()->forOrganization($this->org)->create();

    foreach ([$teacher, $stranger] as $user) {
        $calls = [
            fn () => $this->billing->page($user, $this->org),
            fn () => $this->billing->checkout($user, $this->org, 'tutor', 'month', SubscriptionProvider::Payme),
            fn () => $this->billing->history($user, $this->org),
            fn () => $this->billing->invoice($user, $this->org, $order->id),
            fn () => $this->billing->requestInvoice($user, $this->org, 'tutor', 'month'),
        ];

        foreach ($calls as $call) {
            expect($call)->toThrow(OrgBillingException::class);
        }
    }

    expect(fn () => $this->billing->page($teacher, $this->org))->toThrow(fn (OrgBillingException $e) => $e->errorCode === 'not_owner' && $e->httpStatus() === 403);
    expect(PaymentOrder::query()->count())->toBe(1);
});

// --- checkout -------------------------------------------------------------------------------------

it('opens an organization order for the tier through Payme: tier, tiyin amount, org_panel, checkout link', function (): void {
    $order = $this->billing->checkout($this->owner, $this->org, 'center_s', 'month', SubscriptionProvider::Payme);

    expect($order->subscriber_type)->toBe('organization')
        ->and($order->subscriber_id)->toBe($this->org->id)
        ->and($order->plan)->toBe('pro')
        ->and($order->tier)->toBe('center_s')
        ->and($order->period)->toBe('month')
        ->and($order->provider)->toBe('payme')
        ->and($order->currency)->toBe('UZS')
        ->and($order->amount)->toBe(34_900_000)
        ->and($order->list_amount)->toBe(34_900_000)
        ->and($order->created_via)->toBe('org_panel')
        ->and($order->status)->toBe('pending')
        ->and($order->checkoutLink()?->url)->toBe("https://pay.test/{$order->token}");
});

it('opens an order through Click as well', function (): void {
    $click = FakeBillingProvider::register(SubscriptionProvider::Click);

    $order = $this->billing->checkout($this->owner, $this->org, 'tutor', 'month', SubscriptionProvider::Click);

    expect($order->provider)->toBe('click')->and($order->amount)->toBe(12_900_000)->and($click->checkouts)->toBe([$order->id]);
});

it('refuses Stars for an organization with 422, whether Stars is on or off, and creates no order', function (bool $enabled): void {
    FakeBillingProvider::register(SubscriptionProvider::TelegramStars, $enabled);

    try {
        $this->billing->checkout($this->owner, $this->org, 'tutor', 'month', SubscriptionProvider::TelegramStars);
        $this->fail('Stars must be refused for an organization.');
    } catch (CheckoutException $e) {
        expect($e->errorCode)->toBe('provider_not_supported')->and($e->httpStatus())->toBe(422);
    }

    expect(PaymentOrder::query()->count())->toBe(0);
})->with([true, false]);

it('does not sell a tier without a price (center_l) or a period it has no price for; unknown tier / period are 422', function (): void {
    foreach ([['center_l', 'month'], ['center_l', 'year']] as [$tier, $period]) {
        try {
            $this->billing->checkout($this->owner, $this->org, $tier, $period, SubscriptionProvider::Payme);
            $this->fail("{$tier}/{$period} must not be sold.");
        } catch (OrgBillingException $e) {
            expect($e->errorCode)->toBe('tier_not_purchasable')->and($e->httpStatus())->toBe(422);
        }
    }

    expect(fn () => $this->billing->checkout($this->owner, $this->org, 'gold', 'month', SubscriptionProvider::Payme))
        ->toThrow(fn (CheckoutException $e) => $e->errorCode === 'invalid_tier' && $e->httpStatus() === 422)
        ->and(fn () => $this->billing->checkout($this->owner, $this->org, 'tutor', 'decade', SubscriptionProvider::Payme))
        ->toThrow(fn (CheckoutException $e) => $e->errorCode === 'invalid_period')
        ->and(PaymentOrder::query()->count())->toBe(0);
});

it('refuses a provider that is switched off', function (): void {
    config()->set('billing.providers.payme.enabled', false);

    expect(fn () => $this->billing->checkout($this->owner, $this->org, 'tutor', 'month', SubscriptionProvider::Payme))
        ->toThrow(fn (CheckoutException $e) => $e->errorCode === 'provider_disabled');
});

it('turns an adapter failure into provider_unavailable (502) and leaves a failed order, not a payable one', function (): void {
    $this->payme->failCheckoutWith = new RuntimeException('gateway down');

    try {
        $this->billing->checkout($this->owner, $this->org, 'tutor', 'month', SubscriptionProvider::Payme);
        $this->fail('The provider failure must surface.');
    } catch (ProviderUnavailableException $e) {
        expect($e->httpStatus())->toBe(502);
    }

    expect(PaymentOrder::query()->sole()->status)->toBe('failed');
});

// --- payment -> seats -----------------------------------------------------------------------------

it('applies a paid organization order: seats_limit from the tier, the waitlist is seated FIFO up to it', function (): void {
    config()->set('billing.org_tiers.tutor.seats', 3);
    obWaitlist($this->org, 5);
    expect(obSeatCount($this->org, 'seated'))->toBe(0)->and(obSeatCount($this->org, 'waitlisted'))->toBe(5);

    $order = $this->billing->checkout($this->owner, $this->org, 'tutor', 'month', SubscriptionProvider::Payme);
    $payment = app(PaymentApplier::class)->applyCharge($this->payme->emitCharge($order, eventId: 'org-1'));

    $sub = Subscription::query()->sole();
    expect($payment->status)->toBe('succeeded')
        ->and($sub->tier)->toBe('tutor')
        ->and($sub->seats_limit)->toBe(3)
        ->and($sub->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(30)->toIso8601String())
        ->and($order->refresh()->status)->toBe('paid')
        ->and(obSeatCount($this->org, 'seated'))->toBe(3)
        ->and(obSeatCount($this->org, 'waitlisted'))->toBe(2);

    // FIFO: the three earliest joiners got the seats.
    $seatedIds = OrganizationMember::query()->where('seat_state', 'seated')->orderBy('id')->pluck('id')->all();
    $firstThree = OrganizationMember::query()->orderBy('id')->limit(3)->pluck('id')->all();
    expect($seatedIds)->toBe($firstThree);

    $page = $this->billing->page($this->owner, $this->org);
    expect($page->tier)->toBe('tutor')->and($page->seats->seated)->toBe(3)->and($page->seats->waitlisted)->toBe(2)->and($page->overBy)->toBe(0);
});

it('seats the waitlist only after the payment transaction has committed', function (): void {
    obWaitlist($this->org, 2);
    $order = $this->billing->checkout($this->owner, $this->org, 'tutor', 'month', SubscriptionProvider::Payme);

    DB::transaction(function () use ($order): void {
        app(PaymentApplier::class)->applyCharge($this->payme->emitCharge($order, eventId: 'org-tx'));

        // Credited inside the (still open) transaction, but `reseat` is a side effect of the commit.
        expect(Subscription::query()->sole()->seats_limit)->toBe(30)
            ->and(obSeatCount($this->org, 'seated'))->toBe(0);
    });

    expect(obSeatCount($this->org, 'seated'))->toBe(2)->and(obSeatCount($this->org, 'waitlisted'))->toBe(0);
});

it('does not seat anybody if the payment transaction rolls back', function (): void {
    obWaitlist($this->org, 2);
    $order = $this->billing->checkout($this->owner, $this->org, 'tutor', 'month', SubscriptionProvider::Payme);

    try {
        DB::transaction(function () use ($order): void {
            app(PaymentApplier::class)->applyCharge($this->payme->emitCharge($order, eventId: 'org-rollback'));

            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    expect(Subscription::query()->count())->toBe(0)
        ->and(obSeatCount($this->org, 'seated'))->toBe(0)
        ->and($order->refresh()->status)->toBe('pending');
});

it('credits an order once however many times the provider repeats the event', function (): void {
    obWaitlist($this->org, 2);
    $order = $this->billing->checkout($this->owner, $this->org, 'tutor', 'month', SubscriptionProvider::Payme);
    $event = $this->payme->emitCharge($order, eventId: 'org-dup');
    $applier = app(PaymentApplier::class);

    $applier->applyCharge($event);
    $endsAt = Subscription::query()->sole()->current_period_ends_at?->toIso8601String();

    expect(fn () => $applier->applyCharge($event))->toThrow(DuplicatePaymentException::class);

    expect(Payment::query()->where('provider_event_id', 'org-dup')->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and(Subscription::query()->sole()->current_period_ends_at?->toIso8601String())->toBe($endsAt)
        ->and(obSeatCount($this->org, 'seated'))->toBe(2);
});

it('upgrades through the platform: a bigger tier raises the limit and seats the queue; online, only the live tier can be renewed (2B.8)', function (): void {
    config()->set('billing.org_tiers.tutor.seats', 2);
    config()->set('billing.org_tiers.center_s.seats', 4);
    obWaitlist($this->org, 5);

    $applier = app(PaymentApplier::class);
    $applier->applyCharge($this->payme->emitCharge($this->billing->checkout($this->owner, $this->org, 'tutor', 'month', SubscriptionProvider::Payme), eventId: 'up-1'));
    expect(obSeatCount($this->org, 'seated'))->toBe(2);

    // Another tier is not sold online while the subscription is live: it would re-label the prepaid period.
    try {
        $this->billing->checkout($this->owner, $this->org, 'center_s', 'month', SubscriptionProvider::Payme);
        $this->fail('Expected CheckoutException');
    } catch (CheckoutException $e) {
        expect($e->errorCode)->toBe(CheckoutException::TIER_CHANGE_REQUIRES_SUPPORT)->and($e->httpStatus())->toBe(409);
    }
    expect(PaymentOrder::query()->where('status', 'pending')->count())->toBe(0);

    // The platform changes the tariff by hand (grantManual), the queue is seated right after the grant ...
    app(SubscriptionService::class)->grantManual($this->org, Plan::Pro, CarbonImmutable::now()->addDays(60), User::factory()->create(), 'upgrade agreed', tier: 'center_s');
    expect(obSeatCount($this->org, 'seated'))->toBe(4);

    // ... and the owner renews the new tier online: the period is extended, not reset.
    $applier->applyCharge($this->payme->emitCharge($this->billing->checkout($this->owner, $this->org, 'center_s', 'month', SubscriptionProvider::Payme), eventId: 'up-2'));

    $sub = Subscription::query()->sole();
    expect($sub->tier)->toBe('center_s')
        ->and($sub->seats_limit)->toBe(4)
        ->and($sub->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(90)->toIso8601String())
        ->and(obSeatCount($this->org, 'seated'))->toBe(4)
        ->and(obSeatCount($this->org, 'waitlisted'))->toBe(1);
});

it('a downgrade unseats nobody: the excess is shown and a newcomer waits', function (): void {
    config()->set('billing.org_tiers.center_s.seats', 4);
    config()->set('billing.org_tiers.tutor.seats', 2);
    $applier = app(PaymentApplier::class);

    $applier->applyCharge($this->payme->emitCharge($this->billing->checkout($this->owner, $this->org, 'center_s', 'month', SubscriptionProvider::Payme), eventId: 'dn-1'));
    foreach (range(1, 4) as $_) {
        app(OrganizationMembershipService::class)->joinByInvite(Learner::factory()->create(), obInvite($this->org)->code);
    }
    expect(obSeatCount($this->org, 'seated'))->toBe(4);

    // The page warns before paying for the lower tier ...
    $tiers = collect($this->billing->page($this->owner, $this->org)->tiers)->keyBy('key');
    expect($tiers['tutor']['below_seated'])->toBeTrue()->and($tiers['center_s']['below_seated'])->toBeFalse();

    // ... online it cannot be bought while center_s is live (2B.8); the platform moves the center down by hand,
    // and that changes the limit only.
    try {
        $this->billing->checkout($this->owner, $this->org, 'tutor', 'month', SubscriptionProvider::Payme);
        $this->fail('Expected CheckoutException');
    } catch (CheckoutException $e) {
        expect($e->errorCode)->toBe(CheckoutException::TIER_CHANGE_REQUIRES_SUPPORT);
    }
    app(SubscriptionService::class)->grantManual($this->org, Plan::Pro, CarbonImmutable::now()->addDays(30), User::factory()->create(), 'downgrade agreed', tier: 'tutor');

    $page = $this->billing->page($this->owner, $this->org);
    expect(Subscription::query()->sole()->seats_limit)->toBe(2)
        ->and(obSeatCount($this->org, 'seated'))->toBe(4)
        ->and($page->seats->limit)->toBe(2)
        ->and($page->seats->seated)->toBe(4)
        ->and($page->seats->overLimit())->toBeTrue()
        ->and($page->overBy)->toBe(2);

    $newcomer = app(OrganizationMembershipService::class)->joinByInvite(Learner::factory()->create(), obInvite($this->org)->code);
    expect($newcomer->seat_state)->toBe('waitlisted')->and(obSeatCount($this->org, 'seated'))->toBe(4);
});

// --- history and invoice --------------------------------------------------------------------------

it('lists only the own orders and payments, newest first', function (): void {
    $other = Organization::factory()->create();
    $mine = $this->billing->checkout($this->owner, $this->org, 'tutor', 'month', SubscriptionProvider::Payme);
    $paid = $this->billing->checkout($this->owner, $this->org, 'center_s', 'month', SubscriptionProvider::Payme);
    app(PaymentApplier::class)->applyCharge($this->payme->emitCharge($paid, eventId: 'h-1'));
    $foreign = PaymentOrder::factory()->forOrganization($other)->create();
    PaymentOrder::factory()->create(); // a learner's order
    Payment::factory()->create(['subscriber_type' => 'organization', 'subscriber_id' => $other->id]);

    $history = $this->billing->history($this->owner, $this->org);

    expect($history['orders']->pluck('id')->all())->toBe([$paid->id, $mine->id])
        ->and($history['orders']->pluck('id')->all())->not->toContain($foreign->id)
        ->and($history['payments'])->toHaveCount(1)
        ->and($history['payments']->first()?->provider_event_id)->toBe('h-1');
});

it('builds the invoice of an own order from site.legal and the order; another organization\'s order is not found', function (): void {
    config()->set('site.legal', ['name' => 'LexiFlow LLC', 'inn' => '123456789', 'address' => 'Tashkent', 'bank' => '', 'bank_account' => '20208000', 'mfo' => '00014', 'phone' => '', 'email' => 'billing@lexiflow.test']);
    $this->org->forceFill(['settings' => ['contact' => ['email' => 'owner@bright.test', 'phone' => '+998901112233', 'telegram' => '']]])->save();
    $order = $this->billing->checkout($this->owner, $this->org, 'center_s', 'month', SubscriptionProvider::Payme);

    $invoice = $this->billing->invoice($this->owner, $this->org, $order->id);

    expect($invoice->number)->toBe(sprintf('LF-%06d', $order->id))
        ->and($invoice->amount)->toBe(34_900_000)
        ->and($invoice->currency)->toBe('UZS')
        ->and($invoice->tier)->toBe('center_s')
        ->and($invoice->seats)->toBe(100)
        ->and($invoice->periodDays)->toBe(30)
        ->and($invoice->status)->toBe('pending')
        ->and($invoice->seller)->toBe(['name' => 'LexiFlow LLC', 'inn' => '123456789', 'address' => 'Tashkent', 'bank_account' => '20208000', 'mfo' => '00014', 'email' => 'billing@lexiflow.test'])
        ->and($invoice->payer)->toBe(['name' => 'Bright Center', 'email' => 'owner@bright.test', 'phone' => '+998901112233']);

    $other = Organization::factory()->create();
    $foreign = PaymentOrder::factory()->forOrganization($other)->create();
    $learnerOrder = PaymentOrder::factory()->create();

    foreach ([$foreign->id, $learnerOrder->id, 999_999] as $id) {
        expect(fn () => $this->billing->invoice($this->owner, $this->org, $id))
            ->toThrow(fn (OrgBillingException $e) => $e->errorCode === 'invoice_not_found' && $e->httpStatus() === 404);
    }
});

// --- request an invoice ---------------------------------------------------------------------------

it('mails the invoice request to the configured address with the owner as Reply-To, audited', function (): void {
    Mail::fake();
    config()->set('billing.invoice.request_email', 'sales@lexiflow.test');
    $this->owner->forceFill(['name' => 'Olga Owner', 'email' => 'olga@bright.test'])->save();

    $this->billing->requestInvoice($this->owner, $this->org, 'center_m', 'month', '  Need a signed copy  ', '203.0.113.7');

    Mail::assertSent(OrgInvoiceRequestMail::class, function (OrgInvoiceRequestMail $mail): bool {
        return $mail->hasTo('sales@lexiflow.test')
            && $mail->hasReplyTo('olga@bright.test')
            && $mail->tier === 'center_m'
            && $mail->seats === 300
            && $mail->amountLabel === '849 000 UZS'
            && $mail->comment === 'Need a signed copy'
            && str_contains($mail->render(), 'Bright Center');
    });
    Mail::assertSentCount(1);

    $audit = AuditLog::query()->where('action', 'organization.invoice_requested')->sole();
    expect($audit->user_id)->toBe($this->owner->id)->and($audit->entity_id)->toBe($this->org->id)->and($audit->ip)->toBe('203.0.113.7');
});

it('falls back to site.legal.email, and lets a tier without a price be requested (center_l: by agreement)', function (): void {
    Mail::fake();
    config()->set('billing.invoice.request_email', '');
    config()->set('site.legal.email', 'legal@lexiflow.test');

    $this->billing->requestInvoice($this->owner, $this->org, 'center_l', 'month');

    Mail::assertSent(OrgInvoiceRequestMail::class, fn (OrgInvoiceRequestMail $m): bool => $m->hasTo('legal@lexiflow.test') && $m->amountLabel === null && $m->seats === null);
});

it('refuses an invoice request without an address, an unknown tier / period, and a repeat within minutes', function (): void {
    Mail::fake();
    config()->set('billing.invoice.request_email', '');
    config()->set('site.legal.email', '');

    expect(fn () => $this->billing->requestInvoice($this->owner, $this->org, 'tutor', 'month'))
        ->toThrow(fn (OrgBillingException $e) => $e->errorCode === 'invoice_not_configured');

    config()->set('billing.invoice.request_email', 'sales@lexiflow.test');
    expect(fn () => $this->billing->requestInvoice($this->owner, $this->org, 'gold', 'month'))->toThrow(CheckoutException::class)
        ->and(fn () => $this->billing->requestInvoice($this->owner, $this->org, 'tutor', 'decade'))->toThrow(CheckoutException::class);

    Cache::flush();
    $this->billing->requestInvoice($this->owner, $this->org, 'tutor', 'month');
    expect(fn () => $this->billing->requestInvoice($this->owner, $this->org, 'tutor', 'month'))
        ->toThrow(fn (OrgBillingException $e) => $e->errorCode === 'invoice_request_throttled' && $e->httpStatus() === 429);

    // The limit is per organization: another tier / period does not get around it.
    expect(fn () => $this->billing->requestInvoice($this->owner, $this->org, 'center_s', 'month'))
        ->toThrow(fn (OrgBillingException $e) => $e->errorCode === 'invoice_request_throttled');

    Mail::assertSentCount(1);
});

it('allows a new invoice request after 10 minutes, but no more than 5 a day per organization', function (): void {
    Mail::fake();
    config()->set('billing.invoice.request_email', 'sales@lexiflow.test');
    $other = Organization::factory()->create();
    $otherOwner = obStaff($other, OrganizationRole::Owner);

    foreach (range(1, 5) as $i) {
        $this->billing->requestInvoice($this->owner, $this->org, 'center_s', 'month');
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(11));
        Cache::flush(); // the array store has no clock of its own: emulate the 10 minute marker expiry
        Cache::add("org-billing:invoice-request-day:{$this->org->id}:".now()->format('Ymd'), $i);
    }

    expect(fn () => $this->billing->requestInvoice($this->owner, $this->org, 'center_s', 'month'))
        ->toThrow(fn (OrgBillingException $e) => $e->errorCode === 'invoice_request_throttled');

    // Another organization has its own budget.
    $this->billing->requestInvoice($otherOwner, $other, 'center_s', 'month');
    Mail::assertSentCount(6);
});

it('does not let a suspended center place an order', function (): void {
    $this->org->update(['status' => 'suspended']);

    expect(fn () => $this->billing->checkout($this->owner, $this->org, 'center_s', 'month', SubscriptionProvider::Payme))
        ->toThrow(fn (OrgBillingException $e) => $e->errorCode === 'organization_suspended' && $e->httpStatus() === 409);
    expect(PaymentOrder::query()->count())->toBe(0);
});

it('shows a user-typed comment in the invoice mail as text, never as a link', function (): void {
    Mail::fake();
    config()->set('billing.invoice.request_email', 'sales@lexiflow.test');
    $this->org->update(['name' => '[Evil](http://evil.example/org)']);

    $this->billing->requestInvoice($this->owner, $this->org->refresh(), 'center_s', 'month', "[x](http://evil.example)\n# Heading\n<b>raw</b>");

    Mail::assertSent(OrgInvoiceRequestMail::class, function (OrgInvoiceRequestMail $mail): bool {
        $html = $mail->render();

        return ! str_contains($html, 'href="http://evil.example')
            && ! str_contains($html, '<h1>Heading')
            && ! str_contains($html, '<b>raw</b>')
            && str_contains($html, 'evil.example'); // still readable, as text
    });
});

it('lets the owner retry when the mail transport failed', function (): void {
    config()->set('billing.invoice.request_email', 'sales@lexiflow.test');
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('smtp down'));

    expect(fn () => $this->billing->requestInvoice($this->owner, $this->org, 'tutor', 'month'))->toThrow(RuntimeException::class);
    expect(Cache::has("org-billing:invoice-request:{$this->org->id}"))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'organization.invoice_requested')->count())->toBe(0);
});
