<?php

declare(strict_types=1);

namespace App\Domain\Billing\DTO;

/**
 * Answer of StarsPreCheckoutGuard to a `pre_checkout_query` (docs/13 §2.2, §4.1): `ok`, or a
 * machine-readable `reason` plus the localized text Telegram shows to the payer.
 */
final readonly class PreCheckoutVerdict
{
    private function __construct(
        public bool $ok,
        public ?string $reason,
        public ?string $errorMessage,
        public ?int $paymentOrderId,
    ) {}

    public static function ok(int $paymentOrderId): self
    {
        return new self(true, null, null, $paymentOrderId);
    }

    public static function refuse(string $reason, string $errorMessage, ?int $paymentOrderId = null): self
    {
        return new self(false, $reason, $errorMessage, $paymentOrderId);
    }
}
