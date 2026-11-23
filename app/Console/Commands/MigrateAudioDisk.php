<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Content\Services\WordAudioDiskMigrator;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Copies the word audio to another disk (docs/26 §6, stage 6): `audio:migrate-disk --to=audio` before TTS_AUDIO_DISK=audio is
 * switched on. Idempotent (a file already on the target is skipped), `--dry-run` only counts. The work is
 * {@see WordAudioDiskMigrator}; the exit code is non-zero when a file could not be copied.
 */
final class MigrateAudioDisk extends Command
{
    /** @var string */
    protected $signature = 'audio:migrate-disk
        {--from=public : The disk the files are on now}
        {--to= : The disk to copy them to (default: twa.tts.audio_disk, TTS_AUDIO_DISK)}
        {--dry-run : Only count what would be copied}';

    /** @var string */
    protected $description = 'Copy the pre-generated word audio from one disk to another (e.g. public -> audio on R2).';

    public function handle(WordAudioDiskMigrator $migrator): int
    {
        $from = (string) $this->option('from');
        $to = (string) ($this->option('to') ?: config('twa.tts.audio_disk', 'public'));
        $dryRun = (bool) $this->option('dry-run');

        try {
            $report = $migrator->migrate($from, $to, $dryRun);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $this->line(sprintf(
            '%s%s -> %s: %d files; %s %d, already there %d, missing on %s %d, failed %d.',
            $dryRun ? '[dry run] ' : '',
            $from,
            $to,
            $report->files,
            $dryRun ? 'would copy' : 'copied',
            $report->copied,
            $report->present,
            $from,
            $report->missing,
            $report->failed,
        ));

        if ($report->missing > 0) {
            $this->warn('Missing files are voiced again by `php artisan audio:pregenerate <course>` once TTS_AUDIO_DISK points at the new disk.');
        }
        if ($report->failed > 0) {
            foreach ($report->failures as $path) {
                $this->line('  failed: '.$path);
            }
            $this->error('Some files were not copied; run the command again (copied files are skipped).');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
