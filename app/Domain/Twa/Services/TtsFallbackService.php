<?php

declare(strict_types=1);

namespace App\Domain\Twa\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class TtsFallbackService
{
    /** The message (a stable code) of the RuntimeException thrown while `twa.tts.service_url` is empty: nothing to retry. */
    public const NOT_CONFIGURED = 'tts_service_not_configured';

    /** The message of the RuntimeException thrown when every live-synthesis slot is taken. */
    public const BUSY = 'tts_busy';

    /**
     * A synthesis on behalf of an API request (`GET /api/twa/tts`): short timeouts (`twa.tts.live_timeout`,
     * `twa.tts.live_connect_timeout`) and at most `twa.tts.live_concurrency` of them at once across all workers, so a slow
     * TTS service cannot hold every worker of the API. No free slot is an immediate {@see BUSY} refusal, not a wait.
     * Pre-generation (jobs) calls {@see synthesize()} with the long timeout instead.
     *
     * @return array{body:string, content_type:string}
     */
    public function synthesizeLive(string $word, ?string $voice = null): array
    {
        $timeout = max(1, (int) config('twa.tts.live_timeout', 3));
        $connect = max(1, (int) config('twa.tts.live_connect_timeout', 2));

        // Slots are plain locks on the default store (a lock never lives on the evicting one); the TTL outlives a request that dies.
        for ($slot = 0, $slots = max(1, (int) config('twa.tts.live_concurrency', 2)); $slot < $slots; $slot++) {
            $lock = Cache::lock("tts:live:slot:{$slot}", $timeout + $connect + 5);
            if ($lock->get()) {
                try {
                    return $this->synthesize($word, $voice, $timeout, $connect);
                } finally {
                    $lock->release();
                }
            }
        }

        throw new RuntimeException(self::BUSY);
    }

    /**
     * @return array{body:string, content_type:string}
     */
    public function synthesize(string $word, ?string $voice = null, ?int $timeout = null, ?int $connectTimeout = null): array
    {
        $baseUrl = rtrim((string) config('twa.tts.service_url'), '/');
        if ($baseUrl === '') {
            throw new RuntimeException(self::NOT_CONFIGURED);
        }

        $request = Http::timeout($timeout ?? (int) config('twa.tts.timeout', 8));
        if ($connectTimeout !== null) {
            $request = $request->connectTimeout($connectTimeout);
        }

        $response = $request
            ->accept('audio/mpeg')
            ->post($baseUrl.'/tts', array_filter([
                'word' => $word,
                'voice' => $voice,
            ], fn ($value): bool => $value !== null && $value !== ''));

        if (! $response->successful()) {
            throw new RuntimeException('tts_service_failed');
        }

        return [
            'body' => $response->body(),
            'content_type' => $response->header('Content-Type') ?: 'audio/mpeg',
        ];
    }
}
