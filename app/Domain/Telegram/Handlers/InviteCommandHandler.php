<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Engagement\Services\ReferralService;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Support\DmLearnerResolver;
use App\Models\Learner;

/**
 * `/invite` in a DM (docs/13 §7.1, docs/04 B.3): the learner's invite link, the rule ("your friend
 * studies N days — you both get M days of Pro"), how many friends came / were activated / rewarded,
 * and a "Share" button (`t.me/share/url`). Private-chat only; groups and teachers get no answer.
 */
final class InviteCommandHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly DmLearnerResolver $learners,
        private readonly ReferralService $referrals,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        $message = $update['message'] ?? null;
        if (! is_array($message)) {
            return false;
        }

        return ($message['chat']['type'] ?? '') === 'private'
            && preg_match('~^/invite(?:@\w+)?(\s|$)~', (string) ($message['text'] ?? '')) === 1;
    }

    // Private-chat only command; the Group Lock does not apply.
    public function requiresActiveGroup(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        /** @var array<string, mixed> $message */
        $message = $update['message'];
        $chatId = (int) $message['chat']['id'];

        $learner = $this->learners->resolve(is_array($message['from'] ?? null) ? $message['from'] : []);
        if ($learner === null) {
            return;
        }

        $locale = $this->locales->forLearner($learner);

        if ($learner->blocked_at !== null) {
            $this->telegram->sendMessage($chatId, __('referrals.blocked', [], $locale));

            return;
        }

        $this->telegram->sendMessage($chatId, $this->text($learner, $locale), replyMarkup: $this->markup($learner, $locale));
    }

    private function text(Learner $learner, string $locale): string
    {
        $overview = $this->referrals->overview($learner, limit: 0);
        $rule = __('referrals.invite.rule', [
            'days' => $overview->activationDays,
            'reviews' => $overview->activationReviewsPerDay,
            'reward' => $overview->rewardDays,
        ], $locale);
        $link = $overview->link ?? __('referrals.invite.no_link', ['code' => $overview->code], $locale);

        return __('referrals.invite.text', ['rule' => $rule, 'link' => $link], $locale)
            ."\n\n".__('referrals.invite.stats', [
                'invited' => $overview->invited,
                'activated' => $overview->activated,
                'rewarded' => $overview->rewarded,
            ], $locale);
    }

    /** @return array<string, mixed>|null */
    private function markup(Learner $learner, string $locale): ?array
    {
        $link = $this->referrals->inviteLink($this->referrals->codeFor($learner));
        if ($link === null) {
            return null;
        }

        $share = 'https://t.me/share/url?'.http_build_query([
            'url' => $link,
            'text' => __('referrals.invite.share_text', [], $locale),
        ]);

        return ['inline_keyboard' => [[
            ['text' => __('referrals.invite.share_button', [], $locale), 'url' => $share],
        ]]];
    }
}
