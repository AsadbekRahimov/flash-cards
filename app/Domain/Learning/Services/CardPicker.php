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
 *      stamped with `served_ahead_at` so ReviewSubmissionService can accept
 *      the answer before `next_review_at` without opening a double-submit hole.
 *   4. Otherwise, null (student finished the lesson for now).
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
     *   kind: 'due'|'new'|'learning'
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
            ];
        }

        $new = $this->pickNewInTransaction($studentId, $lessonId, $now);
        if ($new !== null) {
            return $new;
        }

        return $this->pickLearnAhead($studentId, $lessonId, $now);
    }

    /**
     * @return array{repetition: WordRepetition, word: Word, kind: 'new'}|null
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
            ];
        });
    }

    /**
     * Serve the nearest (re)learning card before its step elapsed, so the
     * session does not end with cards hanging for a few minutes.
     *
     * @return array{repetition: WordRepetition, word: Word, kind: 'learning'}|null
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

        if ($ahead === null || $ahead->word === null) {
            return null;
        }

        $ahead->served_ahead_at = $now;
        $ahead->save();

        return [
            'repetition' => $ahead,
            'word' => $ahead->word,
            'kind' => 'learning',
        ];
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
