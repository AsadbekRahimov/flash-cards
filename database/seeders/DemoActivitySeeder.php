<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ExamAnswer;
use App\Models\ExamResult;
use App\Models\ExamSession;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\TelegramGroup;
use App\Models\TrainingReview;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds historical learning activity (training reviews + closed exams) so the
 * Filament analytics dashboard (FR-ADM-04) and exam logs (FR-ADM-05) show real
 * data in the demo environment. DemoRepetitionsSeeder only seeds the spaced
 * repetition state; the dashboard widgets read training_reviews/exam_sessions,
 * which this seeder fills.
 */
class DemoActivitySeeder extends Seeder
{
    public function run(): void
    {
        $group = TelegramGroup::where('chat_id', -1001234567890)->first();

        if ($group === null) {
            return;
        }

        $teacher = $group->teachers()->first()
            ?? User::query()->whereNotNull('telegram_user_id')->first();

        $students = Student::where('telegram_group_id', $group->id)->get();

        /** @var Collection<int, Lesson> $lessons */
        $lessons = Lesson::query()->with('words')->has('words')->orderBy('id')->take(3)->get();

        if ($teacher === null || $students->isEmpty() || $lessons->isEmpty()) {
            return;
        }

        // Idempotent: skip if this group already has activity history.
        $alreadySeeded = TrainingSession::where('telegram_group_id', $group->id)
            ->whereHas('reviews')
            ->exists();

        if ($alreadySeeded) {
            return;
        }

        DB::transaction(function () use ($group, $teacher, $students, $lessons): void {
            $this->seedTrainingHistory($group, $teacher, $students, $lessons);
            $this->seedExamHistory($group, $teacher, $students, $lessons);
        });
    }

    /**
     * @param  Collection<int, Student>  $students
     * @param  Collection<int, Lesson>  $lessons
     */
    private function seedTrainingHistory(
        TelegramGroup $group,
        User $teacher,
        Collection $students,
        Collection $lessons,
    ): void {
        // One training session every ~3 days across the last 30 days.
        for ($dayOffset = 28; $dayOffset >= 1; $dayOffset -= 3) {
            $lesson = $lessons[$dayOffset % $lessons->count()] ?? null;
            if ($lesson === null) {
                continue;
            }
            $startedAt = now()->subDays($dayOffset)->setTime(18, 0);

            $session = TrainingSession::create([
                'telegram_group_id' => $group->id,
                'lesson_id' => $lesson->id,
                'started_by_user_id' => $teacher->id,
                'status' => 'closed',
                'started_at' => $startedAt,
                'ended_at' => $startedAt->copy()->addMinutes(20),
            ]);

            $words = $lesson->words->take(10);

            foreach ($students as $studentIndex => $student) {
                foreach ($words as $wordIndex => $word) {
                    // Deterministic but varied quality; some words consistently
                    // hard (low quality) so the "hardest words" widget has signal.
                    $quality = ($wordIndex < 3)
                        ? (($studentIndex + $dayOffset) % 3)        // 0..2 → hard
                        : 3 + (($studentIndex + $wordIndex) % 3);   // 3..5 → ok

                    TrainingReview::create([
                        'training_session_id' => $session->id,
                        'student_id' => $student->id,
                        'word_id' => $word->id,
                        'quality' => $quality,
                        'time_spent_ms' => random_int(1500, 9000),
                        'created_at' => $startedAt->copy()->addMinutes(random_int(0, 18)),
                    ]);
                }
            }
        }
    }

    /**
     * @param  Collection<int, Student>  $students
     * @param  Collection<int, Lesson>  $lessons
     */
    private function seedExamHistory(
        TelegramGroup $group,
        User $teacher,
        Collection $students,
        Collection $lessons,
    ): void {
        foreach ([24, 12, 4] as $dayOffset) {
            $lesson = $lessons[$dayOffset % $lessons->count()] ?? null;
            if ($lesson === null) {
                continue;
            }
            $startedAt = now()->subDays($dayOffset)->setTime(19, 0);
            $words = $lesson->words->take(8);

            if ($words->isEmpty()) {
                continue;
            }

            $session = ExamSession::create([
                'telegram_group_id' => $group->id,
                'lesson_id' => $lesson->id,
                'started_by_user_id' => $teacher->id,
                'status' => 'closed',
                'started_at' => $startedAt,
                'ends_at' => $startedAt->copy()->addMinutes(10),
                'closed_at' => $startedAt->copy()->addMinutes(10),
                'config' => ['duration_minutes' => 10],
            ]);

            $scoreboard = [];

            foreach ($students as $studentIndex => $student) {
                $totalScore = 0;
                $correctCount = 0;

                // Stronger students (lower index) miss fewer words, so the
                // leaderboard has distinct ranks instead of a flat scoreboard.
                $missThreshold = ($studentIndex + $dayOffset) % $words->count();

                foreach ($words as $wordIndex => $word) {
                    $isCorrect = $wordIndex >= $missThreshold;
                    $score = $isCorrect ? 10 : 0;
                    $totalScore += $score;
                    $correctCount += $isCorrect ? 1 : 0;

                    ExamAnswer::create([
                        'exam_session_id' => $session->id,
                        'student_id' => $student->id,
                        'word_id' => $word->id,
                        'selected_translation' => $isCorrect ? $word->translation : 'неверный вариант',
                        'is_correct' => $isCorrect,
                        'score' => $score,
                        'time_spent_ms' => random_int(2000, 10000),
                        'answered_at' => $startedAt->copy()->addMinutes(random_int(0, 9)),
                    ]);
                }

                $scoreboard[] = [
                    'student_id' => $student->id,
                    'total_score' => $totalScore,
                    'correct_count' => $correctCount,
                    'total_count' => $words->count(),
                ];
            }

            // Rank by total score desc, then assign 1-based ranks.
            usort($scoreboard, fn (array $a, array $b): int => $b['total_score'] <=> $a['total_score']);

            foreach ($scoreboard as $rankIndex => $row) {
                ExamResult::create([
                    'exam_session_id' => $session->id,
                    'student_id' => $row['student_id'],
                    'total_score' => $row['total_score'],
                    'correct_count' => $row['correct_count'],
                    'total_count' => $row['total_count'],
                    'rank' => $rankIndex + 1,
                ]);
            }
        }
    }
}
