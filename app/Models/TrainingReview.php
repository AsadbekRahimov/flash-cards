<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Learning\Enums\ExerciseType;
use Database\Factories\TrainingReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $training_session_id
 * @property int $learner_id
 * @property int $word_id
 * @property int $quality
 * @property ExerciseType $exercise the exercise the grade came from (docs/13 §2.5)
 * @property string|null $answer_text the typed answer of a server-graded exercise, cut to 100 characters
 * @property string $scheduler `sm2` | `fsrs` (2D.3)
 * @property int|null $interval_before_days `word_repetitions.interval_days` before this grade
 * @property int $time_spent_ms
 */
class TrainingReview extends Model
{
    /** @use HasFactory<TrainingReviewFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'training_session_id',
        'learner_id',
        'word_id',
        'quality',
        'exercise',
        'answer_text',
        'scheduler',
        'interval_before_days',
        'time_spent_ms',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quality' => 'integer',
            'exercise' => ExerciseType::class,
            'interval_before_days' => 'integer',
            'time_spent_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TrainingSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'training_session_id');
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    /** @return BelongsTo<Word, $this> */
    public function word(): BelongsTo
    {
        return $this->belongsTo(Word::class);
    }
}
