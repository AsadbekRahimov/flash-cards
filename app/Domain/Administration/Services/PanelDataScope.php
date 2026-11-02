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

    /**
     * Admin: every review. Teacher: the reviews inside the sessions of {@see trainingSessions()}.
     *
     * An admin sees every session, and a review always belongs to one (`training_session_id` is a NOT NULL foreign key), so for
     * an admin the filter by sessions is an empty one - which PostgreSQL still pays for with a hash join of the reviews against
     * every session id (docs/20 §4, the activity chart at 200 000 learners: 249 ms). Left out for an admin, kept for anybody else.
     *
     * @return Builder<TrainingReview>
     */
    public function trainingReviews(): Builder
    {
        $reviews = TrainingReview::query();

        return $this->isAdmin() ? $reviews : $reviews->whereIn('training_session_id', $this->trainingSessionIds());
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

    /**
     * WHICH data this viewer sees, as a short key part for a cached aggregate (docs/19 K1): `all` for an admin; for a
     * teacher their user id and a hash of the ids of their groups, so the day a group is added or taken away is a
     * different key at once and no stale aggregate of a group they lost is ever shown (nothing to invalidate); `none`
     * when nobody is signed in (callers do not cache that). One small indexed read for a teacher, none for an admin.
     */
    public function fingerprint(): string
    {
        $user = $this->user();

        if ($user === null) {
            return 'none';
        }

        if ($user->hasRole('admin')) {
            return 'all';
        }

        /** @var list<int> $groupIds */
        $groupIds = $user->telegramGroups()->pluck('telegram_groups.id')->map(static fn (mixed $id): int => (int) $id)->sort()->values()->all();

        return 'u'.$user->id.'g'.substr(hash('sha256', implode(',', $groupIds)), 0, 12);
    }

    private function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
