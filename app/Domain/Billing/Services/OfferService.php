<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Exceptions\OfferException;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\PaymentOrder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Personal discounts (docs/13 §1.2 `offers`, §2.1). An offer only ever changes
 * `payment_orders.amount` on the server (CheckoutService); the provider is told the discounted
 * amount and the webhook is checked against it, so a discount can never be forged by a client.
 *
 * Reservation (docs/13 "Реализация 2B.7a"): an open offer is HELD by at most one pending order
 * (`payment_orders.offer_id`, the "holder"). A new discounted checkout moves the hold — the previous
 * holder is canceled (`meta.canceled_reason = offer_moved`) unless it is being paid right now, in
 * which case the checkout is refused (`offer_busy`). The offer is used up (`redeemed_at`) by the
 * first successful payment; after that every other pending holder is released (`offer_redeemed`).
 * Hence at most one order ever pays with the discount, except for a charge that was already in
 * flight at the provider when its order was canceled: that money is honoured and the double
 * discount is logged (`billing.offer_double_discount`), never silently lost.
 */
final class OfferService
{
    /** Smallest chargeable amount per currency in minor units (1 Star; 100 tiyin = 1 sum). */
    private const MIN_AMOUNT = ['XTR' => 1, 'UZS' => 100];

    public const ACTION_ANNULLED = 'offer.annulled';

