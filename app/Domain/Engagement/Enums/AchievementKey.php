<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

/**
 * The achievements a learner can unlock (docs/13 §1.5, §7.4; `achievements.key`). The value is the
 * stored key; the threshold of the counting ones lives here, next to the key it belongs to.
 */
enum AchievementKey: string
{
    case FirstSession = 'first_session';
    case Streak7 = 'streak_7';
    case Streak30 = 'streak_30';
    case Streak100 = 'streak_100';
    case Words100 = 'words_100';
    case Words500 = 'words_500';
    case Words1000 = 'words_1000';
    case LeagueTop1 = 'league_top1';
    case FirstFriend = 'first_friend';

    /** `streak` / `words` have a threshold and a progress; the others are one-off events. */
    public function family(): string
    {
        return match ($this) {
            self::Streak7, self::Streak30, self::Streak100 => 'streak',
            self::Words100, self::Words500, self::Words1000 => 'words',
            self::FirstSession => 'session',
            self::LeagueTop1 => 'league',
            self::FirstFriend => 'friend',
        };
    }

    /** The number to reach for a counting key, null for the one-off ones. */
    public function threshold(): ?int
    {
        return match ($this) {
            self::Streak7 => 7,
            self::Streak30 => 30,
            self::Streak100 => 100,
            self::Words100 => 100,
            self::Words500 => 500,
            self::Words1000 => 1000,
            default => null,
        };
    }

    /** The short mark in the middle of the share card. */
    public function badge(): string
    {
        return match ($this) {
            self::FirstSession => '1',
            self::Streak7 => '7',
            self::Streak30 => '30',
            self::Streak100 => '100',
            self::Words100 => '100',
            self::Words500 => '500',
            self::Words1000 => '1000',
            self::LeagueTop1 => '#1',
            self::FirstFriend => '+1',
        };
    }

    public function title(string $locale): string
    {
        return __('achievements.items.'.$this->value.'.title', [], $locale);
    }

    public function description(string $locale): string
    {
        return __('achievements.items.'.$this->value.'.description', [], $locale);
    }
}
