<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Learning\DTO\MockExamResult;
use App\Domain\Learning\Exceptions\ExamSessionException;
use App\Domain\Learning\Exceptions\MockExamException;
use App\Domain\Localization\Services\LocaleResolver;
use App\Models\Course;
use App\Models\ExamAnswer;
use App\Models\ExamResult;
use App\Models\ExamSession;
use App\Models\Learner;
use App\Models\Word;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Personal IELTS / SAT vocabulary mocks (docs/13 §2.5, §8.4): a `personal_mock`
 * row of `exam_sessions` owned by a learner, built from random active words of a
 * course and played through the ordinary exam flow (`ExamFlowService`,
 * `ExamQuestionPersonalizer`, `ExamAnswerService`) under the owner policy.
 *
 * Opening (Pro feature `exam.simulator`, course `exam_tag` in `learning.mock_exam.exam_tags`):
 *   - at most ONE open mock per learner (partial unique `exam_sessions_one_open_mock_per_learner`):
 *     an open, unexpired mock is returned as-is, whatever its course; an expired one is finalized
 *     first and replaced. Two concurrent opens end up with one session (the loser re-reads the
 *     winner's row after the unique violation, so this must not run inside an outer transaction);
 *   - the words are a seeded shuffle of the course's active words (seed = session id), so the
 *     same session always means the same word set, while every mock differs;
 *   - `config.seconds_per_question` equals the whole-exam limit: a mock has one timer, not one
 *     per question.
 *
 * Timer. The server is the only clock: after `ends_at` answers are refused by the exam flow
 * (410 `session_closed`). The row is finalized lazily — by `/result` (every question answered
 * or time over) and by the next `open` of the learner — and by `exams:close-expired` for
 * abandoned mocks, so the percentile distribution does not depend on learners coming back.
 *
 * Result = percent of correct answers over ALL questions (unanswered = wrong) plus the
 * percentile among closed mocks of the same course over `percentile_days`, read from a
 * per-course distribution cached for `percentile_cache_ttl` (dropped on every finalize).
 * A mock without a single answer is closed without an `exam_results` row and does not take
 * part in anybody else's distribution.
 */
final class MockExamService
{
    private const CACHE_PREFIX = 'mock_exam:distribution:course:';

    public function __construct(
        private readonly ExamQuestionBuilder $questions,
        private readonly EntitlementResolver $entitlements,
        private readonly LocaleResolver $locales,
        private readonly ProductEventRecorder $events,
    ) {}

    /** Cache key of the per-course percentile distribution (dropped on every finalize of the course). */
    public static function distributionCacheKey(int $courseId): string
    {
        return self::CACHE_PREFIX.$courseId;
    }

    /** Whether the course offers a mock at all (`exam_tag` of an exam course). */
    public function isOffered(Course $course): bool
    {
        return $course->exam_tag !== null && in_array($course->exam_tag, $this->examTags(), true);
    }

    /**
     * Opens the learner's mock of `$course` or returns the open one. `wasRecentlyCreated`
     * of the returned session tells which (the controller answers 201 / 200).
     *
     * @throws MockExamException exam_simulator_pro_required | course_not_exam | not_enough_words | not_enough_distractors
     */
    public function open(Learner $learner, Course $course, ?CarbonImmutable $now = null): ExamSession
    {
        $now ??= CarbonImmutable::now();

        if (! $this->entitlements->for($learner, $now)->can('exam.simulator')) {
            throw MockExamException::proRequired();
        }

        if (! $this->isOffered($course)) {
            throw MockExamException::courseNotExam();
        }

        $available = $this->activeWords($course)->count();
        if ($available < ExamQuestionBuilder::MIN_QUESTIONS) {
            throw MockExamException::notEnoughWords($available, ExamQuestionBuilder::MIN_QUESTIONS);
        }

        // An expired mock the scheduler has not reached yet: finalize it first (its own
        // transaction, idempotent under the row lock) so the insert below is not blocked by it.
        $stale = $this->openMockOf($learner)->first();
        if ($stale !== null && ! $this->isRunning($stale, $now)) {
            $this->finalize($stale, 'expired', $now);
        }

        $total = max(ExamQuestionBuilder::MIN_QUESTIONS, min($this->questionCount(), $available));
        $seconds = $this->seconds();
        $locale = $this->locales->forLearner($learner);

        try {
            $session = DB::transaction(function () use ($learner, $course, $now, $total, $seconds, $locale): ExamSession {
                /** @var ExamSession|null $existing */
                $existing = $this->openMockOf($learner)->lockForUpdate()->first();
                if ($existing !== null) {
                    return $existing;
                }

                $session = ExamSession::query()->create([
                    'mode' => ExamSession::MODE_PERSONAL_MOCK,
                    'learner_id' => $learner->id,
                    'course_id' => $course->id,
                    'telegram_group_id' => null,
                    'lesson_id' => null,
                    'started_by_user_id' => null,
                    'status' => 'open',
                    'started_at' => $now,
                    'ends_at' => $now->addSeconds($seconds),
                    'config' => [],
                ]);

                $words = $this->sampleWords($course, (int) $session->id, $total);

                try {
                    $questions = $this->questions->forWords($words, $course, $locale);
                } catch (ExamSessionException $e) {
                    throw $e->reason === ExamSessionException::REASON_NOT_ENOUGH_DISTRACTORS
                        ? MockExamException::notEnoughDistractors($this->wordInMessage($e->getMessage()))
                        : $e;
                }

                $session->config = [
                    'total_questions' => count($questions),
                    'locale' => $locale,
                    'seconds' => $seconds,
                    'seconds_per_question' => $seconds,
                    'duration_minutes' => intdiv($seconds, 60),
                    'question_order' => ExamQuestionPersonalizer::ORDER_PER_LEARNER,
                    'order_secret' => bin2hex(random_bytes(32)),
                    'questions' => $questions,
                ];
                $session->save();

                return $session;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost the race against a parallel open: the winner's row is the learner's mock.
            return $this->openMockOf($learner)->firstOrFail();
        }

        if ($session->wasRecentlyCreated) {
            $this->events->record(ProductEventName::ExamMockStarted, [
                'exam_session_id' => $session->id,
                'course_id' => $course->id,
                'exam_tag' => $course->exam_tag,
                'questions' => (int) ($session->config['total_questions'] ?? 0),
            ], learner: $learner);
        }

        return $session;
    }

    /** The mock is open and its time has not run out. */
    public function isRunning(ExamSession $session, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return $session->status === 'open'
            && ($session->ends_at === null || $session->ends_at->isAfter($now));
    }

    /**
     * Closes the mock when it is over — time out, or every question answered by its owner —
     * and returns the fresh row. A running mock is returned unchanged (still open).
     */
    public function finalizeIfDue(ExamSession $session, ?CarbonImmutable $now = null): ExamSession
    {
        $now ??= CarbonImmutable::now();

        if ($session->status !== 'open' || ! $session->isPersonalMock()) {
            return $session;
        }

        if (! $this->isRunning($session, $now)) {
            return $this->finalize($session, 'expired', $now);
        }

        $total = (int) ($session->config['total_questions'] ?? 0);
        $answered = ExamAnswer::query()->where('exam_session_id', $session->id)->count();

        return $answered >= $total && $total > 0
            ? $this->finalize($session, 'finished', $now)
            : $session;
    }

    /**
     * Closes a mock (idempotent, under the row lock: a parallel finalize is a no-op) and writes
     * its single `exam_results` row (only when something was answered). Records
     * `exam.mock_finished` once, after the commit.
     *
     * @param  string  $reason  `finished` (owner answered everything) | `expired` (time over / scheduler)
     */
    public function finalize(ExamSession $session, string $reason, ?CarbonImmutable $now = null): ExamSession
    {
        $now ??= CarbonImmutable::now();

        /** @var array{session: ExamSession, closed: bool} $outcome */
        $outcome = DB::transaction(function () use ($session, $now): array {
            /** @var ExamSession|null $locked */
            $locked = ExamSession::query()->lockForUpdate()->find($session->id);
            if ($locked === null || ! $locked->isPersonalMock()) {
                return ['session' => $session, 'closed' => false];
            }

            if ($locked->status !== 'open') {
                return ['session' => $locked, 'closed' => false];
            }

            $locked->update(['status' => 'closed', 'closed_at' => $now]);

            /** @var object{answered: int|string|null, correct: int|string|null, score: int|string|null}|null $stats */
            $stats = ExamAnswer::query()
                ->where('exam_session_id', $locked->id)
                ->selectRaw(
                    'COUNT(*) AS answered,
                     SUM(CASE WHEN is_correct THEN 1 ELSE 0 END) AS correct,
                     SUM(score) AS score',
                )
                ->first();

            $answered = (int) ($stats->answered ?? 0);
            if ($answered > 0) {
                ExamResult::query()->where('exam_session_id', $locked->id)->delete();
                ExamResult::query()->create([
                    'exam_session_id' => $locked->id,
                    'learner_id' => $locked->learner_id,
                    'total_score' => (int) ($stats->score ?? 0),
                    'correct_count' => (int) ($stats->correct ?? 0),
                    'total_count' => (int) ($locked->config['total_questions'] ?? $answered),
                    'rank' => 1,
                ]);
            }

            return ['session' => $locked, 'closed' => true];
        });

        $closed = $outcome['session'];
        if (! $outcome['closed']) {
            return $closed;
        }

        if ($closed->course_id !== null) {
            Cache::forget(self::distributionCacheKey((int) $closed->course_id));
        }

        $result = $this->result($closed, $now);
        $this->events->record(ProductEventName::ExamMockFinished, [
            'exam_session_id' => $closed->id,
            'course_id' => $closed->course_id,
            'percent' => $result->percent,
            'percentile' => $result->percentile,
            'answered' => $result->answered,
            'reason' => $reason,
        ], learner: $closed->learner);

        return $closed;
    }

    /**
     * Percent and percentile of a mock. The percentile is null while the mock is open.
     */
    public function result(ExamSession $session, ?CarbonImmutable $now = null): MockExamResult
    {
        $now ??= CarbonImmutable::now();

        /** @var ExamResult|null $row */
        $row = ExamResult::query()->where('exam_session_id', $session->id)->first();
        $total = $row !== null ? (int) $row->total_count : (int) ($session->config['total_questions'] ?? 0);
        $correct = $row !== null ? (int) $row->correct_count : 0;
        $answered = $row !== null
            ? ExamAnswer::query()->where('exam_session_id', $session->id)->count()
            : 0;

        $percent = self::percent($correct, $total);

        if ($session->status !== 'closed' || $session->course_id === null) {
            return new MockExamResult($session, $correct, $total, $answered, $percent, null, 0);
        }

        $distribution = $this->distribution((int) $session->course_id, $now);
        $distribution[(int) $session->id] = $percent;

        $atOrBelow = count(array_filter($distribution, static fn (int $p): bool => $p <= $percent));
        $attempts = count($distribution);

        return new MockExamResult(
            $session,
            $correct,
            $total,
            $answered,
            $percent,
            (int) round(100 * $atOrBelow / $attempts),
            $attempts,
        );
    }

    /**
     * Percents of the closed mocks of a course over the window, keyed by session id (cached).
     *
     * @return array<int, int>
     */
    private function distribution(int $courseId, CarbonImmutable $now): array
    {
        $days = max(1, (int) config('learning.mock_exam.percentile_days', 90));
        $ttl = max(1, (int) config('learning.mock_exam.percentile_cache_ttl', 3600));
        $cutoff = $now->subDays($days);

        /** @var array<int, int> $distribution */
        $distribution = Cache::remember(
            self::distributionCacheKey($courseId),
            $ttl,
            function () use ($courseId, $cutoff): array {
                /** @var list<object{exam_session_id: int|string, correct_count: int|string, total_count: int|string}> $rows */
                $rows = ExamResult::query()
                    ->join('exam_sessions', 'exam_sessions.id', '=', 'exam_results.exam_session_id')
                    ->where('exam_sessions.mode', ExamSession::MODE_PERSONAL_MOCK)
                    ->where('exam_sessions.course_id', $courseId)
                    ->where('exam_sessions.status', 'closed')
                    ->where('exam_sessions.closed_at', '>=', $cutoff)
                    ->get(['exam_results.exam_session_id', 'exam_results.correct_count', 'exam_results.total_count'])
                    ->all();

                $out = [];
                foreach ($rows as $r) {
                    $out[(int) $r->exam_session_id] = self::percent((int) $r->correct_count, (int) $r->total_count);
                }

                return $out;
            },
        );

        return $distribution;
    }

    public static function percent(int $correct, int $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        return (int) round(100 * max(0, min($correct, $total)) / $total);
    }

    /**
     * The ids in a deterministic pseudo-random order derived from the session id: sorted by
     * `sha256(session_id|id)` (ties — impossible in practice — by id). Pure, for tests too.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public static function seededOrder(int $sessionId, array $ids): array
    {
        $keys = [];
        foreach ($ids as $id) {
            $keys[$id] = hash('sha256', "{$sessionId}|{$id}");
        }

        usort($ids, static fn (int $a, int $b): int => strcmp($keys[$a], $keys[$b]) ?: ($a <=> $b));

        return $ids;
    }

    /**
     * The first `$count` active words of the course in the seeded order of the session.
     *
     * @return list<Word>
     */
    private function sampleWords(Course $course, int $sessionId, int $count): array
    {
        /** @var list<int> $ids */
        $ids = $this->activeWords($course)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $chosen = array_slice(self::seededOrder($sessionId, $ids), 0, $count);

        /** @var array<int, Word> $byId */
        $byId = Word::query()->whereIn('id', $chosen)->get()->keyBy('id')->all();

        $words = [];
        foreach ($chosen as $id) {
            if (isset($byId[$id])) {
                $words[] = $byId[$id];
            }
        }

        return $words;
    }

    /** @return Builder<Word> */
    private function activeWords(Course $course): Builder
    {
        return Word::query()
            ->where('is_active', true)
            ->whereHas('lesson.stage', fn ($q) => $q->where('course_id', $course->id));
    }

    /** @return Builder<ExamSession> */
    private function openMockOf(Learner $learner): Builder
    {
        return ExamSession::query()
            ->where('learner_id', $learner->id)
            ->where('mode', ExamSession::MODE_PERSONAL_MOCK)
            ->where('status', 'open');
    }

    /** @return list<string> */
    private function examTags(): array
    {
        /** @var array<int, mixed> $tags */
        $tags = (array) config('learning.mock_exam.exam_tags', ['ielts', 'sat']);

        return array_values(array_map(static fn (mixed $t): string => (string) $t, $tags));
    }

    private function questionCount(): int
    {
        return max(ExamQuestionBuilder::MIN_QUESTIONS, (int) config('learning.mock_exam.questions', 40));
    }

    private function seconds(): int
    {
        return max(60, (int) config('learning.mock_exam.seconds', 1200));
    }

    private function wordInMessage(string $message): string
    {
        return preg_match('/"([^"]+)"/', $message, $m) === 1 ? $m[1] : '?';
    }
}
