<?php

declare(strict_types=1);

namespace App\Domain\Learning\Exceptions;

use DomainException;

final class ExamSessionException extends DomainException
{
    public const REASON_NOT_TEACHER      = 'not_teacher';
    public const REASON_GROUP_INACTIVE   = 'group_inactive';
    public const REASON_STAGE_NOT_FOUND  = 'stage_not_found';
    public const REASON_LESSON_NOT_FOUND = 'lesson_not_found';
    public const REASON_NOT_ENOUGH_WORDS = 'not_enough_words';
    public const REASON_ALREADY_OPEN     = 'already_open';
    public const REASON_NO_OPEN_EXAM     = 'no_open_exam';
    public const REASON_INVALID_DURATION = 'invalid_duration';

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

    public static function notEnoughWords(int $have, int $need): self
    {
        return new self(
            self::REASON_NOT_ENOUGH_WORDS,
            "Lesson has only {$have} words, but exam needs at least {$need}.",
        );
    }

    public static function alreadyOpen(): self
    {
        return new self(self::REASON_ALREADY_OPEN, 'An open exam already exists in this group.');
    }

    public static function noOpenExam(): self
    {
        return new self(self::REASON_NO_OPEN_EXAM, 'There is no open exam in this group.');
    }

    public static function invalidDuration(int $minutes): self
    {
        return new self(
            self::REASON_INVALID_DURATION,
            "Duration {$minutes}m is outside the allowed 1..30 range.",
        );
    }
}
