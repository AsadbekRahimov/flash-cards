<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learners\Services\DailyStatsService;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Exceptions\ReviewSubmissionException;
use App\Domain\Learning\Support\LearningPolicy;
use App\Domain\Learning\Support\TrainingScope;
use App\Models\Learner;
use App\Models\TrainingReview;
use App\Models\TrainingSession;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Atomically applies a learner's grade to a card inside an open session.
 *
 * Checks, in order (docs/06 §5.1): session open (410 `session_closed`) → the
 * repetition row exists (409 `card_not_started`) → the word is inside the
 * session's TrainingScope (422 `out_of_scope`) → the card may be graded now
 * (409 `card_not_due`). Then SM-2 is applied, the grade is logged in
 * `training_reviews` and the day's aggregate is updated through
 * DailyStatsService::recordReview — all in ONE transaction, so a failure in any
 * step leaves no partial state.
 *
 * Double-submit protection: a card may be graded only if it is due
 * (`next_review_at <= now`) or if CardPicker served it early (learn-ahead)
 * and the client presents that serve's one-time ticket (`serve_token`, the
 * row's `served_ahead_token`). Every accepted review moves `next_review_at`
 * into the future and clears the ticket, so replaying the same request —
 * even after the engine re-served the same card with a new ticket in the
 * same response — is rejected with 409 `card_not_due`.
 */
final class ReviewSubmissionService
{
    public function __construct(
        private readonly SpacedRepetitionEngine $engine,
        private readonly LearningPolicy $policy,
        private readonly DailyStatsService $dailyStats,
    ) {}

    /**
     * @param  string|null  $serveToken  `card.serve_token` echoed by the client; ignored for due cards
     */
    public function submit(
        TrainingSession $session,
        Learner $learner,
        int $wordId,
        int $quality,
        int $timeSpentMs,
        ?string $serveToken = null,
        ?CarbonImmutable $now = null,
    ): WordRepetition {
        $now ??= CarbonImmutable::now();

        /** @var ConnectionInterface $db */
        $db = DB::connection();

        return $db->transaction(function () use (
            $session,
            $learner,
            $wordId,
            $quality,
            $timeSpentMs,
            $serveToken,
            $now,
        ): WordRepetition {
            /** @var TrainingSession|null $lockedSession */
            $lockedSession = TrainingSession::query()
                ->lockForUpdate()
                ->find($session->id);

            if ($lockedSession === null || ! $lockedSession->isLiveAt($now)) {
                throw new ReviewSubmissionException(
                    410,
                    'session_closed',
                    'This training session is closed.',
                );
            }

            /** @var WordRepetition|null $repetition */
            $repetition = WordRepetition::query()
                ->where('learner_id', $learner->id)
                ->where('word_id', $wordId)
                ->with('word')
                ->lockForUpdate()
                ->first();

            if ($repetition === null || $repetition->word === null) {
                throw new ReviewSubmissionException(
                    409,
                    'card_not_started',
                    'Card was never served to this learner.',
                );
            }

            if (! TrainingScope::forSession($lockedSession)->contains($repetition->word)) {
                throw new ReviewSubmissionException(
                    422,
                    'out_of_scope',
                    'Word is outside the scope of this training session.',
                );
            }

            if (! $this->isAcceptable($repetition, $serveToken, $now)) {
                throw new ReviewSubmissionException(
                    409,
                    'card_not_due',
                    'This card was already reviewed and is not due yet.',
                );
            }

            // First grade of a card that was only served so far (docs/12 §3.3).
            $isNewCard = $repetition->state === RepetitionState::New;

            $this->engine->applyReview($repetition, $quality, $now);

            TrainingReview::query()->create([
                'training_session_id' => $session->id,
                'learner_id' => $learner->id,
                'word_id' => $wordId,
                'quality' => $quality,
                'time_spent_ms' => $timeSpentMs,
                'created_at' => $now,
            ]);

            $this->dailyStats->recordReview($learner, $quality, $timeSpentMs, $isNewCard, $now);

            return $repetition;
        });
    }

    private function isAcceptable(WordRepetition $repetition, ?string $serveToken, CarbonImmutable $now): bool
    {
        if (! $repetition->next_review_at->isAfter($now)) {
            return true;
        }

        if (! $repetition->state->isIntraSession()) {
            return false;
        }

        // Learn-ahead: the client must present the ticket of the serve it is
        // answering. A missing, stale or foreign token means this is a replay
        // (or an old client) — the engine clears the token on every answer.
        if ($serveToken === null || $serveToken === '' || $repetition->served_ahead_token === null) {
            return false;
        }
        if (! hash_equals($repetition->served_ahead_token, $serveToken)) {
            return false;
        }

        // The ticket is valid only inside the configured window.
        return ! $repetition->next_review_at->isAfter($now->addMinutes($this->policy->learnAheadMinutes));
    }
}
