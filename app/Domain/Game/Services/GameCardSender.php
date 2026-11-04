<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Engagement\DTO\CardSpec;
use App\Domain\Engagement\Services\AchievementCardRenderer;
use App\Domain\Engagement\Services\AchievementSender;
use App\Domain\Engagement\Services\ReferralService;
use App\Domain\Game\Support\CityNames;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Support\DmLearnerResolver;
use App\Jobs\SendGameCardJob;
use App\Models\Learner;
use App\Models\NotificationLog;
use App\Models\Stage;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the card of a first win over the boss of a city in a DM (docs/13 §7.4, docs/18 §12.3.4) - the body of {@see SendGameCardJob}.
 * The picture is "I got to {city}" ({@see AchievementCardRenderer::renderSpec()}), the caption and the "Share" button are the ones of
 * the achievements' DM ({@see AchievementSender::shareUrl()} with the learner's invite link).
 *
 * At most once per learner and city: a `notifications_log` row of type `game_card` with `payload.key = boss:{stage_id}` is written
 * BEFORE the call to Telegram (as {@see AchievementSender}); a failure before the call leaves no row and the job is retried, a 403
 * deletes the row, closes the DMs of the learner and ends the job.
 */
final class GameCardSender
{
    public const NOTIFICATION_TYPE = 'game_card';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly AchievementCardRenderer $cards,
        private readonly AchievementSender $achievements,
        private readonly ReferralService $referrals,
        private readonly DmLearnerResolver $dm,
    ) {}

    /** @return 'sent'|'forbidden'|'skipped' */
    public function sendBossCard(int $learnerId, int $stageId): string
    {
        /** @var Learner|null $learner */
        $learner = Learner::query()->find($learnerId);
        $stage = Stage::query()->find($stageId);
        if ($learner === null || $stage === null || $learner->blocked_at !== null || $learner->dm_blocked_at !== null) {
            return 'skipped';
        }

        $marker = 'boss:'.$stage->id;
        $alreadySent = NotificationLog::query()
            ->where('learner_id', $learner->id)
            ->where('type', self::NOTIFICATION_TYPE)
            ->where('payload->key', $marker)
            ->exists();
        if ($alreadySent) {
            return 'skipped';
        }

        $locale = $this->locales->forLearner($learner);
        $params = ['city' => CityNames::toward($stage->number, $locale)];
        $path = $this->cards->renderSpec(new CardSpec(
            key: 'boss_'.$stage->number,
            palette: 'game',
            badge: 'BOSS',
            title: __('game.card.title', $params, $locale),
            description: __('game.card.description', [], $locale),
        ), $locale);
        $share = $this->share($learner, $stage->number);

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
                caption: __($share === null ? 'game.card.caption_no_link' : 'game.card.caption', $params, $locale),
                replyMarkup: $share === null ? null : ['inline_keyboard' => [[[
                    'text' => __('game.card.share_button', [], $locale),
                    'url' => $this->achievements->shareUrl($share['link'], $share['text']),
                ]]]],
            );
        } catch (TelegramForbiddenException $e) {
            $log->delete();
            $this->dm->markBlocked($learner->telegram_user_id);
            Log::warning('telegram.game_card_forbidden', ['learner_id' => $learner->id, 'reason' => $e->reason]);

            return 'forbidden';
        }

        return 'sent';
    }

    /**
     * What the "Share" button of the card of the boss of city number `$stageNumber` shares: the learner's invite link and the text, in the
     * learner's language - the very pair of the DM card and of `summary.boss.share` of the TWA (docs/18 §12.9, one rule). `null` while the
     * bot has no username (no link, nothing to share). `title` is the heading of the card ("I got to Samarkand").
     *
     * @return array{link: string, text: string, title: string}|null
     */
    public function share(Learner $learner, int $stageNumber): ?array
    {
        $link = $this->shareLink($learner);
        if ($link === null) {
            return null;
        }
        $locale = $this->locales->forLearner($learner);

        $params = ['city' => CityNames::toward($stageNumber, $locale)];

        return ['link' => $link, 'text' => __('game.card.share_text', $params, $locale), 'title' => __('game.card.title', $params, $locale)];
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
