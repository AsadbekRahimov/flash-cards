<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Learners\Exceptions\ProEmojiProRequiredException;
use App\Models\Learner;
use Carbon\CarbonImmutable;

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

    /** @throws ProEmojiProRequiredException when a Free learner chooses an emoji (null - taking it back - is always allowed) */
    public function assertAllowed(?string $code, Entitlement $entitlement): void
    {
        if ($code !== null && ! $this->isAllowed($entitlement)) {
            throw new ProEmojiProRequiredException;
        }
    }
}
