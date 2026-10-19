<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Learning\Enums\RepetitionState;
use Carbon\CarbonImmutable;
use Database\Factories\WordRepetitionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $learner_id
 * @property int $word_id
 * @property int $repetitions
 * @property int $interval_days
 * @property float $easiness_factor
 * @property int $last_quality
 * @property CarbonImmutable|null $last_reviewed_at
 * @property CarbonImmutable $next_review_at
 * @property bool $is_hard
 * @property string $introduced_mode mode of the session that first served the word (TrainingSession::MODE_*)
 * @property RepetitionState $state
 * @property int $learning_step
 * @property int $lapses
 * @property CarbonImmutable|null $served_ahead_at
 * @property string|null $served_ahead_token
 * @property array<mixed>|null $meta
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class WordRepetition extends Model
{
    /** @use HasFactory<WordRepetitionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'learner_id',
        'word_id',
        'repetitions',
        'interval_days',
        'easiness_factor',
        'last_quality',
        'last_reviewed_at',
        'next_review_at',
        'is_hard',
        'introduced_mode',
        'state',
        'learning_step',
        'lapses',
        'served_ahead_at',
        'served_ahead_token',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'repetitions' => 'integer',
            'interval_days' => 'integer',
            'easiness_factor' => 'decimal:2',
            'last_quality' => 'integer',
            'last_reviewed_at' => 'immutable_datetime',
            'next_review_at' => 'immutable_datetime',
            'is_hard' => 'boolean',
            'state' => RepetitionState::class,
            'learning_step' => 'integer',
            'lapses' => 'integer',
            'served_ahead_at' => 'immutable_datetime',
            'meta' => 'array',
        ];
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
