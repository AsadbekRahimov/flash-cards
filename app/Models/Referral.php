<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Engagement\Enums\ReferralStatus;
use Database\Factories\ReferralFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * "Invite a friend" (docs/13 §1.5): the inviter (`referrer`) and the invited new learner
 * (`referee`, unique), the state machine and its timestamps. `meta`:
 *   rewards: {referee: {subscription_id|null, skipped|null}, referrer: {...}} — filled by one
 *            transaction per side, so a retry grants only what is missing;
 *   reward_subscription_ids: list<int>; active_days: list<string> (the local days that activated it).
 *
 * @property int $id
 * @property int $referrer_learner_id
 * @property int $referee_learner_id
 * @property string $status
 * @property string|null $reject_reason
 * @property Carbon $linked_at
 * @property Carbon|null $activated_at
 * @property Carbon|null $rewarded_at
 * @property Carbon|null $flagged_at
 * @property array<string, mixed> $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Learner|null $referrer
 * @property-read Learner|null $referee
 */
class Referral extends Model
{
    /** @use HasFactory<ReferralFactory> */
    use HasFactory;

    /** Keys of `meta.rewards`: the two sides of a referral. */
    public const SIDE_REFEREE = 'referee';

    public const SIDE_REFERRER = 'referrer';

    /** @var list<string> */
    protected $fillable = [
        'referrer_learner_id',
        'referee_learner_id',
        'status',
        'reject_reason',
        'linked_at',
        'activated_at',
        'rewarded_at',
        'flagged_at',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'referrer_learner_id' => 'integer',
            'referee_learner_id' => 'integer',
            'linked_at' => 'datetime',
            'activated_at' => 'datetime',
            'rewarded_at' => 'datetime',
            'flagged_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function status(): ReferralStatus
    {
        return ReferralStatus::from($this->status);
    }

    /**
     * The inviter (null while soft-deleted; the row itself lives until the hard delete cascades it).
     *
     * @return BelongsTo<Learner, $this>
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Learner::class, 'referrer_learner_id');
    }

    /**
     * The invited learner (null while soft-deleted: a deleted profile shows no name anywhere).
     *
     * @return BelongsTo<Learner, $this>
     */
    public function referee(): BelongsTo
    {
        return $this->belongsTo(Learner::class, 'referee_learner_id');
    }

    /**
     * What `meta.rewards.<side>` holds, or null when that side was not granted yet.
     *
     * @return array{subscription_id: int|null, skipped: string|null}|null
     */
    public function rewardOf(string $side): ?array
    {
        $rewards = $this->meta['rewards'] ?? null;
        if (! is_array($rewards) || ! is_array($rewards[$side] ?? null)) {
            return null;
        }

        /** @var array{subscription_id?: mixed, skipped?: mixed} $entry */
        $entry = $rewards[$side];

        return [
            'subscription_id' => is_int($entry['subscription_id'] ?? null) ? $entry['subscription_id'] : null,
            'skipped' => is_string($entry['skipped'] ?? null) ? $entry['skipped'] : null,
        ];
    }

    /** Both sides granted (or deliberately skipped): the reward job has nothing left to do. */
    public function fullyRewarded(): bool
    {
        return $this->rewardOf(self::SIDE_REFEREE) !== null && $this->rewardOf(self::SIDE_REFERRER) !== null;
    }
}
