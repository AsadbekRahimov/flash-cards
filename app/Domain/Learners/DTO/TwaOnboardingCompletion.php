<?php

declare(strict_types=1);

namespace App\Domain\Learners\DTO;

/**
 * The answer of `POST /onboarding/complete` (docs/40 §2.1): the learner's active course after the onboarding (the one chosen now or
 * the one they already had; null - none available) and the first lesson with new words in it, which "Today" offers as step 1.
 */
final readonly class TwaOnboardingCompletion
{
    /** @param  array{slug: string, title: string}|null  $course */
    public function __construct(
        public ?array $course,
        public ?int $nextLessonId,
    ) {}
}
