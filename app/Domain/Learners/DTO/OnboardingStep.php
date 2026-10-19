<?php

declare(strict_types=1);

namespace App\Domain\Learners\DTO;

/**
 * What the bot shows next: the step (`state` — one of the five steps or `done`), the translation
 * key of its message and the buttons. `columns` is only a layout hint for the keyboard.
 */
final readonly class OnboardingStep
{
    /** @param list<OnboardingOption> $options */
    public function __construct(
        public string $state,
        public string $messageKey,
        public array $options = [],
        public int $columns = 2,
    ) {}

    public function isDone(): bool
    {
        return $this->state === 'done';
    }
}
