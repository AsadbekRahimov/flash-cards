<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learning\DTO\CardPick;
use App\Domain\Learning\Enums\DoneReason;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Support\LearningPolicy;
use App\Domain\Learning\Support\NewCardBudget;
use App\Domain\Learning\Support\TrainingScope;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Picks the next card for a learner inside a TrainingScope (docs/06 §4).
 *
 * Strategy:
 *   1. A due repetition in scope (`next_review_at <= now()`), hard cards first,
 *      then earliest due. Covers review cards and (re)learning cards whose
 *      minute step has elapsed.
 *   2. Otherwise, a new word of the scope the learner has never seen — only if the
 *      NewCardBudget allows it. Insert initial WordRepetition row so subsequent
 *      `review` is idempotent. The learner row is locked for the duration, so two
 *      tabs of one learner are served different words and cannot jointly exceed
 *      the budget (docs/12 §5.3).
 *   3. Otherwise, learn-ahead: the earliest (re)learning card in scope whose step
 *      elapses within `policy->learnAheadMinutes`. It is served early and
 *      stamped with a one-time ticket (`served_ahead_at` + random
 *      `served_ahead_token`). The token travels to the client as
 *      `card.serve_token` and must come back in POST /review, so a replayed
 *      answer (same body, stale token) is rejected even when the engine has
 *      just re-served the same card in the same response. An outstanding
 *      ticket is reused, not reissued: two tabs fetching /next see the same
 *      token and only one of them can consume it.
 *   4. Otherwise, no card with a DoneReason: `new_quota_exhausted` when (and only
 *      when) step 2 was stopped by the budget while unseen words remain, else the
 *      scope's own reason (`lesson_complete` / `nothing_due`).
 *
 * Words with `is_active = false` are never served (new, due or ahead).
 *
 * `ahead` is true only for step 3: that is the only path that serves a card
 * with a live ticket (a card that later became due is served as due, `ahead`
 * false, even if a stale ticket is still stamped on its row).
 *
 * `kind` tells the client how to label the card:
 *   'new'      — never answered (state new)
 *   'learning' — inside minute-based (re)learning steps
 *   'due'      — graduated card scheduled in days
 */
final class CardPicker
{
    public function __construct(private readonly LearningPolicy $policy) {}

    public function pickNext(
        int $learnerId,
        TrainingScope $scope,
        ?NewCardBudget $budget = null,
        ?CarbonImmutable $now = null,
    ): CardPick {
        $now ??= CarbonImmutable::now();
        $budget ??= NewCardBudget::unlimited();

        /** @var WordRepetition|null $due */
        $due = $this->scopedRepetitions($learnerId, $scope)
            ->where('next_review_at', '<=', $now)
            ->orderByDesc('is_hard')
            ->orderBy('next_review_at')
            ->orderBy('id')
            ->with('word')
            ->first();

        if ($due !== null && $due->word !== null) {
            return CardPick::card($due, $due->word, self::kindOf($due));
        }

        [$new, $quotaBlocked] = $this->pickNewInTransaction($learnerId, $scope, $budget, $now);
        if ($new !== null) {
            return $new;
        }

        $ahead = $this->pickLearnAhead($learnerId, $scope, $now);
        if ($ahead !== null) {
            return $ahead;
        }

        return CardPick::done($quotaBlocked ? DoneReason::NewQuotaExhausted : $scope->exhaustedReason());
    }

