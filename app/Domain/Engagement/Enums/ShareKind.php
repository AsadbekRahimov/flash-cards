<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

use App\Domain\Engagement\Services\ShareMessageBuilder;
use App\Domain\Engagement\Services\StoryShareService;

/**
 * What a learner shares "through the bot" (docs/36 §1в): the kinds of the message {@see ShareMessageBuilder}
 * builds, and of the story {@see StoryShareService} prepares (docs/40 §2.4). `subject` is what the message is about: the key of an
 * unlocked achievement, the id of a beaten city (stage), nothing for the invitations and the series. `LeagueWeek` and `MonthPlace`
 * are named by docs/36 but come later (N-tasks, A5): not {@see self::isAvailable()} yet - the API refuses them as an unknown kind.
 * `Streak` exists for the story only: `POST /share/prepare` refuses it the same way.
 */
enum ShareKind: string
{
    /** The card of an achievement; subject = the achievement key. */
    case Achievement = 'achievement';

    /** An invitation (referral `ref_<code>`), no subject; the photo is the card of the brand. */
    case Invite = 'invite';

    /** "Let's be friends", link `f_<code>`, no subject. */
    case FriendInvite = 'friend_invite';

    /** The card of a city of the game "Caravan" whose boss the learner beat; subject = the stage id. */
    case GameCity = 'game_city';

    /** The learner's current series of days (docs/40 §2.4), no subject; a story only. */
    case Streak = 'streak';

    /** The result of a league week; later (N-tasks). */
    case LeagueWeek = 'league_week';

    /** A place 1-3 of the monthly rating; after A5. */
    case MonthPlace = 'month_place';

    /** A kind of the message through the bot (`POST /share/prepare`). */
    public function isAvailable(): bool
    {
        return match ($this) {
            self::LeagueWeek, self::MonthPlace, self::Streak => false,
            default => true,
        };
    }

    /** A kind of the story (`POST /share/story`): the series and an achievement; the invitations have no story. */
    public function forStory(): bool
    {
        return match ($this) {
            self::Streak, self::Achievement => true,
            default => false,
        };
    }

    public function needsSubject(): bool
    {
        return match ($this) {
            self::Invite, self::FriendInvite, self::Streak => false,
            default => true,
        };
    }

    /** @return list<self> */
    public static function available(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $kind): bool => $kind->isAvailable()));
    }

    /** @return list<self> */
    public static function storyKinds(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $kind): bool => $kind->forStory()));
    }
}
