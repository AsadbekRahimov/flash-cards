<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Learning\DTO\SessionSummary;
use App\Domain\Learning\Exceptions\TrainingSessionException;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TelegramGroup;
use App\Models\TrainingSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Opens and closes training sessions (docs/12 §1.4, §3.3, §7.6).
 *
 * Group sessions (`group_lesson`) are bound to (telegram_group, lesson, teacher);
 * personal sessions (`lesson`, `daily_review`) to a learner. Each kind has at most
 * one `open` row per key — the partial unique indexes
 * `training_sessions_one_open_per_group_lesson_unique`, `..._one_open_lesson_per_learner`
 * and `..._one_open_daily_per_learner` are the source of truth, and every open path
 * is idempotent: an existing open session is returned, a lost INSERT race re-reads
 * the winner's row.
 *
 * The `mode_shape` invariant (group session ⇒ group and lesson; personal ⇒ learner)
 * is enforced here for every driver and additionally by a PostgreSQL CHECK.
 */
final class TrainingSessionService
{
    public function __construct(private readonly CourseCatalogService $catalog) {}

    /**
     * Teacher command `/start_training <stage> <lesson>`: the numbers are resolved inside the
     * course of the group (`telegram_groups.course_id`, else the default course) — stage numbers
     * repeat across courses.
     *
     * @param  string  $source  stored on a NEWLY created session only (`command`, `reminder`, ...);
     *                          an already open session keeps the source it was created with.
     *
     * An open session of the same lesson is reused unless it is older than half of
     * `group_session_ttl_hours` — then it is closed (`expired`) and a fresh one is opened.
     *
     * @throws TrainingSessionException when the stage/lesson does not exist or
     *                                  the user does not teach the group.
     */
    public function open(
        TelegramGroup $group,
        int $stageNumber,
        int $lessonNumber,
        User $teacher,
        string $source = 'command',
    ): TrainingSession {
        $this->assertCanOpen($group, $teacher);

        $stage = $this->catalog->findStage($this->catalog->courseForGroup($group), $stageNumber);
        if ($stage === null) {
            throw TrainingSessionException::stageNotFound($stageNumber);
        }

        $lesson = $this->catalog->findLesson($stage, $lessonNumber);
        if ($lesson === null) {
            throw TrainingSessionException::lessonNotFound($stageNumber, $lessonNumber);
        }

        return $this->openLesson($group, $lesson, $teacher, $source);
    }

    /**
     * Same as {@see open()} for a lesson that is already known (reminder links): no number
     * lookup, so the lesson need not belong to the course of the group.
     *
     * @throws TrainingSessionException when the user does not teach the group or it is inactive.
     */
    public function openForLesson(
        TelegramGroup $group,
        Lesson $lesson,
        User $teacher,
        string $source = 'command',
    ): TrainingSession {
        $this->assertCanOpen($group, $teacher);

        return $this->openLesson($group, $lesson, $teacher, $source);
    }

    /**
     * Opens (or returns) the learner's personal session (docs/12 §7.6).
     *
     *   - `lesson`: one open session per (learner, lesson); the lesson's course must be published.
     *   - `daily_review`: one open session per learner; `course_id` is a snapshot of the
     *     learner's `active_course_id` (where new words come from; null once that course is no longer
     *     available) and is refreshed on re-open.
     *
     * Idempotent: an open, unexpired session is returned as-is. An open session whose
     * `expires_at` has passed (the scheduler has not run yet) is closed with
     * `done_reason = expired` and replaced. Two concurrent calls end up with one session.
     *
     * Must not be called inside an outer transaction: the lost-race recovery re-reads the winner's
     * row after a unique violation, which on PostgreSQL would run in an already aborted transaction.
     *
     * @param  string  $mode  `lesson` | `daily_review`
     * @param  string  $source  `self` | `picker` | `reminder` | `onboarding` (new sessions only)
     *
     * @throws TrainingSessionException invalid_mode | lesson_mismatch | lesson_not_available
     */
    public function openPersonal(
        Learner $learner,
        string $mode,
        ?Lesson $lesson = null,
        string $source = 'self',
        ?CarbonImmutable $now = null,
    ): TrainingSession {
        $now ??= CarbonImmutable::now();

        if (! in_array($mode, [TrainingSession::MODE_LESSON, TrainingSession::MODE_DAILY_REVIEW], true)) {
            throw TrainingSessionException::invalidMode($mode);
        }

        if ($mode === TrainingSession::MODE_LESSON) {
            if ($lesson === null) {
                throw TrainingSessionException::lessonRequired();
            }
            $this->assertLessonAvailable($lesson, $learner);
        } elseif ($lesson !== null) {
            throw TrainingSessionException::lessonNotAllowed();
        }

        $attributes = [
            'learner_id' => $learner->id,
            'mode' => $mode,
            'lesson_id' => $lesson?->id,
            'course_id' => $mode === TrainingSession::MODE_DAILY_REVIEW ? $this->dailyCourseId($learner) : null,
            'telegram_group_id' => null,
            'started_by_user_id' => null,
            'source' => $source,
            'status' => 'open',
            'started_at' => $now,
            'expires_at' => $now->addHours($this->sessionTtlHours()),
        ];
        self::assertModeShape($attributes);

        try {
            return DB::transaction(function () use ($learner, $mode, $lesson, $attributes, $now): TrainingSession {
                /** @var TrainingSession|null $existing */
                $existing = $this->openPersonalQuery($learner, $mode, $lesson)->lockForUpdate()->first();

                if ($existing !== null) {
                    if ($existing->expires_at === null || $existing->expires_at->isAfter($now)) {
                        if ($mode === TrainingSession::MODE_DAILY_REVIEW) {
                            $courseId = $this->dailyCourseId($learner);
                            if ($existing->course_id !== $courseId) {
                                $existing->update(['course_id' => $courseId]);
                            }
                        }

                        return $existing;
                    }

                    // Expired but not yet closed by the scheduler: close it, open a fresh one.
                    $existing->update([
                        'status' => 'closed',
                        'ended_at' => $existing->expires_at,
                        'done_reason' => TrainingSession::DONE_EXPIRED,
                    ]);
                }

                return TrainingSession::query()->create($attributes);
            });
        } catch (UniqueConstraintViolationException) {
            // Lost the race against a parallel open: the winner's row satisfies the request.
            return $this->openPersonalQuery($learner, $mode, $lesson)->firstOrFail();
        }
    }

