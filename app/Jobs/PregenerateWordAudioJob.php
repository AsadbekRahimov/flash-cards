<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Content\Services\WordAudioService;
use App\Models\Word;

/**
 * Synthesises one word's audio into `audio/{sha1}.mp3` on the word-audio disk and sets `words.audio_path` (docs/12 task
 * 1.2c). The rules of the queue, the errors, the decks of learners and the uniqueness: {@see PregenerateAudioJob}.
 */
final class PregenerateWordAudioJob extends PregenerateAudioJob
{
    protected function voice(WordAudioService $audio, Word $word): void
    {
        $audio->generate($word, $this->force);
    }

    protected function failureEvent(): string
    {
        return 'audio.pregenerate_failed';
    }
}
