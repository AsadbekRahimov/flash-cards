<?php

declare(strict_types=1);

namespace App\Domain\Learning\Exceptions;

use DomainException;

final class TrainingSessionException extends DomainException
{
    public const REASON_NOT_TEACHER     = 'not_teacher';
    public const REASON_GROUP_INACTIVE  = 'group_inactive';
    public const REASON_STAGE_NOT_FOUND = 'stage_not_found';
    public const REASON_LESSON_NOT_FOUND = 'lesson_not_found';

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
}
