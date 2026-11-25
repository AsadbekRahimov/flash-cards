<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Enums\ScorePeriod;
use App\Models\Concerns\HasCompositeKey;
use Database\Factories\LearnerScorePeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The category and the rules version of a learner's week or month (docs/32 §2.3), fixed once at the first credit of the period
 * (`App\Domain\Engagement\Services\Scoring\ScorePeriodService::fix`) and never changed: a plan change inside the period moves the
 * NEXT period only. Primary key `(learner_id, period, starts_on)`; `starts_on` is the Monday or the 1st in the league zone.
 *
 * @property int $learner_id
 * @property ScorePeriod $period
 * @property Carbon $starts_on
 * @property ScoreCategory $category
 * @property int $rules_version
 * @property Carbon $fixed_at
 * @property string|null $region_code the learner's region at the first credit of the period (docs/35 §2.4); an empty one of an open
 *                                    month is filled once by the first region chosen, never changed afterwards
 */
class LearnerScorePeriod extends Model
{
    use HasCompositeKey;

    /** @use HasFactory<LearnerScorePeriodFactory> */
    use HasFactory;

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'learner_id';

    /** @var list<string> */
    protected $fillable = ['learner_id', 'period', 'starts_on', 'category', 'rules_version', 'fixed_at', 'region_code'];

    public function compositeKey(): array
    {
        return ['learner_id', 'period', 'starts_on'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'learner_id' => 'integer',
            'period' => ScorePeriod::class,
            'starts_on' => 'date',
            'category' => ScoreCategory::class,
            'rules_version' => 'integer',
            'fixed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }
}
