<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Practice\Enums\PracticeMode;
use Carbon\CarbonImmutable;
use Database\Factories\PracticeRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The personal best of a learner in a mode (docs/16 §2.10): one row per `(learner_id, mode)`. Written only by
 * `PracticeRecords::record()` in the transaction that finishes a session, under `FOR UPDATE` of this row, after the session's.
 *
 * @property int $id
 * @property int $learner_id
 * @property PracticeMode $mode
 * @property int $best_score
 * @property int|null $best_session_id
 * @property CarbonImmutable $achieved_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PracticeRecord extends Model
{
    /** @use HasFactory<PracticeRecordFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['learner_id', 'mode', 'best_score', 'best_session_id', 'achieved_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'learner_id' => 'integer',
            'mode' => PracticeMode::class,
            'best_score' => 'integer',
            'best_session_id' => 'integer',
            'achieved_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    /** @return BelongsTo<PracticeSession, $this> */
    public function bestSession(): BelongsTo
    {
        return $this->belongsTo(PracticeSession::class, 'best_session_id');
    }
}
