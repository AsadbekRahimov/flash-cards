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
     *   streak: array{current: int, best: int, met_today: bool, at_risk: bool, freezes: int, frozen_yesterday: bool, held_by: string|null},
     *   active_course: array{
     *     id: int, slug: string, title: string,
     *     progress: array{learned: int, total: int},
     *     next_lesson: array{id: int, stage: int|null, lesson: int, title: string|null, words_left: int}|null,
     *     readiness: array{
     *       level_label: string|null, percent: int,
     *       words: array{total: int, learned: int, mastered: int, in_progress: int, not_started: int},
     *       quiz: array{enabled: bool, week_start?: string, this_week?: array{correct: int, total: int, percent: int}|null}|null
     *     }|null
     *   }|null,
     *   open_session_id: int|null,
     *   entitlement: array{plan: string, source: string, until: string|null, lapsed: array{provider: string, ended_at: string}|null},
     *   onboarding_completed: bool,
     *   league: array{tier: int, tier_key: string, rank: int, points: int, members_count: int}|null,
     *   timezone: string,
     *   game: array{available: bool, daily_available: bool, active_run: array{id: int, kind: string, progress: array{answered: int, total: int}}|null, class_caravan: array{id: int, stars_total: int, target_stars: int, ends_at: string}|null},
     *   practice: array{available: bool, pro_required: bool, modes: list<string>, battle: bool, pro_promise: string},
     *   hard_round: array{available: bool, reason: string|null, words: int, open_session_id: int|null, resets_at: string|null}|null,
     *   assignments_possible: bool,
     *   plan: array{
     *     step: int|null, done: bool,
     *     lesson: array{done: bool, lesson_id: int|null, next_lesson_id: int|null, words_left: int|null},
     *     training: array{lesson_id: int, course: string|null, title: string|null, words: int, done: bool, open_session_id: int|null}|null,
     *     game: array{lesson_id: int, course: string|null, mode: string, played: bool}|null
     *   }|null,
     *   score_today?: array<string, mixed>
     * }
     */
    public function toArray(Request $request): array
    {
        $summary = $this->resource;
        $course = $summary->activeCourse;
        $lesson = $course?->nextLesson;

        $payload = [
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
                    // The lesson's words never served to the learner ("5 new words"); the Free quota of the day may cut what is served.
                    'words_left' => $lesson->newWords,
                ],
                // The readiness to the level (docs/13 §8.5): null while switched off; `quiz` - the quiz of the week beside it.
                'readiness' => $course->readiness === null ? null : [
                    ...$course->readiness->toArray(),
                    'quiz' => $course->quiz?->toTodayArray() ?? ['enabled' => false],
                ],
            ],
            'open_session_id' => $summary->openSessionId,
            'entitlement' => [
                'plan' => $summary->entitlement->plan->value,
                'source' => $summary->entitlement->source,
                'until' => $summary->entitlement->until?->toIso8601String(),
                'lapsed' => $summary->lapsed,
            ],
            'onboarding_completed' => $summary->onboardingCompleted,
            'league' => $summary->league?->toArray(),
            'timezone' => $summary->timezone,
            'game' => $summary->game,
            // The practice card (docs/16 §3.3): the same flags as `/me.practice`, without its day limits - no second request for them.
            'practice' => $summary->practice,
            // The round of hard words (docs/06 §14): always present, null while `learning.hard_round.enabled` is off.
            'hard_round' => $summary->hardRound,
            // false - surely no assignment of a center (no active membership of a linked group): the TWA does not ask `/assignments`.
            'assignments_possible' => $summary->assignmentsPossible,
            // The plan of the day: lesson -> review of the lesson -> game on its words (docs/04 A.2a, docs/31 §5б).
            'plan' => $summary->plan?->toArray(),
        ];

        // "Today N of 100" of the word points (docs/32 §8.2): only while the rating day counts by v2 - outside `live` (and before the
        // start) the key is absent, the screen keeps its shape of before.
        return $summary->scoreToday === null ? $payload : [...$payload, 'score_today' => $summary->scoreToday->toArray()];
    }
}
