<?php

declare(strict_types=1);

return [

    // Tier names, keys = config('leagues.tiers').
    'tiers' => [
        'bronze' => 'Бронза',
        'silver' => 'Серебро',
        'gold' => 'Золото',
        'platinum' => 'Платина',
        'diamond' => 'Алмаз',
    ],

    // Week-result DM (docs/13 §7.3); :tier — the finished week's league, :next_tier — next week's.
    'result' => [
        'promoted' => '🏆 Итоги недели в лиге «:tier»: вы заняли :rank-е место с :points очками и переходите в лигу «:next_tier»! Поздравляем!',
        'stayed' => '🏅 Итоги недели в лиге «:tier»: вы заняли :rank-е место с :points очками и остаётесь в лиге «:tier». На этой неделе — выше!',
        'demoted' => '📉 Итоги недели в лиге «:tier»: :rank-е место, :points очков — на этой неделе вы в лиге «:next_tier». Пара тренировок в день вернут вас наверх.',
    ],

    'open_button' => 'Открыть лигу',

];
