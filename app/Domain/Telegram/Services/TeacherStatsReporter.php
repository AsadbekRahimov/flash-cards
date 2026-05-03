<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Models\ExamAnswer;
use App\Models\ExamSession;
use App\Models\Student;
use App\Models\TrainingReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class TeacherStatsReporter
{
    public function build(User $teacher): string
    {
        $groupIds = DB::table('teacher_groups')
            ->where('user_id', $teacher->id)
            ->pluck('telegram_group_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($groupIds === []) {
            return "У вас пока нет привязанных групп.\nПопросите администратора привязать группу в /admin.";
        }

        $since = now()->subDays(7);

        $students = Student::query()
            ->whereIn('telegram_group_id', $groupIds)
            ->where('is_active', true)
            ->count();

        $reviews = TrainingReview::query()
            ->where('created_at', '>=', $since)
            ->whereHas('student', fn ($q) => $q->whereIn('telegram_group_id', $groupIds))
            ->count();

        $exams = ExamSession::query()
            ->whereIn('telegram_group_id', $groupIds)
            ->where('started_at', '>=', $since)
            ->count();

        $correct = ExamAnswer::query()
            ->where('answered_at', '>=', $since)
            ->whereHas('student', fn ($q) => $q->whereIn('telegram_group_id', $groupIds))
            ->where('is_correct', true)
            ->count();

        $answers = ExamAnswer::query()
            ->where('answered_at', '>=', $since)
            ->whereHas('student', fn ($q) => $q->whereIn('telegram_group_id', $groupIds))
            ->count();

        $accuracy = $answers > 0 ? (int) round(($correct / $answers) * 100) : 0;

        return implode("\n", [
            '📊 Статистика LexiFlow за 7 дней',
            '',
            'Групп: '.count($groupIds),
            "Активных студентов: {$students}",
            "Повторений карточек: {$reviews}",
            "Экзаменов запущено: {$exams}",
            "Точность экзаменов: {$accuracy}%",
        ]);
    }
}
