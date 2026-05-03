<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Models\ExamResult;
use App\Models\ExamSession;
use App\Models\Lesson;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class LearningCache
{
    private const LESSON_WORD_COUNT_TTL = 3600;

    private const EXAM_RESULT_TTL = 3600;

    public function lessonWordCount(int $lessonId): int
    {
        return (int) Cache::remember(
            "lesson:{$lessonId}:word_count",
            self::LESSON_WORD_COUNT_TTL,
            fn (): int => (int) DB::table('words')
                ->where('lesson_id', $lessonId)
                ->count(),
        );
    }

    /**
     * @return array{
     *   total_participants:int,
     *   rows:list<array{student_id:int|null, rank:int, name:string, score:int}>
     * }
     */
    public function examLeaderboard(ExamSession $session): array
    {
        /** @var array{total_participants:int, rows:list<array{student_id:int|null, rank:int, name:string, score:int}>} $leaderboard */
        $leaderboard = Cache::remember(
            "exam:{$session->id}:leaderboard",
            self::EXAM_RESULT_TTL,
            function () use ($session): array {
                $rows = ExamResult::query()
                    ->with('student')
                    ->where('exam_session_id', $session->id)
                    ->orderBy('rank')
                    ->get()
                    ->map(function (ExamResult $result): array {
                        $student = $result->student;

                        return [
                            'student_id' => $result->student_id === null ? null : (int) $result->student_id,
                            'rank' => (int) $result->rank,
                            'name' => $student === null ? '—' : $student->first_name,
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

    public function forgetLesson(Lesson $lesson): void
    {
        Cache::forget("lesson:{$lesson->id}:word_count");
    }

    public function forgetExam(ExamSession $session): void
    {
        Cache::forget("exam:{$session->id}:leaderboard");
    }
}
