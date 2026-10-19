<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Billing\Support\PaidSubscriptionQuery;
use App\Domain\Billing\Support\SubscriptionLiveness;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 * PaidSubscriptionQuery is the SQL twin of SubscriptionLiveness / EntitlementResolver (docs/12 §5.1): the
 * admin lists and the platform widgets rely on it giving the same answer as the rule the product enforces.
 * The matrix below is the guard: any change of the rule in one place and not the other fails here.
 */

uses(RefreshDatabase::class);

/**
 * One subscription per combination, each with its own subscriber (the one-live-per-subscriber index).
 *
 * @return list<array{status: string, provider: string, ends: string|null, grace: string|null, plan: string}>
 */
function pq_matrix(): array
{
    // Boundaries of the rule: the period end itself, one second either side, the grace edge (3 days)
    // and the 7-day expiry window, both for a fixed and for a renewing provider.
    $ends = [null, '-10 days', '-3 days', '-2 days', '-1 second', '0 seconds', '+1 second', '+4 days', '+5 days', '+7 days', '+10 days'];
    $graces = [null, '-1 hour', '0 seconds', '+1 hour', '+7 days', '+8 days'];
    $cases = [];

    foreach (SubscriptionStatus::cases() as $status) {
        foreach (SubscriptionProvider::cases() as $provider) {
            foreach ($ends as $end) {
                foreach ($graces as $grace) {
                    $cases[] = ['status' => $status->value, 'provider' => $provider->value, 'ends' => $end, 'grace' => $grace, 'plan' => Plan::Pro->value];
                }
            }
        }
        // A subscription of the free plan never grants a PAID plan, whatever its dates say.
        foreach ([null, '+5 days', '-1 second'] as $end) {
            $cases[] = ['status' => $status->value, 'provider' => 'manual', 'ends' => $end, 'grace' => '+1 hour', 'plan' => Plan::Free->value];
        }
    }

    return $cases;
}

/** @return array<int, Subscription> id => subscription, as stored */
function pq_seed(CarbonImmutable $now): array
{
    $stamp = fn (?string $modifier): ?string => $modifier === null ? null : $now->modify($modifier)->utc()->format('Y-m-d H:i:s');
    $rows = [];

    foreach (pq_matrix() as $i => $case) {
        $rows[] = [
            'subscriber_type' => 'learner',
            'subscriber_id' => $i + 1,
            'plan' => $case['plan'],
            'status' => $case['status'],
            'provider' => $case['provider'],
            'starts_at' => $stamp('-60 days'),
            'current_period_ends_at' => $stamp($case['ends']),
            'grace_until' => $stamp($case['grace']),
            'meta' => '{}',
            'created_at' => $stamp('0 seconds'),
            'updated_at' => $stamp('0 seconds'),
        ];
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('subscriptions')->insert($chunk);
    }

    return Subscription::query()->get()->keyBy('id')->all();
}

/** @param  iterable<int|string>  $ids */
function pq_sorted(iterable $ids): array
{
    $ids = [...$ids];
    $ids = array_map('intval', $ids);
    sort($ids);

    return $ids;
}

it('granting() agrees with SubscriptionLiveness on every status x provider x period end x grace_until x plan', function (): void {
    $now = CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC');
    $subscriptions = pq_seed($now);
    expect(count($subscriptions))->toBeGreaterThan(1500);

    $expected = [];
    foreach ($subscriptions as $id => $subscription) {
        if ($subscription->plan === Plan::Pro->value && SubscriptionLiveness::isLive($subscription, $now)) {
            $expected[] = $id;
        }
    }
    $actual = PaidSubscriptionQuery::granting(Subscription::query(), $now)->pluck('subscriptions.id');

    // Not vacuous: some rows grant and many do not.
    expect(count($expected))->toBeGreaterThan(100)
        ->and(count($expected))->toBeLessThan(count($subscriptions) / 2)
        ->and(pq_sorted($actual))->toBe(pq_sorted($expected));
});

