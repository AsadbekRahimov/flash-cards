<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Practice\DTO\AudioClip;
use App\Domain\Practice\DTO\AudioStream;
use App\Domain\Practice\DTO\Snapshot;
use App\Models\Learner;
use Illuminate\Support\Facades\Storage;

/**
 * The audio of an element of a snapshot (docs/16 §10): where the file is and how to hand it out. The path of a file never
 * leaves the server - a client gets only the URL of the endpoint and the id of the element.
 *
 * Task 18.1 has the reading side: {@see clipFor()} and {@see open()}. The generation of the audio of a dictation
 * (`schedule`, `generate`, `isReady`: a job after the commit) comes with 18.5.
 *
 * Two disks only: the one of `practice.audio.disk` (the phrases of the authors, not served by nginx) and `public` (the
 * pre-generated audio of the words). A snapshot is written by the server, yet a name of any other disk is not followed.
 */
final class PracticeAudioService
{
    private const MIME = 'audio/mpeg';

    public function __construct(private readonly ProductEventRecorder $events) {}

    /**
     * The file the element of the snapshot names. Null: there is no such element, or it has no audio. It does not look at
     * the disk - whether the file is there is {@see open()}'s answer (the endpoint tells "not found" from "unavailable").
     */
    public function clipFor(Snapshot $snapshot, string $elementId): ?AudioClip
    {
        $element = $snapshot->element($elementId);
        if ($element === null) {
            return null;
        }

        $disk = $element->get('audio_disk');
        $path = $element->get('audio_path');
        if (! is_string($disk) || ! is_string($path) || $path === '' || ! in_array($disk, $this->disks(), true)) {
            return null;
        }

        return new AudioClip($disk, $path, self::MIME);
    }

    /**
     * Opens the file for streaming. Null: it is not there, is empty or cannot be read.
     */
    public function open(AudioClip $clip): ?AudioStream
    {
        $disk = Storage::disk($clip->disk);
        if (! $disk->exists($clip->path)) {
            return null;
        }

        $size = $disk->size($clip->path);
        $stream = $size > 0 ? $disk->readStream($clip->path) : null;

        return is_resource($stream) ? new AudioStream($stream, $size, $clip->mime) : null;
    }

    /**
     * The file an element names is not there (the endpoint answered 503): the event `practice.audio_failed` with
     * `reason = file_missing`, `verified = true` (the server looked).
     */
    public function recordMissing(Learner $learner, int $sessionId, Snapshot $snapshot, string $elementId): void
    {
        $this->events->record(ProductEventName::PracticeAudioFailed, [
            'session_id' => $sessionId,
            'source' => $snapshot->element($elementId)?->get('source'),
            'reason' => 'file_missing',
            'verified' => true,
        ], learner: $learner);
    }

    /** @return list<string> */
    private function disks(): array
    {
        return [(string) config('practice.audio.disk'), 'public'];
    }
}
