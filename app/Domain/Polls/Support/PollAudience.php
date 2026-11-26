<?php

declare(strict_types=1);

namespace App\Domain\Polls\Support;

/**
 * `polls.audience` read strictly (docs/35 §1.4): `{plan: all|free|pro, locales: [], course_ids: [], region_codes: [],
 * onboarded_only: bool}`. Anything unknown or malformed falls to the narrowest safe reading: an unknown plan is `all`, list items
 * of the wrong type are dropped, a missing `onboarded_only` is true. An empty list means "no filter". {@see toArray()} is the
 * normalized form the launcher stores as the snapshot.
 */
final readonly class PollAudience
{
    public const PLAN_ALL = 'all';

    public const PLAN_FREE = 'free';

    public const PLAN_PRO = 'pro';

    /**
     * @param  list<string>  $locales
     * @param  list<int>  $courseIds
     * @param  list<string>  $regionCodes
     */
    public function __construct(
        public string $plan = self::PLAN_ALL,
        public array $locales = [],
        public array $courseIds = [],
        public array $regionCodes = [],
        public bool $onboardedOnly = true,
    ) {}

    /** @param array<array-key, mixed>|null $audience */
    public static function from(?array $audience): self
    {
        $audience ??= [];
        $plan = $audience['plan'] ?? self::PLAN_ALL;

        return new self(
            plan: in_array($plan, [self::PLAN_ALL, self::PLAN_FREE, self::PLAN_PRO], true) ? $plan : self::PLAN_ALL,
            locales: self::strings($audience['locales'] ?? [], 5),
            courseIds: self::ids($audience['course_ids'] ?? []),
            regionCodes: self::strings($audience['region_codes'] ?? [], 8),
            onboardedOnly: ($audience['onboarded_only'] ?? true) !== false,
        );
    }

    /** @return array{plan: string, locales: list<string>, course_ids: list<int>, region_codes: list<string>, onboarded_only: bool} */
    public function toArray(): array
    {
        return [
            'plan' => $this->plan,
            'locales' => $this->locales,
            'course_ids' => $this->courseIds,
            'region_codes' => $this->regionCodes,
            'onboarded_only' => $this->onboardedOnly,
        ];
    }

    /** @return list<string> */
    private static function strings(mixed $values, int $maxLength): array
    {
        $out = [];
        foreach (is_array($values) ? $values : [] as $value) {
            if (is_string($value) && $value !== '' && strlen($value) <= $maxLength) {
                $out[$value] = $value;
            }
        }

        return array_values($out);
    }

    /** @return list<int> */
    private static function ids(mixed $values): array
    {
        $out = [];
        foreach (is_array($values) ? $values : [] as $value) {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $id = (int) $value;
                if ($id > 0) {
                    $out[$id] = $id;
                }
            }
        }

        return array_values($out);
    }
}
