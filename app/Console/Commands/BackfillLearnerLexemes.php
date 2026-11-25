<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Engagement\Services\Scoring\LearnerLexemeBackfill;
use Illuminate\Console\Command;

/**
 * `learner_lexemes:backfill` (docs/32 §12.3, task S7): confirms the lexemes every learner has already studied (`confirmed_source =
 * legacy`), so an old word does not pay "new" once more after the launch of the word points. Batches of learners, one statement a
 * batch, no long transaction; idempotent (a second run confirms nothing). Run once after `lexemes:backfill` and before
 * `LEAGUES_SCORING_MODE=live` (docs/32 §12.4, DEVOPS_GUIDE). Not scheduled.
 */
final class BackfillLearnerLexemes extends Command
{
    /** @var string */
    protected $signature = 'learner_lexemes:backfill
        {--batch=200 : Learners per batch}
        {--learner= : Only this learner id}';

    /** @var string */
    protected $description = 'Confirm the lexemes learners have already studied (word points v2: no "new" for old words).';

    public function handle(LearnerLexemeBackfill $backfill): int
    {
        $batch = (int) $this->option('batch');
        if ($batch < 1) {
            $this->error('--batch must be a positive number.');

            return self::INVALID;
        }

        $learner = $this->option('learner');
        if ($learner !== null && preg_match('/^[1-9]\d{0,18}$/', $learner) !== 1) {
            $this->error('--learner must be a learner id.');

            return self::INVALID;
        }

        $report = $backfill->run(
            batch: $batch,
            learnerId: $learner === null ? null : (int) $learner,
            progress: fn (int $learners, int $confirmed, int $last) => $this->line("  learners: {$learners} (up to id {$last}), confirmed: {$confirmed}"),
        );

        $this->info("Learners: {$report->learners} in {$report->batches} batches; lexemes confirmed: {$report->confirmed}; "
            ."studied cards without a lexeme (skipped): {$report->withoutLexeme}");

        return self::SUCCESS;
    }
}
