<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Content\Services\WordAudioPruner;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

/**
 * Removes the old word audio no word needs (docs/26 §5.8): `audio:prune` only lists what would go, `audio:prune --delete`
 * deletes it. Run by hand, not scheduled. The work is {@see WordAudioPruner}; the exit code is non-zero when a file could
 * not be looked at or deleted, or when the database has no audio at all (the wrong database; `--force` overrides).
 */
final class PruneAudio extends Command
{
    /** @var string */
    protected $signature = 'audio:prune
        {--days=30 : Keep a file nobody points at while it is younger than this}
        {--delete : Delete the files (without it nothing is deleted)}
        {--disk= : The disk of the word audio (default: twa.tts.audio_disk, TTS_AUDIO_DISK)}
        {--force : Prune even when no word has audio_path}';

    /** @var string */
    protected $description = 'List (or delete with --delete) the old word audio files no word points at.';

    public function handle(WordAudioPruner $pruner): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        if ($days === false) {
            $this->error('--days must be a whole number of days.');

            return self::INVALID;
        }
        $delete = (bool) $this->option('delete');
        $disk = $this->option('disk');

        try {
            $report = $pruner->prune(
                days: $days,
                delete: $delete,
                diskName: is_string($disk) && $disk !== '' ? $disk : null,
                force: (bool) $this->option('force'),
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($report->orphanPaths as $path) {
            $this->line(($delete ? '  deleted: ' : '  would delete: ').$path);
        }
        if ($report->orphans > count($report->orphanPaths)) {
            $this->line(sprintf('  ... and %d more', $report->orphans - count($report->orphanPaths)));
        }

        $this->line(sprintf(
            '%s%s: %d files; in use %d, younger than %d days %d, %s %d, failed %d.',
            $delete ? '' : '[dry run] ',
            $report->disk,
            $report->files,
            $report->linked,
            $report->days,
            $report->fresh,
            $delete ? 'deleted' : 'would delete',
            $delete ? $report->deleted : $report->orphans,
            $report->failed,
        ));

        if (! $delete && $report->orphans > 0) {
            $this->line('Nothing was deleted; run with --delete to delete them.');
        }
        if ($report->failed > 0) {
            foreach ($report->failures as $failure) {
                $this->line('  failed: '.$failure);
            }
            $this->error('Some files were not pruned; run the command again.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
