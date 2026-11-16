<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Billing\Services\ProTrialService;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Models\Learner;

/**
 * "A week of Pro is yours — free, until DATE. Invite 3 friends and get 7 more days: /invite" (docs/13 §7.1 "2C.9"): one short
 * DM after the welcome trial was granted. The notice is one-shot ({@see ProTrialService::consumeNotice()}): the bot and the
 * Mini App race for it, whoever takes it first tells the learner, the other stays silent — so a learner who opened the Mini
 * App first gets nothing here, and a second `/start` nothing again.
 *
 * It is sent where the language is known and no question is pending: right BEFORE the closing message of the onboarding (so its
 * "Start" button stays last; or on `/start` of a learner who has finished it already) — not before the first step, where the person has not chosen the language yet, and
 * not between a question and its buttons. The numbers come from the config (`billing.trial.days`,
 * `referrals.milestones` / `milestone_days`); with no milestones configured the invitation is left out.
 */
final class TrialWelcomeMessenger
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly ProTrialService $trials,
    ) {}

    /** Sends the notice when this call took it; true when a message went out. */
    public function announce(Learner $learner, int|string $chatId): bool
    {
        // A learner whose row carries no notice costs no write: pendingNotice() reads the loaded `meta`.
        if ($this->trials->pendingNotice($learner) === null) {
            return false;
        }

        $endsAt = $this->trials->consumeNotice($learner);
        if ($endsAt === null) {
            return false;
        }

        $locale = $this->locales->forLearner($learner);
        $days = max(1, $this->trials->days());

        /** @var array<int, mixed> $configured */
        $configured = (array) config('referrals.milestones', []);
        $first = $configured === [] ? null : (int) min($configured);
        $reward = max(1, (int) config('referrals.milestone_days', 7));

        $params = [
            'days' => trans_choice('referrals.days', $days, ['count' => $days], $locale),
            'date' => $endsAt->setTimezone($learner->timezone)->format('d.m.Y'),
        ];

        if ($first !== null && $first > 0) {
            $text = __('billing.trial.welcome', $params + [
                'friends' => trans_choice('referrals.friends_gen', $first, ['count' => $first], $locale),
                'reward' => trans_choice('referrals.days', $reward, ['count' => $reward], $locale),
            ], $locale);
        } else {
            $text = __('billing.trial.welcome_plain', $params, $locale);
        }

        $this->telegram->sendMessage($chatId, $text);

        return true;
    }
}
