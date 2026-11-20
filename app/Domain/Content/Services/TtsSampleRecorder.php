<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Content\Contracts\SpeechSynthesizer;
use App\Domain\Content\Exceptions\SpeechSynthesisException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * The control set of `audio:sample` (docs/26 §5.3, §7 step 3): ~30 words and phrases the owner listens to before the voice is
 * approved for the mass run - short words (the start of a one-syllable word is the main risk of a "live" voice), homographs,
 * abbreviations, numbers, dates, example sentences and two long dictations. It is also the first real call of the configured
 * driver (the check of the sign-in to Google, docs/26 §9.1).
 *
 * Synchronous, with the current {@see SpeechSynthesizer}: the characters go through the usual monthly budget of the driver.
 * No transaction is opened (docs/26 §9.3). A word is sent the way {@see WordAudioService::generate()} sends it (normalised:
 * lower case), a sentence as it is (like a dictation). An error is kept as its stable code - never a message of the service, a
 * key or a token; after a final one (no sign-in, no permission, the limit) the run stops: the next texts would fail the same way.
 *
 * @phpstan-type SampleRow array{name: string, text: string, sent: string, file: string|null, bytes: int|null, error: string|null}
 */
final class TtsSampleRecorder
{
    /**
     * kind => texts. `word` - sent normalised, as a word; `sentence` - sent as it is.
     *
     * @var array<string, array{kind: 'word'|'sentence', texts: list<string>}>
     */
    public const array SAMPLES = [
        'short' => ['kind' => 'word', 'texts' => ['a', 'I', 'go', 'at', 'up', 'the', 'eye', 'ox']],
        'homograph' => ['kind' => 'word', 'texts' => ['read', 'live', 'lead', 'wind', 'tear']],
        'abbreviation' => ['kind' => 'word', 'texts' => ['TV', 'USA', 'Mr.', 'Dr.']],
        'number' => ['kind' => 'word', 'texts' => ['7', '15', '100']],
        'date' => ['kind' => 'word', 'texts' => ['March 3', '1999']],
        'phrase' => ['kind' => 'sentence', 'texts' => [
            'I read this book last year.',
            'They live in a small house near the river.',
            'Can you turn on the TV, please?',
            'Mr. Smith arrived at 7 o\'clock on March 3.',
            'Where is the nearest bus stop?',
            'She has never been to the USA.',
        ]],
        'dictation' => ['kind' => 'sentence', 'texts' => [
            'Every morning my brother gets up at six, makes a cup of strong tea and walks to the station. The train is often late, so he reads the news on his phone and answers a few emails before work.',
            'Last summer we spent two weeks by the sea. The weather was warm, the water was clear, and in the evenings we sat on the beach, watched the sunset and talked about our plans for the next year.',
        ]],
    ];

    public function __construct(
        private readonly SpeechSynthesizer $tts,
        private readonly WordAudioService $audio,
    ) {}

    /**
     * The set in order: `name` is the file name (`NN-group-slug.mp3`), `text` what the owner reads, `sent` what the driver gets.
     *
     * @return list<array{name: string, text: string, sent: string}>
     */
    public function samples(): array
    {
        $out = [];
        $n = 0;
        foreach (self::SAMPLES as $group => $set) {
            foreach ($set['texts'] as $i => $text) {
                $n++;
                $slug = $set['kind'] === 'word' ? Str::slug($text) : (string) ($i + 1);
                $out[] = [
                    'name' => sprintf('%02d-%s-%s.mp3', $n, $group, $slug === '' ? 'x' : $slug),
                    'text' => $text,
                    'sent' => $set['kind'] === 'word' ? $this->audio->normalize($text) : $text,
                ];
            }
        }

        return $out;
    }

    /**
     * Synthesizes the set into `$dir` (created when missing; a file of the same name is overwritten).
     *
     * @param  (callable(SampleRow): void)|null  $progress  called after every text
     * @return list<SampleRow> the texts done or failed, in order (the rest are missing after a final error)
     */
    public function record(string $voice, string $dir, ?callable $progress = null): array
    {
        File::ensureDirectoryExists($dir, 0755);

        $rows = [];
        foreach ($this->samples() as $sample) {
            $row = ['name' => $sample['name'], 'text' => $sample['text'], 'sent' => $sample['sent'], 'file' => null, 'bytes' => null, 'error' => null];
            $final = false;

            try {
                $audio = $this->tts->synthesize($sample['sent'], $voice);
                if ($audio['body'] === '' || ! str_starts_with(strtolower($audio['content_type']), 'audio/')) {
                    throw SpeechSynthesisException::badAudio();
                }

                $path = rtrim($dir, '/').'/'.$sample['name'];
                if (File::put($path, $audio['body']) === false) {
                    $row['error'] = 'tts_audio_not_stored';
                } else {
                    $row['file'] = $path;
                    $row['bytes'] = strlen($audio['body']);
                }
            } catch (SpeechSynthesisException $e) {
                $row['error'] = $e->getMessage();
                $final = ! $e->retryable;
            } catch (Throwable $e) {
                // Not a code of the synthesizer: the class only, its message may carry what the service answered.
                $row['error'] = 'tts_unexpected_error ('.class_basename($e).')';
            }

            $rows[] = $row;
            if ($progress !== null) {
                $progress($row);
            }
            if ($final) {
                break;
            }
        }

        return $rows;
    }
}
