<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Engagement\Services\ReferralService;
use App\Domain\Learners\Services\LearnerClock;
use App\Domain\Learning\DTO\SessionSummary;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Enums\WordStage;
use App\Domain\Learning\Events\LessonWithNewWordsFinished;
use App\Domain\Learning\Exceptions\TrainingSessionException;
use App\Domain\Learning\Support\LessonReviewState;
use App\Domain\Localization\Services\LocaleResolver;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TelegramGroup;
use App\Models\TrainingSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

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
 *
 * A personal session is COMPLETED (`completed_at`, docs/06 §13.2) once the server answered `card: null` after its grades: the
 * round or the lesson was played to the end, whether or not `/finish` ever comes (the Mini App may be closed on the verdict of
 * the last card). A completed `daily_review` session is not handed back on the next open — it is closed as `finished`, with
 * the side effects of `/finish`, and a fresh round is opened; the same for a daily session begun before the learner's local
 * today (as `finished` when completed, else `expired`, like the scheduler would). A round of today with cards left goes on.
 * The scheduler closes a completed session as `finished` too, so the retention report does not count it as abandoned.
 */
final class TrainingSessionService
{
    public function __construct(
        private readonly CourseCatalogService $catalog,
        private readonly TeacherAuthority $authority,
        private readonly ProductEventRecorder $events,
        private readonly ReferralService $referrals,
        private readonly LearnerClock $clock,
        private readonly CorrectedMistakeFinder $corrections,
        private readonly LocaleResolver $locales,
    ) {}

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

        $course = $this->catalog->courseForGroup($group);
        $lesson = $this->catalog->numberedLesson($course, $stageNumber, $lessonNumber);
        if ($lesson === null) {
            if ($this->catalog->findStage($course, $stageNumber) === null) {
                throw TrainingSessionException::stageNotFound($stageNumber);
            }

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
     * `done_reason = expired` and replaced. A `daily_review` session that is completed ({@see markCompleted()}) or was
     * begun before the learner's local today is closed as well (`finished` when completed, with the side effects of
     * {@see finish()}; `expired` otherwise) and replaced by a fresh round. Two concurrent calls end up with one session:
     * the open row is locked first, so the second caller sees it closed, loses the INSERT race and re-reads the winner's row.
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
                        if ($mode !== TrainingSession::MODE_DAILY_REVIEW) {
                            return $existing;
                        }

