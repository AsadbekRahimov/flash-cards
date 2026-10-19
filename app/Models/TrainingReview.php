<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TrainingReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'time_spent_ms',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quality' => 'integer',
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
