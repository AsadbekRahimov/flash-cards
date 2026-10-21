<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Billing\DTO\CheckoutLink;
use App\Domain\Billing\Support\ProviderTransactionTimeout;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentOrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * The intent to buy; the only source of the amount (docs/13 §1.2 `payment_orders`).
 *
 * @property int $id
 * @property string $token
 * @property string $subscriber_type
 * @property int $subscriber_id
 * @property string $plan
 * @property string|null $tier
 * @property string $period
 * @property string $provider
 * @property int $amount
 * @property int $list_amount
 * @property string $currency
 * @property int|null $offer_id
 * @property string $status
 * @property string|null $provider_ref
 * @property Carbon $expires_at
 * @property Carbon|null $paid_at
 * @property int|null $subscription_id
 * @property string $created_via
 * @property array<string, mixed> $meta
 */
class PaymentOrder extends Model
{
    /** @use HasFactory<PaymentOrderFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FAILED = 'failed';

    /**
     * `meta.canceled_reason` values written by offer bookkeeping (docs/13 "Реализация 2B.7a"): the order was
     * closed by US, not by the payer or the provider, because its discount moved to a newer order
     * (`offer_moved`) or was used up by another order (`offer_redeemed`). A charge the provider still
     * delivers for such an order is honoured by PaymentApplier — the pre-checks refuse it, but money that
     * was already taken must never be lost.
     */
    public const CANCEL_REASON_OFFER_MOVED = 'offer_moved';

    public const CANCEL_REASON_OFFER_REDEEMED = 'offer_redeemed';

    /**
     * `meta.canceled_reason` of an organization order closed by a payment that set another tier on the
     * organization's subscription (docs/13 "Реализация 2B.8", OrganizationOrderGuard::cancelCompeting): paying
     * it later would only extend the period at the live tier. Honoured by PaymentApplier like the offer
     * reasons (the money may already be taken) — such a late payment is then flagged `tier_mismatch`.
     */
    public const CANCEL_REASON_TIER_CHANGED = 'tier_changed';

    /**
     * `meta.canceled_reason` of a pending Stars `month` order closed by the first payment of the learner's Stars
     * subscription (OrganizationOrderGuard::cancelCompetingStarsSubscriptions, "Реализация 2A.8"): a second Telegram
     * subscription would double-charge them. Honoured like the other system reasons — an invoice already past
     * pre_checkout still pays, and that payment is flagged `stars_double_subscription`.
     */
    public const CANCEL_REASON_ALREADY_SUBSCRIBED = 'already_subscribed';

    /** @var list<string> */
    protected $fillable = [
        'token',
        'subscriber_type',
        'subscriber_id',
        'plan',
        'tier',
        'period',
        'provider',
        'amount',
        'list_amount',
        'currency',
        'offer_id',
        'status',
        'provider_ref',
        'expires_at',
        'paid_at',
        'subscription_id',
        'created_via',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'subscriber_id' => 'integer',
            'amount' => 'integer',
            'list_amount' => 'integer',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Pending and not past `expires_at` (what the provider pre-checks look at). */
    public function isPayableAt(CarbonImmutable $now): bool
    {
        return $this->isPending() && $this->expires_at->greaterThan($now);
    }

    /** Canceled by offer bookkeeping (see CANCEL_REASON_OFFER_*), not by the payer / provider / admin. */
    public function canceledForOffer(): bool
    {
        return $this->status === self::STATUS_CANCELED
            && in_array($this->meta['canceled_reason'] ?? null, [self::CANCEL_REASON_OFFER_MOVED, self::CANCEL_REASON_OFFER_REDEEMED], true);
    }

    /**
     * Canceled by OUR bookkeeping (offer moved / redeemed, organization tier changed, Stars already subscribed) rather than by the
     * payer, the provider or an admin: the pre-checks refuse the token, but a charge that was already in
     * flight at the provider is still credited by PaymentApplier — money that was taken is never lost.
     */
    public function canceledBySystem(): bool
    {
        return $this->canceledForOffer()
            || ($this->status === self::STATUS_CANCELED && in_array($this->meta['canceled_reason'] ?? null, [self::CANCEL_REASON_TIER_CHANGED, self::CANCEL_REASON_ALREADY_SUBSCRIBED], true));
    }

    /**
     * The provider may be mid-payment on this order: an open (`state = 1`) Payme / Click transaction younger
     * than THAT provider's timeout (Payme CreateTransaction -> debit -> PerformTransaction, 12 h; Click
     * Prepare -> debit -> Complete, `transaction_timeout_ms`), see {@see ProviderTransactionTimeout}. Such an
     * order must not be canceled under the payer's feet. Stars has no such row: its window (pre_checkout_query
     * answered -> successful_payment) lasts seconds and is covered by PaymentApplier honouring a charge on a
     * system-canceled order. Uncached query — call it after the row is locked.
     */
    public function isBeingPaidAt(CarbonImmutable $now): bool
    {
        $since = ProviderTransactionTimeout::openSince($this->provider, $now);

        if ($since === null) {
            return false;
        }

        return $this->providerTransactions()
            ->where('state', ProviderTransaction::STATE_CREATED)
            ->where('created_at_provider', '>', $since)
            ->exists();
    }

    /**
     * Orders without an open provider transaction younger than its provider's cut-off — i.e. nothing
     * is being paid right now (Payme CreateTransaction / Click Prepare happened and Perform / Complete
     * may follow); an open transaction older than the provider's timeout is treated as dead
     * (docs/13 §4.2, §4.3; {@see ProviderTransactionTimeout}).
     * A transaction of a provider without a cut-off never counts as open.
     *
     * @param  Builder<PaymentOrder>  $query
     * @param  array<string, CarbonImmutable>  $openSinceByProvider  provider key => instant after which an open transaction still counts
     */
    public function scopeWithoutOpenProviderTransaction(Builder $query, array $openSinceByProvider): void
    {
        if ($openSinceByProvider === []) {
            return;
        }

        $query->whereDoesntHave('providerTransactions', function (Builder $transactions) use ($openSinceByProvider): void {
            $transactions->where('state', ProviderTransaction::STATE_CREATED)
                ->where(function (Builder $open) use ($openSinceByProvider): void {
                    foreach ($openSinceByProvider as $provider => $since) {
                        $open->orWhere(function (Builder $of) use ($provider, $since): void {
                            $of->where('provider', $provider)->where('created_at_provider', '>', $since);
                        });
                    }
                });
        });
    }

    /** The checkout link stored by CheckoutService::create (provider_ref + meta.checkout), if any. */
    public function checkoutLink(): ?CheckoutLink
    {
        $checkout = $this->meta['checkout'] ?? null;

        if ($this->provider_ref === null || ! is_array($checkout)) {
            return null;
        }

        return new CheckoutLink(
            url: $this->provider_ref,
            kind: (string) ($checkout['kind'] ?? CheckoutLink::KIND_REDIRECT),
            expiresAt: isset($checkout['expires_at']) && is_string($checkout['expires_at'])
                ? CarbonImmutable::parse($checkout['expires_at'])
                : CarbonImmutable::instance($this->expires_at),
        );
    }

    /** @return MorphTo<Model, $this> */
    public function subscriber(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<ProviderTransaction, $this> */
    public function providerTransactions(): HasMany
    {
        return $this->hasMany(ProviderTransaction::class);
    }
}
