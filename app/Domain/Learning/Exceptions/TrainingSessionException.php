<?php

declare(strict_types=1);

namespace App\Domain\Learning\Exceptions;

use DomainException;

final class TrainingSessionException extends DomainException
{
    public const REASON_NOT_TEACHER = 'not_teacher';

    public const REASON_GROUP_INACTIVE = 'group_inactive';

    public const REASON_STAGE_NOT_FOUND = 'stage_not_found';

    public const REASON_LESSON_NOT_FOUND = 'lesson_not_found';

    /** `mode` is not a personal mode (`lesson` / `daily_review`). */
    public const REASON_INVALID_MODE = 'invalid_mode';

    /** `lesson` mode without a lesson, or `daily_review` with one. */
    public const REASON_LESSON_MISMATCH = 'lesson_mismatch';

    /** The lesson's course is not published (draft / review / archived). */
    public const REASON_LESSON_NOT_AVAILABLE = 'lesson_not_available';

    /** finish() on a group session: learners cannot close a teacher's lesson. */
    public const REASON_NOT_PERSONAL = 'not_personal';

    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }

    public static function notTeacher(): self
    {
        return new self(self::REASON_NOT_TEACHER, 'User is not a teacher of this group.');
    }

    public static function groupInactive(): self
    {
        return new self(self::REASON_GROUP_INACTIVE, 'Group is not active.');
    }

    public static function stageNotFound(int $stage): self
    {
        return new self(self::REASON_STAGE_NOT_FOUND, "Stage {$stage} not found.");
    }

    public static function lessonNotFound(int $stage, int $lesson): self
    {
        return new self(
            self::REASON_LESSON_NOT_FOUND,
            "Lesson {$lesson} not found in stage {$stage}.",
        );
    }

    public static function invalidMode(string $mode): self
    {
        return new self(self::REASON_INVALID_MODE, "'{$mode}' is not a personal training mode.");
    }

    public static function lessonRequired(): self
    {
        return new self(self::REASON_LESSON_MISMATCH, 'A lesson session needs a lesson.');
    }

    public static function lessonNotAllowed(): self
    {
        return new self(self::REASON_LESSON_MISMATCH, 'A daily review spans all lessons; do not pass one.');
    }

    public static function lessonNotAvailable(int $lessonId): self
    {
        return new self(self::REASON_LESSON_NOT_AVAILABLE, "Lesson {$lessonId} belongs to an unpublished course.");
    }

    public static function notPersonal(): self
    {
        return new self(self::REASON_NOT_PERSONAL, 'Only personal sessions can be finished by the learner.');
    }
}
