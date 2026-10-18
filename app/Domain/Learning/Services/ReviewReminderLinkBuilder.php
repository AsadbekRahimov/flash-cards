<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learners\Services\GroupMembershipService;
use App\Domain\Learning\Support\DueRepetitionCriteria;
use App\Models\Learner;
use App\Models\TelegramGroup;
use App\Models\TrainingReview;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\WordRepetition;
use Illuminate\Support\Collection;
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

        // The session is opened on behalf of a teacher of one of the learner's active groups,
        // preferring a group where they already studied this lesson.
        foreach ($this->groupsPreferringStudied($learner, (int) $lesson->id) as $group) {
            $teacher = $group->teachers()
                ->orderByDesc('teacher_groups.is_primary')
                ->first();

            if ($teacher instanceof User) {
                return $this->trainingSessions->open(
                    $group,
                    (int) $stage->number,
                    (int) $lesson->number,
                    $teacher,
                    source: 'reminder',
                );
            }
        }

        throw new \RuntimeException('review_reminder_context_incomplete');
    }

    /**
     * Active groups of the learner; those with group-lesson reviews of $lessonId by
     * the learner come first (the rest keep their order).
     *
     * @return Collection<int, TelegramGroup>
     */
    private function groupsPreferringStudied(Learner $learner, int $lessonId): Collection
    {
        $studiedGroupIds = TrainingSession::query()
            ->where('mode', 'group_lesson')
            ->where('lesson_id', $lessonId)
            ->whereIn('id', TrainingReview::query()
                ->where('learner_id', $learner->id)
                ->select('training_session_id'))
            ->pluck('telegram_group_id')
            ->all();

        return $this->memberships->activeGroups($learner)
            ->sortBy(fn (TelegramGroup $group): int => in_array($group->id, $studiedGroupIds, true) ? 0 : 1)
            ->values();
    }
}
