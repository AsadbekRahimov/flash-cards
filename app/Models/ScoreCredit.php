<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Engagement\Enums\ScoreKind;
use App\Domain\Engagement\Enums\ScoreSource;
use Database\Factories\ScoreCreditFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The journal of the word points (docs/32 §2.5): one row per credited event, also when both counts were cut to 0 by the limits
 * ("cut by the cap" stays visible). Unique `(learner_id, rating_day, lexeme_id)` - one lexeme, one credit a day. A correction
 * voids rows (`voided_*`) and recomputes the day; nothing is deleted except by the retention clean-up
 * (`leagues.scoring.credits_retention_days`, only days of closed months).
 *
 * @property int $id
 * @property int $learner_id
 * @property Carbon $rating_day
 * @property int $lexeme_id
 * @property int $word_id
 * @property ScoreSource $source
 * @property int $source_id
 * @property ScoreKind $kind
 * @property int $week_points
 * @property int $month_points
 * @property Carbon|null $voided_at
 * @property int|null $voided_by_user_id
 * @property string|null $void_reason
 * @property Carbon|null $created_at
 */
class ScoreCredit extends Model
{
    /** @use HasFactory<ScoreCreditFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'learner_id', 'rating_day', 'lexeme_id', 'word_id', 'source', 'source_id', 'kind', 'week_points', 'month_points',
        'voided_at', 'voided_by_user_id', 'void_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'learner_id' => 'integer',
            'rating_day' => 'date',
            'lexeme_id' => 'integer',
            'word_id' => 'integer',
            'source' => ScoreSource::class,
            'source_id' => 'integer',
            'kind' => ScoreKind::class,
            'week_points' => 'integer',
            'month_points' => 'integer',
            'voided_at' => 'datetime',
            'voided_by_user_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    /** @return BelongsTo<Lexeme, $this> */
    public function lexeme(): BelongsTo
    {
        return $this->belongsTo(Lexeme::class);
    }
}
