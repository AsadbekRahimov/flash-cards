<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

use App\Domain\Billing\Exceptions\PlanException;

/**
 * Tariff plan. Ranks, feature lists and limits come from config/billing.php
 * (docs/12 §3.4); the enum only names the keys so the rest of the code never
 * compares raw strings.
 *
 * Use `fromKey()` for any key that comes from outside the enum (forms, stored
 * rows, config): unlike the native `from()`, it raises a domain exception the
 * HTTP / bot layer understands instead of a ValueError.
 */
enum Plan: string
{
    case Free = 'free';
    case Pro = 'pro';

    /** @throws PlanException plan_unknown for a key that is not a configured plan */
    public static function fromKey(string $key): self
    {
        $plan = self::tryFrom($key);

        if ($plan === null || ! is_array(config("billing.plans.{$key}"))) {
            throw PlanException::unknown($key);
        }

        return $plan;
    }

    public function rank(): int
    {
        return (int) config("billing.plans.{$this->value}.rank", 0);
    }

    /** @return list<string> */
    public function features(): array
    {
        /** @var array<int, mixed> $features */
        $features = (array) config("billing.plans.{$this->value}.features", []);

        return array_values(array_map(static fn (mixed $f): string => (string) $f, $features));
    }

    /** New words a learner may start per local day; null = unlimited. */
    public function newWordsPerDay(): ?int
    {
        $limit = config("billing.plans.{$this->value}.limits.new_words_per_day");

        return $limit === null ? null : max(0, (int) $limit);
    }

    public function outranks(self $other): bool
    {
        return $this->rank() > $other->rank();
    }
}
