<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Ledger row (docs/13 §1.2 `payments`): one per provider event. `(provider, provider_event_id)`
 * is unique — the idempotency key of PaymentApplier.
 *
 * @property int $id
 * @property int|null $payment_order_id
 * @property int|null $provider_transaction_id
 * @property int|null $subscription_id
 * @property string $subscriber_type
 * @property int $subscriber_id
 * @property string $provider
 * @property string $provider_event_id
 * @property string|null $provider_charge_id
 * @property string $kind
 * @property string $status
 * @property int $amount
 * @property string $currency
 * @property int|null $refunded_payment_id
 * @property Carbon|null $period_starts_at
 * @property Carbon|null $period_ends_at
 * @property Carbon $occurred_at
 * @property array<string, mixed> $raw
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    public const KIND_CHARGE = 'charge';

    public const KIND_RENEWAL = 'renewal';

    public const KIND_REFUND = 'refund';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    protected $fillable = [
        'payment_order_id',
        'provider_transaction_id',
        'subscription_id',
        'subscriber_type',
        'subscriber_id',
        'provider',
        'provider_event_id',
        'provider_charge_id',
        'kind',
        'status',
        'amount',
        'currency',
        'refunded_payment_id',
        'period_starts_at',
        'period_ends_at',
        'occurred_at',
        'raw',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'subscriber_id' => 'integer',
            'amount' => 'integer',
            'period_starts_at' => 'datetime',
            'period_ends_at' => 'datetime',
            'occurred_at' => 'datetime',
            'raw' => 'array',
        ];
    }

    public function isSucceeded(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }

    /** @return MorphTo<Model, $this> */
    public function subscriber(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<PaymentOrder, $this> */
    public function paymentOrder(): BelongsTo
    {
        return $this->belongsTo(PaymentOrder::class);
    }

    /** @return BelongsTo<ProviderTransaction, $this> */
    public function providerTransaction(): BelongsTo
    {
        return $this->belongsTo(ProviderTransaction::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function refundedPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'refunded_payment_id');
    }
}
