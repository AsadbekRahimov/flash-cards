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
     * The cache form (docs/12 §5.2): WHAT the learner has - the plan, its source, its end, the subscription. The features
     * and the limit are read from `config/billing.php` on every read ({@see fromArray()}), so a release that adds a feature
     * to a plan reaches a learner whose snapshot was written before it, within the same TTL.
     *
     * `features` and `new_words_per_day` are still WRITTEN (as granted when the snapshot is made) for the release before
     * this one, which reads them from the snapshot: during a rollback (`deploy.sh --rollback`, the cache instance keeps its
     * keys) or while old workers run next to a new app, it must not read "no features, no limit" from a newer snapshot.
     *
     * @return array{plan:string, source:string, until:string|null, subscription_id:int|null, features:list<string>, new_words_per_day:int|null}
     */
    public function toArray(): array
    {
        return [
            'plan' => $this->plan->value,
            'source' => $this->source,
            'until' => $this->until?->toIso8601String(),
            'subscription_id' => $this->subscriptionId,
            'features' => $this->features,
            'new_words_per_day' => $this->newWordsPerDay,
        ];
    }

    /**
     * The entitlement from its cache form: the features and the limit come from the plan's config of NOW, not from the
     * snapshot (a `features` / `new_words_per_day` key of an older snapshot is ignored).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $plan = Plan::from((string) ($data['plan'] ?? Plan::Free->value));

        return new self(
            plan: $plan,
            source: (string) ($data['source'] ?? self::SOURCE_FREE),
            until: isset($data['until']) && is_string($data['until']) ? CarbonImmutable::parse($data['until']) : null,
            features: $plan->features(),
            newWordsPerDay: $plan->newWordsPerDay(),
            subscriptionId: isset($data['subscription_id']) ? (int) $data['subscription_id'] : null,
        );
    }
}
