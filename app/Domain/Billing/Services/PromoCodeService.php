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
 * (inside SubscriptionService::grantFromPromo); lock order is always
 * promo_codes -> learners, and grantManual locks only learners, so no cycle.
 * `promo_redemptions (promo_code_id, learner_id)` unique is the backstop.
 */
final class PromoCodeService
{
    public const CODE_LENGTH = 10;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly ProductEventRecorder $events,
    ) {}

    /**
     * @throws PromoCodeException promo_not_found | promo_expired | promo_exhausted | promo_already_redeemed | promo_not_applicable
     */
    public function redeem(Learner $learner, string $code, ?CarbonImmutable $now = null): Subscription
    {
        $now ??= CarbonImmutable::now();
        $normalized = self::normalize($code);

        if ($normalized === '') {
            throw PromoCodeException::notFound();
        }

        return DB::transaction(function () use ($learner, $normalized, $now): Subscription {
            /** @var PromoCode|null $promo */
            $promo = PromoCode::query()->where('code', $normalized)->lockForUpdate()->first();

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
