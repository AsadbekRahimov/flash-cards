<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Learning\Exceptions\ExamSessionException;
use App\Domain\Localization\Services\LocaleResolver;
use App\Models\ExamSession;
use App\Models\TelegramGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Opens and closes exam sessions. Stores the canonical question list in
 * `exam_sessions.config.questions`; every participant gets their own
 * question/option order from it via {@see ExamQuestionPersonalizer}
 * (`config.question_order = 'per_learner'`).
 *
 * Invariants:
 *   - At most ONE exam with status=open per group at any time.
 *   - Duration is clamped to 1..30 minutes.
 */
final class ExamSessionService
{
    public const MIN_DURATION_MINUTES = 1;

    public const MAX_DURATION_MINUTES = 30;

    public const DEFAULT_QUESTIONS = 10;

    public const DEFAULT_SECONDS_PER_QUESTION = 10;

    public function __construct(
        private readonly ExamQuestionBuilder $questionBuilder,
        private readonly CourseCatalogService $catalog,
        private readonly LocaleResolver $locales,
        private readonly TeacherAuthority $authority,
    ) {}

    /**
     * Stage / lesson numbers are resolved inside the course of the group (`telegram_groups.course_id`,
     * else the default course). Question texts are the translations in the locale of the GROUP
     * (fallback ru), fixed when the exam is opened and stored in `config.locale`.
     *
     * @throws ExamSessionException
     */
    public function open(
        TelegramGroup $group,
        int $stageNumber,
        int $lessonNumber,
        int $durationMinutes,
        User $teacher,
        ?int $totalQuestions = null,
        ?int $secondsPerQuestion = null,
        ?CarbonImmutable $now = null,
    ): ExamSession {
        if (! $this->userTeachesGroup($teacher, $group)) {
            throw ExamSessionException::notTeacher();
        }

        if ($group->status !== 'active') {
            throw ExamSessionException::groupInactive();
        }

        if ($durationMinutes < self::MIN_DURATION_MINUTES || $durationMinutes > self::MAX_DURATION_MINUTES) {
            throw ExamSessionException::invalidDuration($durationMinutes);
        }

        $totalQuestions ??= self::DEFAULT_QUESTIONS;
        $secondsPerQuestion ??= self::DEFAULT_SECONDS_PER_QUESTION;

        $course = $this->catalog->courseForGroup($group);
        $lesson = $this->catalog->numberedLesson($course, $stageNumber, $lessonNumber);
        if ($lesson === null) {
            if ($this->catalog->findStage($course, $stageNumber) === null) {
                throw ExamSessionException::stageNotFound($stageNumber);
            }

            throw ExamSessionException::lessonNotFound($stageNumber, $lessonNumber);
        }

        $now ??= CarbonImmutable::now();
        $locale = $this->locales->forGroup($group);

        try {
            return DB::transaction(function () use (
                $group,
                $locale,
                $lesson,
                $teacher,
                $durationMinutes,
                $totalQuestions,
                $secondsPerQuestion,
                $now,
            ): ExamSession {
                $wordPool = $this->questionBuilder->sampleLessonWords($lesson);
                if (count($wordPool) < ExamQuestionBuilder::MIN_QUESTIONS) {
                    throw ExamSessionException::notEnoughWords(count($wordPool), ExamQuestionBuilder::MIN_QUESTIONS);
                }

                $openExists = ExamSession::query()
                    ->where('telegram_group_id', $group->id)
                    ->where('status', 'open')
                    ->lockForUpdate()
                    ->exists();

                if ($openExists) {
                    throw ExamSessionException::alreadyOpen();
                }

                $questions = $this->questionBuilder->forLessonWords($lesson, $wordPool, $totalQuestions, $locale);

                return ExamSession::query()->create([
                    'telegram_group_id' => $group->id,
                    'lesson_id' => $lesson->id,
                    'started_by_user_id' => $teacher->id,
                    'status' => 'open',
                    'started_at' => $now,
                    'ends_at' => $now->addMinutes($durationMinutes),
                    'config' => [
                        'total_questions' => count($questions),
                        'locale' => $locale,
                        'seconds_per_question' => $secondsPerQuestion,
                        'duration_minutes' => $durationMinutes,
                        'question_order' => ExamQuestionPersonalizer::ORDER_PER_LEARNER,
                        // Server-side HMAC key of the per-learner ordering. Never leaves the server.
                        'order_secret' => bin2hex(random_bytes(32)),
                        'questions' => $questions,
                    ],
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ExamSessionException::alreadyOpen();
        }
    }

    /**
     * Close a session (idempotent). Returns the (fresh) session.
     * Actual leaderboard is built by LeaderboardBuilder after this call.
     */
    public function close(ExamSession $session, ?CarbonImmutable $now = null): ExamSession
    {
        if ($session->status === 'closed') {
            return $session->fresh() ?? $session;
        }

        $now ??= CarbonImmutable::now();

        $attributes = [
            'status' => 'closed',
            'closed_at' => $now,
            'updated_at' => $session->freshTimestamp(),
        ];
        $closed = ExamSession::query()->whereKey($session->id)->where('status', $session->status)->update($attributes);

        if ($closed === 0) {
            return $session->fresh() ?? $session;
        }

        return $session->forceFill($attributes)->syncOriginal();
    }

    /**
     * Withdraws an exam nobody could reach (its Mini App button was not delivered) so the command can be
     * repeated: closes it with no results (no answers exist yet). Idempotent.
     */
    public function cancelUndelivered(ExamSession $session, ?CarbonImmutable $now = null): void
    {
        $this->close($session, $now);
    }

    /**
     * @throws ExamSessionException
     */
    public function closeOpenForGroup(TelegramGroup $group, ?CarbonImmutable $now = null): ExamSession
    {
        /** @var ExamSession|null $open */
        $open = ExamSession::query()
            ->where('telegram_group_id', $group->id)
            ->where('status', 'open')
            ->latest('started_at')
            ->first();

        if ($open === null) {
            throw ExamSessionException::noOpenExam();
        }

        return $this->close($open, $now);
    }

    private function userTeachesGroup(User $user, TelegramGroup $group): bool
    {
        return $this->authority->canTeach($user, $group);
    }
}
