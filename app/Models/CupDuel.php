<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Cup\Enums\CupDuelEndReason;
use App\Domain\Cup\Enums\CupDuelStatus;
use App\Domain\Practice\Enums\PracticeMode;
use Carbon\CarbonInterface;
use Database\Factories\CupDuelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A duel of a cup (docs/33 §2.9, §5.7): a challenge of a participant (to one participant or open to the first who accepts) and, once
 * accepted, its live battle (`battle_id`). `rated_offered` is what both saw before the acceptance, `rated` what the acceptance made of it.
 * The points of a rated duel go into `cup_day_scores.duel_points` of the day of the acceptance (`accepted_day`). Written only by
 * {@see \App\Domain\Cup\Services\CupDuelService}, always under `FOR UPDATE` of the row.
 *
 * @property int $id
 * @property int $cup_id
 * @property int $day
 * @property int $challenger_participant_id
 * @property int|null $opponent_participant_id
 * @property bool $is_public
 * @property bool $rated_offered
 * @property bool|null $rated
 * @property PracticeMode $mode
 * @property CupDuelStatus $status
 * @property CupDuelEndReason|null $end_reason
 * @property int|null $open_challenger_id
 * @property int|null $battle_id
 * @property string $request_id
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $accepted_at
 * @property int|null $accepted_day
 * @property CarbonInterface|null $scored_at
 * @property CarbonInterface|null $closed_at
 * @property int|null $challenger_points
 * @property int|null $opponent_points
 * @property int|null $winner_participant_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Cup $cup
 * @property-read CupParticipant $challenger
 * @property-read CupParticipant|null $opponent
 * @property-read Battle|null $battle
 */
class CupDuel extends Model
{
    /** @use HasFactory<CupDuelFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'day' => 'integer',
            'is_public' => 'boolean',
            'rated_offered' => 'boolean',
            'rated' => 'boolean',
            'mode' => PracticeMode::class,
            'status' => CupDuelStatus::class,
            'end_reason' => CupDuelEndReason::class,
            'open_challenger_id' => 'integer',
            'battle_id' => 'integer',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'accepted_day' => 'integer',
            'scored_at' => 'datetime',
            'closed_at' => 'datetime',
            'challenger_points' => 'integer',
            'opponent_points' => 'integer',
            'winner_participant_id' => 'integer',
        ];
    }

    /** The participant on the other side of `$participantId`, or null (an open challenge, or not a player of this duel). */
    public function otherParticipantId(int $participantId): ?int
    {
        return match ($participantId) {
            $this->challenger_participant_id => $this->opponent_participant_id,
            $this->opponent_participant_id => $this->challenger_participant_id,
            default => null,
        };
    }

    /** @return BelongsTo<Cup, $this> */
    public function cup(): BelongsTo
    {
        return $this->belongsTo(Cup::class);
    }

    /** @return BelongsTo<CupParticipant, $this> */
    public function challenger(): BelongsTo
    {
        return $this->belongsTo(CupParticipant::class, 'challenger_participant_id');
    }

    /** @return BelongsTo<CupParticipant, $this> */
    public function opponent(): BelongsTo
    {
        return $this->belongsTo(CupParticipant::class, 'opponent_participant_id');
    }

    /** @return BelongsTo<Battle, $this> */
    public function battle(): BelongsTo
    {
        return $this->belongsTo(Battle::class);
    }
}
