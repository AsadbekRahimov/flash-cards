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
 * @property int|null $perform_time
 * @property int|null $cancel_time
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
        'perform_time',
        'cancel_time',
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
            'perform_time' => 'integer',
            'cancel_time' => 'integer',
            'cancel_reason' => 'integer',
            'raw' => 'array',
            'history' => 'array',
        ];
    }

    /** Payme cancel reasons (docs/13 §4.2): 4 = canceled by timeout (the transaction outlived `transaction_timeout_ms`). */
    public const REASON_TIMEOUT = 4;

    public function isOpen(): bool
    {
        return $this->state === self::STATE_CREATED;
    }

    public function isPerformed(): bool
    {
        return $this->state === self::STATE_PERFORMED;
    }

    public function isCanceled(): bool
    {
        return $this->state === self::STATE_CANCELED || $this->state === self::STATE_CANCELED_AFTER_PERFORM;
    }

    /**
     * An open transaction whose provider `time` is older than `$timeoutMs` at `$now` (Payme: 12 h);
     * the provider adapter then cancels it with {@see REASON_TIMEOUT}.
     */
    public function isTimedOutAt(CarbonImmutable $now, int $timeoutMs): bool
    {
        if (! $this->isOpen()) {
            return false;
        }

        $createdMs = $this->provider_time ?? $this->created_at_provider?->getTimestampMs() ?? $this->created_at?->getTimestampMs() ?? $now->getTimestampMs();

        return $now->getTimestampMs() - $createdMs > $timeoutMs;
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
