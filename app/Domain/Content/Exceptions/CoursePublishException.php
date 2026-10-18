<?php

declare(strict_types=1);

namespace App\Domain\Content\Exceptions;

use DomainException;

final class CoursePublishException extends DomainException
{
    public const REASON_FORBIDDEN = 'forbidden';

    public const REASON_UNKNOWN_STATUS = 'unknown_status';

    public const REASON_INVALID_TRANSITION = 'invalid_transition';

    public const REASON_DEFAULT_COURSE_PROTECTED = 'default_course_protected';

    public const REASON_TITLE_INCOMPLETE = 'title_incomplete';

    public const REASON_NO_LESSONS = 'no_lessons';

    public const REASON_NO_WORDS = 'no_words';

    public const REASON_TRANSLATIONS_BELOW_THRESHOLD = 'translations_below_threshold';

    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }

    public static function forbidden(): self
    {
        return new self(self::REASON_FORBIDDEN, 'Only an active admin can change the course status.');
    }

    public static function unknownStatus(string $status): self
    {
        return new self(self::REASON_UNKNOWN_STATUS, "Unknown course status '{$status}'.");
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return new self(self::REASON_INVALID_TRANSITION, "Course cannot go from '{$from}' to '{$to}'.");
    }

    public static function defaultCourseProtected(): self
    {
        return new self(self::REASON_DEFAULT_COURSE_PROTECTED, 'The default course serves every group without a course and cannot be archived.');
    }

    /** @param  list<string>  $locales  locales with a missing / empty title */
    public static function titleIncomplete(array $locales): self
    {
        return new self(
            self::REASON_TITLE_INCOMPLETE,
            'Course title is required in every supported language, missing: '.implode(', ', $locales).'.',
        );
    }

    public static function noLessons(): self
    {
        return new self(self::REASON_NO_LESSONS, 'The course has no lessons.');
    }

    public static function noWords(): self
    {
        return new self(self::REASON_NO_WORDS, 'The course has no active words.');
    }

    /** @param  list<string>  $locales */
    public static function translationsBelowThreshold(int $covered, int $total, float $threshold, array $locales): self
    {
        return new self(
            self::REASON_TRANSLATIONS_BELOW_THRESHOLD,
            sprintf(
                'Translations (%s) are complete for %d of %d words (%.1f%%), required: %.1f%%.',
                implode('+', $locales),
                $covered,
                $total,
                $total > 0 ? $covered / $total * 100 : 0,
                $threshold * 100,
            ),
        );
    }
}
