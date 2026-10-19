<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Learners\DTO\TodaySummary;
use Illuminate\Http\Request;

/**
 * GET /api/twa/today (docs/12 §7.3, docs/04 A.2a).
 *
 * @property TodaySummary $resource
 */
final class TodayResource extends TwaResource
{
    /**
     * @return array{
     *   due_count: int,
     *   new_words_remaining: int|null,
     *   goal: array{target: int, done: int, met: bool},
     *   streak: array{current: int, best: int, met_today: bool, at_risk: bool},
     *   active_course: array{
     *     id: int, slug: string, title: string,
     *     progress: array{learned: int, total: int},
     *     next_lesson: array{id: int, stage: int|null, lesson: int, title: string|null}|null
     *   }|null,
     *   open_session_id: int|null,
     *   entitlement: array{plan: string, source: string, until: string|null},
     *   onboarding_completed: bool
     * }
     */
    public function toArray(Request $request): array
    {
        $summary = $this->resource;
        $course = $summary->activeCourse;
        $lesson = $course?->nextLesson;

        return [
            'due_count' => $summary->dueCount,
            'new_words_remaining' => $summary->newWordsRemaining,
            'goal' => $summary->progress->goal->toArray(),
            'streak' => $summary->progress->streak->toArray(),
            'active_course' => $course === null ? null : [
                'id' => $course->id,
                'slug' => $course->slug,
                'title' => $course->title,
                'progress' => ['learned' => $course->learned, 'total' => $course->total],
                'next_lesson' => $lesson === null ? null : [
                    'id' => $lesson->id,
                    'stage' => $lesson->stage,
                    'lesson' => $lesson->lesson,
                    'title' => $lesson->title,
                ],
            ],
            'open_session_id' => $summary->openSessionId,
            'entitlement' => [
                'plan' => $summary->entitlement->plan->value,
                'source' => $summary->entitlement->source,
                'until' => $summary->entitlement->until?->toIso8601String(),
            ],
            'onboarding_completed' => $summary->onboardingCompleted,
        ];
    }
}
