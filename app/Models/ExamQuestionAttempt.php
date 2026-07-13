<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $exam_session_id
 * @property int $student_id
 * @property int $question_index
 * @property CarbonImmutable $served_at
 * @property CarbonImmutable|null $answered_at
 */
class ExamQuestionAttempt extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'exam_session_id',
        'student_id',
        'question_index',
        'served_at',
        'answered_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'question_index' => 'integer',
            'served_at' => 'immutable_datetime',
            'answered_at' => 'immutable_datetime',
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
}
