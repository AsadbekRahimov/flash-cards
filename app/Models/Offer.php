<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\OfferFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Personal discount (docs/13 §1.2 `offers`).
 *
 * @property int $id
 * @property int $learner_id
 * @property string $kind
 * @property string|null $plan
 * @property string|null $period
 * @property int $discount_pct
 * @property Carbon $expires_at
 * @property Carbon|null $redeemed_at
 * @property int|null $payment_order_id
 * @property int|null $source_organization_id
 * @property array<string, mixed> $meta
 */
class Offer extends Model
{
    /** @use HasFactory<OfferFactory> */
    use HasFactory;

    public const KIND_POST_ORG_DISCOUNT = 'post_org_discount';

    public const KIND_WINBACK = 'winback';

    public const KIND_CAMPAIGN = 'campaign';

    /** @var list<string> */
    protected $fillable = [
        'learner_id',
        'kind',
        'plan',
        'period',
        'discount_pct',
        'expires_at',
        'redeemed_at',
        'payment_order_id',
        'source_organization_id',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'discount_pct' => 'integer',
            'expires_at' => 'datetime',
            'redeemed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /** Not redeemed and not expired at $now. */
    public function isOpenAt(CarbonImmutable $now): bool
    {
        return $this->redeemed_at === null && $this->expires_at->greaterThan($now);
    }

    /** Whether the offer covers a purchase of $plan for $period (NULL = any). */
    public function covers(string $plan, string $period): bool
    {
        return ($this->plan === null || $this->plan === $plan)
            && ($this->period === null || $this->period === $period);
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    /** @return BelongsTo<PaymentOrder, $this> */
    public function paymentOrder(): BelongsTo
    {
        return $this->belongsTo(PaymentOrder::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function sourceOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'source_organization_id');
    }
}
