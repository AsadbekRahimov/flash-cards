<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use App\Models\WordRepetition;

final readonly class ReviewOutcome
{
    /**
     * @param  SpellingVerdict|null  $verdict  the server's grade of a typed answer; null when the client graded
     * @param  RevealedCard|null  $revealed  what a reverse / audio card was sent without; null for the other exercises
     * @param  WordScoreOutcome|null  $score  the word points of the answer (docs/32 §8.1: `score` = `$score?->toApi()`); null for a
     *                                        review of a lesson (`lesson_review`, docs/06 §15: no points)
     */
    public function __construct(
        public WordRepetition $repetition,
        public TrainingStep $next,
        public ?SpellingVerdict $verdict = null,
        public ?RevealedCard $revealed = null,
        public ?WordScoreOutcome $score = null,
    ) {}
}
