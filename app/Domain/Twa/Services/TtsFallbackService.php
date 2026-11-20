<?php

declare(strict_types=1);

namespace App\Domain\Twa\Services;

use App\Domain\Content\Contracts\SpeechSynthesizer;
use App\Domain\Content\Exceptions\SpeechSynthesisException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * The live synthesis of `GET /api/twa/tts` on top of the configured {@see SpeechSynthesizer}: the voice of a request without
 * one and the cap on simultaneous syntheses. Pre-generation (jobs) calls the synthesizer directly.
 */
final class TtsFallbackService
{
    /** The message of the RuntimeException thrown when every live-synthesis slot is taken. */
    public const BUSY = 'tts_busy';

    public function __construct(private readonly SpeechSynthesizer $synthesizer) {}

    /**
     * A synthesis on behalf of an API request: short timeouts (`twa.tts.live_timeout`, `twa.tts.live_connect_timeout`) and
     * at most `twa.tts.live_concurrency` of them at once across all workers, so a slow TTS service cannot hold every worker
     * of the API. No free slot is an immediate {@see BUSY} refusal, not a wait.
     *
     * @return array{body:string, content_type:string}
     *
     * @throws SpeechSynthesisException|RuntimeException
     */
    public function synthesizeLive(string $word, ?string $voice = null): array
    {
        $timeout = max(1, (int) config('twa.tts.live_timeout', 3));
        $connect = max(1, (int) config('twa.tts.live_connect_timeout', 2));
        $voice = $voice !== null && $voice !== '' ? $voice : (string) config('twa.tts.voice');

        // Slots are plain locks on the default store (a lock never lives on the evicting one); the TTL outlives a request that dies.
        for ($slot = 0, $slots = max(1, (int) config('twa.tts.live_concurrency', 2)); $slot < $slots; $slot++) {
            $lock = Cache::lock("tts:live:slot:{$slot}", $timeout + $connect + 5);
            if ($lock->get()) {
                try {
                    return $this->synthesizer->synthesize($word, $voice, live: true);
                } finally {
                    $lock->release();
                }
            }
        }

        throw new RuntimeException(self::BUSY);
    }
}
