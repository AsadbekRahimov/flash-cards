<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $subscriber_type
 * @property int $subscriber_id
 * @property string $plan
 * @property string|null $tier
 * @property string $status
 * @property string $provider
 * @property string|null $provider_ref
 * @property Carbon $starts_at
 * @property Carbon|null $current_period_ends_at
 * @property Carbon|null $grace_until
 * @property Carbon|null $canceled_at
 * @property Carbon|null $ended_at
 * @property int|null $seats_limit
 * @property int|null $granted_by_user_id
 * @property string|null $note
 * @property array<string, mixed> $meta
 */
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'subscriber_type',
        'subscriber_id',
        'plan',
        'tier',
        'status',
        'provider',
        'provider_ref',
        'starts_at',
        'current_period_ends_at',
        'grace_until',
        'canceled_at',
        'ended_at',
        'seats_limit',
        'granted_by_user_id',
        'note',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'subscriber_id' => 'integer',
            'starts_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'grace_until' => 'datetime',
            'canceled_at' => 'datetime',
            'ended_at' => 'datetime',
            'seats_limit' => 'integer',
            'meta' => 'array',
        ];
    }

    /**
     * The learner / organization the subscription belongs to (`subscriber_type` is
     * 'learner' | 'organization', see the morph map in AppServiceProvider).
     *
     * @return MorphTo<Model, $this>
     */
    public function subscriber(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    /** @return HasMany<PromoRedemption, $this> */
    public function promoRedemptions(): HasMany
    {
        return $this->hasMany(PromoRedemption::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<PaymentOrder, $this> */
    public function paymentOrders(): HasMany
    {
        return $this->hasMany(PaymentOrder::class);
    }
}
