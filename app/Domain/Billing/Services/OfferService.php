<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\Plan;
use App\Models\Learner;
use App\Models\Offer;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Personal discounts (docs/13 §1.2 `offers`, §2.1). An offer only ever changes
 * `payment_orders.amount` on the server (CheckoutService); the provider is told the discounted
 * amount and the webhook is checked against it, so a discount can never be forged by a client.
 */
final class OfferService
{
    /** Smallest chargeable amount per currency in minor units (1 Star; 100 tiyin = 1 sum). */
    private const MIN_AMOUNT = ['XTR' => 1, 'UZS' => 100];

    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /** The learner's open (unredeemed, unexpired) offer of a kind, if any. */
    public function openFor(Learner $learner, string $kind, ?CarbonImmutable $now = null): ?Offer
    {
        $now ??= CarbonImmutable::now();

        /** @var Offer|null $offer */
        $offer = $this->openQuery($learner, $now)->where('kind', $kind)->orderBy('id')->first();

        return $offer;
    }

    /**
     * The best open offer that covers a purchase of $plan for $period (largest discount, then
     * the oldest). What CheckoutService applies when the caller passes no explicit offer.
     */
    public function applicableFor(Learner $learner, Plan $plan, string $period, ?CarbonImmutable $now = null): ?Offer
    {
        $now ??= CarbonImmutable::now();

        /** @var Offer|null $offer */
        $offer = $this->openQuery($learner, $now)
            ->where(fn ($q) => $q->whereNull('plan')->orWhere('plan', $plan->value))
            ->where(fn ($q) => $q->whereNull('period')->orWhere('period', $period))
            ->orderByDesc('discount_pct')
            ->orderBy('id')
            ->first();

        return $offer;
    }

    /**
     * B2B -> B2C bridge (docs/13 §0, §6): a learner who lost a seated place in an organization
     * gets `post_org_discount` for the first month. Null (nothing created) when the learner has a
     * live personal subscription — nothing to buy — or already holds an open offer of this kind.
     * Called after the membership transaction committed (own short transaction).
     */
    public function createPostOrgDiscount(Learner $learner, Organization $from, ?CarbonImmutable $now = null): ?Offer
    {
        $now ??= CarbonImmutable::now();
        $pct = max(1, min(90, (int) config('billing.offers.post_org_discount.pct', 30)));
        $validDays = max(1, (int) config('billing.offers.post_org_discount.valid_days', 14));
        $period = config('billing.offers.post_org_discount.period');
        $period = is_string($period) && $period !== '' ? $period : null;

        if ($this->subscriptions->liveFor($learner) !== null) {
            return null;
        }

        if ($this->openFor($learner, Offer::KIND_POST_ORG_DISCOUNT, $now) !== null) {
            return null;
        }

        try {
            return DB::transaction(function () use ($learner, $from, $pct, $validDays, $period, $now): Offer {
                // The partial unique index counts every unredeemed offer, expired ones included:
                // a lapsed, never-used offer of this kind is worthless — drop it to make room.
                Offer::query()
                    ->where('learner_id', $learner->id)
                    ->where('kind', Offer::KIND_POST_ORG_DISCOUNT)
                    ->whereNull('redeemed_at')
                    ->where('expires_at', '<=', $now)
                    ->delete();

                return Offer::query()->create([
                    'learner_id' => $learner->id,
                    'kind' => Offer::KIND_POST_ORG_DISCOUNT,
                    'plan' => Plan::Pro->value,
                    'period' => $period,
                    'discount_pct' => $pct,
                    'expires_at' => $now->addDays($validDays),
                    'source_organization_id' => $from->id,
                    'meta' => ['organization_slug' => $from->slug],
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Lost a race with a parallel leave(): the other transaction created the open offer.
            return null;
        }
    }

    /**
     * Discounted amount in minor units: round(list x (100 - pct) / 100), never below the
     * currency's smallest chargeable amount.
     */
    public function apply(Offer $offer, int $listAmount, string $currency): int
    {
        $pct = max(0, min(100, $offer->discount_pct));
        $discounted = (int) round($listAmount * (100 - $pct) / 100);

        return max(self::MIN_AMOUNT[$currency] ?? 1, $discounted);
    }

    /** @return Builder<Offer> */
    private function openQuery(Learner $learner, CarbonImmutable $now)
    {
        return Offer::query()
            ->where('learner_id', $learner->id)
            ->whereNull('redeemed_at')
            ->where('expires_at', '>', $now);
    }
}
