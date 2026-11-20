<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Content\Contracts\SpeechSynthesizer;
use App\Domain\Content\Exceptions\SpeechSynthesisException;
use App\Domain\Content\Exceptions\WordAudioNotStoredException;
use App\Jobs\PregenerateWordAudioJob;
use App\Models\Word;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\UnableToRetrieveMetadata;
use RuntimeException;
use Throwable;

/**
 * Pre-generated word audio (docs/12 task 1.2c).
 *
 * The file name is content-addressed: `audio/{sha1(text|voice|version)}.mp3` on the
 * disk of the word audio, so the same text in two words (or two courses) is one file, and the
 * TTS endpoint can find it from the text alone. `words.audio_path` is the relative path.
 * The synthesis itself is the configured {@see SpeechSynthesizer}, called only from the queued
 * {@see PregenerateWordAudioJob} (no request of a learner synthesizes, docs/26 §9.4).
 *
 * The disk is `twa.tts.audio_disk`: `public` (this server) or `audio` (Cloudflare R2, docs/26 §5.4). A screen of a learner
 * never asks the disk: a word is voiced when `audio_path` is the path its current text hashes to ({@see urlFor()}).
 */
final class WordAudioService
{
    /** The headers of a word file on an S3 disk (docs/26 §9.5): the name follows the content, nothing can change it. */
    public const array PUT_OPTIONS = [
        'CacheControl' => 'public, max-age=31536000, immutable',
        'ContentType' => 'audio/mpeg',
    ];

    public function __construct(private readonly SpeechSynthesizer $tts) {}

