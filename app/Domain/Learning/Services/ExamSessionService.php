<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Exceptions\ExamSessionException;
use App\Models\ExamSession;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Opens and closes exam sessions. Stores the pre-generated question list in
 * `exam_sessions.config.questions` so every participant sees the same
 * sequence.
 *
 * Invariants:
 *   - At most ONE exam with status=open per group at any time.
 *   - Duration is clamped to 1..30 minutes.
 */
final class ExamSessionService
{
    public const MIN_DURATION_MINUTES = 1;
    public const MAX_DURATION_MINUTES = 30;
    public const DEFAULT_QUESTIONS    = 10;
    public const DEFAULT_SECONDS_PER_QUESTION = 10;

    public function __construct(private readonly ExamQuestionBuilder $questionBuilder) {}

    /**
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

        $totalQuestions    ??= self::DEFAULT_QUESTIONS;
        $secondsPerQuestion ??= self::DEFAULT_SECONDS_PER_QUESTION;

        /** @var Stage|null $stage */
        $stage = Stage::query()->where('number', $stageNumber)->first();
        if ($stage === null) {
            throw ExamSessionException::stageNotFound($stageNumber);
        }

        /** @var Lesson|null $lesson */
        $lesson = Lesson::query()
            ->where('stage_id', $stage->id)
            ->where('number', $lessonNumber)
            ->first();
        if ($lesson === null) {
            throw ExamSessionException::lessonNotFound($stageNumber, $lessonNumber);
        }

        $wordCount = $lesson->words()->count();
        if ($wordCount < ExamQuestionBuilder::MIN_QUESTIONS) {
            throw ExamSessionException::notEnoughWords($wordCount, ExamQuestionBuilder::MIN_QUESTIONS);
        }

        $now ??= CarbonImmutable::now();

        return DB::transaction(function () use (
            $group,
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

            $questions = $this->questionBuilder->build($lesson, $totalQuestions);
            if (count($questions) < ExamQuestionBuilder::MIN_QUESTIONS) {
                throw ExamSessionException::notEnoughWords(
                    count($questions),
                    ExamQuestionBuilder::MIN_QUESTIONS,
                );
            }

            return ExamSession::query()->create([
                'telegram_group_id'  => $group->id,
                'lesson_id'          => $lesson->id,
                'started_by_user_id' => $teacher->id,
                'status'             => 'open',
                'started_at'         => $now,
                'ends_at'            => $now->addMinutes($durationMinutes),
                'config'             => [
                    'total_questions'      => count($questions),
                    'seconds_per_question' => $secondsPerQuestion,
                    'duration_minutes'     => $durationMinutes,
                    'questions'            => $questions,
                ],
            ]);
        });
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
            'status'    => 'closed',
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
        return DB::table('teacher_groups')
            ->where('user_id', $user->id)
            ->where('telegram_group_id', $group->id)
            ->exists();
    }
}
