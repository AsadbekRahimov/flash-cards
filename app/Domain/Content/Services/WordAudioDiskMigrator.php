<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Content\DTO\AudioDiskMigrationReport;
use App\Models\Word;
use Closure;
use InvalidArgumentException;
use League\Flysystem\FilesystemException;
use RuntimeException;

/**
 * Copies the word audio from one disk to another (docs/26 §6, stage 6: `public` -> `audio` on R2) for `audio:migrate-disk`.
 *
 * The files are the unique `words.audio_path` values, the switched-off words included (a word can be switched on again);
 * a file nobody points at stays behind (`audio:prune` is for those). Each file is written the way the jobs write it
 * ({@see WordAudioService::write()}: on R2 one PUT with `Cache-Control` and `Content-Type`). Idempotent: a file that is
 * already on the target and not empty is not copied again, so the command can be repeated after an error. Nothing in the
 * database changes: the path is the same on every disk, switching TTS_AUDIO_DISK is what makes the words use the copies.
 */
final class WordAudioDiskMigrator
{
    private const int CHUNK = 500;

    private const int FAILURES_LISTED = 20;

    public function __construct(private readonly WordAudioService $audio) {}

    /**
     * @param  bool  $dryRun  only count: nothing is downloaded or written (the target and the source are looked at)
     * @param  (Closure(string $path, string $outcome): void)|null  $progress  called per file: copied | present | missing | failed
     *
     * @throws InvalidArgumentException an unknown disk, or the same disk twice
     */
    public function migrate(string $from, string $to, bool $dryRun = false, ?Closure $progress = null): AudioDiskMigrationReport
    {
        foreach ([$from, $to] as $disk) {
            if (! is_array(config("filesystems.disks.{$disk}"))) {
                throw new InvalidArgumentException("Unknown disk: {$disk}");
            }
        }
        if ($from === $to) {
            throw new InvalidArgumentException('The source and the target are the same disk.');
        }

        $counts = ['copied' => 0, 'present' => 0, 'missing' => 0, 'failed' => 0];
        $failures = [];
        $files = 0;

        Word::query()
            ->toBase()
            ->select('audio_path')
            ->whereNotNull('audio_path')
            ->where('audio_path', '<>', '')
            ->distinct()
            ->orderBy('audio_path')
            ->lazy(self::CHUNK)
            ->each(function (object $row) use ($from, $to, $dryRun, $progress, &$counts, &$failures, &$files): void {
                $path = (string) $row->audio_path;
                $files++;

                $outcome = $this->copy($from, $to, $path, $dryRun);
                $counts[$outcome]++;
                if ($outcome === 'failed' && count($failures) < self::FAILURES_LISTED) {
                    $failures[] = $path;
                }
                if ($progress !== null) {
                    $progress($path, $outcome);
                }
            });

        return new AudioDiskMigrationReport(
            dryRun: $dryRun,
            files: $files,
            copied: $counts['copied'],
            present: $counts['present'],
            missing: $counts['missing'],
            failed: $counts['failed'],
            failures: $failures,
        );
    }

    /** @return 'copied'|'present'|'missing'|'failed' */
    private function copy(string $from, string $to, string $path, bool $dryRun): string
    {
        try {
            if ($this->audio->fileState($path, $to) === 'ready') {
                return 'present';
            }

            if ($dryRun) {
                return $this->audio->fileState($path, $from) === 'ready' ? 'copied' : 'missing';
            }

            $body = $this->audio->read($path, $from);
            if ($body === null || $body === '') {
                // Not there, or an empty leftover: nothing worth copying (a read error of a remote source is reported by its disk).
                return 'missing';
            }

            $this->audio->write($to, $path, $body);
        } catch (FilesystemException|RuntimeException) {
            return 'failed';
        }

        return 'copied';
    }
}
