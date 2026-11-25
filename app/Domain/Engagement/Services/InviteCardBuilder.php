<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use App\Domain\Billing\Services\ProTrialService;
use App\Domain\Engagement\DTO\CardSpec;
use RuntimeException;

/**
 * The card of the brand (docs/36 §2б, A4): the photo of a shared invitation (`invite`). The same layout as the achievements -
 * the illustration `invite` (the medal with the first letter of the brand until the designer's file is there), "Learning English
 * in Telegram" and the length of the welcome trial (`BILLING_TRIAL_DAYS`; without a trial, a line about the product). No personal
 * data: one card per language and trial length, shared by every learner.
 */
final class InviteCardBuilder
{
    public const KEY = 'invite';

    public function __construct(
        private readonly AchievementCardRenderer $cards,
        private readonly ProTrialService $trials,
    ) {}

    public function spec(string $locale): CardSpec
    {
        $trial = $this->trials->days();
        $brand = trim((string) config('app.brand'));

        return new CardSpec(
            key: self::KEY,
            palette: 'invite',
            badge: $brand === '' ? '' : mb_strtoupper(mb_substr($brand, 0, 1)),
            title: __('achievements.card.invite.title', [], $locale),
            description: $trial > 0
                ? __('achievements.card.invite.description', ['trial' => trans_choice('referrals.days', $trial, ['count' => $trial], $locale)], $locale)
                : __('achievements.card.invite.description_plain', [], $locale),
            label: __('achievements.card.invite.label', [], $locale),
        );
    }

    /**
     * @return string absolute path of the JPEG on the cards disk
     *
     * @throws RuntimeException GD / the font is not available
     */
    public function render(string $locale): string
    {
        return $this->cards->renderSpec($this->spec($locale), $locale);
    }
}