it('expiringWithin(7) agrees with liveUntil() <= now + 7 days for the subscriptions that grant now', function (): void {
    $now = CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC');
    $subscriptions = pq_seed($now);
    $deadline = $now->addDays(7);

    $expected = [];
    foreach ($subscriptions as $id => $subscription) {
        if ($subscription->plan !== Plan::Pro->value || ! SubscriptionLiveness::isLive($subscription, $now)) {
            continue;
        }
        $until = SubscriptionLiveness::liveUntil($subscription);
        if ($until !== null && ! $until->greaterThan($deadline)) {
            $expected[] = $id;
        }
    }
    $actual = PaidSubscriptionQuery::expiringWithin(Subscription::query(), $now, 7)->pluck('subscriptions.id');

    expect(count($expected))->toBeGreaterThan(50)
        ->and(pq_sorted($actual))->toBe(pq_sorted($expected));
});

it('takes its live statuses from SubscriptionStatus::liveValues(): a status added there is picked up by the query', function (): void {
    // The query must not carry its own list of statuses: every live status except past_due is judged by
    // the period end, past_due by grace_until. Compare against the enum for every status.
    $now = CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC');

    foreach (SubscriptionStatus::cases() as $status) {
        $period = Subscription::factory()->create([
            'subscriber_id' => 900 + array_search($status, SubscriptionStatus::cases(), true),
            'status' => $status->value,
            'current_period_ends_at' => $now->addDays(5),
            'grace_until' => null,
        ]);
        $grace = Subscription::factory()->create([
            'subscriber_id' => 950 + array_search($status, SubscriptionStatus::cases(), true),
            'status' => $status->value,
            'current_period_ends_at' => null,
            'grace_until' => $now->addDays(5),
        ]);

        $live = in_array($status->value, SubscriptionStatus::liveValues(), true);
        $judgedByPeriod = $live && $status !== SubscriptionStatus::PastDue;
        $judgedByGrace = $status === SubscriptionStatus::PastDue;

        // `current_period_ends_at` = +5 days, no grace_until: live only when the period decides.
        expect(PaidSubscriptionQuery::granting(Subscription::query()->whereKey($period->id), $now)->exists())->toBe($judgedByPeriod, "{$status->value} by period")
            // `grace_until` = +5 days, perpetual period: past_due lives by the grace; a period-judged status lives forever.
            ->and(PaidSubscriptionQuery::granting(Subscription::query()->whereKey($grace->id), $now)->exists())->toBe($judgedByGrace || $judgedByPeriod, "{$status->value} by grace");
    }
});

it('the sponsor chain (organization x membership status x seat) agrees with EntitlementResolver', function (): void {
    $now = CarbonImmutable::now();
    $resolver = app(EntitlementResolver::class);
    $learners = [];
    $expectedPro = [];

    foreach (['active', 'suspended'] as $orgStatus) {
        foreach (['active', 'left', 'removed'] as $memberStatus) {
            foreach (['seated', 'waitlisted', 'none'] as $seatState) {
                foreach ([true, false] as $granting) {
                    $org = Organization::factory()->create(['status' => $orgStatus]);
                    $learner = Learner::factory()->create();
                    OrganizationMember::factory()->create([
                        'organization_id' => $org->id,
                        'learner_id' => $learner->id,
                        'status' => $memberStatus,
                        'seat_state' => $seatState,
                        'left_at' => $memberStatus === 'active' ? null : now(),
                    ]);
                    // A group row of the organization never sponsors by itself since 2B.1.
                    $group = TelegramGroup::factory()->create(['organization_id' => $org->id, 'status' => 'active']);
                    $learner->groups()->attach($group->id, ['status' => 'active', 'joined_at' => now()]);
                    $granting
                        ? Subscription::factory()->forOrganization($org)->create()
                        : Subscription::factory()->forOrganization($org)->expired()->create();

                    $learners[$learner->id] = $learner;
                    if ($resolver->resolve($learner)->plan === Plan::Pro) {
                        $expectedPro[] = $learner->id;
                    }
                }
            }
        }
    }

    // Exactly one combination sponsors: active organization, active member, seated, live subscription.
    expect($expectedPro)->toHaveCount(1);

    $viaIds = Learner::query()->whereIn('learners.id', PaidSubscriptionQuery::sponsoredLearnerIds($now))->pluck('id');
    $viaCorrelated = Learner::query()->whereExists(PaidSubscriptionQuery::sponsorFor($now)->select('subscriptions.id'))->pluck('id');

    expect(pq_sorted($viaIds))->toBe(pq_sorted($expectedPro))
        ->and(pq_sorted($viaCorrelated))->toBe(pq_sorted($expectedPro));
});
