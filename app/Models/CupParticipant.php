<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Cup\Enums\CupParticipantStatus;
use Carbon\CarbonInterface;
use Database\Factories\CupParticipantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A learner in a cup (docs/33 §2.3). Only `id` goes out (never `learner_id` or a Telegram id). `best_total` / `best_round_points` are
 * the sum of the best `counted_days` days and its round part, rewritten under the lock of the row by every change of the day scores.
 *
 * @property int $id
 * @property int $cup_id
 * @property int $learner_id
 * @property string $display_name
 * @property CupParticipantStatus $status
 * @property CarbonInterface $joined_at
 * @property int $first_day
 * @property CarbonInterface|null $left_at
 * @property CarbonInterface|null $withdrawn_at  left the cup himself (docs/33 §11.12): `left` for good - no "member" brings him back
 * @property CarbonInterface|null $membership_checked_at
 * @property int $best_total
 * @property int $best_round_points
 * @property int $days_played
 * @property int|null $final_rank
 * @property int $rename_count
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Cup $cup
 * @property-read Learner $learner
 */
class CupParticipant extends Model
{
    /** @use HasFactory<CupParticipantFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CupParticipantStatus::class,
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'membership_checked_at' => 'datetime',
            'first_day' => 'integer',
            'best_total' => 'integer',
            'best_round_points' => 'integer',
            'days_played' => 'integer',
            'final_rank' => 'integer',
            'rename_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Cup, $this> */
    public function cup(): BelongsTo
    {
        return $this->belongsTo(Cup::class);
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }
}
