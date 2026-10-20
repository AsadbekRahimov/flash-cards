<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

/**
 * The payment provider did not answer / refused a call we made on the learner's behalf
 * (a checkout link, `editUserStarSubscription`): nothing was changed on our side, the learner
 * may try again later.
 *
 *   provider_unavailable  502
 */
final class ProviderUnavailableException extends BillingException
{
    public const UNAVAILABLE = 'provider_unavailable';

    public static function during(string $operation, string $provider): self
    {
        return new self(self::UNAVAILABLE, "Payment provider [{$provider}] is unavailable ({$operation}); try again later.");
    }

    public function httpStatus(): int
    {
        return 502;
    }
}
