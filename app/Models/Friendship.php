<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Engagement\Enums\FriendshipStatus;
use Database\Factories\FriendshipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A pair of learners (docs/13 §1.5): `learner_id` < `friend_id` always (see FriendshipService::pair()),
 * so one row serves both directions. `requested_by_learner_id` asked for the friendship (for a
 * `blocked` row: blocked the other side).
 *
 * @property int $id
 * @property int $learner_id
 * @property int $friend_id
 * @property string $status
 * @property int $requested_by_learner_id
 * @property Carbon|null $accepted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Learner|null $lowerLearner
 * @property-read Learner|null $higherLearner
 */
class Friendship extends Model
{
    /** @use HasFactory<FriendshipFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'learner_id',
        'friend_id',
        'status',
        'requested_by_learner_id',
        'accepted_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'learner_id' => 'integer',
            'friend_id' => 'integer',
            'requested_by_learner_id' => 'integer',
            'accepted_at' => 'datetime',
        ];
    }

    public function status(): FriendshipStatus
    {
        return FriendshipStatus::from($this->status);
    }

    public function involves(int $learnerId): bool
    {
        return $this->learner_id === $learnerId || $this->friend_id === $learnerId;
    }

    /** The other side of the pair, as seen by `$learnerId` (which must be one of the two). */
    public function otherId(int $learnerId): int
    {
        return $this->learner_id === $learnerId ? $this->friend_id : $this->learner_id;
    }

    /**
     * The side with the smaller id (null while soft-deleted).
     *
     * @return BelongsTo<Learner, $this>
     */
    public function lowerLearner(): BelongsTo
    {
        return $this->belongsTo(Learner::class, 'learner_id');
    }

    /**
     * The side with the larger id (null while soft-deleted).
     *
     * @return BelongsTo<Learner, $this>
     */
    public function higherLearner(): BelongsTo
    {
        return $this->belongsTo(Learner::class, 'friend_id');
    }
}
