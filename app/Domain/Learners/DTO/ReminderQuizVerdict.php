<?php

declare(strict_types=1);

namespace App\Domain\Learners\DTO;

use App\Domain\Learners\Enums\ReminderQuizStatus;
use App\Domain\Learners\Services\ReminderQuizAnswerService;

/**
 * The outcome of a tap on a reminder question ({@see ReminderQuizAnswerService}).
 * `repeated` = this question was already answered (a double tap, a retried update): the same verdict again, no new event.
 * An expired verdict carries no content (`word` / `answer` empty, `kind` null).
 */
final readonly class ReminderQuizVerdict
{
    public function __construct(
        public ReminderQuizStatus $status,
        public bool $correct = false,
        public string $word = '',
        public string $answer = '',
        public int $dueCount = 0,
        public ?string $kind = null,
        public bool $repeated = false,
    ) {}

    public static function expired(): self
    {
        return new self(ReminderQuizStatus::Expired);
    }
}