    /**
     * Serialised per learner: `SELECT ... FOR NO KEY UPDATE` on the learner row (PostgreSQL;
     * sqlite locks the whole database) orders concurrent tabs, so the second one sees
     * the row the first inserted and the budget it consumed before choosing a word.
     *
     * @return array{0: CardPick|null, 1: bool} [pick, quotaBlocked]
     */
    private function pickNewInTransaction(
        int $learnerId,
        TrainingScope $scope,
        NewCardBudget $budget,
        CarbonImmutable $now,
    ): array {
        $candidates = $scope->newWordCandidates($learnerId);
        if ($candidates === null) {
            return [null, false];
        }

        /** @var ConnectionInterface $db */
        $db = DB::connection();

        return $db->transaction(function () use ($learnerId, $scope, $candidates, $budget, $now): array {
            // Serialises the learner's tabs. On PostgreSQL `FOR NO KEY UPDATE`, not `FOR UPDATE`: it
            // conflicts with itself but not with the `FOR KEY SHARE` that every FK insert referencing
            // learners (word_repetitions, training_reviews, ...) takes, so those never wait for us.
            $lock = DB::table('learners')->where('id', $learnerId);
            (DB::connection()->getDriverName() === 'pgsql' ? $lock->lock('for no key update') : $lock->lockForUpdate())->value('id');

            /** @var Word|null $new */
            $new = $candidates->first();
            if ($new === null) {
                return [null, false];
            }

            $remaining = $budget->remaining($learnerId, $now);
            if ($remaining !== null && $remaining <= 0) {
                return [null, true];
            }

            /** @var WordRepetition $rep */
            $rep = WordRepetition::query()->create([
                'learner_id' => $learnerId,
                'word_id' => $new->id,
                'repetitions' => 0,
                'interval_days' => 0,
                'easiness_factor' => SpacedRepetitionEngine::DEFAULT_EF,
                'next_review_at' => $now,
                'is_hard' => false,
                // Who introduced the word: a group lesson's words stay outside the Free quota (docs/12 §5.3).
                'introduced_mode' => $scope->sessionMode,
                'state' => RepetitionState::New,
                'learning_step' => 0,
                'lapses' => 0,
                'meta' => [],
            ]);

            return [CardPick::card($rep, $new, 'new'), false];
        });
    }

    /**
     * Serve the nearest (re)learning card before its step elapsed, so the
     * session does not end with cards hanging for a few minutes.
     */
    private function pickLearnAhead(int $learnerId, TrainingScope $scope, CarbonImmutable $now): ?CardPick
    {
        if ($this->policy->learnAheadMinutes <= 0) {
            return null;
        }

        /** @var WordRepetition|null $ahead */
        $ahead = $this->scopedRepetitions($learnerId, $scope)
            ->whereIn('state', [RepetitionState::Learning->value, RepetitionState::Relearning->value])
            ->where('next_review_at', '<=', $now->addMinutes($this->policy->learnAheadMinutes))
            ->orderBy('next_review_at')
            ->orderBy('id')
            ->with('word')
            ->first();

        $word = $ahead?->word;
        if ($ahead === null || $word === null) {
            return null;
        }

        $this->issueTicket($ahead, $now);

        return CardPick::card($ahead, $word, 'learning', ahead: true);
    }

    /**
     * Stamp the learn-ahead ticket. If one is already outstanding (the card
     * was served ahead and not answered yet) it is kept, so concurrent fetches
     * share a single ticket; otherwise a fresh random token is issued with a
     * conditional UPDATE, and on a lost race the winner's token is reloaded.
     */
    private function issueTicket(WordRepetition $rep, CarbonImmutable $now): void
    {
        if ($rep->served_ahead_token !== null) {
            return;
        }

        $token = self::newToken();

        $updated = WordRepetition::query()
            ->whereKey($rep->id)
            ->whereNull('served_ahead_token')
            ->update([
                'served_ahead_at' => $now,
                'served_ahead_token' => $token,
            ]);

        if ($updated === 0) {
            // Lost the race with another request: adopt its ticket (may be null
            // again if an answer consumed it meanwhile — then this client's
            // answer is rejected with 409 and it refetches /next).
            $rep->refresh();

            return;
        }

        $rep->served_ahead_at = $now;
        $rep->served_ahead_token = $token;
        $rep->syncOriginal();
    }

    public static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @return Builder<WordRepetition>
     */
    private function scopedRepetitions(int $learnerId, TrainingScope $scope): Builder
    {
        return $scope->constrainRepetitions(
            WordRepetition::query()->where('learner_id', $learnerId),
        );
    }

    /**
     * @return 'due'|'new'|'learning'
     */
    public static function kindOf(WordRepetition $rep): string
    {
        return match ($rep->state) {
            RepetitionState::New => 'new',
            RepetitionState::Review => 'due',
            RepetitionState::Learning, RepetitionState::Relearning => 'learning',
        };
    }

    /**
     * Lesson progress: graduated (answered at least once successfully) active words
     * of the lesson vs. all its active words.
     *
     * @param  int|null  $totalWords  pre-computed active word count (LearningCache::lessonWordCount)
     * @return array{done:int, total:int}
     */
    public function progress(int $learnerId, int $lessonId, ?int $totalWords = null): array
    {
        $total = $totalWords ?? Word::query()
            ->where('lesson_id', $lessonId)
            ->where('is_active', true)
            ->count();

        $done = TrainingScope::lesson($lessonId)
            ->constrainRepetitions(WordRepetition::query()->where('learner_id', $learnerId))
            ->where('repetitions', '>=', 1)
            ->count();

        return ['done' => $done, 'total' => $total];
    }
}
