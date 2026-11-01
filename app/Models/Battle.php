<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Practice\Enums\BattleEndReason;
use App\Domain\Practice\Enums\BattlePhase;
use App\Domain\Practice\Enums\BattleStatus;
use App\Domain\Practice\Enums\PracticeMode;
use Carbon\CarbonImmutable;
use Database\Factories\BattleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A 1-on-1 battle (docs/16 §2.7, §8): one match of one mode on one theme between two friends. The 5 rounds are frozen
 * in `snapshot` (shared by both players, server-only); the clocks (`round_deadline_at`, `phase_ends_at`, ...) are the
 * server's. `state_version` grows with every change the players can see; the presence does not change it.
 *
 * @property int $id
 * @property int $challenger_id
 * @property int $opponent_id
 * @property int|null $friendship_id
 * @property string|null $open_pair "<lower id>:<higher id>" of the players while the battle is not over (unique: one such battle per pair, docs/16 §8.2); NULL after any end
 * @property int $course_id
 * @property int|null $lesson_id
 * @property PracticeMode $mode
 * @property BattleStatus $status
 * @property BattleEndReason|null $end_reason
 * @property array<string, mixed> $snapshot
 * @property int $current_round
 * @property BattlePhase|null $phase
 * @property CarbonImmutable|null $round_deadline_at
 * @property CarbonImmutable|null $phase_ends_at
 * @property CarbonImmutable $invite_expires_at
 * @property CarbonImmutable|null $room_expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $finished_at
 * @property int|null $winner_learner_id
 * @property int|null $rematch_of_id
 * @property int $state_version
 * @property array<string, mixed> $meta
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Battle extends Model
{
    /** @use HasFactory<BattleFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'challenger_id',
        'opponent_id',
        'friendship_id',
        'open_pair',
        'course_id',
        'lesson_id',
        'mode',
        'status',
        'end_reason',
        'snapshot',
        'current_round',
        'phase',
        'round_deadline_at',
        'phase_ends_at',
        'invite_expires_at',
        'room_expires_at',
        'accepted_at',
        'starts_at',
        'finished_at',
        'winner_learner_id',
        'rematch_of_id',
        'state_version',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'challenger_id' => 'integer',
            'opponent_id' => 'integer',
            'friendship_id' => 'integer',
            'course_id' => 'integer',
            'lesson_id' => 'integer',
            'mode' => PracticeMode::class,
            'status' => BattleStatus::class,
            'end_reason' => BattleEndReason::class,
            'snapshot' => 'array',
            'current_round' => 'integer',
            'phase' => BattlePhase::class,
            'round_deadline_at' => 'immutable_datetime',
            'phase_ends_at' => 'immutable_datetime',
            'invite_expires_at' => 'immutable_datetime',
            'room_expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'starts_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'winner_learner_id' => 'integer',
            'rematch_of_id' => 'integer',
            'state_version' => 'integer',
            'meta' => 'array',
        ];
    }

    /** The key of `open_pair` for two players, whichever order they come in: "<lower id>:<higher id>". */
    public static function pairKey(int $a, int $b): string
    {
        return $a < $b ? "{$a}:{$b}" : "{$b}:{$a}";
    }

    public function involves(int $learnerId): bool
    {
        return $this->challenger_id === $learnerId || $this->opponent_id === $learnerId;
    }

    public function otherId(int $learnerId): int
    {
        return $this->challenger_id === $learnerId ? $this->opponent_id : $this->challenger_id;
    }

    /**
     * The participant row of a player, out of the loaded `participants` (loaded here when they are not yet).
     *
     * @throws LogicException the learner is not a player of this battle
     */
    public function participant(int $learnerId): BattleParticipant
    {
        foreach ($this->participants as $participant) {
            if ($participant->learner_id === $learnerId) {
                return $participant;
            }
        }

        throw new LogicException("Learner {$learnerId} is not a participant of battle {$this->id}.");
    }

    /** @return BelongsTo<Learner, $this> */
    public function challenger(): BelongsTo
    {
        return $this->belongsTo(Learner::class, 'challenger_id');
    }

    /** @return BelongsTo<Learner, $this> */
    public function opponent(): BelongsTo
    {
        return $this->belongsTo(Learner::class, 'opponent_id');
    }

    /** @return BelongsTo<Friendship, $this> */
    public function friendship(): BelongsTo
    {
        return $this->belongsTo(Friendship::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<Lesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** @return BelongsTo<Learner, $this> */
    public function winner(): BelongsTo
    {
        return $this->belongsTo(Learner::class, 'winner_learner_id');
    }

    /** @return BelongsTo<Battle, $this> the match this one is a rematch of */
    public function rematchOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rematch_of_id');
    }

    /** @return HasMany<BattleParticipant, $this> exactly two rows */
    public function participants(): HasMany
    {
        return $this->hasMany(BattleParticipant::class);
    }

    /** @return HasMany<BattleAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(BattleAnswer::class);
    }
}
