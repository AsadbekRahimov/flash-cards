<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ExamResultFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamResult extends Model
{
    /** @use HasFactory<ExamResultFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'exam_session_id',
        'learner_id',
        'total_score',
        'correct_count',
        'total_count',
        'rank',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'total_score' => 'integer',
            'correct_count' => 'integer',
            'total_count' => 'integer',
            'rank' => 'integer',
        ];
    }

    /** @return BelongsTo<ExamSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'exam_session_id');
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }
}
