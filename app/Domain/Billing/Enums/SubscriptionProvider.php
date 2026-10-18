<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * `subscriptions.provider` (docs/12 §1.9). Phase 1 writes only `manual` and
 * `promo`; the payment providers are listed so the state machine (grace on
 * renewal failure) is already correct when they arrive (docs/12 §6).
 */
enum SubscriptionProvider: string
{
    case Manual = 'manual';
    case Promo = 'promo';
    case TelegramStars = 'telegram_stars';
    case Payme = 'payme';
    case Click = 'click';

    /**
     * Whether a period end is followed by a grace period (`past_due`) while a
     * renewal is retried. Manual and promo grants have a fixed end: they expire
     * at once (docs/12 §5.6).
     */
    public function hasGrace(): bool
    {
        return ! in_array($this, [self::Manual, self::Promo], true);
    }
}
