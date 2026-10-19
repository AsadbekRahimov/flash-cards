<?php

declare(strict_types=1);

namespace App\Domain\Learning\Support;

use App\Domain\Learning\Enums\DoneReason;
use App\Models\Course;
use App\Models\TrainingSession;
use App\Models\Word;
use App\Models\WordRepetition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use LogicException;

/**
 * Which cards a training session may serve and accept (docs/06 §4, docs/12 §3.3).
 *
 *   - lesson scope (`group_lesson`, `lesson`): repetitions and new words of ONE lesson;
 *   - daily scope (`daily_review`): due repetitions of EVERY word the learner has ever
 *     seen, in any lesson of any course, plus new words from the session's course
 *     (`training_sessions.course_id`, a snapshot of `learners.active_course_id`) in
 *     study order `(stage.number, lesson.number, word.id)`, as long as that course is
 *     available (published, not private) — otherwise only due cards. Without a course the
 *     daily session serves due cards only.
 *
 * Inactive words (`words.is_active = false`, the replacement for deletion) are never
 * served in either scope; their `word_repetitions` rows are left untouched.
 */
final readonly class TrainingScope
{
    private const KIND_LESSON = 'lesson';

    private const KIND_DAILY = 'daily';

    /**
     * @param  string  $sessionMode  `TrainingSession::MODE_*` of the session the scope was built for. Does not
     *                               change what is served; CardPicker stamps it on every word it introduces
     *                               (`word_repetitions.introduced_mode`), so the Free quota can leave words of a
     *                               teacher's group lesson out (docs/12 §5.3).
     */
    private function __construct(
        private string $kind,
        public ?int $lessonId,
        public ?int $courseId,
        public string $sessionMode,
    ) {}

    /** @param string $sessionMode `lesson` (personal, default) or `group_lesson` */
    public static function lesson(int $lessonId, string $sessionMode = TrainingSession::MODE_LESSON): self
    {
        return new self(self::KIND_LESSON, $lessonId, null, $sessionMode);
    }

    public static function daily(?int $courseId): self
    {
        return new self(self::KIND_DAILY, null, $courseId, TrainingSession::MODE_DAILY_REVIEW);
    }

    /**
     * @throws LogicException when a lesson-mode session has no lesson (the `mode_shape`
     *                        invariant guarded by the DB CHECK and TrainingSessionService)
     */
    public static function forSession(TrainingSession $session): self
    {
        if ($session->mode === TrainingSession::MODE_DAILY_REVIEW) {
            return self::daily($session->course_id === null ? null : (int) $session->course_id);
        }

        if ($session->lesson_id === null) {
            throw new LogicException("Training session {$session->id} ({$session->mode}) has no lesson.");
        }

        return self::lesson((int) $session->lesson_id, (string) $session->mode);
    }

    /** A teacher-led group lesson (`mode = group_lesson`): its new words are outside the Free quota. */
    public function isGroupLesson(): bool
    {
        return $this->sessionMode === TrainingSession::MODE_GROUP_LESSON;
    }

    public function isLesson(): bool
    {
        return $this->kind === self::KIND_LESSON;
    }

    public function isDaily(): bool
    {
        return $this->kind === self::KIND_DAILY;
    }

    /** Whether this scope can serve words the learner has never seen. */
    public function includesNew(): bool
    {
        return $this->isLesson() || $this->courseId !== null;
    }

    /** Can a grade for $word be accepted in this scope? (The repetition row must already exist — checked by the caller.) */
    public function contains(Word $word): bool
    {
        if (! $word->is_active) {
            return false;
        }

        return $this->isDaily() || (int) $word->lesson_id === $this->lessonId;
    }

    /** `done_reason` when the scope has nothing left to serve. */
    public function exhaustedReason(): DoneReason
    {
        return $this->isLesson() ? DoneReason::LessonComplete : DoneReason::NothingDue;
    }

    /**
     * Narrows a `word_repetitions` query to the repetitions of active words inside the scope.
     *
     * @param  Builder<WordRepetition>  $query
     * @return Builder<WordRepetition>
     */
    public function constrainRepetitions(Builder $query): Builder
    {
        return $query->whereHas('word', function (Builder $word): void {
            $word->where('is_active', true);
            if ($this->isLesson()) {
                $word->where('lesson_id', $this->lessonId);
            }
        });
    }

    /**
     * Active words of the scope the learner has never been served, in the order they
     * should be introduced. Null when the scope has no source of new words.
     *
     * @return Builder<Word>|null
     */
    public function newWordCandidates(int $learnerId): ?Builder
    {
        if (! $this->includesNew()) {
            return null;
        }

        $unseen = fn (QueryBuilder $q): QueryBuilder => $q
            ->from('word_repetitions')
            ->whereColumn('word_repetitions.word_id', 'words.id')
            ->where('word_repetitions.learner_id', $learnerId);

        if ($this->isLesson()) {
            return Word::query()
                ->where('words.lesson_id', $this->lessonId)
                ->where('words.is_active', true)
                ->whereNotExists($unseen)
                ->orderBy('words.id');
        }

        return Word::query()
            ->select('words.*')
            ->join('lessons', 'lessons.id', '=', 'words.lesson_id')
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->where('stages.course_id', $this->courseId)
            // The snapshot course may have been archived or made private since the session opened.
            ->whereIn('stages.course_id', Course::query()->available()->select('courses.id'))
            ->where('words.is_active', true)
            ->whereNotExists($unseen)
            ->orderBy('stages.number')
            ->orderBy('lessons.number')
            ->orderBy('words.id');
    }
}
