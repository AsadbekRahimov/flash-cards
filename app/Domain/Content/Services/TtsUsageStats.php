<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Administration\Services\WidgetCache;
use App\Domain\Content\Services\Tts\TtsCharacterBudget;

/**
 * The characters the `google` driver has sent this billing month against `TTS_MONTHLY_CHAR_LIMIT` (docs/26 §5.9, §9.3), for the
 * block «Требуют внимания» of the dashboard of `/admin`: a line from {@see WARNING_PERCENT} percent, another once the limit is spent.
 *
 * Only while `TTS_DRIVER=google` (the other drivers spend no budget). The figure is the row of {@see TtsCharacterBudget::usage()}
 * in the pure cache ({@see WidgetCache}, `admin:widget:tts_usage:v1:all:<month>` - the month in the key, so a new month starts
 * from its own entry), an array of scalars, fresh {@see FRESH_SECONDS} like the other K1 widgets: no reset on a reservation (one
 * per synthesis), the timer alone. The limit is read from the configuration on every call, not cached.
 */
final class TtsUsageStats
{
    public const WIDGET = 'tts_usage';

    public const FRESH_SECONDS = 300;

    /** Percent of the monthly limit from which the dashboard warns. */
    public const WARNING_PERCENT = 90.0;

    public function __construct(
        private readonly WidgetCache $cache,
        private readonly TtsCharacterBudget $budget,
    ) {}

    /**
     * The usage of this month, or null when the driver is not `google`.
     *
     * @return array{month: string, characters: int, limit: int, percent: float, exhausted: bool, near: bool}|null
     */
    public function current(): ?array
    {
        if ((string) config('twa.tts.driver') !== 'google') {
            return null;
        }

        $month = $this->budget->month();
        $cached = $this->cache->remember(self::WIDGET, 'all', self::FRESH_SECONDS, fn (): array => [
            'characters' => $this->budget->usage()['characters'],
        ], $month);

        $characters = is_numeric($cached['characters'] ?? null) ? (int) $cached['characters'] : 0;
        $limit = $this->budget->limit();
        $percent = $limit > 0 ? round($characters * 100 / $limit, 1) : 100.0;
        $exhausted = $limit <= 0 || $characters >= $limit;

        return [
            'month' => $month,
            'characters' => $characters,
            'limit' => $limit,
            'percent' => $percent,
            'exhausted' => $exhausted,
            'near' => ! $exhausted && $characters * 100 >= self::WARNING_PERCENT * $limit,
        ];
    }
}
