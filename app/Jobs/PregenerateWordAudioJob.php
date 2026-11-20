<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Content\Exceptions\SpeechSynthesisException;
use App\Domain\Content\Exceptions\WordAudioNotStoredException;
use App\Domain\Content\Services\WordAudioService;
use App\Models\Course;
use App\Models\Word;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Synthesises one word's audio into `audio/{sha1}.mp3` on the word-audio disk and sets `words.audio_path` (docs/12 task
 * 1.2c). One word per job: a failing TTS call retries with backoff and never takes the rest of the batch down. Idempotent.
 *
 * Synthesis errors (docs/26 §9.2), as in {@see GeneratePracticeAudioJob}: a `retryable` one is thrown for the retry; the
 * monthly limit ends the job quietly (the nightly reconciliation picks the word up next month - no failed job, no warning
 * per word); any other final one fails the job at once. Words of a learner's own deck are never voiced by the synthesizer
 * (docs/26 §9.4) - checked here too, whatever queued the job.
 *
 * A disk that does not take the file after a synthesis ({@see WordAudioNotStoredException}): the audio was paid for and is
 * lost, so the word gets {@see STORE_ATTEMPTS} attempts in all, not the five of a failed synthesis.
 *
 * Unique until it is picked up ({@see uniqueId()}: the word and `force`): the nightly reconciliation queues a word that is still
 * waiting in `tts` once, not once a night. Until PROCESSING, not until done: the text is read when the job runs, so a job that
 * waits voices the latest text, but an edit made while a job is already synthesizing the old text must queue a job of its
 * own ({@see WordAudioService::textChanged()}) - with a lock held to the end that dispatch would be dropped and the new text
 * would wait for the next night. The lock is on the default cache store (`redis`, noeviction, docs/19 §4), the one of the other
 * unique jobs; {@see uniqueFor} frees it should the queued job be lost.
 */
final class PregenerateWordAudioJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** Attempts in all when the disk keeps refusing the file after a paid synthesis. */
    public const int STORE_ATTEMPTS = 2;

    /** Seconds the lock of a queued job lives at most: longer than a mass run of `audio:pregenerate` waits in `tts`. */
    public int $uniqueFor = 43_200;

    public function __construct(
        public readonly int $wordId,
        public readonly bool $force = false,
    ) {
        // docs/26 §9.3: the queue of its own, served by the `low` worker after `low` (`--queue=low,tts`): a mass run never
        // stands in front of the `low` heartbeat.
        $this->onQueue('tts');
    }

    public function uniqueId(): string
    {
        return $this->wordId.($this->force ? ':force' : '');
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

        // A learner's own deck keeps the phone voice (docs/26 §9.4): word -> lesson -> stage -> course.
        $ownDeck = Course::query()
            ->where('owner_type', 'learner')
            ->whereHas('stages.lessons', fn (Builder $lessons) => $lessons->whereKey($word->lesson_id))
            ->exists();
        if ($ownDeck) {
            return;
        }

        try {
            $audio->generate($word, $this->force);
        } catch (SpeechSynthesisException $e) {
            if ($e->retryable) {
                throw $e;
            }
            if ($e->getMessage() === SpeechSynthesisException::MONTHLY_LIMIT_REACHED) {
                return;
            }

            $this->fail($e);
        } catch (WordAudioNotStoredException $e) {
            if ($this->attempts() >= self::STORE_ATTEMPTS) {
                $this->fail($e);

                return;
            }

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        Log::warning('audio.pregenerate_failed', [
            'word_id' => $this->wordId,
            'reason' => $e->getMessage(),
        ]);
    }
}
