<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Content\Services\WordAudioScheduler;
use Illuminate\Console\Command;

/**
 * The nightly reconciliation of the word audio (docs/26 §5.6, §9.11): the active words of the courses (not the decks of learners)
 * whose `audio_path` is not the path of their current text get a PregenerateWordAudioJob, then the examples whose
 * `example_audio_path` is not the path of theirs a PregenerateExampleAudioJob - at most `--limit` jobs in all per run, the words
 * first. Nothing
 * while the synthesizer is `null` or unknown, the characters of the month are spent, or every job would fail with `google` (the
 * halt marker, no valid key file, not a Google voice) - with a line that says why. See {@see WordAudioScheduler::reconcile()}.
 */
final class ReconcileAudio extends Command
{
    /** @var string */
    protected $signature = 'audio:reconcile {--limit= : The most jobs (words and examples together) to queue in one run (default '.WordAudioScheduler::RECONCILE_LIMIT.')}';

    /** @var string */
    protected $description = 'Queue the audio of the course words and examples that have no current file (the nightly insurance against lost jobs).';

    public function handle(WordAudioScheduler $scheduler): int
    {
        $limit = $this->option('limit') ?? WordAudioScheduler::RECONCILE_LIMIT;
        if (! is_numeric($limit) || (int) $limit < 1 || (string) (int) $limit !== (string) $limit) {
            $this->error('--limit must be a whole number, 1 or more.');

            return self::INVALID;
        }

        $result = $scheduler->reconcile((int) $limit);

        match ($result['reason']) {
            WordAudioScheduler::SKIP_NOT_CONFIGURED => $this->warn('TTS is not configured (TTS_DRIVER=null): nothing queued.'),
            WordAudioScheduler::SKIP_DRIVER_UNKNOWN => $this->warn('TTS_DRIVER is none of google, http or null: nothing queued. Fix the value in .env.'),
            WordAudioScheduler::SKIP_HALTED => $this->warn('Google refused the sign-in (tts_auth_failed / tts_forbidden) a short while ago: nothing queued. Replace the key or fix the project, then run `php artisan audio:sample --clear-halt`.'),
            WordAudioScheduler::SKIP_CREDENTIALS_MISSING => $this->warn('TTS_DRIVER=google, but GOOGLE_TTS_CREDENTIALS is empty: nothing queued.'),
            WordAudioScheduler::SKIP_CREDENTIALS_INVALID => $this->warn('TTS_DRIVER=google, but the key file of GOOGLE_TTS_CREDENTIALS is missing, unreadable or not a service-account key (tts_credentials_invalid): nothing queued.'),
            WordAudioScheduler::SKIP_VOICE_UNSUPPORTED => $this->warn('TTS_VOICE is not a voice of Google (e.g. en-US-Neural2-F, en-US-Chirp3-HD-Charon): nothing queued.'),
            WordAudioScheduler::SKIP_BUDGET_EXHAUSTED => $this->warn('The monthly TTS character limit is spent: nothing queued until next month.'),
            default => $this->info("Queued: {$result['queued']}"),
        };
        if ($result['reason'] === null) {
            $this->line("Words: {$result['words']}, examples: {$result['examples']}");
        }

        return self::SUCCESS;
    }
}
