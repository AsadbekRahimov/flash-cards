<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Billing\Services\ProTrialService;
use App\Domain\Billing\Support\ProPromises;
use App\Domain\Game\Services\GameAccess;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Practice\Services\PracticeAccess;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Support\TwaScreen;
use App\Models\Learner;

/**
 * "Pro for 14 days is yours — free, until DATE. What Pro gives: • … Invite 3 friends and get 7 more days of Pro: /invite" (docs/13 §7.1
 * "2C.9", docs/36 §4б): one DM after the welcome trial was granted. The number of days is `billing.trial.days`, the date is the end in the
 * learner's time zone, the lines are the first {@see BENEFITS} of {@see ProPromises::top()} - what Pro gives to THIS learner (a line about
 * practice or the game only while it is open to them), the same list the Mini App shows. The notice is one-shot ({@see ProTrialService::consumeNotice()}): only the bot takes it (the Mini App shows the trial from
 * the entitlement), so a second `/start` says nothing again. A notice that has gone stale by the time the person comes back —
 * the trial is over, or a paid / promo subscription replaced it — is taken silently and nothing is sent.
 *
 * It is sent where the language is known and no question is pending: right BEFORE the closing message of the onboarding (so its
 * "Start" button stays last; or on `/start` of a learner who has finished it already) — not before the first step, where the person has not chosen the language yet, and
 * not between a question and its buttons. One main button (docs/36 §3б): in the onboarding the closing message right after it carries
 * "Start the first lesson", so this one has none; on `/start` of a learner who has finished it, "▶️ Start a lesson" (`withButton`). The numbers come from the config (`billing.trial.days`,
 * `referrals.milestones` / `milestone_days`); with no milestones configured the invitation is left out.
 */
final class TrialWelcomeMessenger
{
    /** How many lines of "what Pro gives" (docs/36 §4б: four - longer reads as a wall of text in Telegram). */
    public const BENEFITS = 4;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly ProTrialService $trials,
        private readonly PracticeAccess $practice,
        private readonly GameAccess $game,
        private readonly BotButtons $buttons,
    ) {}

    /**
     * Sends the notice when this call took it; true when a message went out.
     *
     * @param  bool  $withButton  "▶️ Start a lesson" under it: on `/start`, not in the onboarding (its closing message has the button)
     */
    public function announce(Learner $learner, int|string $chatId, bool $withButton = false): bool
    {
        // A learner whose row carries no notice costs no write: pendingNotice() reads the loaded `meta`.
        if ($this->trials->pendingNotice($learner) === null) {
            return false;
        }

        $endsAt = $this->trials->consumeNotice($learner);
        if ($endsAt === null) {
            return false;
        }

        if (! $this->trials->noticeIsCurrent($learner, $endsAt)) {
            return false;
        }

        $locale = $this->locales->forLearner($learner);
        $days = max(1, $this->trials->days());

        /** @var array<int, mixed> $configured */
        $configured = (array) config('referrals.milestones', []);
        $first = $configured === [] ? null : (int) min($configured);
        $reward = max(1, (int) config('referrals.milestone_days', 7));

        $benefits = ProPromises::top(
            self::BENEFITS,
            $locale,
            practiceOpen: $this->practice->state($learner)->showsEntries(),
            gameOpen: $this->game->state($learner)->isAvailable(),
        );

        $params = [
            'days' => trans_choice('referrals.days', $days, ['count' => $days], $locale),
            'date' => $endsAt->setTimezone($learner->timezone)->format('d.m.Y'),
            'benefits' => implode("\n", array_map(static fn (string $line): string => '• '.$line, $benefits)),
        ];

        if ($first !== null && $first > 0) {
            $text = __('billing.trial.welcome', $params + [
                'friends' => trans_choice('referrals.friends_gen', $first, ['count' => $first], $locale),
                'reward' => trans_choice('referrals.days', $reward, ['count' => $reward], $locale),
            ], $locale);
        } else {
            $text = __('billing.trial.welcome_plain', $params, $locale);
        }

        $this->telegram->sendMessage($chatId, $text, replyMarkup: $withButton
            ? $this->buttons->single($chatId, $this->buttons->open(__('buttons.start_lesson', [], $locale), TwaScreen::Today, query: ['from' => 'trial_welcome']))
            : null);

        return true;
    }
}
