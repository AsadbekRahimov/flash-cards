<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $student_id
 * @property int $word_id
 * @property int $repetitions
 * @property int $interval_days
 * @property float $easiness_factor
 * @property int $last_quality
 * @property CarbonImmutable|null $last_reviewed_at
 * @property CarbonImmutable $next_review_at
 * @property bool $is_hard
 * @property array<mixed>|null $meta
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class WordRepetition extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'student_id',
        'word_id',
        'repetitions',
        'interval_days',
        'easiness_factor',
        'last_quality',
        'last_reviewed_at',
        'next_review_at',
        'is_hard',
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
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<Word, $this> */
    public function word(): BelongsTo
    {
        return $this->belongsTo(Word::class);
    }
}
