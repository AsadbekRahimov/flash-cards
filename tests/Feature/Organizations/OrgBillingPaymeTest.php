<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Services\OrganizationMembershipService;
use App\Domain\Organizations\Services\OrgBillingService;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\OrganizationMember;
use App\Models\OrganizationUser;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PaymeFixtures;

/*
 * The real Payme adapter and webhook (not the fake provider) paying an organization order created
 * by OrgBillingService: Create + Perform from the fixtures -> subscription with the tier's seats ->
 * the waitlist is seated (docs/13 §6.5, DoD 2B.6).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
    config()->set('billing.providers.payme.enabled', true);
    config()->set('billing.providers.payme.merchant_id', '5e730e8e0b852a417aa49ceb');
    config()->set('billing.providers.payme.key', 'live-merchant-key-0123456789abcdef');
    config()->set('billing.providers.payme.ip_allowlist', '');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('pays an organization order over the Payme webhook: tier seats, waitlist seated, one payment on a repeated Perform', function (): void {
    $org = Organization::factory()->create();
    $owner = User::factory()->create(['is_active' => true]);
    OrganizationUser::factory()->create(['organization_id' => $org->id, 'user_id' => $owner->id, 'role' => OrganizationRole::Owner->value]);

    foreach (range(1, 3) as $_) {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
        app(OrganizationMembershipService::class)->joinByInvite(Learner::factory()->create(), OrganizationInvite::factory()->create(['organization_id' => $org->id])->code);
    }
    expect(OrganizationMember::query()->where('seat_state', 'waitlisted')->count())->toBe(3);

    $order = app(OrgBillingService::class)->checkout($owner, $org, 'center_s', 'month', SubscriptionProvider::Payme);
    expect($order->checkoutLink()?->url)->toStartWith('https://checkout.paycom.uz/');

    $headers = ['Authorization' => PaymeFixtures::basic('live-merchant-key-0123456789abcdef'), 'Accept' => 'application/json'];
    $post = fn (array $body) => test()->call('POST', '/billing/webhook/payme', [], [], [], array_merge(test()->transformHeadersToServerVars($headers), ['CONTENT_TYPE' => 'application/json']), json_encode($body, JSON_THROW_ON_ERROR));

    $post(PaymeFixtures::for('CheckPerformTransaction', $order))->assertOk()->assertJsonPath('result.allow', true);
    $post(PaymeFixtures::for('CreateTransaction', $order))->assertOk()->assertJsonPath('result.state', 1);
    $post(PaymeFixtures::for('PerformTransaction', $order))->assertOk()->assertJsonPath('result.state', 2);
    $post(PaymeFixtures::for('PerformTransaction', $order))->assertOk()->assertJsonPath('result.state', 2);

    $sub = Subscription::query()->where('subscriber_id', $org->id)->where('subscriber_type', 'organization')->sole();
    expect(Payment::query()->where('subscriber_id', $org->id)->where('status', 'succeeded')->count())->toBe(1)
        ->and($sub->tier)->toBe('center_s')
        ->and($sub->seats_limit)->toBe(100)
        ->and($sub->provider)->toBe('payme')
        ->and(OrganizationMember::query()->where('seat_state', 'seated')->count())->toBe(3)
        ->and($order->refresh()->status)->toBe('paid');
});
