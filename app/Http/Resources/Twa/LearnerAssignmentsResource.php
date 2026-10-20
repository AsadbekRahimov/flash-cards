<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Organizations\DTO\LearnerAssignment;
use Illuminate\Http\Request;

/**
 * GET /api/twa/assignments (docs/04 A.9): `{"assignments": [...]}` — the active assignments of the
 * learner's groups with the learner's OWN progress. `lesson_id` is what `POST /training/sessions
 * {mode: lesson}` takes; nothing about other learners is exposed.
 *
 * @property list<LearnerAssignment> $resource
 */
final class LearnerAssignmentsResource extends TwaResource
{
    /**
     * @return array{assignments: list<array{id: int, title: string, group: string, lesson_id: int, stage: int, lesson: int, due_at: string, overdue: bool, progress_pct: int, completed: bool, completed_at: string|null, late: bool}>}
     */
    public function toArray(Request $request): array
    {
        return [
            'assignments' => array_map(static function (LearnerAssignment $item): array {
                $assignment = $item->assignment;

                return [
                    'id' => $assignment->id,
                    'title' => $assignment->displayTitle(),
                    'group' => $assignment->group->title,
                    'lesson_id' => $assignment->lesson_id,
                    'stage' => $assignment->lesson->stage->number,
                    'lesson' => $assignment->lesson->number,
                    'due_at' => $assignment->due_at->toIso8601String(),
                    'overdue' => $assignment->isOverdue(),
                    'progress_pct' => $item->progressPct,
                    'completed' => $item->completed(),
                    'completed_at' => $item->completedAt?->toIso8601String(),
                    'late' => $item->late(),
                ];
            }, $this->resource),
        ];
    }
}