                        if ($existing->completed_at !== null) {
                            // The round was played to the end but never closed (the app was shut on the last verdict): what
                            // `/finish` would have done, then a fresh round - never the exhausted one handed back.
                            $this->finish($existing, TrainingSession::DONE_FINISHED, $now, $learner);
                        } elseif ($existing->started_at->lt($this->clock->today($learner, $now))) {
                            // Yesterday's round, left in the middle: closed the way the scheduler closes it, a new one for today.
                            $existing->update([
                                'status' => 'closed',
                                'ended_at' => $now,
                                'done_reason' => TrainingSession::DONE_EXPIRED,
                            ]);
                        } else {
                            // Today's round with cards left goes on. The course snapshot was taken a moment ago; re-reading after
                            // the lock wait would narrow the race only by the wait itself, and it is refreshed on every open anyway.
                            $courseId = $attributes['course_id'];
                            if ($existing->course_id !== $courseId) {
                                $existing->update(['course_id' => $courseId]);
                            }

                            return $existing;
                        }
                    } else {
                        // Expired but not yet closed by the scheduler: close it, open a fresh one.
                        $existing->update([
                            'status' => 'closed',
                            'ended_at' => $existing->expires_at,
                            'done_reason' => $existing->completed_at === null ? TrainingSession::DONE_EXPIRED : TrainingSession::DONE_FINISHED,
                        ]);
                    }
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
     * The side effects of a session the learner finished — the event `training.session_finished` with the summary's numbers,
     * the evaluation of a pending referral (docs/13 §7.1) and, for a lesson with new words, {@see LessonWithNewWordsFinished}
     * (the bot's reminder after a lesson, docs/31 §5б) — happen here, once, after the commit, and only when THIS call
     * closed the session with `finished`: `/finish` and the re-open of a completed round share them, a repeat of `/finish`
     * and a close as `expired` have none.
     *
     * The summary of a round of hard words may carry one corrected mistake (`$withCorrected`, `/finish` only, docs/06 §14): one statement
     * more, after the commit and only for `hard_review` - the internal closes (a round re-opened, the scheduler) do not ask for it.
     *
     * @param  string  $reason  `done_reason` to store: `finished` (default) | `expired`
     * @param  Learner|null  $learner  the owner, when the caller has the row (read otherwise, for the side effects only)
     * @param  bool  $withCorrected  look for a corrected mistake ({@see CorrectedMistakeFinder}); needs `$learner` (the locale)
     *
     * @throws TrainingSessionException not_personal for a group session
     */
    public function finish(
        TrainingSession $session,
        string $reason = TrainingSession::DONE_FINISHED,
        ?CarbonImmutable $now = null,
        ?Learner $learner = null,
        bool $withCorrected = false,
    ): SessionSummary {
        $now ??= CarbonImmutable::now();

        if ($session->mode === TrainingSession::MODE_GROUP_LESSON) {
            throw TrainingSessionException::notPersonal();
        }

        $summary = $this->close($session, $reason, $now, $learner);

        if ($withCorrected && $learner !== null && $summary->session->mode === TrainingSession::MODE_HARD_REVIEW) {
            // The session is closed and committed already: a failure of this extra line must not turn every retry of `/finish` into a 500.
            try {
                $corrected = $this->corrections->find($summary->session, $this->locales->forTrainingSession($summary->session, $learner));
            } catch (Throwable $e) {
                report($e);
                $corrected = null;
            }

            return $summary->withCorrected($corrected);
        }

        return $summary;
    }

    /** The transaction of {@see finish()}: the lock, the close, the summary and the side effects after the commit. */
    private function close(TrainingSession $session, string $reason, CarbonImmutable $now, ?Learner $learner): SessionSummary
    {
        return DB::transaction(function () use ($session, $reason, $now, $learner): SessionSummary {
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

            $summary = $this->summarise($locked, $wasOpen);

            if ($wasOpen && $reason === TrainingSession::DONE_FINISHED) {
                // After the locks are released (the referral claims its own transaction; the event buffer takes rows after a commit).
                DB::afterCommit(fn () => $this->recordFinished($summary, $learner, $now));
            }

            return $summary;
        });
    }

    /**
     * The round or the lesson of a personal session is over: the server answered `card: null` after the session's grades
     * (TrainingFlowService). Stamped once (`completed_at`, a conditional UPDATE: still open, not stamped yet); a session without
     * grades is never stamped — there was nothing to play, and the same open session serves whatever comes due later.
     */
    public function markCompleted(TrainingSession $session, ?CarbonImmutable $now = null): void
    {
        if ($session->mode === TrainingSession::MODE_GROUP_LESSON || $session->completed_at !== null) {
            return;
        }
        $now ??= CarbonImmutable::now();

        $stamped = TrainingSession::query()
            ->whereKey($session->id)
            ->where('status', 'open')
            ->whereNull('completed_at')
            ->update(['completed_at' => $now, 'updated_at' => $now]);

        if ($stamped > 0) {
            $session->forceFill(['completed_at' => $now])->syncOriginalAttribute('completed_at');
        }
    }

    private function recordFinished(SessionSummary $summary, ?Learner $learner, CarbonImmutable $now): void
    {
        $session = $summary->session;
        $learner ??= $session->learner_id === null ? null : Learner::query()->find($session->learner_id);
        if ($learner === null) {
            return;
        }

        $this->events->record(
            ProductEventName::TrainingSessionFinished,
            [
                'training_session_id' => $session->id,
                'mode' => $session->mode,
                'reviewed' => $summary->reviewed,
                'correct' => $summary->correct,
                'new_words' => $summary->newWords,
                // The round of hard words: how many of its set were won (docs/06 §14).
                ...($summary->hardTotal === null ? [] : ['hard_won' => $summary->hardWon, 'hard_total' => $summary->hardTotal]),
            ],
            learner: $learner,
        );

        // A pending referral of this learner (docs/13 §7.1): activated once they have studied enough.
        $this->referrals->evaluate($learner, $now);

        // A lesson with new words: the bot may remind of the game on its words later (docs/31 §5б).
        if ($session->mode === TrainingSession::MODE_LESSON && $session->lesson_id !== null && $summary->newWords > 0) {
            LessonWithNewWordsFinished::dispatch($learner, (int) $session->id, (int) $session->lesson_id, $summary->newWords);
        }
    }

    /**
     * Withdraws an open group session that nobody could reach (its Mini App button was not delivered):
     * closes it as `cancelled`, without a summary. Idempotent; personal sessions are not touched.
     */
    public function cancelUndelivered(TrainingSession $session, ?CarbonImmutable $now = null): void
    {
        TrainingSession::query()
            ->whereKey($session->id)
            ->where('mode', TrainingSession::MODE_GROUP_LESSON)
            ->where('status', 'open')
            ->update([
                'status' => 'closed',
                'ended_at' => $now ?? CarbonImmutable::now(),
                'done_reason' => TrainingSession::DONE_CANCELLED,
                'updated_at' => $now ?? CarbonImmutable::now(),
            ]);
    }

    /**
     * Scheduler (`training:close-expired`, every 15 min): closes personal sessions whose
     * `expires_at` has passed and group sessions older than `group_session_ttl_hours`.
     * `ended_at` is the moment of closing: grades were accepted until then. A personal session that was
     * played to its end ({@see markCompleted()}) closes as `finished` — the learner did not abandon it, `/finish`
     * just never came — every other one as `expired`. No side effects either way (the summary was never shown).
     *
     * @return int number of sessions closed
     */
    public function closeExpired(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        $personal = 0;
        foreach ([TrainingSession::DONE_FINISHED => true, TrainingSession::DONE_EXPIRED => false] as $reason => $completed) {
            $personal += TrainingSession::query()
                ->where('status', 'open')
                ->where('mode', '<>', TrainingSession::MODE_GROUP_LESSON)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', $now)
                ->when($completed, fn (Builder $q) => $q->whereNotNull('completed_at'), fn (Builder $q) => $q->whereNull('completed_at'))
                ->update([
                    'status' => 'closed',
                    'ended_at' => $now,
                    'done_reason' => $reason,
                    'updated_at' => $now,
                ]);
        }

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

        if (! in_array($mode, [TrainingSession::MODE_LESSON, TrainingSession::MODE_DAILY_REVIEW, TrainingSession::MODE_HARD_REVIEW, TrainingSession::MODE_LESSON_REVIEW], true)) {
            throw new LogicException("Unknown training session mode '{$mode}'.");
        }

        if (($attributes['learner_id'] ?? null) === null) {
            throw new LogicException("A {$mode} session needs learner_id.");
        }

        if ($mode === TrainingSession::MODE_LESSON_REVIEW && ($attributes['lesson_id'] ?? null) === null) {
            throw new LogicException('A lesson_review session needs lesson_id.');
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

    /**
     * The lesson may be opened by the learner as a personal session (the rule of `mode: lesson`): its course is available to them, or it
     * is a live assignment of a group they are in. For the review of a lesson (LessonReviewService), which opens on the same terms.
     *
     * @throws TrainingSessionException lesson_not_available
     */
    public function assertLessonOpenable(Lesson $lesson, Learner $learner): void
    {
        $this->assertLessonAvailable($lesson, $learner);
    }

    /** @param  Learner|null  $learner  the owner of a personal session: the lessons of their own deck are available to them */
    private function assertLessonAvailable(Lesson $lesson, ?Learner $learner = null): void
    {
        $lesson->loadMissing('stage.course');

        $course = $lesson->stage?->course;
        if ($course === null) {
            throw TrainingSessionException::lessonNotAvailable((int) $lesson->id);
        }

        if ($this->catalog->isAvailable($course, $learner)) {
            return;
        }

        // A published private course of a center is open to the learners of the group it was
        // assigned to — only for the lessons set as a live assignment, nothing else of the course.
        if ($learner !== null && $course->status === 'published' && $course->owner_type !== 'learner' && $this->isAssignedTo($lesson, $learner)) {
            return;
        }

        throw TrainingSessionException::lessonNotAvailable((int) $lesson->id);
    }

    public function findForStep(int $sessionId): ?TrainingSession
    {
        return TrainingSession::query()->addSelect([
            'center_private_lesson_id' => $this->privateCenterLessons()
                ->select('lessons.id')
                ->whereColumn('lessons.id', 'training_sessions.lesson_id')
                ->where('training_sessions.mode', TrainingSession::MODE_LESSON),
        ])->find($sessionId);
    }

    /**
     * Re-check access to a running private center lesson on each step. The session lookup can
     * identify these lessons in the same statement; the assignment is checked against live data.
     *
     * @return bool false when the session was closed because the lesson is no longer available
     */
    public function ensureStillAvailable(TrainingSession $session, Learner $learner): bool
    {
        if ($session->mode !== TrainingSession::MODE_LESSON || $session->lesson_id === null) {
            return true;
        }

        $attributes = $session->getAttributes();
        $privateCenterLesson = array_key_exists('center_private_lesson_id', $attributes)
            ? $attributes['center_private_lesson_id'] !== null
            : $this->privateCenterLessons()->where('lessons.id', $session->lesson_id)->exists();

        if (! $privateCenterLesson) {
            return true;
        }

        $lesson = $this->catalog->lessonWithContext((int) $session->lesson_id);
        if ($lesson === null) {
            return true;
        }

        try {
            $this->assertLessonAvailable($lesson, $learner);
        } catch (TrainingSessionException) {
            $this->finish($session, TrainingSession::DONE_EXPIRED);

            return false;
        }

        return true;
    }

    /**
     * The lesson is in a private course that is NOT a learner's own deck - the only kind whose running
     * sessions ensureStillAvailable() judges again. A missing lesson / stage / course is "no".
     */
    private function privateCenterLessons(): QueryBuilder
    {
        return DB::table('lessons')
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->join('courses', 'courses.id', '=', 'stages.course_id')
            ->where('courses.visibility', 'private')
            ->where('courses.owner_type', '!=', 'learner');
    }

    /** An active assignment of this lesson in a group of an active center where the learner is an active member. */
    private function isAssignedTo(Lesson $lesson, Learner $learner): bool
    {
        return Assignment::query()
            ->where('lesson_id', $lesson->id)
            ->where('status', Assignment::STATUS_ACTIVE)
            ->ofActiveOrganization()
            ->inLinkedGroup()
            ->whereIn('telegram_group_id', GroupMember::query()
                ->select('telegram_group_id')
                ->where('learner_id', $learner->id)
                ->where('status', 'active'))
            ->exists();
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

    /**
     * The numbers of the summary in ONE statement (bindings only, no values in the SQL): one row per word graded in the
     * session (its grades, the correct ones, whether its progress row was born in the session, the stage it started
     * the session on and the stage it holds now — docs/06 §13.3), aggregated once.
     *
     * The stage before = by `interval_before_days` of the word's FIRST grade in the session (the window `first_value` over the
     * grades by id; not the smallest one: a card that lapses here has a 0 on its relearning grade and must not look like it
     * began as new). An interval of 0 is a card in minute steps: `new` when it had never graduated (`lapses` = 0: the word was
     * met in an earlier session and left in its steps), `learning` when it was in relearning (`lapses` > 0 — a card never
     * returns to the learning steps once graduated, so a lapse on the row tells the two apart). A word born in the session
     * starts at `new`. The stage after = the current row: `review` by its interval, a lapsed card in relearning is `learning`,
     * a new word still in the minute steps stays `new`. An approximation kept from before: a word first served to the learner
     * in another tab while this session was open counts as new here too, as long as it was graded in this session.
     */
    private function summarise(TrainingSession $session, bool $wasOpen): SessionSummary
    {
        if ($session->mode === TrainingSession::MODE_LESSON_REVIEW) {
            // The review of a lesson writes no `training_reviews` (docs/06 §15): its numbers are its own state. No new word, no stage moved.
            $state = LessonReviewState::of($session);

            return new SessionSummary(
                $session,
                $state->answers(),
                $state->correct(),
                0,
                $wasOpen,
                reviewWon: $state->wonCount(),
                reviewTotal: $state->total(),
                reviewFirstTry: $state->firstTryCount(),
            );
        }

        $t = WordStage::thresholds();
        $startedAt = $session->started_at->format(DB::connection()->getQueryGrammar()->getDateFormat());

        // The grades of the session, each carrying the interval before the word's first grade here.
        $grades = DB::table('training_reviews')
            ->where('training_reviews.training_session_id', $session->id)
            ->select(['training_reviews.learner_id', 'training_reviews.word_id', 'training_reviews.quality'])
            ->selectRaw('first_value("training_reviews"."interval_before_days") over (partition by "training_reviews"."word_id" order by "training_reviews"."id") as first_interval');
        if ($session->learner_id !== null) {
            $grades->where('training_reviews.learner_id', $session->learner_id);
        }
        $hard = $session->mode === TrainingSession::MODE_HARD_REVIEW;
        if ($hard) {
            // The round of hard words: the word is won when its LAST grade in the session is a pass (docs/06 §14).
            $grades->selectRaw('first_value("training_reviews"."quality") over (partition by "training_reviews"."word_id" order by "training_reviews"."id" desc) as last_quality');
        }

        // A LEFT join: every grade counts as before even if its progress row is gone (the app never deletes rows; data made by hand may lack them).
        $perWord = DB::query()
            ->fromSub($grades, 'g')
            ->leftJoin('word_repetitions', function ($join): void {
                $join->on('word_repetitions.word_id', '=', 'g.word_id')
                    ->on('word_repetitions.learner_id', '=', 'g.learner_id');
            })
            ->groupBy('g.word_id')
            ->selectRaw('count(*) as grades')
            ->selectRaw('sum(case when "g"."quality" >= ? then 1 else 0 end) as correct', [3])
            ->selectRaw('max(case when "word_repetitions"."created_at" >= ? then 1 else 0 end) as born', [$startedAt])
            ->when($hard, fn (QueryBuilder $q) => $q->selectRaw('max(case when "g"."last_quality" >= ? then 1 else 0 end) as won', [3]))
            // The stage the word held when the session began (3 mastered, 2 known, 1 learning, 0 new — the ranks of WordStage).
            ->selectRaw(
                'max(case when "g"."first_interval" >= ? then 3 when "g"."first_interval" >= ? then 2 when "g"."first_interval" >= 1 then 1'
                .' when coalesce("word_repetitions"."lapses", 0) > 0 then 1 else 0 end) as stage_before',
                [$t['mastered'], $t['known']],
            )
            // The stage it holds now: a graduated card by its interval, a card in relearning is `learning`, still new is `new`.
            ->selectRaw(
                'max(case when "word_repetitions"."state" = ? then (case when "word_repetitions"."interval_days" >= ? then 3 when "word_repetitions"."interval_days" >= ? then 2 else 1 end)'
                .' when "word_repetitions"."state" = ? then 1 else 0 end) as stage_after',
                [RepetitionState::Review->value, $t['mastered'], $t['known'], RepetitionState::Relearning->value],
            );

        /** @var object{reviewed: int|string|null, correct: int|string|null, new_words: int|string|null, graduated: int|string|null, promoted: int|string|null, mastered: int|string|null, hard_won?: int|string|null}|null $row */
        $row = DB::query()
            ->fromSub($perWord, 'w')
            ->selectRaw('coalesce(sum("w"."grades"), 0) as reviewed')
            ->selectRaw('coalesce(sum("w"."correct"), 0) as correct')
            ->selectRaw('coalesce(sum("w"."born"), 0) as new_words')
            ->when($hard, fn (QueryBuilder $q) => $q->selectRaw('coalesce(sum("w"."won"), 0) as hard_won'))
            // A new word that left the minute steps: born here and graduated (its stage is `learning` or above).
            ->selectRaw('coalesce(sum(case when "w"."born" = 1 and "w"."stage_after" >= 1 then 1 else 0 end), 0) as graduated')
            // An older word that climbed: a word born here starts at `new`, its climb is `graduated` above.
            ->selectRaw('coalesce(sum(case when "w"."born" = 0 and "w"."stage_after" > "w"."stage_before" then 1 else 0 end), 0) as promoted')
            ->selectRaw('coalesce(sum(case when "w"."stage_after" = ? and ("w"."born" = 1 or "w"."stage_before" < ?) then 1 else 0 end), 0) as mastered', [WordStage::Mastered->rank(), WordStage::Mastered->rank()])
            ->first();

        return new SessionSummary(
            $session,
            (int) ($row->reviewed ?? 0),
            (int) ($row->correct ?? 0),
            (int) ($row->new_words ?? 0),
            $wasOpen,
            graduated: (int) ($row->graduated ?? 0),
            promoted: (int) ($row->promoted ?? 0),
            mastered: (int) ($row->mastered ?? 0),
            hardWon: $hard ? (int) ($row->hard_won ?? 0) : null,
            hardTotal: $hard ? $this->hardWordCount($session) : null,
        );
    }

    /** The size of the set of a round of hard words (`meta.hard_words`). */
    private function hardWordCount(TrainingSession $session): int
    {
        $words = ($session->meta ?? [])['hard_words'] ?? [];

        return is_array($words) ? count($words) : 0;
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
        return $this->authority->canTeach($user, $group);
    }
}
