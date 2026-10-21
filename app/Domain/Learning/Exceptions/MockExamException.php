<?php

declare(strict_types=1);

namespace App\Domain\Learning\Exceptions;

use RuntimeException;

/**
 * Refusals of `MockExamService::open` (docs/13 §8.4). `errorCode` is the API
 * `error.code`, `httpStatus()` its status. `exam_simulator_pro_required` is a
 * paywall reason: the TWA answers it with the paywall sheet (`reason: exam.simulator`).
 */
final class MockExamException extends RuntimeException
{
    public const PRO_REQUIRED = 'exam_simulator_pro_required';

    public const COURSE_NOT_EXAM = 'course_not_exam';

    public const NOT_ENOUGH_WORDS = 'not_enough_words';

    public const NOT_ENOUGH_DISTRACTORS = 'not_enough_distractors';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        private readonly int $status,
    ) {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    public static function proRequired(): self
    {
        return new self(self::PRO_REQUIRED, 'Mock exams are a Pro feature.', 403);
    }

    public static function courseNotExam(): self
    {
        return new self(self::COURSE_NOT_EXAM, 'This course has no mock exam (no IELTS / SAT tag).', 422);
    }

    public static function notEnoughWords(int $have, int $need): self
    {
        return new self(self::NOT_ENOUGH_WORDS, "The course has only {$have} active words, a mock needs at least {$need}.", 422);
    }

    public static function notEnoughDistractors(string $word): self
    {
        return new self(
            self::NOT_ENOUGH_DISTRACTORS,
            "No real distractor could be found for the word \"{$word}\".",
            422,
        );
    }
}
