<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Practice\DTO\PracticeEntry;
use App\Domain\Practice\Services\PracticeAccess;
use App\Models\Course;
use App\Models\Learner;
use App\Models\TrainingSession;

/**
 * The entry to the practice that the end of a training session offers (docs/16 §11.1, `practice_entry` of
 * `POST /training/sessions/{id}/finish`): the lesson the learner has just studied (`lesson`), or the course of a daily review
 * (`course_id`, the snapshot of the active course). The practice is a secondary action after the lesson, never the main one
 * (docs/15 §4).
 *
 * It lives in the Learning domain on purpose: it reads a {@see TrainingSession}, and the code of the practice must not import the
 * models and services of the learning (`PracticeIsolationTest`). The dependency runs one way - Learning asks the practice whether
 * it is open ({@see PracticeAccess}) and takes its DTO ({@see PracticeEntry}); the practice knows nothing of training sessions.
 *
 * There is no entry (`null`) when
 *  - the session is not the learner's own, or is a group one (a teacher closes those, there is no "practice after");
 *  - the practice is closed to the learner (`PracticeAccess::state`: `off`, `not_in_pilot`); a Free learner at `PRACTICE_PLAN=pro`
 *    gets the entry - the hub shows the cards behind Pro, like the course screen does;
 *  - a daily review has no course (the active course is gone or no longer available);
 *  - the hub could not open the course for the learner: the course is not published or is a private one of a center (a lesson
 *    opened through an assignment) - the same availability rule as the hub (`CourseCatalogService::isAvailable`).
 *
 * The material is NOT looked at. Counting the words and the tasks of the four modes costs the queries of a hub (docs/16 §13: at
 * least four) on a request that must stay light; a lesson just studied has its words introduced, and a course or a lesson
 * without enough material is explained by the hub itself ("Not enough material", "Learn the lesson"). The price of the decision
 * is ONE query (the lesson with its course, or the course of the review) beyond what `finish` already does.
 */
final class PracticeEntryResolver
{
    public function __construct(
        private readonly PracticeAccess $access,
        private readonly CourseCatalogService $catalog,
    ) {}

    public function forSession(TrainingSession $session, Learner $learner): ?PracticeEntry
    {
        if ((int) $session->learner_id !== $learner->id || $session->telegram_group_id !== null) {
            return null;
        }
        if (! $this->access->state($learner)->showsEntries()) {
            return null;
        }

        return match ($session->mode) {
            TrainingSession::MODE_LESSON => $this->lessonEntry($session, $learner),
            TrainingSession::MODE_DAILY_REVIEW => $this->courseEntry($session, $learner),
            default => null,
        };
    }

    private function lessonEntry(TrainingSession $session, Learner $learner): ?PracticeEntry
    {
        if ($session->lesson_id === null) {
            return null;
        }

        // The lesson, its stage and the access columns of its course in one query.
        $lesson = $this->catalog->lessonWithContext((int) $session->lesson_id);
        $course = $lesson?->stage?->course;
        if ($lesson === null || $course === null || ! $this->catalog->isAvailable($course, $learner)) {
            return null;
        }

        return new PracticeEntry($course->slug, $lesson->id);
    }

    private function courseEntry(TrainingSession $session, Learner $learner): ?PracticeEntry
    {
        if ($session->course_id === null) {
            return null;
        }

        $slug = Course::query()->availableTo($learner->id)->whereKey($session->course_id)->value('slug');

        return is_string($slug) ? new PracticeEntry($slug, null) : null;
    }
}
