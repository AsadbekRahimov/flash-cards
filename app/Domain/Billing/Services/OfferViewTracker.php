<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Billing\Enums\OfferPlacement;
use App\Domain\Learners\Services\LearnerClock;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * `billing.offer_viewed` (docs/13 §5.1): the first step of the Pro funnel, reported by the Mini App when an offer becomes visible
 * (`POST /api/twa/billing/offer-viewed`, docs/04 A.8).
 *
 * At most one event per (learner, placement, local day of the learner): the marker `billing:offer_viewed:v1:<learner>:<placement>:<Y-m-d>`
 * is taken with `Cache::add` on the default, non-evicting store (docs/19 §3 rules 3, 4, 10: a marker; `add` wins the race of two tabs).
 * Its TTL is 48 h: longer than any local day, so the key of a day outlives the day, then it simply expires. The local date comes from
 * the learner row the authentication has already loaded: a repeated view costs no SQL at all, a new one costs one INSERT, written
 * after the response (`ProductEventBuffer`).
 *
 * Nothing is recorded while nothing is for sale (`paywall.mode` lowered to `coming_soon`, the same config-only check as `/me`): the
 * client does not report then either, this is the server's word on it. Props: `placement` and the learner's interface `locale`
 * (a snapshot: the funnel is segmented by the language the offer was read in). No PII.
 */
final class OfferViewTracker
{
    public const MARKER_PREFIX = 'billing:offer_viewed:v1:';

    public const MARKER_TTL_HOURS = 48;

    public function __construct(
        private readonly LearnerBillingService $billing,
        private readonly LearnerClock $clock,
        private readonly ProductEventRecorder $events,
    ) {}

    /** @return bool whether an event was recorded (false: billing is closed, or this placement was already counted today) */
    public function record(Learner $learner, OfferPlacement $placement, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        if ($this->billing->paywall($learner, $now)['mode'] !== 'purchase') {
            return false;
        }

        if (! Cache::add(self::markerKey($learner->id, $placement, $this->clock->localDate($learner, $now)), true, $now->addHours(self::MARKER_TTL_HOURS))) {
            return false;
        }

        $this->events->record(ProductEventName::BillingOfferViewed, [
            'placement' => $placement->value,
            'locale' => $learner->interface_locale,
        ], learner: $learner);

        return true;
    }

    public static function markerKey(int $learnerId, OfferPlacement $placement, string $localDate): string
    {
        return self::MARKER_PREFIX.$learnerId.':'.$placement->value.':'.$localDate;
    }
}
