<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Content\Services\WordAudioService;
use App\Models\Word;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Synthesises one word's audio into `storage/app/public/audio/{sha1}.mp3` and sets
 * `words.audio_path` (docs/12 task 1.2c). One word per job: a failing TTS call retries
 * with backoff and never takes the rest of the batch down. Idempotent.
 */
final class PregenerateWordAudioJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $wordId,
        public readonly bool $force = false,
    ) {
        $this->onQueue('low');
    }

    /** @return list<int> seconds between attempts */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(WordAudioService $audio): void
    {
        /** @var Word|null $word */
        $word = Word::query()->find($this->wordId);

        // Deleted or deactivated since it was queued: nothing to voice.
        if ($word === null || ! $word->is_active) {
            return;
        }

        $audio->generate($word, $this->force);
    }

    public function failed(Throwable $e): void
    {
        Log::warning('audio.pregenerate_failed', [
            'word_id' => $this->wordId,
            'reason' => $e->getMessage(),
        ]);
    }
}
