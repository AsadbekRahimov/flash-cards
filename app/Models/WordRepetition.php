<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
            'last_reviewed_at' => 'datetime',
            'next_review_at' => 'datetime',
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
