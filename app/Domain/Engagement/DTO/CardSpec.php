<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Domain\Engagement\Services\AchievementCardRenderer;

/**
 * What a share card shows (docs/13 §7.4): the one description {@see AchievementCardRenderer::renderSpec()}
 * draws, shared by the cards of the achievements and of the game ("I got to Samarkand", docs/18 §12.3.4). The texts are already in the
 * language of the receiver - the spec carries no personal data, so the file is cached by what it says.
 */
final readonly class CardSpec
{
    /**
     * @param  string  $key  the name of the file: `[a-z0-9_]`, unique per card (`streak_7`, `boss_3`)
     * @param  string  $palette  the family of colours: `streak` / `words` / `league` / `friend` / `session` / `game`
     * @param  string  $badge  the short mark in the middle of the medal (`7`, `#1`, `BOSS`)
     */
    public function __construct(
        public string $key,
        public string $palette,
        public string $badge,
        public string $title,
        public string $description,
    ) {}
}
