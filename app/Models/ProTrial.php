<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Billing\Services\ProTrialService;
use Database\Factories\ProTrialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The welcome trial of one Telegram account (docs/13 §1.5, "Реализация 2C.9"): `telegram_hash` is the HMAC of the
 * Telegram id ({@see ProTrialService::hashFor()}), unique — the row is the proof that this
 * account had its trial, kept after the learner is purged. Written only by `ProTrialService`.
 *
 * @property int $id
 * @property string $telegram_hash
 * @property int|null $learner_id
 * @property int|null $subscription_id
 * @property int $days
 * @property Carbon $granted_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Learner|null $learner
 */
class ProTrial extends Model
{
    /** @use HasFactory<ProTrialFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'telegram_hash',
        'learner_id',
        'subscription_id',
        'days',
        'granted_at',
        'ends_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'learner_id' => 'integer',
            'subscription_id' => 'integer',
            'days' => 'integer',
            'granted_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }
}
