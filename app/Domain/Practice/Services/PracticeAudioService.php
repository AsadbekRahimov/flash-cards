<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Content\Services\WordAudioService;
use App\Domain\Practice\DTO\AudioClip;
use App\Domain\Practice\DTO\AudioStream;
use App\Domain\Practice\DTO\Snapshot;
use App\Domain\Practice\Enums\AudioStatus;
use App\Domain\Practice\Support\PracticeTextNormalizer;
use App\Domain\Twa\Services\TtsFallbackService;
use App\Jobs\GeneratePracticeAudioJob;
use App\Models\Learner;
use App\Models\PracticeItemVersion;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The audio of an element of a snapshot (docs/16 §10): where the file is and how to hand it out. The path of a file never
 * leaves the server - a client gets only the URL of the endpoint and the id of the element.
 *
 * The reading side (18.1): {@see clipFor()} and {@see open()}. The writing side is split so that the authoring (18.7a) and
 * the dictation (18.5) do not edit the same code: {@see schedule()} is what the authoring calls for every new dictation
 * version, {@see generate()} is the body of the job that voices it (18.5, docs/16 §10.1).
 *
 * Two disks only: the one of `practice.audio.disk` (the phrases of the authors: `storage/app/practice-audio`, no URL, no web
 * server maps it) and the disk of the pre-generated audio of the words (`twa.tts.audio_disk`, `public`). A snapshot is written
 * by the server, yet a name of any other disk is not followed.
 */
final class PracticeAudioService
{
    private const MIME = 'audio/mpeg';

    public function __construct(
        private readonly ProductEventRecorder $events,
        private readonly TtsFallbackService $tts,
    ) {}

    /** The disk of the phrases of the authors (`practice.audio.disk`): streamed by the audio endpoint only. */
    public function diskName(): string
    {
        return (string) config('practice.audio.disk');
    }

    /** The disk of the pre-generated audio of the words (`twa.tts.audio_disk`): the practice only reads from it, {@see WordAudioService} writes. */
    public function wordDiskName(): string
    {
        return (string) config('twa.tts.audio_disk', 'public');
    }

    /** `{audio_key}.mp3` of the version on the audio disk (under `practice.audio.dir`); null while it has no key. */
    public function pathFor(PracticeItemVersion $version): ?string
    {
        $key = $version->audio_key;

        return is_string($key) && $key !== '' ? $this->pathOfKey($key) : null;
    }

    /** The file is there and not empty (an empty or truncated leftover counts as "not generated"). */
    public function hasFile(string $disk, string $path): bool
    {
        $filesystem = Storage::disk($disk);

        return $filesystem->exists($path) && $filesystem->size($path) > 0;
    }

    /** The audio of a version can be played: `audio_status = ready` AND the file is on the disk (docs/16 §1.3). */
    public function isReady(PracticeItemVersion $version): bool
    {
        $path = $this->pathFor($version);

        return $version->audio_status === AudioStatus::Ready && $path !== null && $this->hasFile($this->diskName(), $path);
    }

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

    /**
     * Queues the voicing of a dictation version (docs/16 §10.1): `audio_status` becomes `pending` and
     * {@see GeneratePracticeAudioJob} runs after the surrounding transaction commits. A version that is already `ready`
     * is left alone unless `$force` (the admin action "generate again"). Not a dictation (`none`): nothing to do.
     */
    public function schedule(PracticeItemVersion $version, bool $force = false): void
    {
        if ($version->audio_status === AudioStatus::None || ($version->audio_status === AudioStatus::Ready && ! $force)) {
            return;
        }

        if ($version->audio_status !== AudioStatus::Pending) {
            PracticeItemVersion::query()->whereKey($version->id)->update(['audio_status' => AudioStatus::Pending->value, 'audio_error' => null]);
            $version->audio_status = AudioStatus::Pending;
            $version->audio_error = null;
            $version->syncOriginalAttributes(['audio_status', 'audio_error']);
        }

        GeneratePracticeAudioJob::dispatch((int) $version->id, $force)->afterCommit();
    }

