<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Picks the next card for a student inside a specific lesson.
 *
 * Strategy (MVP):
 *   1. Find a due repetition for any word of the lesson (`next_review_at <= now()`).
 *      Prefer hard cards first, then earliest due.
 *   2. Otherwise, find a new word of the lesson the student has never seen.
 *      Insert initial WordRepetition row so subsequent `review` is idempotent.
 *   3. Otherwise, return null (student finished the lesson for now).
 */
final class CardPicker
{
    /**
     * @return array{
     *   repetition: WordRepetition,
     *   word: Word,
     *   kind: 'due'|'new'
     * }|null
     */
    public function pickNext(int $studentId, int $lessonId, ?CarbonImmutable $now = null): ?array
    {
        $now ??= CarbonImmutable::now();

        /** @var WordRepetition|null $due */
        $due = WordRepetition::query()
            ->where('student_id', $studentId)
            ->whereHas('word', fn ($q) => $q->where('lesson_id', $lessonId))
            ->where('next_review_at', '<=', $now)
            ->orderByDesc('is_hard')
            ->orderBy('next_review_at')
            ->with('word')
            ->first();

        if ($due !== null && $due->word !== null) {
            return [
                'repetition' => $due,
                'word' => $due->word,
                'kind' => 'due',
            ];
        }

        return $this->pickNewInTransaction($studentId, $lessonId, $now);
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
