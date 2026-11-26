<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The emoji of Pro (docs/31 §5а)
|--------------------------------------------------------------------------
|
| A Pro learner shows one emoji of this set next to the name, like a status of Telegram Premium. `learners.pro_emoji` stores the
| CODE (the key); the emoji itself is read from here. Every emoji is ONE code point with an emoji presentation of its own: no
| variation selector, no ZWJ sequence, no flag, no skin tone - a guard test keeps it so. The order is the order of the picker.
| A code removed from here stops showing (the stored choice reads as none); add new ones at the end.
*/

return [
    'set' => [
        'fire' => '🔥',
        'star' => '⭐',
        'glowing_star' => '🌟',
        'sparkles' => '✨',
        'zap' => '⚡',
        'rocket' => '🚀',
        'gem' => '💎',
        'crown' => '👑',
        'trophy' => '🏆',
        'medal' => '🏅',
        'target' => '🎯',
        'books' => '📚',
        'grad_cap' => '🎓',
        'brain' => '🧠',
        'bulb' => '💡',
        'lion' => '🦁',
        'tiger' => '🐯',
        'fox' => '🦊',
        'panda' => '🐼',
        'owl' => '🦉',
        'eagle' => '🦅',
        'dolphin' => '🐬',
        'unicorn' => '🦄',
        'butterfly' => '🦋',
        'turtle' => '🐢',
        'penguin' => '🐧',
        'dragon' => '🐉',
        'blossom' => '🌸',
        'sunflower' => '🌻',
        'clover' => '🍀',
        'moon' => '🌙',
        'rainbow' => '🌈',
        'wave' => '🌊',
        'cherries' => '🍒',
        'watermelon' => '🍉',
        'soccer' => '⚽',
        'music' => '🎵',
        'art' => '🎨',
    ],
];
