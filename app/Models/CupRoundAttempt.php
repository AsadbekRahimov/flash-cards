<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Cup\Enums\CupAttemptFinishReason;
use App\Domain\Cup\Enums\CupAttemptStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The one rated attempt of a participant on a day of a cup (docs/33 §2.5). `order` is the participant's order of the elements of the
 * day's snapshot; `state` the `RunState`-shaped answers. `cup_id` and `learner_id` are copies (no foreign key, see the migration).
 *
 * @property int $id
 * @property int $cup_id
 * @property int $cup_day_id
 * @property int $participant_id
 * @property int $learner_id
 * @property list<string> $order
 * @property array<string, mixed> $state
 * @property int $answered
 * @property int $correct
 * @property int $points
 * @property CupAttemptStatus $status
 * @property CupAttemptFinishReason|null $finish_reason
 * @property string $locale
 * @property CarbonInterface $started_at
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $finished_at
 * @property-read CupDay $cupDay
 * @property-read CupParticipant $participant
 */
class CupRoundAttempt extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'order' => 'array',
            'state' => 'array',
            'answered' => 'integer',
            'correct' => 'integer',
            'points' => 'integer',
            'status' => CupAttemptStatus::class,
            'finish_reason' => CupAttemptFinishReason::class,
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CupDay, $this> */
    public function cupDay(): BelongsTo
    {
        return $this->belongsTo(CupDay::class);
    }

    /** @return BelongsTo<CupParticipant, $this> */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(CupParticipant::class, 'participant_id');
    }

    public function isActive(): bool
    {
        return $this->status === CupAttemptStatus::Active;
    }
}
