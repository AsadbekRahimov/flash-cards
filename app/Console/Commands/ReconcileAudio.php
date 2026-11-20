<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Content\Services\WordAudioScheduler;
use Illuminate\Console\Command;

/**
 * The nightly reconciliation of the word audio (docs/26 §5.6): the active words of the courses (not the decks of learners)
 * whose `audio_path` is not the path of their current text get a PregenerateWordAudioJob, at most `--limit` per run. Nothing
 * while the synthesizer is `null` or the characters of the month are spent. See {@see WordAudioScheduler::reconcile()}.
 */
final class ReconcileAudio extends Command
{
    /** @var string */
    protected $signature = 'audio:reconcile {--limit= : The most jobs to queue in one run (default '.WordAudioScheduler::RECONCILE_LIMIT.')}';

    /** @var string */
    protected $description = 'Queue the audio of the course words that have no current file (the nightly insurance against lost jobs).';

    public function handle(WordAudioScheduler $scheduler): int
    {
        $limit = $this->option('limit') ?? WordAudioScheduler::RECONCILE_LIMIT;
        if (! is_numeric($limit) || (int) $limit < 1 || (string) (int) $limit !== (string) $limit) {
            $this->error('--limit must be a whole number, 1 or more.');

            return self::INVALID;
        }

        $result = $scheduler->reconcile((int) $limit);

        match ($result['reason']) {
            'not_configured' => $this->warn('TTS is not configured (TTS_DRIVER=null): nothing queued.'),
            'budget_exhausted' => $this->warn('The monthly TTS character limit is spent: nothing queued until next month.'),
            default => $this->info("Queued: {$result['queued']}"),
        };

        return self::SUCCESS;
    }
}
