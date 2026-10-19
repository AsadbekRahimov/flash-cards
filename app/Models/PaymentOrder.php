<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Billing\DTO\CheckoutLink;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentOrderFactory;
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
