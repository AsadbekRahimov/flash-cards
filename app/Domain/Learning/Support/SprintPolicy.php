<?php

declare(strict_types=1);

namespace App\Domain\Learning\Support;

/**
 * The sprint of a `daily_review` session (`config/learning.php` → `sprint.*`, docs/06 §13.2): the round's portion of
 * day-based reviews and of new words. Built from the config at resolution time (not a singleton), like LadderPolicy.
 *
 * A limit of 0 or less means "no limit of that kind" (null here): the round is bounded by the other limit alone, or by
 * nothing — a round that could never hold a card is not a round. `enabled = false` means no sprint at all — the session
 * runs until the scope is empty, as before. `debtHoldsNew`: while the learner owes more day-based reviews than one round
 * takes (`dueCards`), a round introduces no new words — the backlog is cleared first, as before the sprint.
 */
final readonly class SprintPolicy
{
    public function __construct(
        public bool $enabled = true,
        public ?int $newWords = 6,
        public ?int $dueCards = 15,
        public bool $debtHoldsNew = true,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array<string, mixed> $c */
        $c = (array) config('learning.sprint', []);

        return new self(
            enabled: (bool) ($c['enabled'] ?? true),
            newWords: self::limit($c['new_words'] ?? 6),
            dueCards: self::limit($c['due_cards'] ?? 15),
            debtHoldsNew: (bool) ($c['debt_holds_new'] ?? true),
        );
    }

    /** A positive number is the limit; 0, a negative number or junk is "no limit". */
    private static function limit(mixed $value): ?int
    {
        $limit = (int) $value;

        return $limit > 0 ? $limit : null;
    }
}
