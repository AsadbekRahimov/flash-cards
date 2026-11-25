<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Engagement\Enums\ScoreCategory;
use App\Models\Concerns\HasCompositeKey;
use Database\Factories\LearnerScoreTotalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * "All time" of one learner in one category (docs/32 §2.6, §5.2): the monthly counts of `learner_score_days` summed by the
 * month's category, recomputed whole from that source (never by deltas). Primary key `(learner_id, category)`;
 * `participant` follows the monthly rule (not deleted, not blocked, not opted out).
 *
 * @property int $learner_id
 * @property ScoreCategory $category
 * @property int $points
 * @property int $long_words
 * @property int $active_days
 * @property Carbon $first_day
 * @property Carbon $last_day
 * @property bool $participant
 * @property Carbon|null $updated_at
 */
class LearnerScoreTotal extends Model
{
    use HasCompositeKey;

    /** @use HasFactory<LearnerScoreTotalFactory> */
    use HasFactory;

    public const CREATED_AT = null;

    public $incrementing = false;

    protected $primaryKey = 'learner_id';

    /** @var list<string> */
    protected $fillable = ['learner_id', 'category', 'points', 'long_words', 'active_days', 'first_day', 'last_day', 'participant'];

    public function compositeKey(): array
    {
        return ['learner_id', 'category'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'learner_id' => 'integer',
            'category' => ScoreCategory::class,
            'points' => 'integer',
            'long_words' => 'integer',
            'active_days' => 'integer',
            'first_day' => 'date',
            'last_day' => 'date',
            'participant' => 'boolean',
        ];
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }
}
