<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use App\Domain\Billing\Services\ProTrialService;
use App\Domain\Engagement\Enums\AchievementKey;
use App\Domain\Engagement\Enums\ShareKind;
use App\Domain\Engagement\Exceptions\ShareException;
use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Services\GameAccess;
use App\Domain\Game\Services\GameCardSender;
use App\Domain\Game\Support\CityNames;
use App\Domain\Telegram\Services\BotButtons;
use App\Models\Achievement;
use App\Models\GameCityProgress;
use App\Models\Learner;
use App\Models\Stage;
use Closure;
use Throwable;

/**
 * The content of a message shared "through the bot" (docs/36 §1в): ONE InlineQueryResult for `savePreparedInlineMessage` (and, later, for
 * `answerInlineQuery`, H5). The core of "share" - everything a stranger will see under "via @bot" is decided here, so:
 *
 * - the subject is looked up only among the learner's OWN rows: an achievement they unlocked, a city whose boss they beat. A key that
 *   does not exist, a locked one and someone else's are the same {@see ShareException::notFound()};
 * - every text is a line of `lang/{ru,uz}/share.php` with parameters of our own (the title of an achievement, the name of a city, the
 *   length of the trial) - nothing from the client, no learner's name; no `parse_mode`, so nothing in a text is markup;
 * - one URL button: the learner's invite link `t.me/<bot>?start=ref_<code>` ({@see ReferralService::inviteLink()}; `f_<code>` for a
 *   friend invitation) - the referral is counted by `/start` as today;
 * - the photo is only the HTTPS address of our own card ({@see AchievementCardRenderer::publicUrl()}); without one (the disk is not
 *   public, APP_URL is not https, the card could not be drawn) the result is an `article` with the same text and button.
 */
final class ShareMessageBuilder
{
    /** Subjects are `[a-z0-9_]`: achievement keys and stage ids; anything else is "not found", never a query. */
    public const SUBJECT_PATTERN = '/^[a-z0-9_]{1,40}$/';

    /** The size of every card (1080×1080 JPEG). */
    private const PHOTO_SIZE = AchievementCardRenderer::SIZE;

    /**
     * The keyboard is not for a private chat with the bot: a shared message goes anywhere, and a `web_app` button would be refused there
     * ({@see BotButtons::keyboard()} turns one into a link outside a private chat; the only button here is a URL anyway).
     */
    private const NOT_A_PRIVATE_CHAT = 0;

    public function __construct(
        private readonly AchievementCardRenderer $cards,
        private readonly InviteCardBuilder $inviteCard,
        private readonly GameCardSender $gameCards,
        private readonly GameAccess $game,
        private readonly ReferralService $referrals,
        private readonly FriendshipService $friends,
        private readonly ProTrialService $trials,
        private readonly BotButtons $buttons,
    ) {}

