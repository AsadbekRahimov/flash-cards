<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Billing\Exceptions\PromoCodeException;
use App\Models\Learner;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Promo codes (docs/12 §3.4, §5.5): `redeem` turns a code into `duration_days`
 * of its plan for the learner.
 *
 * Concurrency: the `promo_codes` row is locked FOR UPDATE for the whole
 * transaction, so the limit check, the redemption insert and the counter
 * increment are serialized per code — two learners racing for the last slot
 * get exactly one subscription, and a learner double-submitting gets one
 * redemption and one `promo_already_redeemed`. The learner row is locked next
 * (inside SubscriptionService::grantFromPromo), then the subscription, then the
 * FK inserts — the project-wide lock order of docs/12 §3.4
 * (`promo_codes -> subject row -> subscriptions -> FK inserts`), which every
 * other billing write path (grantManual, revoke, expireDue) follows from the
 * subject row on, so no cycle is possible.
 * `promo_redemptions (promo_code_id, learner_id)` unique is the backstop.
 */
final class PromoCodeService
{
    public const CODE_LENGTH = 10;

    /** RateLimiter key of the per-learner rejection counter (`promo-rejected:{learnerId}`). */
    public const REJECTIONS_KEY_PREFIX = 'promo-rejected:';

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly ProductEventRecorder $events,
    ) {}

    /**
     * Every failure is also a `promo.rejected` product event (written after the
     * rollback, so it survives) and counts towards the per-learner burst alert
     * (docs/12 §8) — the TWA endpoint and the bot command share this path, so
     * both are covered identically.
     *
     * @throws PromoCodeException promo_not_found | promo_expired | promo_exhausted | promo_already_redeemed | promo_not_applicable
     */
    public function redeem(Learner $learner, string $code, ?CarbonImmutable $now = null): Subscription
    {
        $now ??= CarbonImmutable::now();
        $normalized = self::normalize($code);
        $promoId = null;

        try {
            if ($normalized === '') {
                throw PromoCodeException::notFound();
            }

            return $this->redeemNormalized($learner, $normalized, $now, $promoId);
        } catch (PromoCodeException $e) {
            $this->recordRejected($learner, $e, $promoId, $normalized);

            throw $e;
        }
    }

    /**
     * @param-out int|null $promoId  id of the code once it has been resolved (null when no such code exists)
     *
     * @throws PromoCodeException
     */
    private function redeemNormalized(Learner $learner, string $normalized, CarbonImmutable $now, ?int &$promoId): Subscription
    {
        return DB::transaction(function () use ($learner, $normalized, $now, &$promoId): Subscription {
            /** @var PromoCode|null $promo */
            $promo = PromoCode::query()->where('code', $normalized)->lockForUpdate()->first();

            // Known to analytics even when deactivated; the *user* still gets promo_not_found.
            $promoId = $promo?->id;

            if ($promo === null || ! $promo->is_active) {
                throw PromoCodeException::notFound();
            }

            if ($promo->expires_at !== null && $promo->expires_at->lessThanOrEqualTo($now)) {
                throw PromoCodeException::expired();
            }

            if ($promo->max_redemptions !== null && $promo->redemptions_count >= $promo->max_redemptions) {
                throw PromoCodeException::exhausted();
            }

            $alreadyRedeemed = PromoRedemption::query()
                ->where('promo_code_id', $promo->id)
                ->where('learner_id', $learner->id)
                ->exists();

            if ($alreadyRedeemed) {
                throw PromoCodeException::alreadyRedeemed();
            }

            $subscription = $this->subscriptions->grantFromPromo($learner, $promo, $now);

            try {
                PromoRedemption::query()->create([
                    'promo_code_id' => $promo->id,
                    'learner_id' => $learner->id,
                    'subscription_id' => $subscription->id,
                    'redeemed_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Unreachable while the promo row lock holds; kept so a driver
                // without row locks still cannot double-redeem.
                throw PromoCodeException::alreadyRedeemed();
            }

            $promo->increment('redemptions_count');

            $this->events->record(
                ProductEventName::PromoRedeemed,
                ['promo_code_id' => $promo->id, 'plan' => $promo->plan, 'duration_days' => $promo->duration_days],
                learner: $learner,
            );

            return $subscription;
        });
    }

    /**
     * `promo.rejected {reason, promo_code_id, code_length}` + burst alert.
     *
     * The code itself is never written: it is a bearer secret (docs/12 §8), and a
     * prefix or an unsalted hash of a 36^10 key space would still leak it. The id
     * (when the code exists) and the length are enough to tell "guessing" from
     * "a real code typed wrong" in analytics.
     */
    private function recordRejected(Learner $learner, PromoCodeException $e, ?int $promoId, string $normalized): void
    {
        $this->events->record(
            ProductEventName::PromoRejected,
            ['reason' => $e->errorCode, 'promo_code_id' => $promoId, 'code_length' => mb_strlen($normalized)],
            learner: $learner,
        );

        $this->alertOnRejectionBurst($learner, $e->errorCode);
    }

    /**
     * `billing.promo_rejected_burst` warning when a learner collects
     * config('billing.promo.rejections_alert.threshold') rejections within the
     * window — logged at the threshold and at every further multiple of it, so a
     * sustained guessing run is visible without flooding the log (docs/DEVOPS_GUIDE §15.3).
     */
    private function alertOnRejectionBurst(Learner $learner, string $reason): void
    {
        $threshold = (int) config('billing.promo.rejections_alert.threshold', 5);
        $window = max(1, (int) config('billing.promo.rejections_alert.window', 600));

        if ($threshold <= 0) {
            return;
        }

        $hits = RateLimiter::hit(self::REJECTIONS_KEY_PREFIX.$learner->id, $window);

        if ($hits >= $threshold && $hits % $threshold === 0) {
            Log::warning('billing.promo_rejected_burst', [
                'learner_id' => $learner->id,
                'rejections' => $hits,
                'window_seconds' => $window,
                'last_reason' => $reason,
            ]);
        }
    }

    /** Codes are stored upper-case; whitespace and dashes typed by the user are ignored. */
    public static function normalize(string $code): string
    {
        $stripped = preg_replace('/[\s\-]+/u', '', trim($code)) ?? '';

        return Str::upper($stripped);
    }

    /** A fresh code that does not collide with an existing one (for admin creation). */
    public function generateCode(): string
    {
        do {
            $code = Str::upper(Str::random(self::CODE_LENGTH));
        } while (PromoCode::query()->where('code', $code)->exists());

        return $code;
    }
}
