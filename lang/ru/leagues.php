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

    // DM to the winners of a confirmed month (MonthWinnerSender, docs/13 §7.6); :month — "ноябрь 2026", :prize — the winner's prize.
    'month' => [
        'names' => [
            1 => 'январь',
            2 => 'февраль',
            3 => 'март',
            4 => 'апрель',
            5 => 'май',
            6 => 'июнь',
            7 => 'июль',
            8 => 'август',
            9 => 'сентябрь',
            10 => 'октябрь',
            11 => 'ноябрь',
            12 => 'декабрь',
        ],
        'label' => ':name :year',
        'points' => ':count балл|:count балла|:count баллов',
        'won' => '🏆 Поздравляем! Вы заняли :place-е место в рейтинге за :month — :points. Ваш приз: :prize. Мы свяжемся с вами в Telegram, чтобы вручить его.',
        'won_plain' => '🏆 Поздравляем! Вы заняли :place-е место в рейтинге за :month — :points.',
    ],

    'open_button' => 'Открыть лигу',

];
