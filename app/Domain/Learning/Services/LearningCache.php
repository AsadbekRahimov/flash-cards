<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Models\ExamResult;
use App\Models\ExamSession;
use App\Models\Lesson;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class LearningCache
{
    private const LESSON_WORD_COUNT_TTL = 3600;

    private const EXAM_RESULT_TTL = 3600;

    /** How long a learner's "words learned" shown to their friends may lag behind the real number. */
    private const WORDS_LEARNED_TTL = 600;

    /**
     * Active words of the lesson (`is_active = false` replaces deletion and is not
     * trainable). Call {@see forgetLesson()} after toggling `is_active` or importing.
     */
    public function lessonWordCount(int $lessonId): int
    {
        return (int) Cache::remember(
            "lesson:{$lessonId}:word_count",
            self::LESSON_WORD_COUNT_TTL,
            fn (): int => (int) DB::table('words')
                ->where('lesson_id', $lessonId)
                ->where('is_active', true)
                ->count(),
        );
    }

    /**
     * @return array{
     *   total_participants:int,
     *   rows:list<array{learner_id:int|null, rank:int, name:string, score:int}>
     * }
     */
    public function examLeaderboard(ExamSession $session): array
    {
        /** @var array{total_participants:int, rows:list<array{learner_id:int|null, rank:int, name:string, score:int}>} $leaderboard */
        $leaderboard = Cache::remember(
            "exam:{$session->id}:leaderboard",
            self::EXAM_RESULT_TTL,
            function () use ($session): array {
                $rows = ExamResult::query()
                    ->with('learner')
                    ->where('exam_session_id', $session->id)
                    ->orderBy('rank')
                    ->get()
                    ->map(function (ExamResult $result): array {
                        $learner = $result->learner;

                        return [
                            'learner_id' => $result->learner_id === null ? null : (int) $result->learner_id,
                            'rank' => (int) $result->rank,
                            'name' => $learner === null ? '—' : ($learner->first_name ?? '—'),
                            'score' => (int) $result->total_score,
                        ];
                    })
                    ->values()
                    ->all();

                return [
                    'total_participants' => count($rows),
                    'rows' => $rows,
                ];
            },
        );

        return $leaderboard;
    }

    /**
     * `words_learned` of many learners (`stats:words_learned:{id}`, {@see WORDS_LEARNED_TTL} seconds) for the
     * friends screen: every key is read with ONE `Cache::many`, only the learners that have no entry are handed to
     * `$load` (one call, never with an empty list) and written back with ONE `Cache::putMany`. A count of 0 is an
     * entry too. There is no invalidation: the number lags up to the TTL, so it is only for OTHER learners' figures
     * that nothing depends on — never for the learner's own number or for deciding anything (achievements).
     *
     * @param  list<int>  $learnerIds
     * @param  Closure(list<int>): array<int, int>  $load  exact counts of the learners it gets (a missing one counts as 0)
     * @return array<int, int> learner id => count; every requested id is present
     */
    public function wordsLearned(array $learnerIds, Closure $load): array
    {
        $keys = [];
        foreach (array_unique($learnerIds) as $id) {
            $keys[$id] = self::wordsLearnedKey($id);
        }
        if ($keys === []) {
            return [];
        }

        $cached = Cache::many(array_values($keys));

        $counts = [];
        $missing = [];
        foreach ($keys as $id => $key) {
            $hit = $cached[$key] ?? null;
            if ($hit === null) {
                $missing[] = $id;
            } else {
                $counts[$id] = (int) $hit;
            }
        }

        if ($missing !== []) {
            $loaded = $load($missing);
            $fresh = [];
            foreach ($missing as $id) {
                $counts[$id] = $loaded[$id] ?? 0;
                $fresh[$keys[$id]] = $counts[$id];
            }
            Cache::putMany($fresh, self::WORDS_LEARNED_TTL);
        }

        // The order of the request, not "hits first".
        return array_replace(array_fill_keys(array_keys($keys), 0), $counts);
    }

    public function forgetLesson(Lesson $lesson): void
    {
        Cache::forget("lesson:{$lesson->id}:word_count");
    }

    public function forgetExam(ExamSession $session): void
    {
        Cache::forget("exam:{$session->id}:leaderboard");
    }

    private static function wordsLearnedKey(int $learnerId): string
    {
        return "stats:words_learned:{$learnerId}";
    }
}
