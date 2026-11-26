<?php

declare(strict_types=1);

namespace App\Domain\Content\Exceptions;

use DomainException;

/**
 * A course may not change its kind (`exam_tag` between «phrases» and anything else) while it holds words of the other kind (docs/35
 * §3.3 a, c). The message is English, for logs; the panel shows `admin.courses.errors.kind_change`.
 */
final class CourseKindException extends DomainException
{
    public function __construct(public readonly bool $toPhrases)
    {
        parent::__construct($toPhrases
            ? 'The course holds words: it cannot become a course of phrases.'
            : 'The course holds phrases: it cannot stop being a course of phrases.');
    }
}
