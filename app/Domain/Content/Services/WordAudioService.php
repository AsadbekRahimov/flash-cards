<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Content\Contracts\SpeechSynthesizer;
use App\Domain\Content\Exceptions\SpeechSynthesisException;
use App\Domain\Content\Exceptions\WordAudioNotStoredException;
use App\Domain\Content\Services\Tts\ConfiguredSpeechSynthesizer;
use App\Domain\Content\Services\Tts\GoogleSpeechSynthesizer;
use App\Domain\Content\Services\Tts\NullSpeechSynthesizer;
use App\Jobs\PregenerateExampleAudioJob;
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
 * disk of the word audio, so the same text in two words (or two courses) is one file.
 * `words.audio_path` is the relative path.
 * The synthesis itself is the configured {@see SpeechSynthesizer}, called only from the queued
 * {@see PregenerateWordAudioJob} (no request of a learner synthesizes, docs/26 §9.4).
 *
 * The disk is `twa.tts.audio_disk`: `public` (this server) or `audio` (Cloudflare R2, docs/26 §5.4). A screen of a learner
 * never asks the disk: a word is voiced when `audio_path` is the path its current text hashes to ({@see urlFor()}).
 *
 * The anchor example of a word (`words.example`, docs/26 §5.7, §9.11) is voiced the same way into `words.example_audio_path`
 * ({@see examplePathFor()}, {@see exampleUrlFor()}, {@see generateExample()}, {@see exampleChanged()}): the same directory, the
 * same disk, the same hash - of the sentence as it goes to the synthesizer (case and punctuation kept), so an example equal to
 * another one (or to a word sent the same way) shares its file. The additional examples (`word_examples`) are not voiced.
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

        return $text === '' ? null : $this->hashedPath($text, $voice);
    }

    /**
     * An example as it is sent to the synthesizer (docs/26 §9.11): the blanks collapsed and trimmed - nothing else. The case and
     * the punctuation stay: they are the intonation of a sentence. Null when there is nothing to say, or the sentence is longer
     * than one request of Google takes ({@see GoogleSpeechSynthesizer::MAX_TEXT_BYTES}) or, with `deepgram`, longer than
     * {@see ConfiguredSpeechSynthesizer::maxTextChars()} characters: such an example is never queued (it would fail as
     * `tts_request_rejected` / `tts_text_too_long` every night) and counts as "nothing to voice".
     */
    public function exampleText(?string $example): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $example));

        return $text === '' || strlen($text) > GoogleSpeechSynthesizer::MAX_TEXT_BYTES || mb_strlen($text) > ConfiguredSpeechSynthesizer::maxTextChars()
            ? null
            : $text;
    }

    /** Relative path of the audio of an example sentence ({@see exampleText()}); null when there is nothing to voice. */
    public function examplePathFor(?string $example, ?string $voice = null): ?string
    {
        $text = $this->exampleText($example);

        return $text === null ? null : $this->hashedPath($text, $voice);
    }

    /** `audio/{sha1(text|voice|version)}.mp3`: the text is the one the synthesizer gets. */
    private function hashedPath(string $spoken, ?string $voice): string
    {
        $hash = sha1($spoken.'|'.($voice ?? $this->defaultVoice()).'|'.(int) config('twa.tts.audio_version', 1));

        return trim((string) config('twa.tts.audio_dir', 'audio'), '/').'/'.$hash.'.mp3';
    }

    /**
     * False while nobody can voice anything: `TTS_DRIVER` is `null` (or unset without a service URL) or a value nobody knows.
     * Nothing is queued then (docs/26 §7 step 2); the nightly reconciliation picks the words and examples up once a driver is set.
     */
    public function enabled(): bool
    {
        if ($this->tts instanceof ConfiguredSpeechSynthesizer) {
            return in_array(ConfiguredSpeechSynthesizer::driver(), [ConfiguredSpeechSynthesizer::DEEPGRAM, ConfiguredSpeechSynthesizer::GOOGLE, ConfiguredSpeechSynthesizer::HTTP], true);
        }

        return ! $this->tts instanceof NullSpeechSynthesizer;
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
            ? $this->publicUrl($path)
            : null;
    }

    /**
     * Public URL of the audio of the example a card shows; null when there is none (no disk access). `$shown` is the sentence of
     * this serve as the card gets it ({@see WordExamples::pickRow()}; null - no example, no URL): only the anchor is voiced, so a
     * rotated additional example gets a URL only when its text is the anchor's - and an edited anchor whose `example_audio_path`
     * has not caught up yet gets none, like {@see urlFor()}.
     */
    public function exampleUrlFor(Word $word, ?string $shown): ?string
    {
        $path = $word->example_audio_path;
        if ($path === null || $path === '') {
            return null;
        }

        return $path === $this->examplePathFor($shown)
            ? $this->publicUrl($path)
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

    /** {@see isCurrent()} for the anchor example: the path of its current text, recorded, and the file is there. */
    public function isExampleCurrent(Word $word): bool
    {
        $expected = $this->examplePathFor($word->example);

        return $expected !== null
            && $word->example_audio_path === $expected
            && $this->isReady($expected);
    }

    /**
     * The edited word text no longer matches its audio: drops `audio_path` and queues the job
     * that voices the new text (after the surrounding transaction commits). While TTS is switched off ({@see enabled()}) only the
     * path is dropped: a job would only fail as `tts_service_not_configured`; the nightly reconciliation voices the word later.
     */
    public function textChanged(Word $word): void
    {
        if ($word->audio_path !== null) {
            Word::query()->whereKey($word->id)->update(['audio_path' => null]);
            $word->audio_path = null;
            $word->syncOriginalAttribute('audio_path');
        }

        if ($this->enabled()) {
            PregenerateWordAudioJob::dispatch((int) $word->id)->afterCommit();
        }
    }

    /**
     * The anchor example of the word was edited (call it after the save): an `example_audio_path` that is not the path of the new
     * sentence is dropped, and the sentence is queued (after the commit) - not while TTS is switched off, not for an example with
     * nothing to say. An edit that keeps the spoken sentence (blanks only) keeps the path and queues nothing.
     */
    public function exampleChanged(Word $word): void
    {
        $expected = $this->examplePathFor($word->example);
        if ($expected !== null && $word->example_audio_path === $expected) {
            return;
        }

        // Without looking at the model: a record of a list may have been read without the column.
        Word::query()->whereKey($word->id)->whereNotNull('example_audio_path')->update(['example_audio_path' => null]);
        $word->example_audio_path = null;
        $word->syncOriginalAttribute('example_audio_path');

        if ($expected !== null && $this->enabled()) {
            PregenerateExampleAudioJob::dispatch((int) $word->id)->afterCommit();
        }
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
        $text = $this->normalize($word->word);

        return $this->voice($word, 'word', 'audio_path', $text === '' ? null : $text, $force);
    }

    /**
     * {@see generate()} for the anchor example (`words.example` into `words.example_audio_path`): the same disk check, the same
     * write, the same guarded update - the path is recorded only while the example is still the sentence that was voiced.
     *
     * @return bool true when the TTS service was called
     *
     * @throws SpeechSynthesisException when the TTS service fails (the caller retries a `retryable` one)
     * @throws WordAudioNotStoredException the audio was synthesized, but the disk did not take it
     */
    public function generateExample(Word $word, bool $force = false): bool
    {
        return $this->voice($word, 'example', 'example_audio_path', $this->exampleText($word->example), $force);
    }

    /**
     * Voices `$spoken` (the text of `$sourceColumn` as the synthesizer gets it) into the content-addressed file and records its
     * path in `$pathColumn`. The columns are the fixed pairs of {@see generate()} and {@see generateExample()}, never input.
     *
     * @param  'word'|'example'  $sourceColumn
     * @param  'audio_path'|'example_audio_path'  $pathColumn
     */
    private function voice(Word $word, string $sourceColumn, string $pathColumn, ?string $spoken, bool $force): bool
    {
        if ($spoken === null) {
            return false;
        }
        $path = $this->hashedPath($spoken, null);
        $recorded = $word->getAttribute($pathColumn);

        // One look at the disk (on R2 a HEAD and a size), not one for isCurrent() and another for the synthesis.
        $ready = ! $force && $this->isReady($path);
        if ($ready && $recorded === $path) {
            return false;
        }

        $called = false;
        if (! $ready) {
            $audio = $this->tts->synthesize($spoken, $this->defaultVoice());
            if ($audio['body'] === '' || ! str_starts_with(strtolower($audio['content_type']), 'audio/')) {
                throw new RuntimeException('tts_service_bad_audio');
            }
            $this->store($path, $audio['body']);
            $called = true;
        }

        // Only while the text is still the one voiced (docs/26 §9.6): an edit during the synthesis queued its own job, which
        // may have finished first - its path must not be overwritten with the path of the old text.
        if ($recorded !== $path
            && Word::query()->whereKey($word->id)->where($sourceColumn, $word->getAttribute($sourceColumn))->update([$pathColumn => $path]) > 0) {
            $word->setAttribute($pathColumn, $path);
            $word->syncOriginalAttribute($pathColumn);
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
     * On R2 these are HEAD requests: only the jobs and the `audio:*` commands (`audio:coverage --verify`, `audio:migrate-disk`, ...)
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

    /**
     * The address a learner plays (no disk access). On the `public` disk: `twa.tts.public_disk_audio_url` + the path, i.e.
     * `https://<main domain>/audio/<hash>.mp3` (docs/31 §5д) instead of the `/storage` of the disk; an empty base falls back to
     * the disk. Any other disk (R2) builds it itself: AUDIO_PUBLIC_URL + the path.
     */
    private function publicUrl(string $path): string
    {
        $base = (string) config('twa.tts.public_disk_audio_url', '');

        return $this->diskName() === 'public' && $base !== ''
            ? $base.'/'.ltrim($path, '/')
            : $this->disk()->url($path);
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