    /**
     * The body of {@see GeneratePracticeAudioJob} (docs/16 §10.1): voices the text of the version through the TTS service, writes
     * `{audio_key}.mp3` on the audio disk atomically (a `.tmp` file, then a move: a reader never sees half of an mp3) and sets
     * `audio_status` to `ready`. Idempotent: when the file of the key is there already (a repeat of the job, a crash between the
     * file and the row) there is no TTS call and the row only catches up; `$force` voices the text again ("generate again").
     *
     * `audio_key = sha1(version_id | normalized text | voice | twa.tts.audio_version)`: the name carries no text, and another voice
     * or `TTS_AUDIO_VERSION` is another file. A failed TTS call throws (the queue retries with the backoff of the job); it is the
     * `failed()` of the job that turns the version to `failed` after the last attempt ({@see markFailed()}). A version with no text
     * to voice is `failed` at once: no retry would help.
     *
     * @throws RuntimeException tts_service_not_configured | tts_service_failed | tts_service_bad_audio | tts_audio_not_stored
     */
    public function generate(PracticeItemVersion $version, bool $force = false): void
    {
        // Not a dictation: nothing to voice.
        if ($version->audio_status === AudioStatus::None) {
            return;
        }

        $text = $this->spokenText($version);
        if ($text === null) {
            $version->forceFill(['audio_status' => AudioStatus::Failed, 'audio_error' => 'no_text'])->save();

            return;
        }

        $key = sha1($version->id.'|'.PracticeTextNormalizer::normalize($text).'|'.$this->voice().'|'.(int) config('twa.tts.audio_version', 1));
        $path = $this->pathOfKey($key);
        $disk = $this->diskName();

        if ($force || ! $this->hasFile($disk, $path)) {
            $audio = $this->tts->synthesize($text, $this->voice());
            if ($audio['body'] === '' || ! str_starts_with(strtolower($audio['content_type']), 'audio/')) {
                throw new RuntimeException('tts_service_bad_audio');
            }
            $this->store($disk, $path, $audio['body']);
        }

        $version->forceFill([
            'audio_key' => $key,
            'audio_status' => AudioStatus::Ready,
            'audio_error' => null,
            'audio_generated_at' => now(),
        ])->save();
    }

    /**
     * The last attempt of the job has failed (its `failed()`): a version that still waits for its voice becomes `failed` with a
     * short code of the reason, so that the admin sees it and can generate it again. Only `pending` changes: a version another run
     * has voiced in the meantime stays `ready`.
     */
    public function markFailed(int $versionId, Throwable $reason): void
    {
        PracticeItemVersion::query()
            ->whereKey($versionId)
            ->where('audio_status', AudioStatus::Pending->value)
            ->update(['audio_status' => AudioStatus::Failed->value, 'audio_error' => $this->reasonCode($reason)]);
    }

    /** @return list<string> */
    private function disks(): array
    {
        return [$this->diskName(), $this->wordDiskName()];
    }

    private function pathOfKey(string $key): string
    {
        $dir = trim((string) config('practice.audio.dir', ''), '/');

        return ($dir === '' ? '' : $dir.'/').$key.'.mp3';
    }

    private function voice(): string
    {
        return (string) config('twa.tts.voice');
    }

    /** What the TTS reads: the text of the phrase, trimmed, the white space collapsed; null when the content has none. */
    private function spokenText(PracticeItemVersion $version): ?string
    {
        $text = $version->content['text'] ?? null;
        $spoken = is_string($text) ? trim((string) preg_replace('/\s+/u', ' ', $text)) : '';

        return $spoken === '' ? null : $spoken;
    }

    /**
     * Atomic write, the way {@see WordAudioService} does it: the body goes to `{dir}/.tmp/{name}.{random}` and is moved onto the
     * final name.
     */
    private function store(string $disk, string $path, string $body): void
    {
        $filesystem = Storage::disk($disk);
        $dir = trim(dirname($path), './');
        $tmp = ($dir === '' ? '' : $dir.'/').'.tmp/'.basename($path).'.'.Str::random(12);

        if (! $filesystem->put($tmp, $body)) {
            throw new RuntimeException('tts_audio_not_stored');
        }

        try {
            $moved = $filesystem->move($tmp, $path);
        } catch (Throwable $e) {
            $filesystem->delete($tmp);

            throw new RuntimeException('tts_audio_not_stored', previous: $e);
        }

        if (! $moved) {
            $filesystem->delete($tmp);

            throw new RuntimeException('tts_audio_not_stored');
        }
    }

    /** `audio_error` (100 characters): the code of the service ("tts_service_failed"), else the class of the exception - never a message with a URL in it. */
    private function reasonCode(Throwable $e): string
    {
        $code = preg_match('/\A[a-z][a-z0-9_]*\z/', $e->getMessage()) === 1 ? $e->getMessage() : class_basename($e);

        return mb_substr($code, 0, 100);
    }
}
