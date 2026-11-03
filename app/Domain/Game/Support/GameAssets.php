<?php

declare(strict_types=1);

namespace App\Domain\Game\Support;

/**
 * The whitelist of the names of the game's assets in the Mini App (docs/18 §12.2.6: `game_items.asset` is a name, never a URL).
 * The pictures themselves arrive with G2.6 / G2.7c (`resources/twa/src/assets/game/{name}.*`, transparent background, both themes);
 * until then the client draws a placeholder for a name it does not have. Whoever adds an item adds its name here first.
 */
final class GameAssets
{
    public const NAMES = [
        'camel_dromedary', 'camel_bactrian', 'camel_golden',
        'carpet_plain', 'carpet_bukhara',
        'tent_nomad', 'tent_silk',
        'frame_plain', 'frame_ornament',
        'booster_hint', 'booster_extra_flask', 'booster_second_chance',
    ];

    public static function has(string $name): bool
    {
        return in_array($name, self::NAMES, true);
    }
}
