<?php

declare(strict_types=1);

namespace App\Domain\Learning\Support;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;

/**
 * How many NEW cards (first serve of a word) the learner may still receive today
 * (docs/12 §5.3). Repetitions are never limited.
 *
 * Two readings of the same number (docs/06 §4.2):
 *
 *   - {@see check()} is the DECISION. CardPicker calls it INSIDE the new-card transaction, after the learner row
 *     has been locked. It uses a fresh count projected on the candidate query when available, so it sees every card a
 *     concurrent tab already issued and two tabs cannot jointly exceed the limit. Never served from memory.
 *   - {@see remaining()} is the DISPLAY (`quota.new_words_remaining`). It reuses the number of the last check of
 *     this request, less the cards {@see spend()} recorded since, and only asks the resolver when nothing was
 *     checked yet (a due card was served, the picker never reached the new-card step). One request, one count.
 *
 * `null` = unlimited (Pro). An instance lives for one request: it is built by the NewCardBudgetResolver for one
 * step and must not be kept across requests or shared between learners.
 */
final class NewCardBudget
{
    private bool $hasChecked = false;

    private ?int $checked = null;

    private int $spent = 0;

    /**
     * @param  Closure(int, CarbonImmutable): ?int|null  $resolver  (learnerId, now) => remaining; null = unlimited
     * @param  Closure(int, CarbonImmutable): Builder|null  $usage  query for cards counted against the limit
     */
    private function __construct(
        private readonly ?Closure $resolver,
        private readonly ?Closure $usage = null,
        private readonly int $limit = 0,
    ) {}

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

    /** @param Closure(int, CarbonImmutable): Builder $usage */
    public static function counted(int $limit, Closure $usage): self
    {
        return new self(fn (int $learnerId, CarbonImmutable $now): int => $limit - $usage($learnerId, $now)->count(), $usage, $limit);
    }

    public function usageCount(int $learnerId, CarbonImmutable $now): ?Builder
    {
        return $this->usage === null ? null : ($this->usage)($learnerId, $now)->selectRaw('count(*)');
    }

    public function isUnlimited(): bool
    {
        return $this->resolver === null;
    }

    /**
     * The authoritative number, for the decision under the learner lock: always resolved afresh, never from memory.
     * Resets what {@see spend()} recorded, so a later {@see remaining()} counts from this check.
     *
     * @return int|null remaining new cards for today; null = unlimited
     */
    public function check(int $learnerId, CarbonImmutable $now, ?int $projectedUsage = null): ?int
    {
        if ($this->resolver === null) {
            return null;
        }

        $remaining = $projectedUsage === null ? ($this->resolver)($learnerId, $now) : $this->limit - $projectedUsage;
        $this->hasChecked = true;
        $this->checked = $remaining === null ? null : max(0, $remaining);
        $this->spent = 0;

        return $this->checked;
    }

    /** A new card was issued against the last {@see check()}: the display number drops by one. */
    public function spend(): void
    {
        $this->spent++;
    }

    /**
     * The number to show: the last check less what was spent since; resolved once when nothing was checked yet.
     *
     * @return int|null remaining new cards for today; null = unlimited
     */
    public function remaining(int $learnerId, CarbonImmutable $now): ?int
    {
        if ($this->resolver === null) {
            return null;
        }

        if (! $this->hasChecked) {
            $this->check($learnerId, $now);
        }

        return $this->checked === null ? null : max(0, $this->checked - $this->spent);
    }
}
