<?php

declare(strict_types=1);

namespace App\Domain\Learning\Support;

/**
 * The round of hard words (`config/learning.php` → `hard_round.*`, docs/06 §14), read at resolution time like {@see SprintPolicy}.
 * The numbers are kept sane: a round holds at least one word, `min_words` is within 1..`words`, at least one round a day, a word
 * qualifies by failing at least once, `due_within_days >= 0` (the horizon of a candidate's term). `enabled = false` is the kill switch
 * (no block, no offer, no opening).
 */
final readonly class HardRoundPolicy
{
    public function __construct(
        public bool $enabled = true,
        public int $words = 10,
        public int $minWords = 6,
        public int $perDay = 1,
        public int $lapsesFrom = 2,
        public int $dueWithinDays = 2,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array<string, mixed> $c */
        $c = (array) config('learning.hard_round', []);

        $words = max(1, (int) ($c['words'] ?? 10));

        return new self(
            enabled: (bool) ($c['enabled'] ?? true),
            words: $words,
            minWords: min($words, max(1, (int) ($c['min_words'] ?? 6))),
            perDay: max(1, (int) ($c['per_day'] ?? 1)),
            lapsesFrom: max(1, (int) ($c['lapses_from'] ?? 2)),
            dueWithinDays: max(0, (int) ($c['due_within_days'] ?? 2)),
        );
    }
}
