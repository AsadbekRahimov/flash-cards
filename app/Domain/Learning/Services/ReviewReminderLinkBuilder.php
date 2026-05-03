<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Models\Student;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\WordRepetition;
use Throwable;

final class ReviewReminderLinkBuilder
{
    public function __construct(private readonly TrainingSessionService $trainingSessions) {}

    public function buildTrainingUrl(Student $student): string
    {
        $base = rtrim((string) config('twa.base_url'), '/');

        try {
            $session = $this->openSessionForFirstDueLesson($student);

            return "{$base}/twa/training/{$session->id}";
        } catch (Throwable) {
            return "{$base}/twa/";
        }
    }

    private function openSessionForFirstDueLesson(Student $student): TrainingSession
    {
        /** @var WordRepetition|null $due */
        $due = WordRepetition::query()
            ->where('student_id', $student->id)
            ->where('next_review_at', '<=', now())
            ->orderByDesc('is_hard')
            ->orderBy('next_review_at')
            ->with('word.lesson.stage')
            ->first();

        $lesson = $due?->word?->lesson;
        $stage = $lesson?->stage;
        $group = $student->group;
        $teacher = $group?->teachers()
            ->orderByDesc('teacher_groups.is_primary')
            ->first();

        if ($lesson === null || $stage === null || $group === null || ! $teacher instanceof User) {
            throw new \RuntimeException('review_reminder_context_incomplete');
        }

        return $this->trainingSessions->open(
            $group,
            (int) $stage->number,
            (int) $lesson->number,
            $teacher,
        );
    }
}
