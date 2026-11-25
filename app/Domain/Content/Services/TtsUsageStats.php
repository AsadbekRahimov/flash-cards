<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Administration\Services\WidgetCache;
use App\Domain\Content\Services\Tts\ConfiguredSpeechSynthesizer;
use App\Domain\Content\Services\Tts\TtsCharacterBudget;

/**
 * The characters the paid driver has sent against its limit (docs/26 §5.9, §9.3, §10), for the block «Требуют внимания» of the
 * dashboard of `/admin`: a line from {@see WARNING_PERCENT} percent, another once the limit is spent.
 *  - `google`: this billing month against `TTS_MONTHLY_CHAR_LIMIT`;
 *  - `deepgram`: all time against `DEEPGRAM_TTS_CHAR_LIMIT` (the credit is one-off; the period is {@see TtsCharacterBudget::DEEPGRAM_PERIOD}).
 *
 * Only while `TTS_DRIVER` is one of them (the other drivers spend no budget). The figure is the row of {@see TtsCharacterBudget::usage()}
 * in the pure cache ({@see WidgetCache}, `admin:widget:tts_usage:v1:all:<period>` - the month (or `dgtotal`) in the key, so a new
 * month starts from its own entry), an array of scalars, fresh {@see FRESH_SECONDS} like the other K1 widgets: no reset on a reservation (one
 * per synthesis), the timer alone. The limit is read from the configuration on every call, not cached.
 */
final class TtsUsageStats
{
    public const WIDGET = 'tts_usage';

    public const FRESH_SECONDS = 300;

    /** Percent of the limit from which the dashboard warns. */
    public const WARNING_PERCENT = 90.0;

    public function __construct(
        private readonly WidgetCache $cache,
        private readonly TtsCharacterBudget $budget,
    ) {}

    /**
     * The usage of this period, or null when the driver is neither `google` nor `deepgram`. `month` - the period (`dgtotal` for
     * `deepgram`), `driver` - whose budget it is.
     *
     * @return array{driver: string, month: string, characters: int, limit: int, percent: float, exhausted: bool, near: bool}|null
     */
    public function current(): ?array
    {
        $driver = ConfiguredSpeechSynthesizer::driver();
        if (! in_array($driver, [ConfiguredSpeechSynthesizer::GOOGLE, ConfiguredSpeechSynthesizer::DEEPGRAM], true)) {
            return null;
        }

        $month = $this->budget->period($driver);
        $cached = $this->cache->remember(self::WIDGET, 'all', self::FRESH_SECONDS, fn (): array => [
            'characters' => $this->budget->usage($driver)['characters'],
        ], $month);

        $characters = is_numeric($cached['characters'] ?? null) ? (int) $cached['characters'] : 0;
        $limit = $this->budget->limit($driver);
        $percent = $limit > 0 ? round($characters * 100 / $limit, 1) : 100.0;
        $exhausted = $limit <= 0 || $characters >= $limit;

        return [
            'driver' => $driver,
            'month' => $month,
            'characters' => $characters,
            'limit' => $limit,
            'percent' => $percent,
            'exhausted' => $exhausted,
            'near' => ! $exhausted && $characters * 100 >= self::WARNING_PERCENT * $limit,
        ];
    }
}
