<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learners\Services\GroupMembershipService;
use App\Domain\Learning\Support\DueRepetitionCriteria;
use App\Models\Learner;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\WordRepetition;
use Throwable;

final class ReviewReminderLinkBuilder
{
    public function __construct(
        private readonly TrainingSessionService $trainingSessions,
        private readonly DueRepetitionCriteria $due,
        private readonly GroupMembershipService $memberships,
    ) {}

    public function buildTrainingUrl(Learner $learner): string
    {
        $base = rtrim((string) config('twa.base_url'), '/');

        try {
            $session = $this->openSessionForFirstDueLesson($learner);

            return "{$base}/twa/training/{$session->id}";
        } catch (Throwable) {
            return "{$base}/twa/";
        }
    }

    private function openSessionForFirstDueLesson(Learner $learner): TrainingSession
    {
        // Same "due" rule as the reminder count (DueRepetitionCriteria), so the
        // deep link opens the lesson the message is about.
        /** @var WordRepetition|null $due */
        $due = $this->due->apply(WordRepetition::query()->where('learner_id', $learner->id))
            ->orderByDesc('is_hard')
            ->orderBy('next_review_at')
            ->with('word.lesson.stage')
            ->first();

        $lesson = $due?->word?->lesson;
        $stage = $lesson?->stage;

        if ($lesson === null || $stage === null) {
            throw new \RuntimeException('review_reminder_context_incomplete');
        }

        // The session is opened on behalf of a teacher of one of the learner's active groups.
        foreach ($this->memberships->activeGroups($learner) as $group) {
            $teacher = $group->teachers()
                ->orderByDesc('teacher_groups.is_primary')
                ->first();

            if ($teacher instanceof User) {
                return $this->trainingSessions->open(
                    $group,
                    (int) $stage->number,
                    (int) $lesson->number,
                    $teacher,
                );
            }
        }

        throw new \RuntimeException('review_reminder_context_incomplete');
    }
}
