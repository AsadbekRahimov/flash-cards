<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Content\Exceptions\SpeechSynthesisException;
use App\Domain\Content\Services\Tts\ConfiguredSpeechSynthesizer;
use App\Domain\Content\Services\Tts\DeepgramSpeechSynthesizer;
use App\Domain\Content\Services\Tts\GoogleSpeechSynthesizer;
use App\Domain\Content\Services\Tts\TtsCharacterBudget;
use App\Domain\Content\Services\TtsSampleRecorder;
use Illuminate\Console\Command;

/**
 * `audio:sample` (docs/26 §5.3, §7 step 3, §10): voices the control set ({@see TtsSampleRecorder}) with the current driver right now
 * (no queue) into `storage/app/audio-samples/{voice}/` and prints what was sent, the file and its size - to listen to before the
 * voice is approved, and the first real call of the service (an error is printed as its code). `--voices=a,b,c` records the set in
 * several voices in one run, each into its own folder, to compare them by ear. The characters count against the limit of the driver
 * like any synthesis. The exit code is 1 when any text failed. It warns about an unknown `TTS_DRIVER`, a voice that is not one of the
 * driver and the halt marker of the `google` / `deepgram` driver; `--clear-halt` drops those markers first (after the key was
 * replaced or the account topped up).
 */
final class SampleAudio extends Command
{
    /** @var string */
    protected $signature = 'audio:sample
        {--voice= : The voice (default TTS_VOICE)}
        {--voices= : Several voices, comma-separated, one folder each (e.g. aura-2-thalia-en,aura-2-andromeda-en)}
        {--dir= : Where to put the files (default storage/app/audio-samples/<voice>; with several voices <dir>/<voice>)}
        {--clear-halt : First drop the marker that stops the google or deepgram driver after a refused key or payment}';

    /** @var string */
    protected $description = 'Synthesize the control set of words and phrases with the current TTS driver, to listen to before a voice is approved.';

    /** The most voices of one run: each costs the characters of the whole set (≈ 1 000). */
    public const MAX_VOICES = 10;

    private const VOICE_HINT = 'This is not a voice of Google (tts_voice_unsupported): set TTS_VOICE (or --voice) to one, e.g. '
        .'en-US-Neural2-F, en-US-Chirp3-HD-Charon, en-US-Wavenet-D or en-US-Standard-C - en-US-AriaNeural is a name of edge-tts.';

    private const DEEPGRAM_VOICE_HINT = 'This is not a voice of Deepgram (tts_voice_unsupported): set TTS_VOICE (or --voice) to one, e.g. '
        .'aura-2-thalia-en, aura-2-andromeda-en, aura-2-apollo-en - or leave TTS_VOICE empty for aura-2-thalia-en.';

