<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Support\DmLearnerResolver;
use App\Jobs\SendAchievementJob;
use App\Models\Achievement;
use App\Models\Learner;
use App\Models\NotificationLog;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the card of a new achievement in a DM (docs/13 §7.4) — the body of {@see SendAchievementJob}.
 *
 * The message is the PNG ({@see AchievementCardRenderer}) with a caption and, when the bot's username is
 * known, a "Share" button that opens Telegram's share dialog with the learner's invite link (a plain URL
 * button: no inline mode is needed). Without a link the caption asks to forward the message.
 *
 * At most once per achievement: a `notifications_log` row of type `achievement` is written BEFORE the
 * Telegram call (as {@see LeagueResultSender}), so a job retried after an ambiguous error only skips.
 * A failure BEFORE the call (the card cannot be drawn) deletes nothing — no row exists yet — and the job
 * is retried; a 403 deletes the row, closes the DMs of the learner and ends the job.
 */
final class AchievementSender
{
    public const NOTIFICATION_TYPE = 'achievement';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly AchievementCardRenderer $cards,
        private readonly ReferralService $referrals,
        private readonly DmLearnerResolver $dm,
    ) {}

    /** @return 'sent'|'forbidden'|'skipped' */
    public function send(int $achievementId): string
    {
        /** @var Achievement|null $achievement */
        $achievement = Achievement::query()->with('learner')->find($achievementId);
        $learner = $achievement?->learner;
        $key = $achievement?->keyEnum();

        if ($achievement === null || $learner === null || $key === null
            || $learner->blocked_at !== null || $learner->dm_blocked_at !== null
        ) {
            return 'skipped';
        }

        $marker = 'achievement:'.$achievement->id;
        $alreadySent = NotificationLog::query()
            ->where('learner_id', $learner->id)
            ->where('type', self::NOTIFICATION_TYPE)
            ->where('payload->key', $marker)
            ->exists();
        if ($alreadySent) {
            return 'skipped';
        }

        $locale = $this->locales->forLearner($learner);
        $path = $this->cards->render($achievement, $locale);
        $link = $this->shareLink($learner);
        $params = ['title' => $key->title($locale), 'description' => $key->description($locale)];

        $log = NotificationLog::query()->create([
            'learner_id' => $learner->id,
            'type' => self::NOTIFICATION_TYPE,
            'payload' => ['key' => $marker],
            'sent_at' => now(),
        ]);

        try {
            $this->telegram->sendPhoto(
                chatId: $learner->telegram_user_id,
                photoPath: $path,
                caption: __($link === null ? 'achievements.caption_no_link' : 'achievements.caption', $params, $locale),
                replyMarkup: $link === null ? null : $this->shareKeyboard($link, $params['title'], $locale),
            );
        } catch (TelegramForbiddenException $e) {
            $log->delete();
            $this->dm->markBlocked($learner->telegram_user_id);
            Log::warning('telegram.achievement_forbidden', ['learner_id' => $learner->id, 'reason' => $e->reason]);

            return 'forbidden';
        }

        return 'sent';
    }

    /** Telegram's share dialog for the learner's invite link with a prefilled text. */
    public function shareUrl(string $link, string $text): string
    {
        return 'https://t.me/share/url?'.http_build_query(['url' => $link, 'text' => $text], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{inline_keyboard: list<list<array{text: string, url: string}>>} */
    private function shareKeyboard(string $link, string $title, string $locale): array
    {
        return ['inline_keyboard' => [[[
            'text' => __('achievements.share_button', [], $locale),
            'url' => $this->shareUrl($link, __('achievements.share_text', ['title' => $title], $locale)),
        ]]]];
    }

    private function shareLink(Learner $learner): ?string
    {
        try {
            return $this->referrals->inviteLink($this->referrals->codeFor($learner));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
