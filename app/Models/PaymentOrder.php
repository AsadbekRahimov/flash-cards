<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Billing\DTO\CheckoutLink;
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
     * Orders without an open provider transaction created after $openSince — i.e. nothing is being
     * paid right now (Payme CreateTransaction / Click Prepare happened and Perform / Complete may
     * follow); an open transaction older than the provider timeout is treated as dead (docs/13 §4.2).
     *
     * @param  Builder<PaymentOrder>  $query
     */
    public function scopeWithoutOpenProviderTransaction(Builder $query, CarbonImmutable $openSince): void
    {
        $query->whereDoesntHave('providerTransactions', function (Builder $transactions) use ($openSince): void {
            $transactions->where('state', ProviderTransaction::STATE_CREATED)->where('created_at_provider', '>', $openSince);
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
