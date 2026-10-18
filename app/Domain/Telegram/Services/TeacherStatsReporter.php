<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Localization\Services\LocaleResolver;
use App\Models\ExamAnswer;
use App\Models\ExamSession;
use App\Models\Student;
use App\Models\TrainingReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class TeacherStatsReporter
{
    public function __construct(private readonly LocaleResolver $locales) {}

    public function build(User $teacher, ?string $locale = null): string
    {
        $locale ??= $this->locales->forTeacher();

        $groupIds = DB::table('teacher_groups')
            ->where('user_id', $teacher->id)
            ->pluck('telegram_group_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($groupIds === []) {
            return __('stats.no_groups', [], $locale);
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
            __('stats.title', [], $locale),
            '',
            __('stats.groups', ['count' => count($groupIds)], $locale),
            __('stats.students', ['count' => $students], $locale),
            __('stats.reviews', ['count' => $reviews], $locale),
            __('stats.exams', ['count' => $exams], $locale),
            __('stats.accuracy', ['percent' => $accuracy], $locale),
        ]);
    }
}
