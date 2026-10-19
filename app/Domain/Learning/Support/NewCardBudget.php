<?php

declare(strict_types=1);

namespace App\Domain\Learning\Support;

use Carbon\CarbonImmutable;
use Closure;

/**
 * How many NEW cards (first serve of a word) the learner may still receive today
 * (docs/12 §5.3). Repetitions are never limited.
 *
 * `remaining()` is evaluated by CardPicker INSIDE the new-card transaction, after
 * the learner row has been locked, so a resolver that counts today's serves
 * (task 1.4b, `NewWordQuota`) sees every card already issued by a concurrent tab
 * and two tabs cannot jointly exceed the limit. `null` = unlimited (Pro).
 */
final readonly class NewCardBudget
{
    /**
     * @param  Closure(int, CarbonImmutable): ?int|null  $resolver  (learnerId, now) => remaining; null = unlimited
     */
    private function __construct(private ?Closure $resolver) {}

    public static function unlimited(): self
    {
        return new self(null);
    }

    /** A fixed number — for tests and callers that already hold the learner lock. */
    public static function of(int $remaining): self
    {
        return new self(fn (): int => $remaining);
    }

    /**
     * Computed lazily under the learner lock.
     *
     * @param  Closure(int, CarbonImmutable): ?int  $resolver  (learnerId, now) => remaining today, null = unlimited
     */
    public static function lazy(Closure $resolver): self
    {
        return new self($resolver);
    }

    public function isUnlimited(): bool
    {
        return $this->resolver === null;
    }

    /** @return int|null remaining new cards for today; null = unlimited */
    public function remaining(int $learnerId, CarbonImmutable $now): ?int
    {
        if ($this->resolver === null) {
            return null;
        }

        $remaining = ($this->resolver)($learnerId, $now);

        return $remaining === null ? null : max(0, $remaining);
    }
}
