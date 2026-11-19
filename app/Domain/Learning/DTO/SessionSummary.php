<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use App\Models\TrainingSession;

/**
 * What a learner did inside one training session (TrainingSessionService::finish).
 * Daily goal and streak are added by TodayService (task 1.3b) on top of this.
 */
final readonly class SessionSummary
{
    /**
     * @param  int  $reviewed  accepted grades (training_reviews rows)
     * @param  int  $correct  grades with quality >= 3
     * @param  int  $newWords  distinct words first served during this session
     * @param  bool  $wasOpen  false when the session had already been closed before this call (idempotent finish)
     * @param  int  $graduated  of the new words, those that left the minute steps in this session (stage `learning` reached)
     * @param  int  $promoted  words met before this session whose stage rose in it (docs/06 §13.3)
     * @param  int  $mastered  words that reached the `mastered` stage in this session
     * @param  int|null  $hardWon  round of hard words only: its words whose last grade in the session is a pass; null in other modes
     * @param  int|null  $hardTotal  round of hard words only: the size of its set; null in other modes
     * @param  CorrectedMistake|null  $corrected  round of hard words only, on `/finish`: one mistake of an earlier session the learner
     *                                            corrected in this round ({@see \App\Domain\Learning\Services\CorrectedMistakeFinder}); null otherwise
     */
    public function __construct(
        public TrainingSession $session,
        public int $reviewed,
        public int $correct,
        public int $newWords,
        public bool $wasOpen,
        public int $graduated = 0,
        public int $promoted = 0,
        public int $mastered = 0,
        public ?int $hardWon = null,
        public ?int $hardTotal = null,
        public ?CorrectedMistake $corrected = null,
    ) {}

    public function withCorrected(?CorrectedMistake $corrected): self
    {
        return new self(
            $this->session,
            $this->reviewed,
            $this->correct,
            $this->newWords,
            $this->wasOpen,
            graduated: $this->graduated,
            promoted: $this->promoted,
            mastered: $this->mastered,
            hardWon: $this->hardWon,
            hardTotal: $this->hardTotal,
            corrected: $corrected,
        );
    }
}
