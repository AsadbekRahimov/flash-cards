<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

/** An open audio file ready to be streamed: the stream, its size (the `Content-Length`) and the MIME type. */
final readonly class AudioStream
{
    /** @param resource $stream */
    public function __construct(
        public mixed $stream,
        public int $size,
        public string $mime,
    ) {}
}
