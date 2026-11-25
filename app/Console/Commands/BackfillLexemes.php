<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Content\Services\LexemeCatalog;
use Illuminate\Console\Command;

/**
 * `lexemes:backfill` (docs/32 §2.1, task S1): links every word without a lexeme - the words of platform and organisation
 * courses create theirs, the words of learners' decks only match the catalog. Batches by `words.id` (keyset), a few indexed
 * statements a batch and no long transaction, so it is safe beside the live writers and on a large `words`; a link is written
 * only where `lexeme_id` is still NULL. Idempotent: run it after the deploy that adds the catalog, and again with `--decks`
 * whenever the catalog grew (a deck word that matched nothing may match now). Not scheduled.
 */
final class BackfillLexemes extends Command
{
    /** @var string */
    protected $signature = 'lexemes:backfill
        {--decks : Only link the words of learners\' decks to the existing catalog}
        {--batch=1000 : Words per batch}';

    /** @var string */
    protected $description = 'Link words to the catalog of lexemes (courses create lexemes, learner decks only match them).';

    public function handle(LexemeCatalog $catalog): int
    {
        $batch = (int) $this->option('batch');
        if ($batch < 1) {
            $this->error('--batch must be a positive number.');

            return self::INVALID;
        }

        $report = $catalog->backfill(
            batch: $batch,
            decksOnly: (bool) $this->option('decks'),
            progress: fn (string $phase, int $scanned) => $this->output->isVerbose() ? $this->line("  {$phase}: {$scanned} words scanned") : null,
        );

        $this->info("Course words linked: {$report->catalogWordsLinked}, lexemes created: {$report->lexemesCreated}; "
            ."deck words linked: {$report->deckWordsLinked}, unmatched: {$report->unmatched}; blank: {$report->blank}");

        return self::SUCCESS;
    }
}
