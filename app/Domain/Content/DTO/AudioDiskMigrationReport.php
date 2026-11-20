<?php

declare(strict_types=1);

namespace App\Domain\Content\DTO;

/**
 * Outcome of `audio:migrate-disk` (WordAudioDiskMigrator::migrate): the unique files the words point at (`words.audio_path`,
 * the switched-off words too), and what became of them. `copied` - written to the target (in a dry run: would be);
 * `present` - already on the target, not empty (a repeat of the command copies nothing); `missing` - not on the source
 * either (`audio:pregenerate` voices those words again); `failed` - read or written with an error, the first ones in `failures`.
 */
final readonly class AudioDiskMigrationReport
{
    /** @param  list<string>  $failures  the paths of the first failed files */
    public function __construct(
        public bool $dryRun,
        public int $files,
        public int $copied,
        public int $present,
        public int $missing,
        public int $failed,
        public array $failures,
    ) {}
}
