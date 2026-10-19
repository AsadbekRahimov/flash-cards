<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

/**
 * Why a subscription could not be granted or revoked (SubscriptionService).
 *
 *   plan_not_grantable     422  Free is not a subscription; only ranked plans above Free can be granted
 *   until_in_past          422  the requested end is not in the future
 *   subscriber_not_found   404  the learner / organization row does not exist
 *   subscription_not_live  409  revoke of a subscription that is already canceled / expired
 */
final class SubscriptionException extends BillingException
{
    public const PLAN_NOT_GRANTABLE = 'plan_not_grantable';

    public const UNTIL_IN_PAST = 'until_in_past';

    public const SUBSCRIBER_NOT_FOUND = 'subscriber_not_found';

    public const NOT_LIVE = 'subscription_not_live';

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

    public function httpStatus(): int
    {
        return match ($this->errorCode) {
            self::SUBSCRIBER_NOT_FOUND => 404,
            self::NOT_LIVE => 409,
            default => 422,
        };
    }
}
