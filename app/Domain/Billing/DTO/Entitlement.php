<?php

declare(strict_types=1);

namespace App\Domain\Billing\DTO;

use App\Domain\Billing\Enums\Plan;
use Carbon\CarbonImmutable;

/**
 * What a learner is entitled to right now (docs/12 §3.4, §5.1).
 *
 * Immutable snapshot; cached by EntitlementResolver as a plain array so a
 * deploy that changes this class never has to deal with stale serialized objects.
 */
final readonly class Entitlement
{
    public const SOURCE_FREE = 'free';

    public const SOURCE_SUBSCRIPTION = 'subscription';

    public const SOURCE_SPONSOR_PREFIX = 'sponsor:organization:';

    /**
     * @param  string  $source  `free` | `subscription` | `sponsor:organization:<id>`
     * @param  CarbonImmutable|null  $until  when the plan stops applying (null: no known end — Free or a perpetual grant)
     * @param  list<string>  $features
     * @param  int|null  $newWordsPerDay  null = unlimited
     * @param  int|null  $subscriptionId  the subscription the plan comes from (null for Free)
     */
    public function __construct(
        public Plan $plan,
        public string $source,
        public ?CarbonImmutable $until,
        public array $features,
        public ?int $newWordsPerDay,
        public ?int $subscriptionId = null,
    ) {}

    public static function free(): self
    {
        return self::fromPlan(Plan::Free, self::SOURCE_FREE, null, null);
    }

    public static function fromPlan(Plan $plan, string $source, ?CarbonImmutable $until, ?int $subscriptionId): self
    {
        return new self(
            plan: $plan,
            source: $source,
            until: $until,
            features: $plan->features(),
            newWordsPerDay: $plan->newWordsPerDay(),
            subscriptionId: $subscriptionId,
        );
    }

    public static function sponsorSource(int $organizationId): string
    {
        return self::SOURCE_SPONSOR_PREFIX.$organizationId;
    }

    public function can(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }

    public function isUnlimited(): bool
    {
        return $this->newWordsPerDay === null;
    }

    public function isSponsored(): bool
    {
        return str_starts_with($this->source, self::SOURCE_SPONSOR_PREFIX);
    }

    /** Whether the snapshot still describes the present moment (its end has not passed). */
    public function isCurrentAt(CarbonImmutable $now): bool
    {
        return $this->until === null || $this->until->greaterThan($now);
    }

    /**
     * @return array{plan:string, source:string, until:string|null, features:list<string>, new_words_per_day:int|null, subscription_id:int|null}
     */
    public function toArray(): array
    {
        return [
            'plan' => $this->plan->value,
            'source' => $this->source,
            'until' => $this->until?->toIso8601String(),
            'features' => $this->features,
            'new_words_per_day' => $this->newWordsPerDay,
            'subscription_id' => $this->subscriptionId,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        /** @var array<int, mixed> $features */
        $features = is_array($data['features'] ?? null) ? $data['features'] : [];

        return new self(
            plan: Plan::from((string) ($data['plan'] ?? Plan::Free->value)),
            source: (string) ($data['source'] ?? self::SOURCE_FREE),
            until: isset($data['until']) && is_string($data['until']) ? CarbonImmutable::parse($data['until']) : null,
            features: array_values(array_map(static fn (mixed $f): string => (string) $f, $features)),
            newWordsPerDay: isset($data['new_words_per_day']) ? (int) $data['new_words_per_day'] : null,
            subscriptionId: isset($data['subscription_id']) ? (int) $data['subscription_id'] : null,
        );
    }
}
