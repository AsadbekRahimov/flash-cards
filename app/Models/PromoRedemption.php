<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PromoRedemptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $promo_code_id
 * @property int $learner_id
 * @property int|null $subscription_id
 * @property Carbon $redeemed_at
 */
class PromoRedemption extends Model
{
    /** @use HasFactory<PromoRedemptionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'promo_code_id',
        'learner_id',
        'subscription_id',
        'redeemed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['redeemed_at' => 'datetime'];
    }

    /** @return BelongsTo<PromoCode, $this> */
    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
