<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ProviderTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Provider-side state of one transaction (docs/13 §1.2 `provider_transactions`).
 * Payme states: 1 created, 2 performed, -1 canceled before perform, -2 canceled after;
 * Click: 1 prepared, 2 completed, -1 canceled.
 *
 * @property int $id
 * @property string $provider
 * @property string $provider_transaction_id
 * @property int $payment_order_id
 * @property int $state
 * @property int $amount
 * @property int|null $provider_time
 * @property Carbon|null $created_at_provider
 * @property Carbon|null $performed_at
 * @property Carbon|null $canceled_at
 * @property int|null $cancel_reason
 * @property array<string, mixed> $raw
 * @property list<array<string, mixed>> $history
 */
class ProviderTransaction extends Model
{
    /** @use HasFactory<ProviderTransactionFactory> */
    use HasFactory;

    public const STATE_CREATED = 1;

    public const STATE_PERFORMED = 2;

    public const STATE_CANCELED = -1;

    public const STATE_CANCELED_AFTER_PERFORM = -2;

    /** @var list<string> */
    protected $fillable = [
        'provider',
        'provider_transaction_id',
        'payment_order_id',
        'state',
        'amount',
        'provider_time',
        'created_at_provider',
        'performed_at',
        'canceled_at',
        'cancel_reason',
        'raw',
        'history',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payment_order_id' => 'integer',
            'state' => 'integer',
            'amount' => 'integer',
            'provider_time' => 'integer',
            'created_at_provider' => 'datetime',
            'performed_at' => 'datetime',
            'canceled_at' => 'datetime',
            'cancel_reason' => 'integer',
            'raw' => 'array',
            'history' => 'array',
        ];
    }

    public function isOpen(): bool
    {
        return $this->state === self::STATE_CREATED;
    }

    /** Appends a webhook journal entry (`history`) without saving. */
    public function pushHistory(string $method, int $stateFrom, int $stateTo, CarbonImmutable $at): void
    {
        $history = $this->history;
        $history[] = ['method' => $method, 'at' => $at->toIso8601String(), 'state_from' => $stateFrom, 'state_to' => $stateTo];
        $this->history = $history;
    }

    /** @return BelongsTo<PaymentOrder, $this> */
    public function paymentOrder(): BelongsTo
    {
        return $this->belongsTo(PaymentOrder::class);
    }
}
