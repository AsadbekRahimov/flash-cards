<?php

declare(strict_types=1);

namespace App\Domain\Administration\Services;

use App\Models\ExamSession;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\TrainingReview;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\Word;
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

    /**
     * Stages of the courses a panel user may read: an admin sees all, a teacher none of the private decks
     * of learners (`owner_type = learner`, docs/13 §8.1) — those are the learner's own content.
     *
     * @return Builder<Stage>
     */
    public function stages(): Builder
    {
        $query = Stage::query();

        return $this->isAdmin()
            ? $query
            : $query->whereHas('course', fn (Builder $course): Builder => $course->where('courses.owner_type', '!=', 'learner'));
    }

    /** @return Builder<Lesson> */
    public function lessons(): Builder
    {
        $query = Lesson::query();

        return $this->isAdmin()
            ? $query
            : $query->whereHas('stage.course', fn (Builder $course): Builder => $course->where('courses.owner_type', '!=', 'learner'));
    }

    /** @return Builder<Word> */
    public function words(): Builder
    {
        $query = Word::query();

        return $this->isAdmin()
            ? $query
            : $query->whereHas('lesson.stage.course', fn (Builder $course): Builder => $course->where('courses.owner_type', '!=', 'learner'));
    }

    private function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
