<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Exceptions\TrainingSessionException;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Opens and closes training sessions bound to (telegram_group, lesson, teacher).
 *
 * A single (group, lesson) pair can have at most one `open` session at a time;
 * if one already exists, it is returned as-is so the teacher's `/start_training`
 * command is idempotent.
 */
final class TrainingSessionService
{
    /**
     * @throws TrainingSessionException when the stage/lesson does not exist or
     *                                  the user does not teach the group.
     */
    public function open(
        TelegramGroup $group,
        int $stageNumber,
        int $lessonNumber,
        User $teacher,
    ): TrainingSession {
        if (! $this->userTeachesGroup($teacher, $group)) {
            throw TrainingSessionException::notTeacher();
        }

        if ($group->status !== 'active') {
            throw TrainingSessionException::groupInactive();
        }

        /** @var Stage|null $stage */
        $stage = Stage::query()->where('number', $stageNumber)->first();
        if ($stage === null) {
            throw TrainingSessionException::stageNotFound($stageNumber);
        }

        /** @var Lesson|null $lesson */
        $lesson = Lesson::query()
            ->where('stage_id', $stage->id)
            ->where('number', $lessonNumber)
            ->first();

        if ($lesson === null) {
            throw TrainingSessionException::lessonNotFound($stageNumber, $lessonNumber);
        }

        return DB::transaction(function () use ($group, $lesson, $teacher): TrainingSession {
            /** @var TrainingSession|null $existing */
            $existing = TrainingSession::query()
                ->where('telegram_group_id', $group->id)
                ->where('lesson_id', $lesson->id)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            return TrainingSession::query()->create([
                'telegram_group_id' => $group->id,
                'lesson_id' => $lesson->id,
                'started_by_user_id' => $teacher->id,
                'status' => 'open',
                'started_at' => now(),
            ]);
        });
    }

    private function userTeachesGroup(User $user, TelegramGroup $group): bool
    {
        return DB::table('teacher_groups')
            ->where('user_id', $user->id)
            ->where('telegram_group_id', $group->id)
            ->exists();
    }
}
