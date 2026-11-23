<?php

declare(strict_types=1);

namespace App\Domain\Content\DTO;

/**
 * Outcome of `audio:prune` (WordAudioPruner::prune): the files of the directory of the word audio and what became of them.
 * `files` - the `.mp3` files and the temporary leftovers looked at; `linked` - a word points at them (`audio_path`) or would
 * (`pathFor` of its current text); `fresh` - nobody points at them, but they are younger than the limit; `orphans` - nobody
 * points at them and they are old enough (in a dry run: would be deleted); `deleted` / `failed` - with `--delete`. The first
 * orphans are in `orphanPaths`, the first failed files (with the reason) in `failures`.
 */
final readonly class AudioPruneReport
{
    /**
     * @param  list<string>  $orphanPaths  the paths of the first orphans
     * @param  list<string>  $failures  "path: reason" of the first failed files
     */
    public function __construct(
        public bool $dryRun,
        public string $disk,
        public int $days,
        public int $files,
        public int $linked,
        public int $fresh,
        public int $orphans,
        public int $deleted,
        public int $failed,
        public array $orphanPaths,
        public array $failures,
    ) {}
}
