<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * `subscriptions.provider` / `payment_orders.provider` (docs/12 §1.9, docs/13 §0, §2.1).
 *
 *   manual / promo / reward  — grants without money (admin, promo code, referral milestone reward);
 *   trial                    — the welcome trial of a new learner (docs/13 §7.1 "2C.9"): a grant too, but the one
 *                              grant that is reminded about before it ends (there is something to buy next);
 *   telegram_stars           — the only auto-renewing provider (Telegram charges the renewal
 *                              itself, so a missed renewal gets a grace period);
 *   payme / click            — "pay for a period": no renewal, no grace, reminders instead.
 */
enum SubscriptionProvider: string
{
    case Manual = 'manual';
    case Promo = 'promo';
    case Reward = 'reward';
    case Trial = 'trial';
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

    /**
     * The end of the period is announced to the subscriber beforehand (`subscriptions:remind-expiring`): a paid period
     * that will not renew by itself, and the welcome trial. Manual / promo / reward grants are never reminded.
     */
    public function isReminded(): bool
    {
        return $this->isPaid() || $this === self::Trial;
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
