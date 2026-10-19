<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Learners\Services\LearnerClock;
use App\Domain\Learning\Contracts\NewCardBudgetResolver;
use App\Domain\Learning\Support\NewCardBudget;
use App\Models\Learner;
use App\Models\TrainingSession;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;

/**
 * The daily limit of NEW cards (first serve of a word) by plan (docs/12 §5.3).
 *
 *   limit      = EntitlementResolver::for(learner)->newWordsPerDay (Free 20; Pro, also sponsored, null = unlimited)
 *   used today = word_repetitions of the learner created inside LearnerClock::limitWindowUtc — the UTC
 *                interval of the learner's LOCAL day, carried over after a timezone change so that
 *                hopping between zones can never shorten the day (see LearnerClock) — excluding words
 *                a teacher's group lesson introduced (`introduced_mode = group_lesson`). A row is
 *                created exactly when a word is served for the first time.
 *
 * Group lessons (`mode = group_lesson`) are outside the quota altogether: TrainingFlowService hands
 * the picker an unlimited budget there, and the words they introduce do not count against the
 * learner's personal sessions.
 *
 * `budgetFor()` resolves the plan once, outside the picker's transaction (a cache miss may read
 * subscriptions), and hands CardPicker a lazy budget whose closure runs UNDER the learner-row lock
 * and does one plain COUNT, no locks of its own (docs/06 §4.2: under the learner lock only reads).
 * `remaining()` is the same number for display (`/today`, `quota` of the training answers) without
 * any lock. `learner_daily_stats.new_words_count` is deliberately not used (docs/12 §1.6).
 */
final class NewWordQuota implements NewCardBudgetResolver
{
    public function __construct(
        private readonly EntitlementResolver $entitlements,
        private readonly LearnerClock $clock,
    ) {}

    public function budgetFor(Learner $learner, CarbonImmutable $now): NewCardBudget
    {
        $limit = $this->entitlements->for($learner, $now)->newWordsPerDay;
        if ($limit === null) {
            return NewCardBudget::unlimited();
        }

        return NewCardBudget::lazy(
            fn (int $learnerId, CarbonImmutable $at): int => max(0, $limit - $this->servedToday($learner, $learnerId, $at)),
        );
    }

    /** New cards the learner may still receive today; null = unlimited. Read-only, takes no locks. */
    public function remaining(Learner $learner, ?CarbonImmutable $now = null): ?int
    {
        $now ??= CarbonImmutable::now();

        return $this->budgetFor($learner, $now)->remaining((int) $learner->id, $now);
    }

    private function servedToday(Learner $learner, int $learnerId, CarbonImmutable $now): int
    {
        $window = $this->clock->limitWindowUtc($learner, $now);

        return WordRepetition::query()
            ->where('learner_id', $learnerId)
            ->where('created_at', '>=', $window->startUtc)
            ->where('created_at', '<', $window->endUtc)
            ->where('introduced_mode', '!=', TrainingSession::MODE_GROUP_LESSON)
            ->count();
    }
}
