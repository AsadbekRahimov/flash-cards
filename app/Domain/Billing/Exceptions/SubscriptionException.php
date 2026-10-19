<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

/**
 * Why a subscription could not be granted or revoked (SubscriptionService).
 *
 *   plan_not_grantable     422  Free is not a subscription; only ranked plans above Free can be granted
 *   until_in_past          422  the requested end is not in the future
 *   subscriber_not_found   404  the learner / organization row does not exist
 *   subscription_not_live  409  revoke / endNow of a subscription that is already expired
 *   not_cancelable         409  cancel of a subscription that is not `active` on an auto-renewing provider
 *   not_resumable          409  resume of a subscription that is not `canceled` or whose period is over
 *   tier_unknown           422  organization tier not in config('billing.org_tiers')
 */
final class SubscriptionException extends BillingException
{
    public const PLAN_NOT_GRANTABLE = 'plan_not_grantable';

    public const UNTIL_IN_PAST = 'until_in_past';

    public const SUBSCRIBER_NOT_FOUND = 'subscriber_not_found';

    public const NOT_LIVE = 'subscription_not_live';

    public const NOT_CANCELABLE = 'not_cancelable';

    public const NOT_RESUMABLE = 'not_resumable';

    public const TIER_UNKNOWN = 'tier_unknown';

    public static function planNotGrantable(string $plan): self
    {
        return new self(self::PLAN_NOT_GRANTABLE, "Plan [{$plan}] cannot be granted as a subscription.");
    }

    public static function untilInPast(): self
    {
        return new self(self::UNTIL_IN_PAST, 'The subscription end must be in the future.');
    }

    public static function subscriberNotFound(string $type, int $id): self
    {
        return new self(self::SUBSCRIBER_NOT_FOUND, "Subscriber {$type}#{$id} does not exist.");
    }

    public static function notLive(int $subscriptionId): self
    {
        return new self(self::NOT_LIVE, "Subscription #{$subscriptionId} is not live and cannot be revoked.");
    }

    public static function notCancelable(int $subscriptionId, string $why): self
    {
        return new self(self::NOT_CANCELABLE, "Subscription #{$subscriptionId} cannot be canceled: {$why}.");
    }

    public static function notResumable(int $subscriptionId, string $why): self
    {
        return new self(self::NOT_RESUMABLE, "Subscription #{$subscriptionId} cannot be resumed: {$why}.");
    }

    public static function tierUnknown(string $tier): self
    {
        return new self(self::TIER_UNKNOWN, "Unknown organization tier [{$tier}].");
    }

    public function httpStatus(): int
    {
        return match ($this->errorCode) {
            self::SUBSCRIBER_NOT_FOUND => 404,
            self::NOT_LIVE, self::NOT_CANCELABLE, self::NOT_RESUMABLE => 409,
            default => 422,
        };
    }
}
