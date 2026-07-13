<?php

declare(strict_types=1);

namespace App\Domain\Administration\Services;

use App\Models\ExamSession;
use App\Models\Student;
use App\Models\TelegramGroup;
use App\Models\TrainingReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

final class PanelDataScope
{
    public function isAdmin(): bool
    {
        return $this->user()?->hasRole('admin') === true;
    }

    /** @return Builder<TelegramGroup> */
    public function groups(): Builder
    {
        $query = TelegramGroup::query();
        $user = $this->user();

        if ($user === null) {
            return $query->whereKey(-1);
        }

        if ($user->hasRole('admin')) {
            return $query;
        }

        return $query->whereHas(
            'teachers',
            fn (Builder $teachers): Builder => $teachers->whereKey($user->id),
        );
    }

    /** @return Builder<Student> */
    public function students(): Builder
    {
        $query = Student::query();
        $user = $this->user();

        if ($user === null) {
            return $query->whereKey(-1);
        }

        if ($user->hasRole('admin')) {
            return $query;
        }

        return $query->whereHas(
            'group.teachers',
            fn (Builder $teachers): Builder => $teachers->whereKey($user->id),
        );
    }

    /** @return Builder<Student> */
    public function studentIds(): Builder
    {
        return $this->students()->select('students.id');
    }

    /** @return Builder<ExamSession> */
    public function exams(): Builder
    {
        $query = ExamSession::query();
        $user = $this->user();

        if ($user === null) {
            return $query->whereKey(-1);
        }

        if ($user->hasRole('admin')) {
            return $query;
        }

        return $query->whereHas(
            'group.teachers',
            fn (Builder $teachers): Builder => $teachers->whereKey($user->id),
        );
    }

    /** @return Builder<TrainingReview> */
    public function trainingReviews(): Builder
    {
        return TrainingReview::query()->whereIn('student_id', $this->studentIds());
    }

    private function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
