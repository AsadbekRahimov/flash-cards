<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Administration\Services\WidgetCache;
use App\Domain\Content\Services\Tts\ConfiguredSpeechSynthesizer;
use App\Domain\Content\Services\Tts\GoogleSpeechSynthesizer;
use Illuminate\Queue\QueueManager;

/**
 * Two lines of «Требуют внимания» of the dashboard of `/admin` about the voicing (docs/26 §9.3):
 *  - the queue `tts` is longer than `twa.tts.queue_attention_size` (`TTS_QUEUE_ATTENTION_SIZE`, default {@see DEFAULT_THRESHOLD}):
 *    the jobs come in faster than the `low` worker voices them, or the worker is gone. The size is one call to the queue (on
 *    Redis an EVAL), kept in the pure cache ({@see WidgetCache}, `admin:widget:tts_queue:v1:all`, an array of one scalar) for
 *    {@see FRESH_SECONDS}: a backlog of thousands is not a figure of the minute;
 *  - Google refused the sign-in ({@see GoogleSpeechSynthesizer::haltCode()}, only with `TTS_DRIVER=google`): the marker itself,
 *    one GET on the default store, not cached - it lives ten minutes and `audio:sample --clear-halt` drops it.
 */
final class TtsQueueStats
{
    public const WIDGET = 'tts_queue';

    public const FRESH_SECONDS = 60;

    public const QUEUE = 'tts';

    public const DEFAULT_THRESHOLD = 3000;

    public function __construct(
        private readonly WidgetCache $cache,
        private readonly QueueManager $queues,
    ) {}

    /**
     * The jobs waiting in `tts` when there are more than the threshold, else null.
     *
     * @return array{size: int, threshold: int}|null
     */
    public function backlog(): ?array
    {
        $threshold = self::threshold();
        $cached = $this->cache->remember(self::WIDGET, 'all', self::FRESH_SECONDS, fn (): array => [
            'size' => $this->queues->connection()->size(self::QUEUE),
        ]);
        $size = is_numeric($cached['size'] ?? null) ? (int) $cached['size'] : 0;

        return $size > $threshold ? ['size' => $size, 'threshold' => $threshold] : null;
    }

    /** `tts_auth_failed` | `tts_forbidden` while the `google` driver is halted, else null (another driver: null). */
    public function haltCode(): ?string
    {
        return ConfiguredSpeechSynthesizer::driver() === ConfiguredSpeechSynthesizer::GOOGLE ? GoogleSpeechSynthesizer::haltCode() : null;
    }

    /** `twa.tts.queue_attention_size`, at least 1; a value that is not a number falls back to the default. */
    public static function threshold(): int
    {
        $value = config('twa.tts.queue_attention_size', self::DEFAULT_THRESHOLD);

        return max(1, is_numeric($value) ? (int) $value : self::DEFAULT_THRESHOLD);
    }
}
