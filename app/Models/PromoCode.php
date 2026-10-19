<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PromoCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $plan
 * @property int $duration_days
 * @property int|null $max_redemptions
 * @property int $redemptions_count
 * @property Carbon|null $expires_at
 * @property bool $is_active
 * @property int|null $created_by_user_id
 * @property string|null $note
 */
class PromoCode extends Model
{
    /** @use HasFactory<PromoCodeFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'plan',
        'duration_days',
        'max_redemptions',
        'redemptions_count',
        'expires_at',
        'is_active',
        'created_by_user_id',
        'note',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'duration_days' => 'integer',
            'max_redemptions' => 'integer',
            'redemptions_count' => 'integer',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<PromoRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoRedemption::class);
    }
}
