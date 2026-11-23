<?php

declare(strict_types=1);

namespace App\Domain\Content\Exceptions;

use App\Domain\Content\Services\WordAudioService;
use App\Jobs\PregenerateWordAudioJob;
use RuntimeException;
use Throwable;

/**
 * The disk of the word audio did not take a file ({@see WordAudioService::write()}): the message is the stable code
 * `tts_audio_not_stored`. After a synthesis it means the audio was paid for and lost, so {@see PregenerateWordAudioJob} gives
 * the word one more attempt at most instead of the five of a failed synthesis.
 */
final class WordAudioNotStoredException extends RuntimeException
{
    public const CODE = 'tts_audio_not_stored';

    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(self::CODE, previous: $previous);
    }
}