    /**
     * Closes a personal session (idempotent) and summarises what the learner did in it.
     *
     * @param  string  $reason  `done_reason` to store: `finished` (default) | `expired`
     *
     * @throws TrainingSessionException not_personal for a group session
     */
    public function finish(
        TrainingSession $session,
        string $reason = TrainingSession::DONE_FINISHED,
        ?CarbonImmutable $now = null,
    ): SessionSummary {
        $now ??= CarbonImmutable::now();

        if ($session->mode === TrainingSession::MODE_GROUP_LESSON) {
            throw TrainingSessionException::notPersonal();
        }

        return DB::transaction(function () use ($session, $reason, $now): SessionSummary {
            /** @var TrainingSession $locked */
            $locked = TrainingSession::query()->lockForUpdate()->findOrFail($session->id);

            $wasOpen = $locked->status === 'open';
            if ($wasOpen) {
                $locked->update([
                    'status' => 'closed',
                    'ended_at' => $now,
                    'done_reason' => $reason,
                ]);
            }

            return $this->summarise($locked, $wasOpen);
        });
    }

    /**
     * Scheduler (`training:close-expired`, every 15 min): closes personal sessions whose
     * `expires_at` has passed and group sessions older than `group_session_ttl_hours`.
     * `ended_at` is the moment of closing: grades were accepted until then.
     *
     * @return int number of sessions closed
     */
    public function closeExpired(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        $personal = TrainingSession::query()
            ->where('status', 'open')
            ->where('mode', '<>', TrainingSession::MODE_GROUP_LESSON)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->update([
                'status' => 'closed',
                'ended_at' => $now,
                'done_reason' => TrainingSession::DONE_EXPIRED,
                'updated_at' => $now,
            ]);

        $group = TrainingSession::query()
            ->where('status', 'open')
            ->where('mode', TrainingSession::MODE_GROUP_LESSON)
            ->where('started_at', '<=', $now->subHours($this->groupSessionTtlHours()))
            ->update([
                'status' => 'closed',
                'ended_at' => $now,
                'done_reason' => TrainingSession::DONE_EXPIRED,
                'updated_at' => $now,
            ]);

        return $personal + $group;
    }

    /**
     * The `mode_shape` invariant of `training_sessions` (docs/12 §1.4), mirrored from the
     * PostgreSQL CHECK so every driver rejects a malformed row before INSERT.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws LogicException
     */
    public static function assertModeShape(array $attributes): void
    {
        $mode = $attributes['mode'] ?? null;

        if ($mode === TrainingSession::MODE_GROUP_LESSON) {
            if (($attributes['telegram_group_id'] ?? null) === null || ($attributes['lesson_id'] ?? null) === null) {
                throw new LogicException('A group_lesson session needs telegram_group_id and lesson_id.');
            }

            return;
        }

        if (! in_array($mode, [TrainingSession::MODE_LESSON, TrainingSession::MODE_DAILY_REVIEW], true)) {
            throw new LogicException("Unknown training session mode '{$mode}'.");
        }

        if (($attributes['learner_id'] ?? null) === null) {
            throw new LogicException("A {$mode} session needs learner_id.");
        }
    }

    private function assertCanOpen(TelegramGroup $group, User $teacher): void
    {
        if (! $this->userTeachesGroup($teacher, $group)) {
            throw TrainingSessionException::notTeacher();
        }

        if ($group->status !== 'active') {
            throw TrainingSessionException::groupInactive();
        }
    }

