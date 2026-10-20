<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Engagement\Enums\ChallengeMetric;
use App\Domain\Engagement\Enums\ChallengeStatus;
use Database\Factories\ChallengeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A friend challenge (docs/13 §1.5, §7.5): who gets more of `metric` in `learner_daily_stats` over
 * `starts_on`..`ends_on`. `scores` = `{challenger: int, opponent: int}` once finished; a draw has no winner.
 *
 * @property int $id
 * @property int $challenger_id
 * @property int $opponent_id
 * @property string $metric
 * @property int $days
 * @property string $status
 * @property string|null $open_pair
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property int|null $winner_learner_id
 * @property array{challenger?: int, opponent?: int}|null $scores
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Learner|null $challenger
 * @property-read Learner|null $opponent
 */
class Challenge extends Model
{
    /** @use HasFactory<ChallengeFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'challenger_id',
        'opponent_id',
        'metric',
        'days',
        'status',
        'open_pair',
        'starts_at',
        'ends_at',
        'starts_on',
        'ends_on',
        'winner_learner_id',
        'scores',
        'finished_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'challenger_id' => 'integer',
            'opponent_id' => 'integer',
            'days' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'winner_learner_id' => 'integer',
            'scores' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    public function status(): ChallengeStatus
    {
        return ChallengeStatus::from($this->status);
    }

    public function metric(): ChallengeMetric
    {
        return ChallengeMetric::from($this->metric);
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
     * @return BelongsTo<Learner, $this>
     */
    public function challenger(): BelongsTo
    {
        return $this->belongsTo(Learner::class, 'challenger_id');
    }

    /**
     * @return BelongsTo<Learner, $this>
     */
    public function opponent(): BelongsTo
    {
        return $this->belongsTo(Learner::class, 'opponent_id');
    }
}
