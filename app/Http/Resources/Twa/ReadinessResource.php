<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Learning\DTO\LevelReadinessScreen;
use Illuminate\Http\Request;

/**
 * GET /api/twa/courses/{slug}/readiness (docs/04 A.20). The percent is the floor of the share of the course's words the learner
 * recalls after a week or more; the quiz of the week is a separate figure.
 *
 * @property LevelReadinessScreen $resource
 */
final class ReadinessResource extends TwaResource
{
    /**
     * @return array{
     *   course: array{id: int, slug: string, title: string, level: string|null, level_label: string|null},
     *   percent: int,
     *   words: array{total: int, learned: int, mastered: int, known: int, in_progress: int, not_started: int},
     *   thresholds: array{known_days: int, mastered_days: int},
     *   modules: list<array{number: int, title: string|null, learned: int, total: int}>,
     *   quiz: array{enabled: false}|array{
     *     enabled: true, questions: int, seconds: int, week_start: string,
     *     this_week: array{correct: int, total: int, percent: int, finished_at: string|null}|null,
     *     history: list<array{week_start: string, correct: int, total: int, percent: int}>,
     *     best_percent: int|null
     *   }
     * }
     */
    public function toArray(Request $request): array
    {
        $screen = $this->resource;
        $readiness = $screen->readiness;

        $quiz = ['enabled' => false];
        if ($screen->week->enabled) {
            $percents = array_map(static fn (array $row): int => $row['percent'], $screen->history);
            $quiz = [
                'enabled' => true,
                'questions' => $screen->quizQuestions,
                'seconds' => $screen->quizSeconds,
                'week_start' => $screen->week->weekStart,
                'this_week' => $screen->week->thisWeek,
                'history' => $screen->history,
                'best_percent' => $percents === [] ? null : max($percents),
            ];
        }

        return [
            'course' => [
                'id' => $screen->course->id,
                'slug' => $screen->course->slug,
                'title' => $screen->courseTitle,
                'level' => $screen->course->level,
                'level_label' => $readiness->levelLabel,
            ],
            'percent' => $readiness->percent,
            'words' => [
                'total' => $readiness->total,
                'learned' => $readiness->learned,
                'mastered' => $readiness->mastered,
                'known' => $readiness->known,
                'in_progress' => $readiness->inProgress,
                'not_started' => $readiness->notStarted,
            ],
            'thresholds' => $screen->thresholds,
            'modules' => $screen->modules,
            'quiz' => $quiz,
        ];
    }
}