    public function handle(TtsSampleRecorder $recorder, TtsCharacterBudget $budget): int
    {
        $voices = $this->voices();
        if ($voices === null) {
            return self::INVALID;
        }

        $dir = $this->option('dir');
        $dir = is_string($dir) && $dir !== '' ? rtrim($dir, '/') : null;

        $driver = ConfiguredSpeechSynthesizer::driver();
        if (! ConfiguredSpeechSynthesizer::known()) {
            $this->warn('TTS_DRIVER is none of deepgram, google, http or null: every synthesis fails with '.SpeechSynthesisException::NOT_CONFIGURED.'. Fix the value in .env.');
        }
        if ($driver === ConfiguredSpeechSynthesizer::DEEPGRAM && ! DeepgramSpeechSynthesizer::hasKey()) {
            $this->warn('TTS_DRIVER=deepgram, but DEEPGRAM_API_KEY is empty: every synthesis fails with '.SpeechSynthesisException::NOT_CONFIGURED.'.');
        }

        if ($this->option('clear-halt') === true) {
            $google = GoogleSpeechSynthesizer::clearHalt();
            $deepgram = DeepgramSpeechSynthesizer::clearHalt();
            if ($google) {
                $this->info('The halt marker of the google driver is cleared: the next synthesis signs in to Google again.');
            }
            if ($deepgram) {
                $this->info('The halt marker of the deepgram driver is cleared: the next synthesis calls Deepgram again.');
            }
            if (! $google && ! $deepgram) {
                $this->info('There was no halt marker of the google or deepgram driver.');
            }
        } elseif ($driver === ConfiguredSpeechSynthesizer::GOOGLE && ($halt = GoogleSpeechSynthesizer::haltCode()) !== null) {
            $this->warn("Google refused the sign-in a short while ago ({$halt}): every synthesis fails at once for up to "
                .intdiv(GoogleSpeechSynthesizer::HALT_SECONDS, 60).' minutes. Once the key or the project is fixed, run with --clear-halt.');
        } elseif ($driver === ConfiguredSpeechSynthesizer::DEEPGRAM && ($halt = DeepgramSpeechSynthesizer::haltCode()) !== null) {
            $this->warn("Deepgram refused the key or the payment a short while ago ({$halt}): every synthesis fails at once for up to "
                .intdiv(DeepgramSpeechSynthesizer::HALT_SECONDS, 60).' minutes. Once the key is replaced or the account topped up, run with --clear-halt.');
        }

        $failedVoices = 0;
        foreach ($voices as $voice) {
            $target = match (true) {
                $dir === null => storage_path('app/audio-samples/'.$voice),
                count($voices) > 1 => $dir.'/'.$voice,
                default => $dir,
            };
            $result = $this->record($recorder, $driver, $voice, $target);
            if ($result !== null) {
                $failedVoices++;
            }
            // A final error that is not about the voice (no key, a refused key, no money, the limit) fails every other voice the same way.
            if ($result !== null && $result !== SpeechSynthesisException::VOICE_UNSUPPORTED && $result !== '') {
                break;
            }
        }

        // The credit of Deepgram is one-off: the owner sees how much of the limit is gone after every check.
        if ($driver === ConfiguredSpeechSynthesizer::DEEPGRAM) {
            $usage = $budget->usage($driver);
            $this->line(sprintf(
                'Deepgram characters used: %s of %s (DEEPGRAM_TTS_CHAR_LIMIT, the whole credit).',
                number_format($usage['characters'], 0, '.', ' '),
                number_format($usage['limit'], 0, '.', ' '),
            ));
        }

        return $failedVoices > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The voices of this run: `--voices`, else `--voice`, else `TTS_VOICE`; each a folder name too - letters, digits and dashes, like
     * every voice name of Deepgram, Google and edge-tts. Null (after an error line) when one is not.
     *
     * @return list<string>|null
     */
    private function voices(): ?array
    {
        $list = $this->option('voices');
        $single = $this->option('voice');
        $voices = match (true) {
            is_string($list) && trim($list) !== '' => array_values(array_unique(array_filter(array_map('trim', explode(',', $list)), static fn (string $v): bool => $v !== ''))),
            is_string($single) && $single !== '' => [$single],
            default => [(string) config('twa.tts.voice')],
        };

        foreach ($voices as $voice) {
            if (preg_match('/\A[A-Za-z0-9-]+\z/', $voice) !== 1) {
                $this->error('A voice must be a voice name: letters, digits and dashes (e.g. aura-2-thalia-en, en-US-Neural2-F).');

                return null;
            }
        }
        if ($voices === []) {
            $this->error('--voices names no voice.');

            return null;
        }
        if (count($voices) > self::MAX_VOICES) {
            $this->error('At most '.self::MAX_VOICES.' voices in one run: every voice costs the characters of the whole set.');

            return null;
        }

        return $voices;
    }

    /**
     * Records the set in one voice and prints its table.
     *
     * @return string|null null - every text voiced; `''` - some failed with a retryable error; else the code of the final error
     */
    private function record(TtsSampleRecorder $recorder, string $driver, string $voice, string $dir): ?string
    {
        $this->info("Voice {$voice}, driver {$driver}, into {$dir}");

        $hint = match ($driver) {
            ConfiguredSpeechSynthesizer::GOOGLE => GoogleSpeechSynthesizer::supportsVoice($voice) ? null : self::VOICE_HINT,
            ConfiguredSpeechSynthesizer::DEEPGRAM => DeepgramSpeechSynthesizer::supportsVoice($voice) ? null : self::DEEPGRAM_VOICE_HINT,
            default => null,
        };
        if ($hint !== null) {
            $this->warn($hint);
        }

        $rows = $recorder->record($voice, $dir, function (array $row): void {
            $this->output->write($row['error'] === null ? '.' : 'E');
        });
        $this->newLine();

        $this->table(
            ['Text', 'File', 'Size'],
            array_map(static fn (array $row): array => [
                $row['text'] === $row['sent'] ? $row['text'] : "{$row['text']} → {$row['sent']}",
                $row['error'] ?? basename((string) $row['file']),
                $row['bytes'] === null ? '-' : number_format($row['bytes'] / 1024, 1).' KB',
            ], $rows),
        );

        $total = count($recorder->samples());
        $failed = count(array_filter($rows, static fn (array $row): bool => $row['error'] !== null));
        $done = count($rows) - $failed;

        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        if ($hint === null && in_array(SpeechSynthesisException::VOICE_UNSUPPORTED, array_column($rows, 'error'), true)) {
            $this->warn($driver === ConfiguredSpeechSynthesizer::DEEPGRAM ? self::DEEPGRAM_VOICE_HINT : self::VOICE_HINT);
        }
        if ($last !== null && count($rows) < $total) {
            $this->error('Stopped after a final error: '.(string) $last['error'].'. Voiced '.$done.' of '.$total.'.');

            return (string) $last['error'];
        }

        if ($failed > 0) {
            $this->error("Voiced {$done} of {$total}, {$failed} failed.");

            return '';
        }

        $this->info("Voiced {$done} of {$total}.");

        return null;
    }
}
