<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Support\ExperimentBucket;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Twa\Services\TodayProfileCache;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * `client.timing` (docs/12 §7.7, docs/04 A.21): the timing marks of one sampled open of the Mini App, reported by the client
 * (`POST /api/twa/client-timing`; audit 2026-11-18, «Как увидеть выигрыш» and «Скорость для ученика»).
 *
 * Sampling: the share is `analytics.client_timing.sample_percent` (0..100, handed to the client by `POST /auth`). An open is in the
 * sample when `ExperimentBucket::in(SAMPLE_SALT, open_id, percent)` - crc32 of `client_timing:<open_id>`, the very rule the client
 * applies (`resources/twa/src/composables/useTimingMarks.ts`), so the server refuses an open the client should not have sent. 0 = off.
 *
 * At most one event per `open_id`: the marker `analytics:client_timing:v1:<open_id>` is taken with `Cache::add` on the default,
 * non-evicting store (docs/19 §3 rules 3, 4, 10: a marker; `add` wins the race of the `pagehide` send and a retry of the browser). Its
 * TTL is a day: an open reports within minutes, the key then simply expires.
 *
 * Props, nothing else: `open_id` (a random uuid of the open, not of the device), `platform` (the closed list of {@see PLATFORMS},
 * anything else is `other`), `cold`, `entry`, `locale` (the learner's interface language, from the row the authentication loaded), the
 * millisecond offsets of {@see MARKS} that the client set, and `class` - `assignments` of the `/today` snapshot ({@see TodayProfileCache}:
 * the learner is an active member of a group linked to a center) when that snapshot is in the cache, omitted otherwise. The request
 * path costs no SQL beyond the authentication: two cache reads (the version and the snapshot), one `add`, and the INSERT is written
 * after the response (`ProductEventBuffer`).
 */
final class ClientTimingRecorder
{
    public const MARKER_PREFIX = 'analytics:client_timing:v1:';

    public const MARKER_TTL_HOURS = 24;

    /** The salt of the per-open sample; the client hashes the same string. */
    public const SAMPLE_SALT = 'client_timing';

    /** `Telegram.WebApp.platform` values kept as they are; anything else is recorded as `other`. */
    public const PLATFORMS = ['android', 'ios', 'tdesktop', 'macos', 'web', 'weba', 'webk', 'unknown'];

    public const OTHER_PLATFORM = 'other';

    public const ENTRIES = ['home', 'deep_link', 'other'];

    /** The millisecond offsets from the start of the open, in the order of the open. */
    public const MARKS = ['auth_ready', 'today_received', 'button_ready', 'first_tap', 'first_answer_ack', 'first_audio'];

    /** The ceiling of an offset: ten minutes. */
    public const MAX_OFFSET_MS = 600_000;

    public function __construct(
        private readonly ProductEventRecorder $events,
        private readonly TodayProfileCache $todayProfile,
    ) {}

    public static function samplePercent(): int
    {
        return max(0, min(100, (int) config('analytics.client_timing.sample_percent', 0)));
    }

    /** Whether the open `$openId` is in the sample of `$percent` (the client's rule, see the class doc). */
    public static function sampled(string $openId, int $percent): bool
    {
        return $percent > 0 && ExperimentBucket::in(self::SAMPLE_SALT, strtolower($openId), $percent);
    }

    public static function normalizePlatform(string $platform): string
    {
        return in_array($platform, self::PLATFORMS, true) ? $platform : self::OTHER_PLATFORM;
    }

    public static function markerKey(string $openId): string
    {
        return self::MARKER_PREFIX.strtolower($openId);
    }

    /**
     * @param  array<string, int>  $marks  offsets of {@see MARKS} that were set, in milliseconds (validated by the request)
     * @return bool whether an event was recorded (false: the collection is off, the open is not in the sample, or was already reported)
     */
    public function record(Learner $learner, string $openId, string $platform, bool $cold, string $entry, array $marks, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        if (! self::sampled($openId, self::samplePercent())) {
            return false;
        }

        if (! Cache::add(self::markerKey($openId), true, $now->addHours(self::MARKER_TTL_HOURS))) {
            return false;
        }

        $props = [
            'open_id' => strtolower($openId),
            'platform' => self::normalizePlatform($platform),
            'cold' => $cold,
            'entry' => $entry,
            'locale' => $learner->interface_locale,
        ];

        $class = $this->isClass($learner, $now);
        if ($class !== null) {
            $props['class'] = $class;
        }

        foreach (self::MARKS as $mark) {
            if (isset($marks[$mark])) {
                $props[$mark] = $marks[$mark];
            }
        }

        $this->events->record(ProductEventName::ClientTiming, $props, learner: $learner);

        return true;
    }

    /** `assignments` of the cached `/today` snapshot; null when there is none under the learner's current version (no SQL either way). */
    private function isClass(Learner $learner, CarbonImmutable $now): ?bool
    {
        return $this->todayProfile->get($learner, EntitlementResolver::version((int) $learner->id), $now)['assignments'] ?? null;
    }
}