    /** @param  Learner|null  $learner  the owner of a personal session: the lessons of their own deck are available to them */
    private function assertLessonAvailable(Lesson $lesson, ?Learner $learner = null): void
    {
        $lesson->loadMissing('stage.course');

        $course = $lesson->stage?->course;
        if ($course === null || ! $this->catalog->isAvailable($course, $learner)) {
            throw TrainingSessionException::lessonNotAvailable((int) $lesson->id);
        }
    }

    /**
     * The course `daily_review` takes new words from: the learner's active course while it is
     * still available to them (published and not private, or their own deck), else none — only due cards are served.
     */
    private function dailyCourseId(Learner $learner): ?int
    {
        if ($learner->active_course_id === null) {
            return null;
        }

        $id = Course::query()->availableTo($learner->id)->whereKey($learner->active_course_id)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function openLesson(TelegramGroup $group, Lesson $lesson, User $teacher, string $source): TrainingSession
    {
        $now = CarbonImmutable::now();
        $attributes = [
            'telegram_group_id' => $group->id,
            'lesson_id' => $lesson->id,
            'started_by_user_id' => $teacher->id,
            'mode' => TrainingSession::MODE_GROUP_LESSON,
            'source' => $source,
            'status' => 'open',
            'started_at' => $now,
        ];
        self::assertModeShape($attributes);

        try {
            return DB::transaction(function () use ($group, $lesson, $attributes, $now): TrainingSession {
                /** @var TrainingSession|null $existing */
                $existing = TrainingSession::query()
                    ->where('telegram_group_id', $group->id)
                    ->where('lesson_id', $lesson->id)
                    ->where('mode', TrainingSession::MODE_GROUP_LESSON)
                    ->where('status', 'open')
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    if (! $this->isStaleGroupSession($existing, $now)) {
                        return $existing;
                    }

                    // Too old to be "the class that is on right now" (the scheduler would only close it
                    // at the full TTL): close it and start over, as openPersonal does with an expired one.
                    $existing->update([
                        'status' => 'closed',
                        'ended_at' => $now,
                        'done_reason' => TrainingSession::DONE_EXPIRED,
                    ]);
                }

                return TrainingSession::query()->create($attributes);
            });
        } catch (UniqueConstraintViolationException) {
            /** @var TrainingSession $existing */
            $existing = TrainingSession::query()
                ->where('telegram_group_id', $group->id)
                ->where('lesson_id', $lesson->id)
                ->where('status', 'open')
                ->firstOrFail();

            return $existing;
        }
    }

    /**
     * A group session older than half of `group_session_ttl_hours` is not reused by a teacher
     * command or a reminder link: those come minutes after the class, while the scheduler closes
     * the session only at the full TTL.
     */
    private function isStaleGroupSession(TrainingSession $session, CarbonImmutable $now): bool
    {
        return $session->started_at->lte($now->subMinutes($this->groupSessionTtlHours() * 30));
    }

    /**
     * @return Builder<TrainingSession>
     */
    private function openPersonalQuery(Learner $learner, string $mode, ?Lesson $lesson): Builder
    {
        $query = TrainingSession::query()
            ->where('learner_id', $learner->id)
            ->where('mode', $mode)
            ->where('status', 'open');

        if ($mode === TrainingSession::MODE_LESSON) {
            $query->where('lesson_id', $lesson?->id);
        }

        return $query;
    }

    private function summarise(TrainingSession $session, bool $wasOpen): SessionSummary
    {
        $reviews = DB::table('training_reviews')
            ->where('training_reviews.training_session_id', $session->id);
        if ($session->learner_id !== null) {
            $reviews->where('training_reviews.learner_id', $session->learner_id);
        }

        $reviewed = (clone $reviews)->count();
        $correct = (clone $reviews)->where('training_reviews.quality', '>=', 3)->count();

        // Words whose progress row was born during this session = first served here. An
        // approximation: a word first served to the learner in another tab while this session was
        // open is counted here too, as long as it was graded in this session.
        $newWords = (clone $reviews)
            ->join('word_repetitions', function ($join): void {
                $join->on('word_repetitions.word_id', '=', 'training_reviews.word_id')
                    ->on('word_repetitions.learner_id', '=', 'training_reviews.learner_id');
            })
            ->where('word_repetitions.created_at', '>=', $session->started_at)
            ->distinct()
            ->count('training_reviews.word_id');

        return new SessionSummary($session, $reviewed, $correct, $newWords, $wasOpen);
    }

    private function sessionTtlHours(): int
    {
        return max(1, (int) config('learning.session_ttl_hours', 12));
    }

    private function groupSessionTtlHours(): int
    {
        return max(1, (int) config('learning.group_session_ttl_hours', 24));
    }

    private function userTeachesGroup(User $user, TelegramGroup $group): bool
    {
        return DB::table('teacher_groups')
            ->where('user_id', $user->id)
            ->where('telegram_group_id', $group->id)
            ->exists();
    }
}
