<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learning\DTO\CardPick;
use App\Domain\Learning\Enums\DoneReason;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Support\LearningPolicy;
use App\Domain\Learning\Support\NewCardBudget;
use App\Domain\Learning\Support\RepetitionWithWord;
use App\Domain\Learning\Support\TrainingScope;
use App\Models\TrainingReview;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
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
 * Steps 1 and 3 read the repetition and its word in ONE statement ({@see RepetitionWithWord}): the join carries the
 * scope (active words, the lesson), and the planner walks `word_repetitions_next_card_idx` exactly as it did for the
 * `whereHas` form (docs/06 §4.2; verified with EXPLAIN on the 200k-learner set).
 *
 * Step 2 of a daily scope with a course starts from the learner's frontier ({@see NewWordFrontier}): the lesson where
 * the full candidate query last found the first unseen word, cached per (learner, course, data version of the course).
 * The hint narrows the candidate query to that lesson - it does not replace it: the narrowed statement runs under the
 * same learner lock with the same quota subquery, and the database still decides that the word is unseen and active,
 * the course available and the quota not spent (docs/19 §3 rule 9; the cached thing is where to look, not what to
 * serve). An empty narrowed result - the lesson is finished, gone or someone else's - falls back to the full query,
 * whose finding is written back after the commit. A cached "nothing left" skips the transaction altogether.
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
    public function __construct(
        private readonly LearningPolicy $policy,
        private readonly NewWordFrontier $frontier,
    ) {}

    public function pickNext(
        int $learnerId,
        TrainingScope $scope,
        ?NewCardBudget $budget = null,
        ?CarbonImmutable $now = null,
    ): CardPick {
        $now ??= CarbonImmutable::now();
        $budget ??= NewCardBudget::unlimited();

        /** @var WordRepetition|null $due */
        $due = RepetitionWithWord::query($learnerId, $scope)
            ->where('word_repetitions.next_review_at', '<=', $now)
            ->orderByDesc('word_repetitions.is_hard')
            ->orderBy('word_repetitions.next_review_at')
            ->orderBy('word_repetitions.id')
            ->first();

        $word = $due === null ? null : RepetitionWithWord::hydrate($due);
        if ($due !== null && $word !== null) {
            return CardPick::card($due, $word, self::kindOf($due));
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
     * The frontier hint of a course scope is read BEFORE the transaction (no cache round trip under the learner lock;
     * its key carries the course's data version as it is now) and written back AFTER a committed transaction that ran
     * the full query - never from inside it, and nothing when it threw. A failing cache leaves `$hint` null: the full
     * query, as before.
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

        $courseId = $scope->frontierCourseId();
        $hint = $courseId === null ? null : $this->frontier->read($learnerId, $courseId);
        if ($hint?->isExhausted() === true) {
            // The same answer the full query gave within the TTL and at this data version: no new word, whatever the
            // quota (`$new === null` below returns the same pair), so neither the lock nor the statements are needed.
            return [null, false];
        }

        /** @var ConnectionInterface $db */
        $db = DB::connection();

        // What the FULL query found, when it ran: the lesson of the word, or 0 for none. Null: it did not run (the
        // narrowed statement of the hint found the word), nothing to write back.
        $frontier = null;

        $result = $db->transaction(function () use ($learnerId, $scope, $candidates, $budget, $now, $hint, &$frontier): array {
            // Serialises the learner's tabs. On PostgreSQL `FOR NO KEY UPDATE`, not `FOR UPDATE`: it
            // conflicts with itself but not with the `FOR KEY SHARE` that every FK insert referencing
            // learners (word_repetitions, training_reviews, ...) takes, so those never wait for us.
            $lock = DB::table('learners')->where('id', $learnerId);
            (DB::connection()->getDriverName() === 'pgsql' ? $lock->lock('for no key update') : $lock->lockForUpdate())->value('id');

            $usage = $budget->usageCount($learnerId, $now);
            if ($usage !== null) {
                $candidates->addSelect(['quota_used' => $usage]);
            }

            $new = null;
            $lesson = $hint?->lesson();
            if ($lesson !== null) {
                /** @var Word|null $new */
                $new = $scope->narrowCandidatesToLesson($candidates, $lesson)->first();
            }

            if ($new === null) {
                // No hint, or the hinted lesson has nothing left for this learner (finished, deactivated, moved,
                // deleted - or the hint was stale): the full walk, as before, and its finding is the next hint.
                /** @var Word|null $new */
                $new = $candidates->first();
                $frontier = $new === null ? NewWordFrontier::NONE_LEFT : (int) $new->lesson_id;
            }

            if ($new === null) {
                return [null, false];
            }

            // The decision, under the lock and never from memory (docs/19 §3 rule 9).
            $remaining = $budget->check($learnerId, $now, $usage === null ? null : (int) $new->getAttribute('quota_used'));
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
            $budget->spend();

            return [CardPick::card($rep, $new, 'new'), false];
        });

        if ($hint !== null && $frontier !== null) {
            // Committed. A word found but held back by the quota names the right lesson too.
            $this->frontier->write($hint, $frontier);
        }

        /** @var array{0: CardPick|null, 1: bool} $result */
        return $result;
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
        $ahead = RepetitionWithWord::query($learnerId, $scope)
            ->whereIn('word_repetitions.state', [RepetitionState::Learning->value, RepetitionState::Relearning->value])
            ->where('word_repetitions.next_review_at', '<=', $now->addMinutes($this->policy->learnAheadMinutes))
            ->orderBy('word_repetitions.next_review_at')
            ->orderBy('word_repetitions.id')
            ->first();

        $word = $ahead === null ? null : RepetitionWithWord::hydrate($ahead);
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

        return ['done' => $this->stepCounts($learnerId, null, $lessonId)['done'] ?? 0, 'total' => $total];
    }

    /**
     * What every step of a session counts, in ONE statement: the grades given in the session (the per-session cap,
     * `twa.training.max_cards_per_session`) and, for a lesson scope, the graduated active words of the lesson
     * (`progress.done`). Two scalar subqueries, no FROM — one round trip instead of two.
     *
     * @param  int|null  $sessionId  null: the session count is not needed (`reviews` = 0)
     * @param  int|null  $lessonId  null: a daily scope, `done` is null
     * @return array{reviews:int, done:int|null}
     */
    public function stepCounts(int $learnerId, ?int $sessionId, ?int $lessonId): array
    {
        $query = DB::query();

        if ($sessionId !== null) {
            $query->selectSub(
                TrainingReview::query()
                    ->selectRaw('count(*)')
                    ->where('training_session_id', $sessionId)
                    ->where('learner_id', $learnerId)
                    ->toBase(),
                'reviews',
            );
        }

        if ($lessonId !== null) {
            $query->selectSub(
                WordRepetition::query()
                    ->selectRaw('count(*)')
                    ->join('words', function ($join) use ($lessonId): void {
                        $join->on('words.id', '=', 'word_repetitions.word_id')
                            ->where('words.is_active', true)
                            ->where('words.lesson_id', $lessonId);
                    })
                    ->where('word_repetitions.learner_id', $learnerId)
                    ->where('word_repetitions.repetitions', '>=', 1)
                    ->toBase(),
                'done',
            );
        }

        if ($sessionId === null && $lessonId === null) {
            return ['reviews' => 0, 'done' => null];
        }

        /** @var object{reviews?: int|string, done?: int|string}|null $row */
        $row = $query->first();

        return [
            'reviews' => (int) ($row->reviews ?? 0),
            'done' => $lessonId === null ? null : (int) ($row->done ?? 0),
        ];
    }
}
