<?php

declare(strict_types=1);

namespace App\Domain\Learning\Support;

/**
 * The sprint of a `daily_review` session (`config/learning.php` → `sprint.*`, docs/06 §13.2): the round's portion of
 * day-based reviews and of new words. Built from the config at resolution time (not a singleton), like LadderPolicy.
 *
 * A limit of 0 or less means "none of that kind in a round"; `enabled = false` means no sprint at all — the session
 * runs until the scope is empty, as before.
 */
final readonly class SprintPolicy
{
    public function __construct(
        public bool $enabled = true,
        public int $newWords = 6,
        public int $dueCards = 15,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array<string, mixed> $c */
        $c = (array) config('learning.sprint', []);

        return new self(
            enabled: (bool) ($c['enabled'] ?? true),
            newWords: max(0, (int) ($c['new_words'] ?? 6)),
            dueCards: max(0, (int) ($c['due_cards'] ?? 15)),
        );
    }
}
