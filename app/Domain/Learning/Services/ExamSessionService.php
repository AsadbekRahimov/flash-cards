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

        $stage = $this->catalog->findStage($this->catalog->courseForGroup($group), $stageNumber);
        if ($stage === null) {
            throw ExamSessionException::stageNotFound($stageNumber);
        }

        $lesson = $this->catalog->findLesson($stage, $lessonNumber);
        if ($lesson === null) {
            throw ExamSessionException::lessonNotFound($stageNumber, $lessonNumber);
        }

        $wordCount = $lesson->words()->where('is_active', true)->count();
        if ($wordCount < ExamQuestionBuilder::MIN_QUESTIONS) {
            throw ExamSessionException::notEnoughWords($wordCount, ExamQuestionBuilder::MIN_QUESTIONS);
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
                $openExists = ExamSession::query()
                    ->where('telegram_group_id', $group->id)
                    ->where('status', 'open')
                    ->lockForUpdate()
                    ->exists();

                if ($openExists) {
                    throw ExamSessionException::alreadyOpen();
                }

                $questions = $this->questionBuilder->build($lesson, $totalQuestions, $locale);
                if (count($questions) < ExamQuestionBuilder::MIN_QUESTIONS) {
                    throw ExamSessionException::notEnoughWords(
                        count($questions),
                        ExamQuestionBuilder::MIN_QUESTIONS,
                    );
                }

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

        $session->update([
            'status' => 'closed',
            'closed_at' => $now,
        ]);

        return $session->fresh() ?? $session;
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