    /** Lower-cased, whitespace-collapsed text: "Ice  Cream" and "ice cream" sound the same. */
    public function normalize(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    public function defaultVoice(): string
    {
        return (string) config('twa.tts.voice');
    }

    /** Relative path on the disk for this text; null when there is nothing to pronounce. */
    public function pathFor(string $text, ?string $voice = null): ?string
    {
        $text = $this->normalize($text);
        if ($text === '') {
            return null;
        }

        $hash = sha1($text.'|'.($voice ?? $this->defaultVoice()).'|'.(int) config('twa.tts.audio_version', 1));

        return trim((string) config('twa.tts.audio_dir', 'audio'), '/').'/'.$hash.'.mp3';
    }

    /** Stored file for this text/voice, or null when it was never generated. */
    public function storedPath(string $text, ?string $voice = null): ?string
    {
        $path = $this->pathFor($text, $voice);

        return $path !== null && $this->isReady($path) ? $path : null;
    }

    /** @param  string|null  $diskName  another disk than `twa.tts.audio_disk` (the source of `audio:migrate-disk`) */
    public function read(string $path, ?string $diskName = null): ?string
    {
        return $this->disk($diskName)->get($path);
    }

    /**
     * Public URL of the word's pre-generated audio; null when there is none. Only a path that is
     * the one the current text/voice/version hash to counts (no disk access): after the text was
     * edited, a stale `audio_path` must not voice the old word.
     */
    public function urlFor(Word $word): ?string
    {
        $path = $word->audio_path;

        return $path !== null && $path !== '' && $path === $this->pathFor($word->word)
            ? $this->disk()->url($path)
            : null;
    }

    /** True when the word already points at the file its current text/voice/version hash to, and the file is there. */
    public function isCurrent(Word $word): bool
    {
        $expected = $this->pathFor($word->word);

        return $expected !== null
            && $word->audio_path === $expected
            && $this->isReady($expected);
    }

    /**
     * The edited word text no longer matches its audio: drops `audio_path` and queues the job
     * that voices the new text (after the surrounding transaction commits).
     */
    public function textChanged(Word $word): void
    {
        if ($word->audio_path !== null) {
            Word::query()->whereKey($word->id)->update(['audio_path' => null]);
            $word->audio_path = null;
            $word->syncOriginalAttribute('audio_path');
        }

        PregenerateWordAudioJob::dispatch((int) $word->id)->afterCommit();
    }

    /**
     * Makes sure the word has audio and `audio_path` points at it. Idempotent: a no-op when
     * {@see isCurrent()} (unless $force); an already existing file of the same text is reused
     * without a TTS call.
     *
     * @return bool true when the TTS service was called
     *
     * @throws SpeechSynthesisException when the TTS service fails (the caller retries a `retryable` one)
     * @throws WordAudioNotStoredException the audio was synthesized, but the disk did not take it
     */
    public function generate(Word $word, bool $force = false): bool
    {
        $path = $this->pathFor($word->word);
        if ($path === null) {
            return false;
        }

        // One look at the disk (on R2 a HEAD and a size), not one for isCurrent() and another for the synthesis.
        $ready = ! $force && $this->isReady($path);
        if ($ready && $word->audio_path === $path) {
            return false;
        }

        $called = false;
        if (! $ready) {
            $audio = $this->tts->synthesize($this->normalize($word->word), $this->defaultVoice());
            if ($audio['body'] === '' || ! str_starts_with(strtolower($audio['content_type']), 'audio/')) {
                throw new RuntimeException('tts_service_bad_audio');
            }
            $this->store($path, $audio['body']);
            $called = true;
        }

        // Only while the text is still the one voiced (docs/26 §9.6): an edit during the synthesis queued its own job, which
        // may have finished first - its path must not be overwritten with the path of the old text.
        if ($word->audio_path !== $path
            && Word::query()->whereKey($word->id)->where('word', $word->word)->update(['audio_path' => $path]) > 0) {
            $word->audio_path = $path;
            $word->syncOriginalAttribute('audio_path');
        }

        return $called;
    }

    /** The file is there and not empty (an empty or truncated leftover counts as "not generated"). */
    private function isReady(string $path): bool
    {
        return $this->fileState($path) === 'ready';
    }

    /**
     * What is on the disk at this path: `ready` (a non-empty file), `empty` (a zero-byte leftover) or `missing`.
     * On R2 these are HEAD requests: only the jobs, `audio:coverage --verify`, `audio:migrate-disk` and `GET /api/twa/tts`
     * call it, never the screens of a learner (docs/26 §9.5). An error of the bucket other than "not found" is thrown.
     *
     * @param  string|null  $diskName  another disk than `twa.tts.audio_disk` (the source of `audio:migrate-disk`)
     * @return 'ready'|'empty'|'missing'
     */
    public function fileState(string $path, ?string $diskName = null): string
    {
        $disk = $this->disk($diskName);
        if (! $disk->fileExists($path)) {
            return 'missing';
        }

        try {
            return $disk->size($path) > 0 ? 'ready' : 'empty';
        } catch (UnableToRetrieveMetadata) {
            return 'missing'; // gone between the two calls
        }
    }

    /**
     * Writes a word file on a disk the way {@see store()} does (the copy of `audio:migrate-disk` keeps the same headers).
     *
     * @throws WordAudioNotStoredException tts_audio_not_stored (a RuntimeException)
     */
    public function write(string $diskName, string $path, string $body): void
    {
        $disk = $this->disk($diskName);

        if (config("filesystems.disks.{$diskName}.driver") === 's3') {
            // One PUT is atomic in S3 / R2: no temporary name. The headers go with the object (not the `options` of the disk:
            // the adapter would add those to HEAD / GET as well); the name follows the content, so it is cached for a year.
            if (! $disk->put($path, $body, self::PUT_OPTIONS)) {
                throw new WordAudioNotStoredException;
            }

            return;
        }

        $this->writeThroughTemporary($disk, $path, $body);
    }

    /** Stores the body of a word on the disk of the word audio ({@see write()}); any error of the disk is "not stored". */
    private function store(string $path, string $body): void
    {
        try {
            $this->write($this->diskName(), $path, $body);
        } catch (WordAudioNotStoredException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new WordAudioNotStoredException($e);
        }
    }

    /**
     * Atomic write on a local disk: the body goes to `{dir}/.tmp/{name}.{random}` and is moved onto the final name,
     * so a reader (nginx, {@see read()}) never sees a half-written mp3.
     */
    private function writeThroughTemporary(Filesystem $disk, string $path, string $body): void
    {
        $tmp = dirname($path).'/.tmp/'.basename($path).'.'.Str::random(12);

        if (! $disk->put($tmp, $body)) {
            throw new WordAudioNotStoredException;
        }

        try {
            $moved = $disk->move($tmp, $path);
        } catch (Throwable $e) {
            $disk->delete($tmp);

            throw new WordAudioNotStoredException($e);
        }

        if (! $moved) {
            $disk->delete($tmp);

            throw new WordAudioNotStoredException;
        }
    }

    /** The disk of the word audio: `twa.tts.audio_disk` (`public` - this server, `audio` - R2, docs/26 §5.4). */
    public function diskName(): string
    {
        return (string) config('twa.tts.audio_disk', 'public');
    }

    private function disk(?string $name = null): Filesystem
    {
        return Storage::disk($name ?? $this->diskName());
    }
}
