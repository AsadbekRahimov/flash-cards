<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Content\Services\WordAudioService;
use App\Models\Word;

/**
 * Synthesises the anchor example of one word (`words.example`) into `audio/{sha1}.mp3` on the word-audio disk and sets
 * `words.example_audio_path` (docs/26 §5.7, §9.11). The sentence is read when the job runs: a job that waited voices the latest
 * text. The rules of the queue, the errors, the decks of learners and the uniqueness: {@see PregenerateAudioJob}.
 */
final class PregenerateExampleAudioJob extends PregenerateAudioJob
{
    protected function voice(WordAudioService $audio, Word $word): void
    {
        $audio->generateExample($word, $this->force);
    }

    protected function failureEvent(): string
    {
        return 'audio.pregenerate_example_failed';
    }
}
