<?php

declare(strict_types=1);

namespace App\Domain\Learners\DTO;

use App\Domain\Learners\Services\DayPlanService;

/**
 * The plan of the learner's local day (docs/04 A.2a `plan`, docs/31 §5б): lesson -> review of the lesson -> game on its words.
 * Built by {@see DayPlanService}; the bot asks {@see offersGame()} for the reminder after a lesson.
 *
 * "The lesson of today" is the lesson of the latest personal `lesson` session played to its end today (`completed_at` in the local
 * day) in which the learner met new words today; the review and the game are about that lesson.
 */
final readonly class DayPlan
{
    public const GAME_MODE = 'pairs_timed';

    /**
     * @param  int|null  $todayLessonId  the lesson of today; null - no lesson with new words was played to its end today
     * @param  string|null  $todayLessonTitle  its title in the learner's language, when it is a lesson of the active course; else null
     * @param  int  $todayWords  the words the review asks (met today, capped at `learning.lesson_review.max_words`); 0 without a lesson
     * @param  int|null  $nextLessonId  `active_course.next_lesson.id`: where new words come from next; null - none
     * @param  int|null  $nextLessonWords  its never-served words; null without a next lesson
     * @param  bool  $reviewEnabled  `learning.lesson_review.enabled`
     * @param  bool  $reviewDone  a review of the lesson of today was played to its end today
     * @param  int|null  $reviewOpenSessionId  a live review of that lesson to continue
     * @param  bool  $gameAvailable  the learner may open `pairs_timed` (the practice is open to them and the mode is on)
     * @param  bool  $gamePlayed  a practice session on the lesson of today was begun today and answered at least once
     * @param  string|null  $todayCourseSlug  the slug of the course of the lesson of today - not necessarily the active course (an
     *                                        assignment of a center, a course switched since): the review and the game open there
     */
    public function __construct(
        public ?int $todayLessonId,
        public ?string $todayLessonTitle,
        public int $todayWords,
        public ?int $nextLessonId,
        public ?int $nextLessonWords,
        public bool $reviewEnabled,
        public bool $reviewDone,
        public ?int $reviewOpenSessionId,
        public bool $gameAvailable,
        public bool $gamePlayed,
        public ?string $todayCourseSlug = null,
    ) {}

    public function lessonDone(): bool
    {
        return $this->todayLessonId !== null;
    }

    /**
     * The step to do now: 1 - a lesson (there is a next one), 2 - the review of today's lesson, 3 - the game on its words; null -
     * nothing to suggest: the chain of today is done, or there is no lesson to start (the course is over / no course).
     */
    public function step(): ?int
    {
        if (! $this->lessonDone()) {
            return $this->nextLessonId === null ? null : 1;
        }
        if ($this->reviewEnabled && ! $this->reviewDone) {
            return 2;
        }
        if ($this->gameAvailable && ! $this->gamePlayed) {
            return 3;
        }

        return null;
    }

    /** The chain of today is complete: a lesson, its review (when on) and the game (when open). */
    public function done(): bool
    {
        return $this->lessonDone() && $this->step() === null;
    }

    /**
     * The condition of the bot's reminder after a lesson (docs/31 §5б): a lesson with new words was played today and no game on its words
     * was played since - whether the review was done or not. The other rules of the reminder (once a day, not at night, opt-out,
     * the common daily limit) are the sender's.
     */
    public function offersGame(): bool
    {
        return $this->lessonDone() && $this->gameAvailable && ! $this->gamePlayed;
    }

    /**
     * @return array{
     *   step: int|null, done: bool,
     *   lesson: array{done: bool, lesson_id: int|null, next_lesson_id: int|null, words_left: int|null},
     *   training: array{lesson_id: int, course: string|null, title: string|null, words: int, done: bool, open_session_id: int|null}|null,
     *   game: array{lesson_id: int, course: string|null, mode: string, played: bool}|null
     * }
     */
    public function toArray(): array
    {
        $lessonId = $this->todayLessonId;

        return [
            'step' => $this->step(),
            'done' => $this->done(),
            'lesson' => [
                'done' => $this->lessonDone(),
                'lesson_id' => $lessonId,
                'next_lesson_id' => $this->nextLessonId,
                'words_left' => $this->nextLessonWords,
            ],
            'training' => $lessonId === null || ! $this->reviewEnabled ? null : [
                'lesson_id' => $lessonId,
                'course' => $this->todayCourseSlug,
                'title' => $this->todayLessonTitle,
                'words' => $this->todayWords,
                'done' => $this->reviewDone,
                'open_session_id' => $this->reviewOpenSessionId,
            ],
            'game' => $lessonId === null || ! $this->gameAvailable ? null : [
                'lesson_id' => $lessonId,
                'course' => $this->todayCourseSlug,
                'mode' => self::GAME_MODE,
                'played' => $this->gamePlayed,
            ],
        ];
    }
}
