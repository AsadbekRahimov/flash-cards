<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Billing\DTO\BillingCatalog;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\LearnerBillingService;
use App\Domain\Billing\Services\RenewalReminderSender;
use App\Domain\Billing\Services\TrialOfferSender;
use App\Domain\Billing\Support\PriceFormatter;
use App\Domain\Billing\Support\ProPromises;
use App\Domain\Game\Services\GameAccess;
use App\Domain\Practice\Services\PracticeAccess;
use App\Domain\Telegram\Support\TwaScreen;
use App\Models\Learner;

/**
 * The parts of a bot message that sells Pro to a learner after (or at the end of) the welcome trial (docs/34 §2б.4, §2д): the reminder
 * three days before the end ({@see RenewalReminderSender}) and the offers after it
 * ({@see TrialOfferSender}) say the same things the same way.
 *
 *  - "What Pro gives": the first {@see BENEFITS} lines of {@see ProPromises::top()} for THIS learner (practice / the game only while
 *    open to them), as in the welcome of the trial;
 *  - the prices from the catalog at the moment of sending (never from the text): "from X a month" (the month of every currency on
 *    sale) and "−N %" of the longer periods by `saving_pct`;
 *  - the buttons: the buy rows of `/pro` ({@see ProScreen::buyRows()}), "Invite friends" and - under the offers - "Do not send offers"
 *    (`bill1:offers_off`).
 */
final class ProPitch
{
    /** How many lines of "what Pro gives" (docs/36 §4б: four - longer reads as a wall of text). */
    public const BENEFITS = 4;

    /** `callback_data` of "Do not send offers" (docs/34 §2б.4). */
    public const OPT_OUT_DATA = 'bill1:offers_off';

    public function __construct(
        private readonly ProScreen $screen,
        private readonly BotButtons $buttons,
        private readonly CheckoutService $checkout,
        private readonly LearnerBillingService $billing,
        private readonly PracticeAccess $practice,
        private readonly GameAccess $game,
    ) {}

    /**
     * What this learner can buy now. `selling` = `paywall.mode = purchase` (lowered when no provider serves learners) and a non-empty
     * catalog; otherwise the message says "Pro is coming" instead of prices.
     *
     * @return array{selling: bool, catalog: BillingCatalog}
     */
    public function offer(Learner $learner): array
    {
        $catalog = $this->checkout->catalog($learner);

        return [
            'selling' => $this->billing->paywall($learner)['mode'] === 'purchase' && ! $catalog->isEmpty(),
            'catalog' => $catalog,
        ];
    }

    public function practiceOpen(Learner $learner): bool
    {
        return $this->practice->state($learner)->showsEntries();
    }

    /** "What Pro gives:\n• …" (four lines), or '' when there is nothing to list. */
    public function benefits(Learner $learner, string $locale): string
    {
        $lines = ProPromises::top(
            self::BENEFITS,
            $locale,
            practiceOpen: $this->practiceOpen($learner),
            gameOpen: $this->game->state($learner)->isAvailable(),
        );

        if ($lines === []) {
            return '';
        }

        return (string) __('billing.trial_offer.benefits', [
            'benefits' => implode("\n", array_map(static fn (string $line): string => '• '.$line, $lines)),
        ], $locale);
    }

    /**
     * "Muhrla Pro — from 150 ⭐ / 29 000 sum a month. Longer is cheaper: 3 months −13 %, 1 year −30 %." from the catalog; '' for an
     * empty one. The month line is left out when no month is on sale, the savings when no period saves anything.
     */
    public function prices(BillingCatalog $catalog, string $locale): string
    {
        $currencies = [];
        foreach ($catalog->providers as $key) {
            $currency = SubscriptionProvider::tryFrom($key)?->currency();
            if ($currency !== null && ! in_array($currency, $currencies, true)) {
                $currencies[] = $currency;
            }
        }

        $lines = [];
        $savings = [];

        foreach ($catalog->items as $item) {
            if ($item['period'] === 'month') {
                $prices = [];
                foreach ($currencies as $currency) {
                    if (isset($item['prices'][$currency])) {
                        $prices[] = PriceFormatter::format($item['prices'][$currency], $currency, $locale);
                    }
                }

                if ($prices !== []) {
                    $lines[] = (string) __('billing.trial_offer.from_month', ['price' => implode(' / ', $prices)], $locale);
                }
            } elseif ($item['saving_pct'] > 0) {
                $savings[] = (string) __('billing.trial_offer.saving_item', [
                    'period' => __("billing.period.{$item['period']}", [], $locale),
                    'pct' => $item['saving_pct'],
                ], $locale);
            }
        }

        if ($savings !== []) {
            $lines[] = (string) __('billing.trial_offer.savings', ['items' => implode(', ', $savings)], $locale);
        }

        return implode(' ', $lines);
    }

    /**
     * The keyboard: the buy rows of `/pro` while something is for sale, then "Invite friends" (into the Mini App, `from=$from`) and,
     * with `$optOut`, "Do not send offers" last.
     *
     * @return array{inline_keyboard: list<list<array<string, mixed>>>}
     */
    public function keyboard(Learner $learner, string $locale, bool $selling, bool $optOut, string $from): array
    {
        $rows = $selling ? $this->screen->buyRows($learner) : [];

        $extra = [[$this->buttons->open(__('buttons.invite', [], $locale), TwaScreen::Referrals, query: ['from' => $from])]];
        if ($optOut) {
            $extra[] = [$this->buttons->callback(__('buttons.offers_off', [], $locale), self::OPT_OUT_DATA)];
        }

        return ['inline_keyboard' => [...$rows, ...$this->buttons->keyboard($learner->telegram_user_id, $extra)['inline_keyboard']]];
    }
}
