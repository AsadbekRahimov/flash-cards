<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The result of one finished quiz of the week (docs/03 §2.65). `scored` - the learner's counted quiz of the week.
 *
 * @property int $id
 * @property int $learner_id
 * @property int $course_id
 * @property int $exam_session_id
 * @property Carbon $week_start
 * @property bool $scored
 * @property int $correct
 * @property int $total
 * @property Carbon $finished_at
 * @property Carbon|null $created_at
 */
class LevelQuizResult extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'learner_id',
        'course_id',
        'exam_session_id',
        'week_start',
        'scored',
        'correct',
        'total',
        'finished_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'scored' => 'boolean',
            'correct' => 'integer',
            'total' => 'integer',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<ExamSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'exam_session_id');
    }
}
