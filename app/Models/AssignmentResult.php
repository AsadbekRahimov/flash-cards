<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AssignmentResultFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Progress snapshot of one learner on one assignment (docs/13 §1.4). Written by AssignmentService
 * only; `completed_at` is set once (conditional UPDATE) and never reset.
 *
 * @property int $id
 * @property int $assignment_id
 * @property int $learner_id
 * @property int $progress_pct
 * @property Carbon|null $completed_at
 * @property Carbon $computed_at
 * @property-read Assignment $assignment
 * @property-read Learner $learner
 */
class AssignmentResult extends Model
{
    /** @use HasFactory<AssignmentResultFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['assignment_id', 'learner_id', 'progress_pct', 'completed_at', 'computed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'assignment_id' => 'integer',
            'learner_id' => 'integer',
            'progress_pct' => 'integer',
            'completed_at' => 'datetime',
            'computed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Assignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    /** Done after the due date ("выполнено с опозданием"). */
    public function isLate(): bool
    {
        return $this->completed_at !== null && $this->completed_at->gt($this->assignment->due_at);
    }
}
