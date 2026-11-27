<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Engagement\DTO\CardSpec;
use App\Domain\Engagement\DTO\StoryShare;
use App\Domain\Engagement\Enums\AchievementKey;
use App\Domain\Engagement\Enums\ShareKind;
use App\Domain\Engagement\Exceptions\ShareException;
use App\Domain\Localization\Services\LocaleResolver;
use App\Models\Achievement;
use App\Models\Learner;
use Throwable;

/**
 * The story a learner shares from the Mini App (docs/40 §2.4, docs/04 A.26): the 9:16 card ({@see StoryCardRenderer}), its public
 * address, the caption and the widget link for `WebApp.shareToStory`. No Bot API call and no cache of its own: the file on the disk,
 * named by what it shows, is the cache.
 *
 * - **The subject** is the learner's own and read from what the request already has: the series is `learners.streak_current` of the
 *   loaded row (no statement; 0 → `share_subject_not_found`), an achievement is the learner's own row by its unique key (one statement;
 *   missing, locked and someone else's are the same 404, as in {@see ShareMessageBuilder}). The invitations and the game have no story.
 * - **The texts** are lines of `lang/{ru,uz}/share.php` in the learner's language with our own parameters (the days, the goal of the
 *   day, the title of the achievement); the caption is the phrase and the invite link `t.me/<bot>?start=ref_<code>` - at most
 *   {@see TEXT_LIMIT} characters (Telegram shows a story caption of up to 200 to everybody; the phrase is cut, never the link).
 * - **The picture** carries no personal data (the number of days, not a name): one file per content and language, shared by every
 *   learner with the same series. No https / the disk is not public / the card could not be drawn → `share_unavailable`: the client
 *   falls back to the message through the bot.
 * - **Analytics:** `share.prepared {kind, subject, via: story}` on every answer.
 */
final class StoryShareService
{
    /** Telegram shows a story caption of up to 200 characters to users without Premium (docs/40 §2.4). */
    public const TEXT_LIMIT = 200;

    /** The widget link is named by the brand; Telegram takes up to 48 characters. */
    private const WIDGET_NAME_LIMIT = 48;

    public function __construct(
        private readonly StoryCardRenderer $stories,
        private readonly AchievementCardRenderer $cards,
        private readonly ReferralService $referrals,
        private readonly LocaleResolver $locales,
        private readonly ProductEventRecorder $events,
    ) {}

    /**
     * @param  string|null  $subject  the achievement key for `achievement`; ignored for `streak`
     *
     * @throws ShareException share_subject_not_found | share_unavailable
     */
    public function prepare(Learner $learner, ShareKind $kind, ?string $subject): StoryShare
    {
        $this->assertCanShare($learner);
        if (! $kind->forStory()) {
            throw ShareException::unavailable('kind_not_available');
        }

        $subject = $kind->needsSubject() ? $subject : null;
        $locale = $this->locales->forLearner($learner);

        // The subject first: "not found" must not depend on the configuration of the bot.
        [$spec, $phrase] = match ($kind) {
            ShareKind::Streak => $this->streak($learner, $locale),
            default => $this->achievement($learner, $subject, $locale),
        };

        $link = $this->referrals->inviteLink($this->referrals->codeFor($learner));
        if ($link === null) {
            throw ShareException::unavailable('bot_username_missing');
        }

        try {
            $path = $this->stories->renderSpec($spec, $locale);
        } catch (Throwable $e) {
            report($e);

            throw ShareException::unavailable('render_failed');
        }

        $url = $this->cards->publicUrl($path);
        if ($url === null) {
            throw ShareException::unavailable('no_public_url');
        }

        $this->events->record(ProductEventName::SharePrepared, [
            'kind' => $kind->value,
            'subject' => $subject,
            'via' => 'story',
        ], learner: $learner);

        return new StoryShare($url, $this->caption($phrase, $link), $link, $this->widgetName());
    }

    /**
     * The card and the phrase of the series: the learner's current series as stored (`StreakService::current()` ran on "Today").
     *
     * @return array{0: CardSpec, 1: string}
     *
     * @throws ShareException share_subject_not_found (a series of 0)
     */
    private function streak(Learner $learner, string $locale): array
    {
        $days = (int) $learner->streak_current;
        if ($days <= 0) {
            throw ShareException::notFound();
        }

        $daysText = trans_choice('share.story.days', $days, ['count' => $days], $locale);
        $goal = max(1, (int) $learner->daily_goal);

        return [
            new CardSpec(
                key: 'streak_'.$days,
                palette: 'streak',
                badge: (string) $days,
                title: __('share.story.streak.title', ['days' => $daysText], $locale),
                description: __('share.story.streak.description', ['cards' => trans_choice('share.story.cards', $goal, ['count' => $goal], $locale)], $locale),
                label: '',
            ),
            __('share.story.streak.text', ['days' => $daysText], $locale),
        ];
    }

    /**
     * @return array{0: CardSpec, 1: string}
     *
     * @throws ShareException share_subject_not_found
     */
    private function achievement(Learner $learner, ?string $subject, string $locale): array
    {
        $key = $subject !== null && preg_match(ShareMessageBuilder::SUBJECT_PATTERN, $subject) === 1 ? AchievementKey::tryFrom($subject) : null;
        if ($key === null) {
            throw ShareException::notFound();
        }

        $owned = Achievement::query()
            ->where('learner_id', $learner->id)
            ->where('key', $key->value)
            ->exists();
        if (! $owned) {
            throw ShareException::notFound();
        }

        $title = $key->title($locale);

        return [
            new CardSpec($key->value, $key->family(), $key->badge(), $title, $key->description($locale), label: ''),
            __('share.story.achievement.text', ['title' => $title], $locale),
        ];
    }

    /** `<phrase> · <link>` within {@see TEXT_LIMIT} characters: a phrase too long for it is cut with "…", the link never. */
    private function caption(string $phrase, string $link): string
    {
        $separator = ' · ';
        $room = self::TEXT_LIMIT - mb_strlen($separator.$link);
        $phrase = trim($phrase);
        if (mb_strlen($phrase) > $room) {
            $phrase = rtrim(mb_substr($phrase, 0, max(0, $room - 1))).'…';
        }

        return $phrase.$separator.$link;
    }

    private function widgetName(): string
    {
        $brand = trim((string) config('app.brand'));

        return mb_substr($brand === '' ? 'Muhrla' : $brand, 0, self::WIDGET_NAME_LIMIT);
    }

    /** @throws ShareException share_unavailable */
    private function assertCanShare(Learner $learner): void
    {
        if (! (bool) config('share.enabled', true)) {
            throw ShareException::unavailable('disabled');
        }
        if ($learner->blocked_at !== null) {
            throw ShareException::unavailable('learner_blocked');
        }
        if (ltrim(trim((string) config('telegram.bot_username')), '@') === '') {
            throw ShareException::unavailable('bot_username_missing');
        }
    }
}
