<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Twa\Services\TtsFallbackService;
use App\Jobs\PregenerateWordAudioJob;
use App\Models\Word;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Pre-generated word audio (docs/12 task 1.2c).
 *
 * The file name is content-addressed: `audio/{sha1(text|voice|version)}.mp3` on the
 * `public` disk, so the same text in two words (or two courses) is one file, and the
 * TTS endpoint can find it from the text alone. `words.audio_path` is the relative path.
 * The synthesis itself is {@see TtsFallbackService} (the same edge-tts client the live
 * `/api/twa/tts` fallback uses).
 */
final class WordAudioService
{
    public function __construct(private readonly TtsFallbackService $tts) {}

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

    public function read(string $path): ?string
    {
        return $this->disk()->get($path);
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
     * @throws RuntimeException when the TTS service fails (the caller retries)
     */
    public function generate(Word $word, bool $force = false): bool
    {
        $path = $this->pathFor($word->word);
        if ($path === null || (! $force && $this->isCurrent($word))) {
            return false;
        }

        $called = false;
        if ($force || ! $this->isReady($path)) {
            $audio = $this->tts->synthesize($this->normalize($word->word), $this->defaultVoice());
            if ($audio['body'] === '' || ! str_starts_with(strtolower($audio['content_type']), 'audio/')) {
                throw new RuntimeException('tts_service_bad_audio');
            }
            $this->store($path, $audio['body']);
            $called = true;
        }

        if ($word->audio_path !== $path) {
            Word::query()->whereKey($word->id)->update(['audio_path' => $path]);
            $word->audio_path = $path;
            $word->syncOriginalAttribute('audio_path');
        }

        return $called;
    }

    /** The file is there and not empty (an empty or truncated leftover counts as "not generated"). */
    private function isReady(string $path): bool
    {
        $disk = $this->disk();

        return $disk->exists($path) && $disk->size($path) > 0;
    }

    /**
     * Atomic write: the body goes to `{dir}/.tmp/{name}.{random}` and is moved onto the final name,
     * so a reader (nginx, {@see read()}) never sees a half-written mp3.
     */
    private function store(string $path, string $body): void
    {
        $disk = $this->disk();
        $tmp = dirname($path).'/.tmp/'.basename($path).'.'.Str::random(12);

        if (! $disk->put($tmp, $body)) {
            throw new RuntimeException('tts_audio_not_stored');
        }

        try {
            $moved = $disk->move($tmp, $path);
        } catch (Throwable $e) {
            $disk->delete($tmp);

            throw new RuntimeException('tts_audio_not_stored', previous: $e);
        }

        if (! $moved) {
            $disk->delete($tmp);

            throw new RuntimeException('tts_audio_not_stored');
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('twa.tts.audio_disk', 'public'));
    }
}