    /**
     * @param  string  $locale  the language of the texts and of the card (`ru` / `uz`)
     * @return array<string, mixed> an `InlineQueryResultPhoto` (`type`, `id`, `photo_url`, `thumbnail_url`, `photo_width`, `photo_height`,
     *                              `title`, `caption`, `reply_markup`) or an `InlineQueryResultArticle` (`type`, `id`, `title`,
     *                              `input_message_content.message_text`, `reply_markup`)
     *
     * @throws ShareException share_subject_not_found | share_unavailable
     */
    public function build(Learner $learner, ShareKind $kind, ?string $subject, string $locale): array
    {
        if (! $kind->isAvailable()) {
            throw ShareException::unavailable('kind_not_available');
        }

        // The subject first: "not found" must not depend on the configuration of the bot.
        [$title, $caption, $card] = match ($kind) {
            ShareKind::Achievement => $this->achievement($learner, $subject, $locale),
            ShareKind::GameCity => $this->gameCity($learner, $subject, $locale),
            ShareKind::Invite => $this->invite($locale),
            ShareKind::FriendInvite => [
                __('share.title.friend_invite', [], $locale),
                __('share.caption.friend_invite', [], $locale),
                fn (): string => $this->inviteCard->render($locale),
            ],
            // Not available yet: refused above.
            ShareKind::LeagueWeek, ShareKind::MonthPlace => throw ShareException::unavailable('kind_not_available'),
        };

        $link = $kind === ShareKind::FriendInvite
            ? $this->friends->inviteLink($learner)
            : $this->referrals->inviteLink($this->referrals->codeFor($learner));
        if ($link === null) {
            throw ShareException::unavailable('bot_username_missing');
        }

        $button = __($kind === ShareKind::FriendInvite ? 'share.button.add_friend' : 'share.button.start', [], $locale);
        $markup = $this->buttons->keyboard(self::NOT_A_PRIVATE_CHAT, [[$this->buttons->url($button, $link)]]);
        $photo = $this->photoUrl($card);

        // Stable for the same content, so an inline answer (H5) and a cached prepared message agree; ≤ 64 bytes (Telegram's limit).
        $id = $kind->value.'-'.substr(hash('sha256', json_encode([$kind->value, $subject, $locale, $title, $caption, $link, $photo], JSON_THROW_ON_ERROR)), 0, 32);

        if ($photo === null) {
            return [
                'type' => 'article',
                'id' => $id,
                'title' => $title,
                'input_message_content' => ['message_text' => $caption],
                'reply_markup' => $markup,
            ];
        }

        return [
            'type' => 'photo',
            'id' => $id,
            'photo_url' => $photo,
            'thumbnail_url' => $photo,
            'photo_width' => self::PHOTO_SIZE,
            'photo_height' => self::PHOTO_SIZE,
            'title' => $title,
            'caption' => $caption,
            'reply_markup' => $markup,
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: Closure(): string}
     *
     * @throws ShareException share_subject_not_found
     */
    private function achievement(Learner $learner, ?string $subject, string $locale): array
    {
        $key = $this->validSubject($subject) ? AchievementKey::tryFrom($subject) : null;
        if ($key === null) {
            throw ShareException::notFound();
        }

        /** @var Achievement|null $achievement */
        $achievement = Achievement::query()
            ->where('learner_id', $learner->id)
            ->where('key', $key->value)
            ->first();
        if ($achievement === null) {
            throw ShareException::notFound();
        }

        $title = $key->title($locale);

        return [
            __('share.title.achievement', ['title' => $title], $locale),
            __('share.caption.achievement', ['title' => $title], $locale),
            fn (): string => $this->cards->render($achievement, $locale),
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: Closure(): string}
     *
     * @throws ShareException share_subject_not_found | share_unavailable (the game is closed for the learner)
     */
    private function gameCity(Learner $learner, ?string $subject, string $locale): array
    {
        // A stage id: digits, at most 18 (a longer number does not fit an integer).
        if (! $this->validSubject($subject) || preg_match('/^[1-9][0-9]{0,17}$/', $subject) !== 1) {
            throw ShareException::notFound();
        }

        try {
            $this->game->assertAvailable($learner);
        } catch (GameException) {
            throw ShareException::unavailable('game_unavailable');
        }

        $progress = GameCityProgress::query()
            ->where('learner_id', $learner->id)
            ->where('stage_id', (int) $subject)
            ->whereNotNull('boss_defeated_at')
            ->first();
        $number = $progress === null ? null : Stage::query()->whereKey($progress->stage_id)->value('number');
        if (! is_numeric($number)) {
            throw ShareException::notFound();
        }
        $number = (int) $number;

        $city = CityNames::toward($number, $locale);

        return [
            __('share.title.game_city', ['city' => $city], $locale),
            __('share.caption.game_city', ['city' => $city], $locale),
            fn (): string => $this->cards->renderSpec($this->gameCards->cardSpec($number, $locale), $locale),
        ];
    }

    /** @return array{0: string, 1: string, 2: Closure(): string} */
    private function invite(string $locale): array
    {
        $trial = $this->trials->days();

        return [
            __('share.title.invite', [], $locale),
            $trial > 0
                ? __('share.caption.invite', ['trial' => trans_choice('referrals.days', $trial, ['count' => $trial], $locale)], $locale)
                : __('share.caption.invite_plain', [], $locale),
            fn (): string => $this->inviteCard->render($locale),
        ];
    }

    /** @phpstan-assert-if-true string $subject */
    private function validSubject(?string $subject): bool
    {
        return $subject !== null && preg_match(self::SUBJECT_PATTERN, $subject) === 1;
    }

    /**
     * The public HTTPS address of the card, or null: then the message goes without a photo. A card that cannot be drawn (GD, a font)
     * is reported, not fatal - the text and the button still go.
     *
     * @param  Closure(): string  $render
     */
    private function photoUrl(Closure $render): ?string
    {
        try {
            return $this->cards->publicUrl($render());
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