    public function __construct(
        private readonly EntitlementResolver $entitlements,
        private readonly ProductEventRecorder $events,
    ) {}

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
     * gets `post_org_discount` for the first month. Null (nothing created) when the learner still
     * has Pro from ANY source — own subscription or a seat in another center (2B.7a: the discount is
     * for someone who actually lost Pro) — or already holds an open offer of this kind, or has already
     * USED one (2B.8: the discount is given once in a lifetime; a lapsed unused one may be re-issued).
     * Called after the membership transaction committed (own short transaction).
     */
    public function createPostOrgDiscount(Learner $learner, Organization $from, ?CarbonImmutable $now = null): ?Offer
    {
        $now ??= CarbonImmutable::now();
        $pct = max(1, min(90, (int) config('billing.offers.post_org_discount.pct', 30)));
        $validDays = max(1, (int) config('billing.offers.post_org_discount.valid_days', 14));
        $period = config('billing.offers.post_org_discount.period');
        $period = is_string($period) && $period !== '' ? $period : null;

        if ($this->hasPro($learner, $now)) {
            return null;
        }

        if ($this->openFor($learner, Offer::KIND_POST_ORG_DISCOUNT, $now) !== null) {
            return null;
        }

        // Once per learner: a discount that was already used is never given again, however many times
        // the learner joins and leaves a center (2B.8). A lapsed, never-used one is not an abuse: a new one may follow.
        if (Offer::query()->where('learner_id', $learner->id)->where('kind', Offer::KIND_POST_ORG_DISCOUNT)->whereNotNull('redeemed_at')->exists()) {
            return null;
        }

        try {
            $offer = DB::transaction(function () use ($learner, $from, $pct, $validDays, $period, $now): Offer {
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

        $this->events->record(
            ProductEventName::OfferCreated,
            ['offer_id' => $offer->id, 'kind' => $offer->kind, 'discount_pct' => $offer->discount_pct, 'plan' => $offer->plan, 'period' => $offer->period, 'organization_id' => $from->id],
            learner: $learner,
        );

        return $offer;
    }

    /**
     * The open post-organization offer from $from whose DM has not gone out yet — what a retried
     * "leave" listener finishes after `createPostOrgDiscount` found it already created. Null when
     * the learner has since got Pro or the offer is gone, used, expired, or was announced.
     */
    public function unannouncedPostOrgDiscount(Learner $learner, Organization $from, ?CarbonImmutable $now = null): ?Offer
    {
        $now ??= CarbonImmutable::now();
        $offer = $this->openFor($learner, Offer::KIND_POST_ORG_DISCOUNT, $now);

        if ($offer === null || $offer->source_organization_id !== $from->id || isset($offer->meta['dm_sent_at'])) {
            return null;
        }

        return $this->hasPro($learner, $now) ? null : $offer;
    }

    /**
     * Pending orders that hold the offer, FOR UPDATE in id order. @internal for CheckoutService,
     * inside its transaction and BEFORE the subject row (docs/13 §3: payment_orders -> subject).
     * PostgreSQL re-checks the predicate after waiting for a lock, so a holder that was paid
     * meanwhile is not returned.
     *
     * @return Collection<int, PaymentOrder>
     */
    public function lockHolders(Offer $offer): Collection
    {
        return $this->holdersQuery($offer)->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * Ids of the pending holders as of now (no lock) — CheckoutService compares them with what it
     * locked to detect a holder inserted by a parallel checkout in between.
     *
     * @return list<int>
     */
    public function holderIds(Offer $offer): array
    {
        return array_values($this->holdersQuery($offer)->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all());
    }

    /**
     * The provider may be mid-payment on this order ({@see PaymentOrder::isBeingPaidAt}): an open Payme /
     * Click transaction younger than that provider's timeout. Such a holder is never canceled.
     */
    public function isBeingPaid(PaymentOrder $order, CarbonImmutable $now): bool
    {
        return $order->isBeingPaidAt($now);
    }

    /**
     * Cancels already-locked pending holders (CheckoutService, inside its transaction).
     *
     * @param  iterable<int, PaymentOrder>  $holders
     * @param  array<string, mixed>  $extra  more `meta` keys (e.g. `offer_moved_to_order_id`)
     */
    public function cancelHolders(iterable $holders, string $reason, array $extra, CarbonImmutable $now): void
    {
        foreach ($holders as $holder) {
            if (! $holder->isPending()) {
                continue;
            }

            $holder->fill([
                'status' => PaymentOrder::STATUS_CANCELED,
                'meta' => [...$holder->meta, 'canceled_reason' => $reason, 'canceled_at' => $now->toIso8601String(), ...$extra],
            ])->save();

            Log::info('billing.offer_holder_canceled', ['payment_order_id' => $holder->id, 'offer_id' => $holder->offer_id, 'reason' => $reason, ...$extra]);
        }
    }

    /**
     * After an offer was redeemed by $paidOrderId (PaymentApplier, after commit): every other
     * pending holder loses its right to the discount — canceled with `offer_redeemed` unless the
     * provider is mid-payment on it (then PaymentApplier will honour that charge and log the double
     * discount). Own short transaction, locks only `payment_orders` (docs/13 §3 rule 1).
     * Normally there is nothing to do: the checkout keeps a single holder.
     *
     * @return int how many holders were canceled
     */
    public function releaseHolders(int $offerId, int $paidOrderId, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        return DB::transaction(function () use ($offerId, $paidOrderId, $now): int {
            /** @var Collection<int, PaymentOrder> $holders */
            $holders = PaymentOrder::query()
                ->where('offer_id', $offerId)
                ->where('status', PaymentOrder::STATUS_PENDING)
                ->whereKeyNot($paidOrderId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $released = $holders->filter(fn (PaymentOrder $holder): bool => ! $this->isBeingPaid($holder, $now));
            $this->cancelHolders($released, PaymentOrder::CANCEL_REASON_OFFER_REDEEMED, ['offer_redeemed_by_order_id' => $paidOrderId], $now);

            return $released->count();
        });
    }

    /** Pro from any source right now — own live subscription or a seat in a live organization. Uncached read. */
    private function hasPro(Learner $learner, CarbonImmutable $now): bool
    {
        return $this->entitlements->resolve($learner, $now)->plan->rank() > Plan::Free->rank();
    }

    /** @return Builder<PaymentOrder> */
    private function holdersQuery(Offer $offer): Builder
    {
        return PaymentOrder::query()
            ->where('subscriber_type', 'learner')
            ->where('subscriber_id', $offer->learner_id)
            ->where('offer_id', $offer->id)
            ->where('status', PaymentOrder::STATUS_PENDING);
    }

    /** Remembers that the learner was told about the offer (the DM is sent once per offer). */
    public function markAnnounced(Offer $offer, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();

        $offer->forceFill(['meta' => [...$offer->meta, 'dm_sent_at' => $now->toIso8601String()]])->save();
    }

    /**
     * Admin annuls an unused offer: it expires now (kept for history; `createPostOrgDiscount` drops a
     * lapsed one when it needs the slot). Audited as `offer.annulled`.
     *
     * @throws OfferException forbidden | not_open
     */
    public function annul(Offer $offer, User $by, ?string $ip = null, ?CarbonImmutable $now = null): Offer
    {
        $now ??= CarbonImmutable::now();

        if (! $by->is_active || ! $by->hasRole('admin')) {
            throw OfferException::forbidden();
        }

        return DB::transaction(function () use ($offer, $by, $ip, $now): Offer {
            /** @var Offer $locked */
            $locked = Offer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpenAt($now)) {
                throw OfferException::notOpen();
            }

            $locked->forceFill(['expires_at' => $now, 'meta' => [...$locked->meta, 'annulled_by' => $by->id]])->save();

            AuditLog::create([
                'user_id' => $by->id,
                'action' => self::ACTION_ANNULLED,
                'entity_type' => Offer::class,
                'entity_id' => $locked->id,
                'ip' => $ip,
                'meta' => ['learner_id' => $locked->learner_id, 'kind' => $locked->kind, 'discount_pct' => $locked->discount_pct],
            ]);

            return $locked;
        });
    }

    /**
     * Discounted amount in minor units: intdiv(list x (100 - pct) + 50, 100) (integer half-up, no float), never below the
     * currency's smallest chargeable amount.
     */
    public function apply(Offer $offer, int $listAmount, string $currency): int
    {
        $pct = max(0, min(100, $offer->discount_pct));
        $discounted = intdiv($listAmount * (100 - $pct) + 50, 100);

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
