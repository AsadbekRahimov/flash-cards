<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ReferralMilestoneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A milestone of "Invite a friend" reached by one inviter (docs/13 §1.5, §7.1): `milestone` friends counted
 * (`referrals.status = rewarded`) → `days` of Pro. Unique per `(referrer_learner_id, milestone)`: the row is created
 * once, under the inviter's referral row locks, when the count reaches it; `granted_at` / `subscription_id` / `skipped`
 * are filled by the grant transaction. `granted_at IS NULL` = a reward still owed (the job retries, `referrals:recover`).
 * Written only by `ReferralService`.
 *
 * @property int $id
 * @property int $referrer_learner_id
 * @property int $milestone
 * @property int $days
 * @property int|null $referral_id
 * @property Carbon $reached_at
 * @property Carbon|null $granted_at
 * @property int|null $subscription_id
 * @property string|null $skipped
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Learner|null $referrer
 * @property-read Referral|null $referral
 */
class ReferralMilestone extends Model
{
    /** @use HasFactory<ReferralMilestoneFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'referrer_learner_id',
        'milestone',
        'days',
        'referral_id',
        'reached_at',
        'granted_at',
        'subscription_id',
        'skipped',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'referrer_learner_id' => 'integer',
            'milestone' => 'integer',
            'days' => 'integer',
            'referral_id' => 'integer',
            'reached_at' => 'datetime',
            'granted_at' => 'datetime',
            'subscription_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Learner, $this> */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Learner::class, 'referrer_learner_id');
    }

    /** @return BelongsTo<Referral, $this> */
    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class);
    }

    /** The grant transaction has run: Pro was given, or deliberately not (`skipped`). */
    public function isSettled(): bool
    {
        return $this->granted_at !== null;
    }
}
