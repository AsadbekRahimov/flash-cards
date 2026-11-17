<?php

declare(strict_types=1);

namespace App\Domain\Learning\Exceptions;

use RuntimeException;

/**
 * Refusals of `LevelQuizService::open` (docs/13 §8.5). `errorCode` is the API `error.code`, `httpStatus()` its status.
 */
final class LevelQuizException extends RuntimeException
{
    public const DISABLED = 'level_quiz_disabled';

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

    public static function disabled(): self
    {
        return new self(self::DISABLED, 'The quiz of the week is switched off.', 404);
    }

    public static function notEnoughWords(int $have, int $need): self
    {
        return new self(self::NOT_ENOUGH_WORDS, "The course has only {$have} active words, the quiz needs at least {$need}.", 422);
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
