<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamAnswer extends Model
{
    use HasFactory;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'exam_session_id',
        'student_id',
        'word_id',
        'selected_translation',
        'is_correct',
        'score',
        'time_spent_ms',
        'answered_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'score' => 'integer',
            'time_spent_ms' => 'integer',
            'answered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ExamSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'exam_session_id');
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
