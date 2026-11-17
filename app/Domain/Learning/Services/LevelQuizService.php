<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Learners\Services\LearnerClock;
use App\Domain\Learners\Support\StoredDate;
use App\Domain\Learning\DTO\LevelQuizResult;
use App\Domain\Learning\DTO\LevelQuizWeek;
use App\Domain\Learning\Exceptions\ExamSessionException;
use App\Domain\Learning\Exceptions\LevelQuizException;
use App\Domain\Localization\Services\LocaleResolver;
use App\Models\Course;
use App\Models\ExamAnswer;
use App\Models\ExamSession;
use App\Models\Learner;
use App\Models\LevelQuizResult as LevelQuizResultRow;
use App\Models\Word;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The quiz of the week (docs/13 §8.5): an `exam_sessions` row of `mode = level_quiz` owned by a learner and a course, built from
 * active words of the course and played through the ordinary exam flow (`ExamFlowService`, `ExamQuestionPersonalizer`,
 * `ExamAnswerService`: the key of the answers stays on the server, the order is personal, an answer is accepted once, one timer).
 * It is a measurement: nothing here writes `word_repetitions`, `training_*`, `learner_daily_stats` or any rating points.
 *
 * Opening: at most ONE open quiz per learner (partial unique `exam_sessions_one_open_quiz_per_learner`); an open, unexpired one is
 * returned as-is, an expired one is finalized first. About `met_share` of the words are ones the learner has a row for, the rest are
 * words not met yet (one group tops the other up). The quiz is COUNTED (`scored`) when the learner has no counted result of
 * the ISO week (Monday-Sunday in THEIR zone, fixed when the quiz is opened) for the course; later ones are practice.
 * Two concurrent opens end up with one session (the loser re-reads the winner's row), so this must not run inside an outer transaction.
 *
 * Finalization (idempotent under the row lock): a quiz with at least one answer writes ONE `level_quiz_results` row - counted or
 * not; the partial unique `level_quiz_results_one_scored_per_week` is the last line of the rule "one counted quiz per week" (the
 * insert that loses it is written as practice). A quiz nobody answered writes nothing, the week stays open for a counted one.
 */
final class LevelQuizService
{
    public function __construct(
        private readonly ExamQuestionBuilder $questions,
        private readonly LocaleResolver $locales,
        private readonly LearnerClock $clock,
        private readonly ProductEventRecorder $events,
    ) {}

    /** Both switches: the whole readiness feature and the quiz inside it. */
    public function enabled(): bool
    {
        return (bool) config('readiness.enabled', true) && (bool) config('readiness.quiz.enabled', true);
    }

    /** `Y-m-d` of the Monday of the learner's current ISO week, in the learner's zone. */
    public function weekStart(Learner $learner, ?CarbonImmutable $now = null): string
    {
        return $this->clock->today($learner, $now)->startOfWeek(CarbonInterface::MONDAY)->toDateString();
    }

    /**
     * Opens the learner's quiz of `$course` or returns the open one. `wasRecentlyCreated` of the returned session tells which.
     *
     * @throws LevelQuizException level_quiz_disabled | not_enough_words | not_enough_distractors
     */
    public function open(Learner $learner, Course $course, ?CarbonImmutable $now = null): ExamSession
    {
        $now ??= CarbonImmutable::now();

        if (! $this->enabled()) {
            throw LevelQuizException::disabled();
        }

        $available = $this->activeWords($course)->count();
        if ($available < ExamQuestionBuilder::MIN_QUESTIONS) {
            throw LevelQuizException::notEnoughWords($available, ExamQuestionBuilder::MIN_QUESTIONS);
        }

        // A quiz that is over (time out / everything answered) the scheduler has not reached yet, or one of ANOTHER course (a learner
        // has one open quiz): finalize it first (its own transaction, idempotent under the row lock, with its own counted/practice
        // rule), so its result counts for the week before the new one decides whether it is counted, and the insert is not blocked.
        // Only a running quiz of THIS course is resumed.
        $stale = $this->openQuizOf($learner)->first();
        if ($stale !== null) {
            $stale = $this->finalizeIfDue($stale, $now);
            if ($stale->status === 'open' && $stale->course_id !== $course->id) {
                $this->finalize($stale, 'replaced', $now);
            }
        }

        $weekStart = $this->weekStart($learner, $now);
        $scored = ! LevelQuizResultRow::query()
            ->where('learner_id', $learner->id)
            ->where('course_id', $course->id)
            ->where('week_start', StoredDate::of($weekStart))
            ->where('scored', true)
            ->exists();

        $total = max(ExamQuestionBuilder::MIN_QUESTIONS, min($this->questionCount(), $available));
        $seconds = $this->seconds();
        $locale = $this->locales->forLearner($learner);

        try {
            $session = DB::transaction(function () use ($learner, $course, $now, $total, $seconds, $locale, $weekStart, $scored): ExamSession {
                /** @var ExamSession|null $existing */
                $existing = $this->openQuizOf($learner)->where('course_id', $course->id)->lockForUpdate()->first();
                if ($existing !== null) {
                    return $existing;
                }

                $session = ExamSession::query()->create([
                    'mode' => ExamSession::MODE_LEVEL_QUIZ,
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

                [$words, $met, $unmet] = $this->sampleWords($course, $learner, $total);

                try {
                    $questions = $this->questions->forWords($words, $course, $locale);
                } catch (ExamSessionException $e) {
                    throw $e->reason === ExamSessionException::REASON_NOT_ENOUGH_DISTRACTORS
                        ? LevelQuizException::notEnoughDistractors($this->wordInMessage($e->getMessage()))
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
                    'week_start' => $weekStart,
                    'scored' => $scored,
                    'met_count' => $met,
                    'unmet_count' => $unmet,
                ];
                $session->save();

                return $session;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Lost the race against a parallel open: the winner's row is the learner's quiz (when it is of this course).
            $winner = $this->openQuizOf($learner)->where('course_id', $course->id)->first();
            if ($winner === null) {
                throw $e;
            }

            return $winner;
        }

        if ($session->wasRecentlyCreated) {
            $this->events->record(ProductEventName::LevelQuizStarted, [
                'exam_session_id' => $session->id,
                'course_id' => $course->id,
                'week_start' => $weekStart,
                'scored' => $scored,
                'questions' => (int) ($session->config['total_questions'] ?? 0),
                'met' => (int) ($session->config['met_count'] ?? 0),
                'unmet' => (int) ($session->config['unmet_count'] ?? 0),
            ], learner: $learner);
        }

        return $session;
    }

    /** The quiz is open and its time has not run out. */
    public function isRunning(ExamSession $session, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return $session->status === 'open'
            && ($session->ends_at === null || $session->ends_at->isAfter($now));
    }

    /**
     * Closes the quiz when it is over - time out, or every question answered - and returns the fresh row. A running quiz is returned
     * unchanged (still open).
     */
    public function finalizeIfDue(ExamSession $session, ?CarbonImmutable $now = null): ExamSession
    {
        $now ??= CarbonImmutable::now();

        if ($session->status !== 'open' || ! $session->isLevelQuiz()) {
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
     * Closes a quiz (idempotent, under the row lock: a parallel finalize is a no-op) and writes its result row when something was
     * answered. Records `level_quiz.finished` once, after the commit.
     *
     * @param  string  $reason  `finished` (everything answered) | `expired` (time over / scheduler) | `replaced` (the learner opened a
     *                          quiz of another course); a quiz with everything answered is `finished` whatever was passed
     */
    public function finalize(ExamSession $session, string $reason, ?CarbonImmutable $now = null): ExamSession
    {
        $now ??= CarbonImmutable::now();

        /** @var array{session: ExamSession, closed: bool, correct: int, answered: int, scored: bool, reason: string} $outcome */
        $outcome = DB::transaction(function () use ($session, $now, $reason): array {
            /** @var ExamSession|null $locked */
            $locked = ExamSession::query()->lockForUpdate()->find($session->id);
            if ($locked === null || ! $locked->isLevelQuiz()) {
                return ['session' => $session, 'closed' => false, 'correct' => 0, 'answered' => 0, 'scored' => false, 'reason' => $reason];
            }

            if ($locked->status !== 'open') {
                return ['session' => $locked, 'closed' => false, 'correct' => 0, 'answered' => 0, 'scored' => false, 'reason' => $reason];
            }

            $locked->update(['status' => 'closed', 'closed_at' => $now]);

            /** @var object{answered: int|string|null, correct: int|string|null}|null $stats */
            $stats = ExamAnswer::query()
                ->where('exam_session_id', $locked->id)
                ->selectRaw('COUNT(*) AS answered, SUM(CASE WHEN is_correct THEN 1 ELSE 0 END) AS correct')
                ->first();

            $answered = (int) ($stats->answered ?? 0);
            $correct = (int) ($stats->correct ?? 0);
            $scored = false;

            if ($answered > 0 && $locked->learner_id !== null && $locked->course_id !== null) {
                $scored = $this->recordResult($locked, $correct, $now);
            }

            // A quiz with every question answered is `finished` whoever closes it (the scheduler passes `expired`).
            $total = (int) ($locked->config['total_questions'] ?? 0);
            $why = $total > 0 && $answered >= $total ? 'finished' : $reason;

            return ['session' => $locked, 'closed' => true, 'correct' => $correct, 'answered' => $answered, 'scored' => $scored, 'reason' => $why];
        });

        $closed = $outcome['session'];
        if (! $outcome['closed']) {
            return $closed;
        }

        $total = (int) ($closed->config['total_questions'] ?? 0);
        $this->events->record(ProductEventName::LevelQuizFinished, [
            'exam_session_id' => $closed->id,
            'course_id' => $closed->course_id,
            'week_start' => (string) ($closed->config['week_start'] ?? ''),
            'scored' => $outcome['scored'],
            'correct' => $outcome['correct'],
            'total' => $total,
            'percent' => self::percent($outcome['correct'], $total),
            'answered' => $outcome['answered'],
            'reason' => $outcome['reason'],
        ], learner: $closed->learner);

        return $closed;
    }

    /** The result of a closed quiz (while it is open: nothing answered counted, `scored` false). */
    public function result(ExamSession $session): LevelQuizResult
    {
        /** @var LevelQuizResultRow|null $row */
        $row = LevelQuizResultRow::query()->where('exam_session_id', $session->id)->first();
        $total = $row !== null ? $row->total : (int) ($session->config['total_questions'] ?? 0);
        $correct = $row !== null ? $row->correct : 0;
        $answered = $row !== null ? ExamAnswer::query()->where('exam_session_id', $session->id)->count() : 0;

        return new LevelQuizResult(
            $session,
            $correct,
            $total,
            $answered,
            self::percent($correct, $total),
            $row !== null && $row->scored,
            (string) ($session->config['week_start'] ?? ''),
        );
    }

    /** The learner's counted result of the current week for the course - ONE statement (none when the quiz is off). */
    public function weekLine(Learner $learner, Course $course, ?CarbonImmutable $now = null): LevelQuizWeek
    {
        $weekStart = $this->weekStart($learner, $now);
        if (! $this->enabled()) {
            return new LevelQuizWeek(false, $weekStart, null);
        }

        /** @var LevelQuizResultRow|null $row */
        $row = LevelQuizResultRow::query()
            ->where('learner_id', $learner->id)
            ->where('course_id', $course->id)
            ->where('week_start', StoredDate::of($weekStart))
            ->where('scored', true)
            ->first(['correct', 'total', 'finished_at']);

        return new LevelQuizWeek(true, $weekStart, $row === null ? null : [
            'correct' => $row->correct,
            'total' => $row->total,
            'percent' => self::percent($row->correct, $row->total),
            'finished_at' => $row->finished_at->toIso8601String(),
        ]);
    }

    /**
     * The counted results of the last `$weeks` weeks (the current one included), by `week_start` ascending - ONE statement.
     *
     * @return list<array{week_start: string, correct: int, total: int, percent: int}>
     */
    public function history(Learner $learner, Course $course, ?CarbonImmutable $now = null, ?int $weeks = null): array
    {
        return $this->overview($learner, $course, $now, $weeks)['history'];
    }

    /**
     * The week line AND the history of the screen from the same ONE statement: the counted results of the last `$weeks` weeks hold
     * the current week's one.
     *
     * @return array{week: LevelQuizWeek, history: list<array{week_start: string, correct: int, total: int, percent: int}>}
     */
    public function overview(Learner $learner, Course $course, ?CarbonImmutable $now = null, ?int $weeks = null): array
    {
        $weekStart = $this->weekStart($learner, $now);
        if (! $this->enabled()) {
            return ['week' => new LevelQuizWeek(false, $weekStart, null), 'history' => []];
        }

        $weeks ??= $this->historyWeeks();
        $monday = CarbonImmutable::createFromFormat('!Y-m-d', $weekStart)
            ?: throw new LogicException('week_start is not a date');
        $from = $monday->subWeeks(max(1, $weeks) - 1)->toDateString();

        /** @var Collection<int, LevelQuizResultRow> $rows */
        $rows = LevelQuizResultRow::query()
            ->where('learner_id', $learner->id)
            ->where('course_id', $course->id)
            ->where('scored', true)
            ->where('week_start', '>=', StoredDate::of($from))
            ->orderBy('week_start')
            ->get(['week_start', 'correct', 'total', 'finished_at']);

        $history = [];
        $thisWeek = null;
        foreach ($rows as $row) {
            $percent = self::percent($row->correct, $row->total);
            $date = $row->week_start->toDateString();
            $history[] = ['week_start' => $date, 'correct' => $row->correct, 'total' => $row->total, 'percent' => $percent];
            if ($date === $weekStart) {
                $thisWeek = ['correct' => $row->correct, 'total' => $row->total, 'percent' => $percent, 'finished_at' => $row->finished_at->toIso8601String()];
            }
        }

        return ['week' => new LevelQuizWeek(true, $weekStart, $thisWeek), 'history' => $history];
    }

    public function questionCount(): int
    {
        return max(ExamQuestionBuilder::MIN_QUESTIONS, min(30, (int) config('readiness.quiz.questions', 12)));
    }

    public function seconds(): int
    {
        return max(60, (int) config('readiness.quiz.seconds', 600));
    }

    public function historyWeeks(): int
    {
        return max(1, min(52, (int) config('readiness.quiz.history_weeks', 12)));
    }

    /** The floor of the share, so a figure never promises more than was answered. */
    public static function percent(int $correct, int $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        return intdiv(100 * max(0, min($correct, $total)), $total);
    }

    /**
     * Writes the result row of a closed quiz inside the finalize transaction. A counted row that loses the partial unique (another
     * counted result of the week is already there) is written again as practice, in a savepoint so the outer transaction survives.
     *
     * @return bool whether the row was written as the counted one
     */
    private function recordResult(ExamSession $session, int $correct, CarbonImmutable $now): bool
    {
        $wanted = (bool) ($session->config['scored'] ?? false);
        $attributes = [
            'learner_id' => $session->learner_id,
            'course_id' => $session->course_id,
            'exam_session_id' => $session->id,
            'week_start' => StoredDate::of((string) ($session->config['week_start'] ?? $now->startOfWeek(CarbonInterface::MONDAY)->toDateString())),
            'correct' => $correct,
            'total' => (int) ($session->config['total_questions'] ?? 0),
            'finished_at' => $now,
        ];

        if ($wanted) {
            try {
                DB::transaction(fn () => LevelQuizResultRow::query()->create($attributes + ['scored' => true]));

                return true;
            } catch (UniqueConstraintViolationException) {
                // The week already has its counted quiz: this one is practice.
            }
        }

        LevelQuizResultRow::query()->create($attributes + ['scored' => false]);

        return false;
    }

    /**
     * The words of the quiz: about `met_share` of them from the active words the learner has a row for, the rest from the ones they
     * have not met; one group tops the other up when it is short. In a random order.
     *
     * @return array{0: list<Word>, 1: int, 2: int} the words, how many of them were met and how many were not
     *
     * @throws LevelQuizException not_enough_words
     */
    private function sampleWords(Course $course, Learner $learner, int $total): array
    {
        $share = max(0.0, min(1.0, (float) config('readiness.quiz.met_share', 0.67)));
        $metTarget = (int) round($total * $share);

        $met = $this->metWords($course, $learner, true)->limit($metTarget)->get();
        $unmet = $this->metWords($course, $learner, false)->limit($total - $met->count())->get();

        $missing = $total - $met->count() - $unmet->count();
        if ($missing > 0) {
            $chosen = $met->pluck('id')->all();
            $met = $met->concat($this->metWords($course, $learner, true)->whereNotIn('words.id', $chosen)->limit($missing)->get());
        }

        $words = [...$met->all(), ...$unmet->all()];
        if (count($words) < ExamQuestionBuilder::MIN_QUESTIONS) {
            throw LevelQuizException::notEnoughWords(count($words), ExamQuestionBuilder::MIN_QUESTIONS);
        }

        $metCount = $met->count();
        shuffle($words);

        /** @var list<Word> $words */
        return [$words, $metCount, count($words) - $metCount];
    }

    /**
     * Active words of the course the learner has (`$met`) or has not a `word_repetitions` row for, in a random order.
     *
     * @return Builder<Word>
     */
    private function metWords(Course $course, Learner $learner, bool $met): Builder
    {
        return $this->activeWords($course)
            ->whereExists(
                fn ($q) => $q->selectRaw('1')->from('word_repetitions')
                    ->whereColumn('word_repetitions.word_id', 'words.id')
                    ->where('word_repetitions.learner_id', $learner->id),
                'and',
                ! $met,
            )
            ->inRandomOrder();
    }

    /** @return Builder<Word> */
    private function activeWords(Course $course): Builder
    {
        return Word::query()
            ->where('words.is_active', true)
            ->whereHas('lesson.stage', fn ($q) => $q->where('course_id', $course->id));
    }

    /** @return Builder<ExamSession> */
    private function openQuizOf(Learner $learner): Builder
    {
        return ExamSession::query()
            ->where('learner_id', $learner->id)
            ->where('mode', ExamSession::MODE_LEVEL_QUIZ)
            ->where('status', 'open');
    }

    private function wordInMessage(string $message): string
    {
        return preg_match('/"([^"]+)"/', $message, $m) === 1 ? $m[1] : '?';
    }
}
