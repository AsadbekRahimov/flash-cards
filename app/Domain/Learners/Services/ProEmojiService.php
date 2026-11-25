<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Support\PaidSubscriptionQuery;
use App\Domain\Learners\Exceptions\ProEmojiProRequiredException;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The emoji of Pro (docs/31 §5а, E1): a Pro learner chooses one emoji of the platform's set (`config/pro_emoji.php`) and it is shown
 * next to the name. `learners.pro_emoji` keeps the CODE; free text and sequences never get in - the code is checked against the set.
 *
 * - Choosing one is Pro: a Free learner gets {@see ProEmojiProRequiredException} (403 `pro_emoji_pro_required`); taking the choice
 *   back (null) is always allowed.
 * - Without Pro the emoji is hidden, the choice is kept and shows again when Pro comes back: {@see visible()} is the one rule for
 *   every place that shows it, from the plan the caller has already resolved - no statement of its own.
 * - A code that left the set reads as no choice.
 *
 * The write itself is {@see LearnerSettingsService::update()} (`pro_emoji`), under its row lock and with its `settings.changed`.
 */
final class ProEmojiService
{
    /** @return array<string, string> code => emoji, in the order of the picker */
    public function set(): array
    {
        $set = config('pro_emoji.set', []);
        $codes = [];
        foreach (is_array($set) ? $set : [] as $code => $emoji) {
            if (is_string($code) && is_string($emoji) && $emoji !== '') {
                $codes[$code] = $emoji;
            }
        }

        return $codes;
    }

    /** @return list<array{code: string, emoji: string}> the picker of the settings */
    public function options(): array
    {
        $options = [];
        foreach ($this->set() as $code => $emoji) {
            $options[] = ['code' => $code, 'emoji' => $emoji];
        }

        return $options;
    }

    public function isValid(mixed $code): bool
    {
        return is_string($code) && array_key_exists($code, $this->set());
    }

    /** The emoji of a stored code; null for none or a code that is not in the set any more. */
    public function emoji(?string $code): ?string
    {
        return $code === null ? null : ($this->set()[$code] ?? null);
    }

    /** The stored choice when it is still in the set (the settings show it, Pro or not). */
    public function choice(Learner $learner): ?string
    {
        return $this->isValid($learner->pro_emoji) ? $learner->pro_emoji : null;
    }

    /** Whether the plan shows the emoji: a live Pro. */
    public function isAllowed(Entitlement $entitlement, ?CarbonImmutable $now = null): bool
    {
        return $entitlement->plan === Plan::Pro && $entitlement->isCurrentAt($now ?? CarbonImmutable::now());
    }

    /** What is shown next to the name: the emoji while the learner has Pro, else null (the choice is kept). */
    public function visible(Learner $learner, Entitlement $entitlement, ?CarbonImmutable $now = null): ?string
    {
        return $this->isAllowed($entitlement, $now) ? $this->emoji($learner->pro_emoji) : null;
    }

    /**
     * The emoji of many learners at once, for the lists of other people (league, month, friends, battles): ONE statement for the whole
     * page - the learners with a choice and a live Pro of their own or of a seat in a center (the SQL twin of the plan,
     * {@see PaidSubscriptionQuery}, as the plan column of `/admin`). Learners without an emoji to show are absent from the result.
     *
     * @param  list<int>  $learnerIds
     * @return array<int, string> learner id => emoji
     */
    public function visibleForMany(array $learnerIds, ?CarbonImmutable $now = null): array
    {
        if ($learnerIds === []) {
            return [];
        }

        $now ??= CarbonImmutable::now();
        $set = $this->set();

        $ids = array_values(array_unique($learnerIds));

        // Both "who is Pro" subqueries are narrowed to the ids of the page: unnarrowed, PostgreSQL hashes EVERY live subscription of the
        // platform for one league group (docs/38 §3.1: 12 thousand rows and 300 buffers per call on the stand, growing with the payers);
        // narrowed, it is one index lookup per learner (`subscriptions (subscriber_type, subscriber_id, status)`,
        // `organization_members (learner_id, …)`). The result is the same set.
        $rows = Learner::query()
            ->whereIn('learners.id', $ids)
            ->whereIn('learners.pro_emoji', array_keys($set))
            ->where(fn (Builder $pro): Builder => $pro
                ->whereIn('learners.id', PaidSubscriptionQuery::personalLearnerIds($now)->whereIn('subscriptions.subscriber_id', $ids))
                ->orWhereIn('learners.id', PaidSubscriptionQuery::sponsoredLearnerIds($now)->whereIn('om.learner_id', $ids)))
            ->toBase()
            ->get(['learners.id', 'learners.pro_emoji']);

        $emoji = [];
        foreach ($rows as $row) {
            $emoji[(int) $row->id] = $set[(string) $row->pro_emoji];
        }

        return $emoji;
    }

    /** @throws ProEmojiProRequiredException when a Free learner chooses an emoji (null - taking it back - is always allowed) */
    public function assertAllowed(?string $code, Entitlement $entitlement): void
    {
        if ($code !== null && ! $this->isAllowed($entitlement)) {
            throw new ProEmojiProRequiredException;
        }
    }
}
