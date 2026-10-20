<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * `subscriptions.provider` / `payment_orders.provider` (docs/12 §1.9, docs/13 §0, §2.1).
 *
 *   manual / promo / reward  — grants without money (admin, promo code, referral reward);
 *   telegram_stars           — the only auto-renewing provider (Telegram charges the renewal
 *                              itself, so a missed renewal gets a grace period);
 *   payme / click            — "pay for a period": no renewal, no grace, reminders instead.
 */
enum SubscriptionProvider: string
{
    case Manual = 'manual';
    case Promo = 'promo';
    case Reward = 'reward';
    case TelegramStars = 'telegram_stars';
    case Payme = 'payme';
    case Click = 'click';

    /**
     * Whether a period end is followed by a grace period (`past_due`) while the
     * provider retries the renewal. Only Telegram Stars renews; everything else
     * has a fixed end and expires at once (docs/13 §0 "hasGrace").
     */
    public function hasGrace(): bool
    {
        return $this === self::TelegramStars;
    }

    /** The provider charges the next period by itself (cancel / resume make sense). */
    public function isAutoRenewing(): bool
    {
        return $this === self::TelegramStars;
    }

    /** Money changes hands (a payment provider, not a grant). */
    public function isPaid(): bool
    {
        return in_array($this, [self::TelegramStars, self::Payme, self::Click], true);
    }

    /** Currency the provider settles in (minor units: Stars for XTR, tiyin for UZS); null for grants. */
    public function currency(): ?string
    {
        return match ($this) {
            self::TelegramStars => 'XTR',
            self::Payme, self::Click => 'UZS',
            default => null,
        };
    }

    /** @return list<self> */
    public static function paid(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $p): bool => $p->isPaid()));
    }
}
