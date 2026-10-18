<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Support\LearningPolicy;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Picks the next card for a student inside a specific lesson.
 *
 * Strategy:
 *   1. A due repetition for any word of the lesson (`next_review_at <= now()`),
 *      hard cards first, then earliest due. Covers review cards and
 *      (re)learning cards whose minute step has elapsed.
 *   2. Otherwise, a new word of the lesson the student has never seen.
 *      Insert initial WordRepetition row so subsequent `review` is idempotent.
 *   3. Otherwise, learn-ahead: the earliest (re)learning card whose step
 *      elapses within `policy->learnAheadMinutes`. It is served early and
 *      stamped with a one-time ticket (`served_ahead_at` + random
 *      `served_ahead_token`). The token travels to the client as
 *      `card.serve_token` and must come back in POST /review, so a replayed
 *      answer (same body, stale token) is rejected even when the engine has
 *      just re-served the same card in the same response. An outstanding
 *      ticket is reused, not reissued: two tabs fetching /next see the same
 *      token and only one of them can consume it.
 *   4. Otherwise, null (student finished the lesson for now).
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

    /**
     * @return array{
     *   repetition: WordRepetition,
     *   word: Word,
     *   kind: 'due'|'new'|'learning',
     *   ahead: bool
     * }|null
     */
    public function pickNext(int $studentId, int $lessonId, ?CarbonImmutable $now = null): ?array
    {
        $now ??= CarbonImmutable::now();

        /** @var WordRepetition|null $due */
        $due = $this->lessonRepetitions($studentId, $lessonId)
            ->where('next_review_at', '<=', $now)
            ->orderByDesc('is_hard')
            ->orderBy('next_review_at')
            ->orderBy('id')
            ->with('word')
            ->first();

        if ($due !== null && $due->word !== null) {
            return [
                'repetition' => $due,
                'word' => $due->word,
                'kind' => self::kindOf($due),
                'ahead' => false,
            ];
        }

        $new = $this->pickNewInTransaction($studentId, $lessonId, $now);
        if ($new !== null) {
            return $new;
        }

        return $this->pickLearnAhead($studentId, $lessonId, $now);
    }

    /**
     * @return array{repetition: WordRepetition, word: Word, kind: 'new', ahead: false}|null
     */
    private function pickNewInTransaction(int $studentId, int $lessonId, CarbonImmutable $now): ?array
    {
        /** @var ConnectionInterface $db */
        $db = DB::connection();

        return $db->transaction(function () use ($studentId, $lessonId, $now): ?array {
            /** @var Word|null $new */
            $new = Word::query()
                ->where('lesson_id', $lessonId)
                ->whereNotIn('id', function ($q) use ($studentId): void {
                    $q->select('word_id')
                        ->from('word_repetitions')
                        ->where('student_id', $studentId);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($new === null) {
                return null;
            }

            /** @var WordRepetition $rep */
            $rep = WordRepetition::query()->create([
                'student_id' => $studentId,
                'word_id' => $new->id,
                'repetitions' => 0,
                'interval_days' => 0,
                'easiness_factor' => SpacedRepetitionEngine::DEFAULT_EF,
                'next_review_at' => $now,
                'is_hard' => false,
                'state' => RepetitionState::New,
                'learning_step' => 0,
                'lapses' => 0,
                'meta' => [],
            ]);

            return [
                'repetition' => $rep,
                'word' => $new,
                'kind' => 'new',
                'ahead' => false,
            ];
        });
    }

    /**
     * Serve the nearest (re)learning card before its step elapsed, so the
     * session does not end with cards hanging for a few minutes.
     *
     * @return array{repetition: WordRepetition, word: Word, kind: 'learning', ahead: true}|null
     */
    private function pickLearnAhead(int $studentId, int $lessonId, CarbonImmutable $now): ?array
    {
        if ($this->policy->learnAheadMinutes <= 0) {
            return null;
        }

        /** @var WordRepetition|null $ahead */
        $ahead = $this->lessonRepetitions($studentId, $lessonId)
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

        return [
            'repetition' => $ahead,
            'word' => $word,
            'kind' => 'learning',
            'ahead' => true,
        ];
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
    private function lessonRepetitions(int $studentId, int $lessonId): Builder
    {
        return WordRepetition::query()
            ->where('student_id', $studentId)
            ->whereHas('word', fn ($q) => $q->where('lesson_id', $lessonId));
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
     * @return array{done:int, total:int}
     */
    public function progress(int $studentId, int $lessonId, ?int $totalWords = null): array
    {
        $total = $totalWords ?? Word::query()->where('lesson_id', $lessonId)->count();

        $done = WordRepetition::query()
            ->where('student_id', $studentId)
            ->where('repetitions', '>=', 1)
            ->whereHas('word', fn ($q) => $q->where('lesson_id', $lessonId))
            ->count();

        return ['done' => $done, 'total' => $total];
    }
}
