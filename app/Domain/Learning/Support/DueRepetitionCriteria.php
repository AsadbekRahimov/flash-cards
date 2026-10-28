<?php

declare(strict_types=1);

namespace App\Domain\Learning\Support;

use App\Domain\Learning\Enums\RepetitionState;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Single definition of "a card the learner should come back for" used by
 * review reminders, `words_due_today` and the reminder deep link
 * (docs/06 §6). Keep these three in sync by changing only this class.
 *
 *   - graduated (`review`) cards with `next_review_at <= now`;
 *   - abandoned (re)learning cards: minute step elapsed more than
 *     `abandoned_after_hours` ago — the learner left the session and the
 *     card would otherwise hang without a reminder forever.
 *
 * Fresh in-session steps (minutes old) and `new` rows (card shown, never
 * answered) are deliberately excluded: they belong to the session the
 * learner is in, not to "N words to review". Repetitions of inactive words
 * (`words.is_active = false`) are excluded too: the picker never serves them,
 * so a reminder about them could not be acted upon.
 */
final class DueRepetitionCriteria
{
    public function __construct(private readonly LearningPolicy $policy) {}

    /**
     * Narrows `$query` (a `word_repetitions` query — standalone, inside
     * `whereHas('repetitions')` or `withCount`) to due cards.
     *
     * @template TModel of WordRepetition|Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query, ?CarbonImmutable $now = null): Builder
    {
        $now ??= CarbonImmutable::now();
        $abandonedBefore = $now->subHours($this->policy->abandonedAfterHours);

        // Inactive words are excluded with an anti-join (`NOT EXISTS … is_active = false`) rather than
        // `whereHas('word', is_active = true)`. The two are equivalent — every row has exactly one word
        // (`word_id` NOT NULL, FK ON DELETE CASCADE) and `words.is_active` is NOT NULL — but the anti-join is
        // served from the small partial index `words_inactive_idx` instead of a scan of all active words
        // (docs/03 §2.7), which matters for the counters `/today`, `/me` and the reminder job run per learner.
        $query->whereDoesntHave('word', fn (Builder $word) => $word->where('is_active', false));

        return $query->where(function (Builder $q) use ($now, $abandonedBefore): void {
            $q->where(function (Builder $graduated) use ($now): void {
                $graduated
                    ->where('state', RepetitionState::Review->value)
                    ->where('next_review_at', '<=', $now);
            })->orWhere(function (Builder $abandoned) use ($abandonedBefore): void {
                $abandoned
                    ->whereIn('state', [RepetitionState::Learning->value, RepetitionState::Relearning->value])
                    ->where('next_review_at', '<=', $abandonedBefore);
            });
        });
    }
}
