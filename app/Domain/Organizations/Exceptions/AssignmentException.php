<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Exceptions;

use RuntimeException;

/**
 * Why an assignment could not be created (docs/13 §1.4, §2.3 AssignmentService). All are 422.
 *
 *   foreign_group       the group is not a group of the organization
 *   group_without_course the group has no course, so there is no lesson list to pick from
 *   lesson_not_in_course the lesson is not a lesson of the group's course
 *   group_inactive      the group is not active (the bot was removed)
 *   lesson_not_available the course of the lesson is not published, so nobody could study it
 *   invalid_due         the due date is not in the future
 *   invalid_threshold   the threshold is outside 1..100
 */
final class AssignmentException extends RuntimeException
{
    public const FOREIGN_GROUP = 'foreign_group';

    public const GROUP_WITHOUT_COURSE = 'group_without_course';

    public const LESSON_NOT_IN_COURSE = 'lesson_not_in_course';

    public const GROUP_INACTIVE = 'group_inactive';

    public const LESSON_NOT_AVAILABLE = 'lesson_not_available';

    public const INVALID_DUE = 'invalid_due';

    public const INVALID_THRESHOLD = 'invalid_threshold';

    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function foreignGroup(int $groupId, int $organizationId): self
    {
        return new self(self::FOREIGN_GROUP, "Group #{$groupId} does not belong to organization #{$organizationId}.");
    }

    public static function groupWithoutCourse(int $groupId): self
    {
        return new self(self::GROUP_WITHOUT_COURSE, "Group #{$groupId} has no course.");
    }

    public static function lessonNotInCourse(int $lessonId, int $groupId): self
    {
        return new self(self::LESSON_NOT_IN_COURSE, "Lesson #{$lessonId} is not a lesson of the course of group #{$groupId}.");
    }

    public static function groupInactive(int $groupId): self
    {
        return new self(self::GROUP_INACTIVE, "Group #{$groupId} is not active.");
    }

    public static function lessonNotAvailable(int $lessonId): self
    {
        return new self(self::LESSON_NOT_AVAILABLE, "Lesson #{$lessonId} belongs to a course that is not published.");
    }

    public static function invalidDue(): self
    {
        return new self(self::INVALID_DUE, 'The due date must be in the future.');
    }

    public static function invalidThreshold(int $threshold): self
    {
        return new self(self::INVALID_THRESHOLD, "The threshold {$threshold} is outside 1..100.");
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
