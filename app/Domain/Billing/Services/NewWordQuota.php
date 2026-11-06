<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Learners\Services\LearnerClock;
use App\Domain\Learning\Contracts\NewCardBudgetResolver;
use App\Domain\Learning\Support\NewCardBudget;
use App\Models\Learner;
use App\Models\TrainingSession;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

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
 * subscriptions). CardPicker reads the count under the learner-row lock alongside the candidate word.
 * `remaining()` is the same number for display (`/today`, `quota` of the training answers) without
 * any lock. `learner_daily_stats.new_words_count` is deliberately not used (docs/12 §1.6).
 */
final class NewWordQuota implements NewCardBudgetResolver
{
    public function __construct(
        private readonly EntitlementResolver $entitlements,
        private readonly LearnerClock $clock,
    ) {}

    public function budgetFor(Learner $learner, CarbonImmutable $now, ?Entitlement $entitlement = null): NewCardBudget
    {
        $limit = ($entitlement ?? $this->entitlements->for($learner, $now))->newWordsPerDay;
        if ($limit === null) {
            return NewCardBudget::unlimited();
        }

        return NewCardBudget::counted(
            $limit,
            fn (int $learnerId, CarbonImmutable $at) => $this->servedTodayQuery($learner, $at, $learnerId)->toBase(),
        );
    }

    /**
     * New cards the learner may still receive today; null = unlimited. Read-only, takes no locks. `$entitlement` - the plan
     * the caller already resolved for this request; `$servedToday` - the count of {@see servedTodayQuery()} the caller
     * already has (the Today screen counts it in the same statement as the due cards), else it is counted here.
     */
    public function remaining(Learner $learner, ?CarbonImmutable $now = null, ?Entitlement $entitlement = null, ?int $servedToday = null): ?int
    {
        $now ??= CarbonImmutable::now();
        $limit = ($entitlement ?? $this->entitlements->for($learner, $now))->newWordsPerDay;
        if ($limit === null) {
            return null;
        }

        return max(0, $limit - ($servedToday ?? $this->servedTodayQuery($learner, $now)->count()));
    }

    /**
     * The repetitions that count against today's quota (the words first served inside the learner's limit window,
     * group lessons apart) - for a caller that counts them together with other counts of the learner in one statement.
     *
     * @return Builder<WordRepetition>
     */
    public function servedTodayQuery(Learner $learner, CarbonImmutable $now, ?int $learnerId = null): Builder
    {
        $window = $this->clock->limitWindowUtc($learner, $now);

        return WordRepetition::query()
            ->where('learner_id', $learnerId ?? (int) $learner->id)
            ->where('created_at', '>=', $window->startUtc)
            ->where('created_at', '<', $window->endUtc)
            ->where('introduced_mode', '!=', TrainingSession::MODE_GROUP_LESSON);
    }
}
