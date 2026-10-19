<?php

declare(strict_types=1);

namespace App\Domain\Administration\Services;

use App\Models\ExamSession;
use App\Models\Learner;
use App\Models\TelegramGroup;
use App\Models\TrainingReview;
use App\Models\TrainingSession;
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

    /**
     * Admin: every learner. Teacher: learners with an ACTIVE membership in one of
     * the teacher's groups (someone who left is no longer visible).
     *
     * @return Builder<Learner>
     */
    public function learners(): Builder
    {
        $query = Learner::query();
        $user = $this->user();

        if ($user === null) {
            return $query->whereKey(-1);
        }

        if ($user->hasRole('admin')) {
            return $query;
        }

        return $query->whereHas(
            'memberships',
            fn (Builder $members): Builder => $members
                ->where('status', 'active')
                ->whereHas(
                    'group.teachers',
                    fn (Builder $teachers): Builder => $teachers->whereKey($user->id),
                ),
        );
    }

    /** @return Builder<Learner> */
    public function learnerIds(): Builder
    {
        return $this->learners()->select('learners.id');
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

    /**
     * Admin: every training session. Teacher: GROUP sessions of their groups only —
     * a learner's personal sessions (`mode <> group_lesson`) stay private (docs/12 §4.4).
     *
     * @return Builder<TrainingSession>
     */
    public function trainingSessions(): Builder
    {
        $query = TrainingSession::query();
        $user = $this->user();

        if ($user === null) {
            return $query->whereKey(-1);
        }

        if ($user->hasRole('admin')) {
            return $query;
        }

        return $query
            ->where('mode', 'group_lesson')
            ->whereHas(
                'group.teachers',
                fn (Builder $teachers): Builder => $teachers->whereKey($user->id),
            );
    }

    /** @return Builder<TrainingSession> */
    public function trainingSessionIds(): Builder
    {
        return $this->trainingSessions()->select('training_sessions.id');
    }

    /** @return Builder<TrainingReview> */
    public function trainingReviews(): Builder
    {
        return TrainingReview::query()->whereIn('training_session_id', $this->trainingSessionIds());
    }

    private function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
