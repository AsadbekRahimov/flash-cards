<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Learning\Exceptions\TrainingSessionException;
use App\Models\Lesson;
use App\Models\TelegramGroup;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
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
    public function __construct(private readonly CourseCatalogService $catalog) {}

    /**
     * Teacher command `/start_training <stage> <lesson>`: the numbers are resolved inside the
     * course of the group (`telegram_groups.course_id`, else the default course) — stage numbers
     * repeat across courses.
     *
     * @param  string  $source  stored on a NEWLY created session only (`command`, `reminder`, ...);
     *                          an already open session keeps the source it was created with.
     *
     * @throws TrainingSessionException when the stage/lesson does not exist or
     *                                  the user does not teach the group.
     */
    public function open(
        TelegramGroup $group,
        int $stageNumber,
        int $lessonNumber,
        User $teacher,
        string $source = 'command',
    ): TrainingSession {
        $this->assertCanOpen($group, $teacher);

        $stage = $this->catalog->findStage($this->catalog->courseForGroup($group), $stageNumber);
        if ($stage === null) {
            throw TrainingSessionException::stageNotFound($stageNumber);
        }

        $lesson = $this->catalog->findLesson($stage, $lessonNumber);
        if ($lesson === null) {
            throw TrainingSessionException::lessonNotFound($stageNumber, $lessonNumber);
        }

        return $this->openLesson($group, $lesson, $teacher, $source);
    }

    /**
     * Same as {@see open()} for a lesson that is already known (reminder links): no number
     * lookup, so the lesson need not belong to the course of the group.
     *
     * @throws TrainingSessionException when the user does not teach the group or it is inactive.
     */
    public function openForLesson(
        TelegramGroup $group,
        Lesson $lesson,
        User $teacher,
        string $source = 'command',
    ): TrainingSession {
        $this->assertCanOpen($group, $teacher);

        return $this->openLesson($group, $lesson, $teacher, $source);
    }

    private function assertCanOpen(TelegramGroup $group, User $teacher): void
    {
        if (! $this->userTeachesGroup($teacher, $group)) {
            throw TrainingSessionException::notTeacher();
        }

        if ($group->status !== 'active') {
            throw TrainingSessionException::groupInactive();
        }
    }

    private function openLesson(TelegramGroup $group, Lesson $lesson, User $teacher, string $source): TrainingSession
    {
        try {
            return DB::transaction(function () use ($group, $lesson, $teacher, $source): TrainingSession {
                /** @var TrainingSession|null $existing */
                $existing = TrainingSession::query()
                    ->where('telegram_group_id', $group->id)
                    ->where('lesson_id', $lesson->id)
                    ->where('mode', 'group_lesson')
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
                    'mode' => 'group_lesson',
                    'source' => $source,
                    'status' => 'open',
                    'started_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            /** @var TrainingSession $existing */
            $existing = TrainingSession::query()
                ->where('telegram_group_id', $group->id)
                ->where('lesson_id', $lesson->id)
                ->where('status', 'open')
                ->firstOrFail();

            return $existing;
        }
    }

    private function userTeachesGroup(User $user, TelegramGroup $group): bool
    {
        return DB::table('teacher_groups')
            ->where('user_id', $user->id)
            ->where('telegram_group_id', $group->id)
            ->exists();
    }
}
