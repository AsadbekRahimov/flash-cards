<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

/**
 * Outcome of `learner_lexemes:backfill` (App\Domain\Engagement\Services\Scoring\LearnerLexemeBackfill): the batches and learners
 * walked, the `learner_lexemes` rows the run confirmed (new rows and rows of the core that were not confirmed yet - a row that was
 * already confirmed is not touched and not counted, so a second run reports 0), and the studied cards whose word has no lexeme
 * (skipped: no public points for them, docs/28; counted again by every run).
 */
final class LearnerLexemeBackfillReport
{
    public int $batches = 0;

    public int $learners = 0;

    public int $confirmed = 0;

    public int $withoutLexeme = 0;
}
