<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

/**
 * Where the audio of an element lives (docs/16 §10.2): the disk and the path as the element of the snapshot names them
 * (`practice_audio` for a phrase, `public` for a word). docs/16 names `{path, mime}`; the disk is added because the
 * two sources live on different disks. The path never goes outside the server.
 */
final readonly class AudioClip
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $mime = 'audio/mpeg',
    ) {}
}
