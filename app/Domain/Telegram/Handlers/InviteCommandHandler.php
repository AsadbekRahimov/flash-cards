<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Billing\Services\ProTrialService;
use App\Domain\Engagement\Services\ReferralService;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Support\DmLearnerResolver;
use App\Domain\Telegram\Support\TeacherDmNotice;
use App\Models\Learner;

/**
 * `/invite` in a DM (docs/13 §7.1 "2C.9", docs/04 B.3): the rule ("a friend counts once they have set up and rated N cards in
 * a day; +M days of Pro at 3 and at 6 counted friends"), the progress to the next reward, the learner's invite link and a
 * "Share" button (`t.me/share/url`). The friend is not rewarded: they have the welcome trial. Private-chat only; groups and
 * teachers get no answer.
 */
final class InviteCommandHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly DmLearnerResolver $learners,
        private readonly TeacherDmNotice $teacherNotice,
        private readonly ReferralService $referrals,
        private readonly ProTrialService $trials,
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
            $this->teacherNotice->send($chatId, $message);

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
        $cards = $overview->activationReviewsPerDay;
        $reward = trans_choice('referrals.days', $overview->milestoneDays, ['count' => $overview->milestoneDays], $locale);

        // The milestone scheme (docs/13 §7.1 "2C.9"): "+N days of Pro at 3 and at 6 counted friends".
        $rule = __('referrals.invite.rule', [
            'cards' => trans_choice('referrals.invite.cards', $cards, ['count' => $cards], $locale),
            'milestones' => $this->enumerate(array_map(static fn (array $m): int => $m['friends'], $overview->milestones), $locale),
            'reward' => $reward,
        ], $locale);
        $link = $overview->link ?? __('referrals.invite.no_link', ['code' => $overview->code], $locale);

        $progress = [];
        if ($overview->invited > 0) {
            $progress[] = __('referrals.invite.stats', ['invited' => $overview->invited], $locale);
        }
        $next = $overview->next;
        $progress[] = match (true) {
            $next === null => __('referrals.invite.done', ['counted' => $overview->counted], $locale),
            $next['remaining'] === 0 => __('referrals.invite.owed', ['counted' => $overview->counted, 'days' => $reward], $locale),
            default => __('referrals.invite.next', [
                'counted' => $overview->counted,
                'target' => $next['friends'],
                'days' => $reward,
                'remaining' => $next['remaining'],
            ], $locale),
        };

        return __('referrals.invite.text', ['rule' => $rule, 'progress' => implode("\n", $progress), 'link' => $link], $locale);
    }

    /**
     * "3 and 6" / "3, 6 and 9" in the learner's language.
     *
     * @param  list<int>  $numbers
     */
    private function enumerate(array $numbers, string $locale): string
    {
        $last = array_pop($numbers);

        return $numbers === [] ? (string) $last : implode(', ', $numbers).' '.__('referrals.invite.and', [], $locale).' '.$last;
    }

    /** @return array<string, mixed>|null */
    private function markup(Learner $learner, string $locale): ?array
    {
        $link = $this->referrals->inviteLink($this->referrals->codeFor($learner));
        if ($link === null) {
            return null;
        }

        // The friend's own gift is the welcome trial: named only while there is one.
        $trial = $this->trials->days();
        $text = $trial > 0
            ? __('referrals.invite.share_text', ['trial' => trans_choice('referrals.days', $trial, ['count' => $trial], $locale)], $locale)
            : __('referrals.invite.share_text_plain', [], $locale);

        $share = 'https://t.me/share/url?'.http_build_query(['url' => $link, 'text' => $text]);

        return ['inline_keyboard' => [[
            ['text' => __('referrals.invite.share_button', [], $locale), 'url' => $share],
        ]]];
    }
}
