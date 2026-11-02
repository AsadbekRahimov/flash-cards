<?php

declare(strict_types=1);

namespace App\Domain\Administration\Services;

use App\Models\ExamSession;
use App\Models\GroupMember;
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
use Illuminate\Support\Facades\DB;

/**
 * What the signed-in user of `/admin` may see (docs/07 §5): an admin — everything; a teacher — their groups
 * (`teacher_groups`), the learners active in them and the group sessions and exams of those groups. The list
 * queries (`learners()`, `exams()`, …) and the per-record checks of the row policies (`canViewLearner()`,
 * `canViewExam()`) state the same rule; the per-record checks answer from the groups of the teacher, read once
 * per request ({@see PanelRequestMemo}), and from a relation the list query loaded — never with a query per row.
 */
final class PanelDataScope
{
    public function __construct(private readonly PanelRequestMemo $memo) {}

    public function isAdmin(): bool
    {
        return $this->user()?->hasRole('admin') === true;
    }

    /**
     * Ids of the groups $user is assigned to (`teacher_groups`), read once per request. The role is not looked at
     * here: the policies check it in `viewAny` before they ask, and an admin never needs the list (admin: all).
     *
     * @return list<int>
     */
    public function teacherGroupIds(User $user): array
    {
        return $this->memo->remember("teacher_groups:{$user->id}", function () use ($user): array {
            /** @var list<int> $ids */
            $ids = DB::table('teacher_groups')
                ->where('user_id', $user->id)
                ->orderBy('telegram_group_id')
                ->pluck('telegram_group_id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all();

            return $ids;
        });
    }

    /**
     * Whether $user may see this learner: an admin — anyone; a teacher — a learner with an ACTIVE membership in one
     * of the teacher's groups (the rule of `learners()`). Read from `learner.activeGroupMemberships` when it is loaded
     * (LearnerResource loads it with the list for a teacher), with one query otherwise (a single record elsewhere).
     */
    public function canViewLearner(User $user, Learner $learner): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        $groupIds = $this->teacherGroupIds($user);

        if ($groupIds === []) {
            return false;
        }

        if ($learner->relationLoaded('activeGroupMemberships')) {
            return $learner->activeGroupMemberships->contains(
                fn (GroupMember $membership): bool => in_array((int) $membership->telegram_group_id, $groupIds, true),
            );
        }

        return GroupMember::query()
            ->where('learner_id', $learner->id)
            ->where('status', 'active')
            ->whereIn('telegram_group_id', $groupIds)
            ->exists();
    }

    /**
     * Whether $user may see this exam: an admin — any; a teacher — a group exam of one of their groups (the rule of
     * `exams()`; a personal mock has no group and is nobody's in the panel but the admin's).
     */
    public function canViewExam(User $user, ExamSession $session): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return $session->telegram_group_id !== null
            && in_array((int) $session->telegram_group_id, $this->teacherGroupIds($user), true);
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
