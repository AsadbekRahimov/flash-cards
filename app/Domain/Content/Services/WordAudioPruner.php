<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Content\DTO\AudioPruneReport;
use App\Models\Word;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use League\Flysystem\FilesystemException;
use RuntimeException;

/**
 * Removes the word audio nobody needs any more (docs/26 §5.8) for `audio:prune`: an old `.mp3` of the directory of the word
 * audio that no `words.audio_path` points at (the switched-off words too: a word can be switched on again) and that is not
 * the path the current text of a word hashes to (the job writes the file first and the row after it), and a temporary
 * leftover of a local write (`{dir}/.tmp/`, {@see WordAudioService}) older than a day.
 *
 * Only the files right in the directory (`audio/*.mp3`) and in its `.tmp/` are listed, never another directory, never the
 * disk of the dictations. The age (`lastModified`, a HEAD on R2) is asked only for a file nobody points at. A database
 * without a single `audio_path` next to a non-empty directory is refused unless forced: the wrong database would empty it.
 */
final class WordAudioPruner
{
    private const int CHUNK = 500;

    private const int LISTED = 50;

    private const int TEMPORARY_DAYS = 1;

    /** The dictations of the practice are not word audio: their files are named by their own key (docs/16 §10.5). */
    private const array FOREIGN_DISKS = ['practice_audio'];

    public function __construct(private readonly WordAudioService $audio) {}

    /** @return list<string> the disks never pruned: the default name of the dictations and the disk `practice.audio.disk` names now */
    private function foreignDisks(): array
    {
        $practice = config('practice.audio.disk');

        return is_string($practice) && $practice !== '' ? [...self::FOREIGN_DISKS, $practice] : self::FOREIGN_DISKS;
    }

    /**
     * @param  int  $days  an orphan younger than this stays
     * @param  bool  $delete  false: only count and list (the default of the command)
     * @param  string|null  $diskName  default: the disk of the word audio (`twa.tts.audio_disk`)
     * @param  bool  $force  prune even when no word has `audio_path`
     *
     * @throws InvalidArgumentException an unknown or foreign disk, a limit below one day
     * @throws RuntimeException no word has `audio_path`, but the directory has files (without $force)
     */
    public function prune(int $days = 30, bool $delete = false, ?string $diskName = null, bool $force = false): AudioPruneReport
    {
        $diskName ??= $this->audio->diskName();
        if (! is_array(config("filesystems.disks.{$diskName}"))) {
            throw new InvalidArgumentException("Unknown disk: {$diskName}");
        }
        if (in_array($diskName, $this->foreignDisks(), true)) {
            throw new InvalidArgumentException("The disk {$diskName} has no word audio.");
        }
        if ($days < 1) {
            throw new InvalidArgumentException('--days must be at least 1.');
        }
        $dir = trim((string) config('twa.tts.audio_dir', 'audio'), '/');
        if ($dir === '') {
            throw new InvalidArgumentException('twa.tts.audio_dir is empty: the root of the disk is never pruned.');
        }

        $disk = Storage::disk($diskName);
        $mp3 = array_values(array_filter($disk->files($dir), static fn (string $path): bool => str_ends_with($path, '.mp3')));
        $temporary = $disk->files($dir.'/.tmp');

        [$linked, $stored] = $this->linkedPaths();
        if ($stored === 0 && $mp3 !== [] && ! $force) {
            throw new RuntimeException(sprintf(
                'No word has audio_path, but %s/ on the disk %s has %d files: is this the right database? Run with --force to prune anyway.',
                $dir,
                $diskName,
                count($mp3),
            ));
        }

        $now = CarbonImmutable::now()->getTimestamp();
        $counts = ['linked' => 0, 'fresh' => 0, 'orphans' => 0, 'deleted' => 0, 'failed' => 0];
        $orphanPaths = [];
        $failures = [];

        $candidates = [];
        foreach ($mp3 as $path) {
            if (isset($linked[$path])) {
                $counts['linked']++;
            } else {
                $candidates[$path] = $now - $days * 86400;
            }
        }
        foreach ($temporary as $path) {
            $candidates[$path] = $now - self::TEMPORARY_DAYS * 86400;
        }

        foreach ($candidates as $path => $before) {
            $outcome = $this->prunePath($disk, $path, $before, $delete);
            if (is_string($outcome)) {
                $counts['failed']++;
                if (count($failures) < self::LISTED) {
                    $failures[] = "{$path}: {$outcome}";
                }

                continue;
            }
            if (! $outcome) {
                $counts['fresh']++;

                continue;
            }

            $counts['orphans']++;
            if ($delete) {
                $counts['deleted']++;
            }
            if (count($orphanPaths) < self::LISTED) {
                $orphanPaths[] = $path;
            }
        }

        return new AudioPruneReport(
            dryRun: ! $delete,
            disk: $diskName,
            days: $days,
            files: count($mp3) + count($temporary),
            linked: $counts['linked'],
            fresh: $counts['fresh'],
            orphans: $counts['orphans'],
            deleted: $counts['deleted'],
            failed: $counts['failed'],
            orphanPaths: $orphanPaths,
            failures: $failures,
        );
    }

    /**
     * The paths a word points at or would point at, streamed from the words (the switched-off ones too), and how many words
     * have `audio_path` at all.
     *
     * @return array{0: array<string, true>, 1: int}
     */
    private function linkedPaths(): array
    {
        $linked = [];
        $stored = 0;

        Word::query()
            ->toBase()
            ->select(['id', 'word', 'audio_path'])
            ->lazyById(self::CHUNK)
            ->each(function (object $row) use (&$linked, &$stored): void {
                $path = $row->audio_path ?? null;
                if (is_string($path) && $path !== '') {
                    $linked[$path] = true;
                    $stored++;
                }

                $expected = $this->audio->pathFor((string) $row->word);
                if ($expected !== null) {
                    $linked[$expected] = true;
                }
            });

        return [$linked, $stored];
    }

    /**
     * An orphan: false - younger than $before (kept); true - old enough (and deleted with $delete); a string - what went wrong.
     */
    private function prunePath(Filesystem $disk, string $path, int $before, bool $delete): bool|string
    {
        try {
            if ($disk->lastModified($path) >= $before) {
                return false;
            }
            if ($delete && ! $disk->delete($path)) {
                return 'not deleted';
            }
        } catch (FilesystemException|RuntimeException $e) {
            return $e->getMessage() !== '' ? $e->getMessage() : $e::class;
        }

        return true;
    }
}
