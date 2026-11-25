<?php

declare(strict_types=1);

namespace App\Domain\Learning\Support;

use App\Domain\Learning\Enums\DoneReason;
use App\Domain\Learning\Services\NewWordFrontier;
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
 *     available to the learner (published and not private, or the learner's own deck) — otherwise only due cards. Without a course the
 *     daily session serves due cards only.
 *
 *   - hard scope (`hard_review`, docs/06 §14): due repetitions of the FIXED set of words of the round (`training_sessions.meta.hard_words`),
 *     never a new word, whatever the course.
 *
 * Inactive words (`words.is_active = false`, the replacement for deletion) are never
 * served in either scope; their `word_repetitions` rows are left untouched.
 */
final readonly class TrainingScope
{
    private const KIND_LESSON = 'lesson';

    private const KIND_DAILY = 'daily';

    private const KIND_HARD = 'hard';

    /**
     * @param  string  $sessionMode  `TrainingSession::MODE_*` of the session the scope was built for. Does not
     *                               change what is served; CardPicker stamps it on every word it introduces
     *                               (`word_repetitions.introduced_mode`), so the Free quota can leave words of a
     *                               teacher's group lesson out (docs/12 §5.3).
     * @param  list<int>  $wordIds  hard scope only: the words of the round
     */
    private function __construct(
        private string $kind,
        public ?int $lessonId,
        public ?int $courseId,
        public string $sessionMode,
        public array $wordIds = [],
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

    /** @param  list<int>  $wordIds  the set of the round of hard words, fixed when it was opened */
    public static function hard(array $wordIds): self
    {
        return new self(self::KIND_HARD, null, null, TrainingSession::MODE_HARD_REVIEW, $wordIds);
    }

    /**
     * @throws LogicException when a lesson-mode session has no lesson, or a hard-review session no set of words (the `mode_shape`
     *                        invariant guarded by the DB CHECK and TrainingSessionService)
     */
    public static function forSession(TrainingSession $session): self
    {
        if ($session->mode === TrainingSession::MODE_LESSON_REVIEW) {
            // The review of a lesson (docs/06 §15) has no scope of the picker: it serves a fixed set without touching the schedule and is
            // played by LessonReviewService alone. A lesson scope here would introduce new words and grade with SM-2 - never.
            throw new LogicException("Training session {$session->id} (lesson_review) is not served by the card picker.");
        }

        if ($session->mode === TrainingSession::MODE_DAILY_REVIEW) {
            return self::daily($session->course_id === null ? null : (int) $session->course_id);
        }

        if ($session->mode === TrainingSession::MODE_HARD_REVIEW) {
            $raw = ($session->meta ?? [])['hard_words'] ?? null;
            $ids = is_array($raw) ? array_values(array_unique(array_map('intval', $raw))) : [];
            if ($ids === []) {
                throw new LogicException("Training session {$session->id} (hard_review) has no words.");
            }

            return self::hard($ids);
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

    public function isHard(): bool
    {
        return $this->kind === self::KIND_HARD;
    }

    /** Whether this scope can serve words the learner has never seen. Never the round of hard words: only words released before. */
    public function includesNew(): bool
    {
        return ! $this->isHard() && ($this->isLesson() || $this->courseId !== null);
    }

    /** Can a grade for $word be accepted in this scope? (The repetition row must already exist — checked by the caller.) */
    public function contains(Word $word): bool
    {
        if (! $word->is_active) {
            return false;
        }

        if ($this->isHard()) {
            return in_array((int) $word->id, $this->wordIds, true);
        }

        return $this->isDaily() || (int) $word->lesson_id === $this->lessonId;
    }

    /** `done_reason` when the scope has nothing left to serve. */
    public function exhaustedReason(): DoneReason
    {
        return match (true) {
            $this->isLesson() => DoneReason::LessonComplete,
            $this->isHard() => DoneReason::HardRoundComplete,
            default => DoneReason::NothingDue,
        };
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
            if ($this->isHard()) {
                $word->whereIn('id', $this->wordIds);
            }
        });
    }

    /**
     * The course whose new words are walked in study order - the one a frontier hint ({@see NewWordFrontier})
     * is kept for. Null for a lesson scope (one lesson: nothing to narrow) and for a daily scope without a course.
     */
    public function frontierCourseId(): ?int
    {
        return $this->isDaily() ? $this->courseId : null;
    }

    /**
     * The course candidates of {@see newWordCandidates()} restricted to ONE lesson: the same statement (the course, its
     * availability, `is_active`, the anti-join, the order, whatever the caller added - the quota subquery) plus
     * `lessons.id = ?`. A copy; the full builder is left as it is.
     *
     * @param  Builder<Word>  $candidates
     * @return Builder<Word>
     */
    public function narrowCandidatesToLesson(Builder $candidates, int $lessonId): Builder
    {
        if ($this->isLesson()) {
            throw new LogicException('A lesson scope has nothing to narrow.');
        }

        return (clone $candidates)->where('lessons.id', $lessonId);
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
            // The snapshot course may have been archived or made private since the session opened;
            // a private course still counts when it is this learner's own deck.
            ->whereIn('stages.course_id', Course::query()->availableTo($learnerId)->select('courses.id'))
            ->where('words.is_active', true)
            ->whereNotExists($unseen)
            ->orderBy('stages.number')
            ->orderBy('lessons.number')
            ->orderBy('words.id');
    }
}
