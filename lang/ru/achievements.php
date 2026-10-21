<?php

declare(strict_types=1);

return [

    // Title and description of every achievement: the TWA list, the share card and the DM use them.
    'items' => [
        'first_session' => ['title' => 'Первый шаг', 'description' => 'Первая оценённая карточка'],
        'streak_7' => ['title' => 'Серия 7 дней', 'description' => 'Неделя занятий без пропусков'],
        'streak_30' => ['title' => 'Серия 30 дней', 'description' => 'Месяц занятий без пропусков'],
        'streak_100' => ['title' => 'Серия 100 дней', 'description' => 'Сто дней подряд — железная привычка'],
        'words_100' => ['title' => '100 слов', 'description' => 'Выучено 100 слов'],
        'words_500' => ['title' => '500 слов', 'description' => 'Выучено 500 слов'],
        'words_1000' => ['title' => '1000 слов', 'description' => 'Выучено 1000 слов'],
        'league_top1' => ['title' => 'Победитель лиги', 'description' => 'Первое место в группе лиги за неделю'],
        'first_friend' => ['title' => 'Первый друг', 'description' => 'Появился первый друг в LexiFlow'],
    ],

    // Text on the card under the description.
    'card' => [
        'brand' => 'LexiFlow',
        'tagline' => 'Учим английский в Telegram',
    ],

    // DM with the card; :title / :description from `items`.
    'caption' => "🏆 Новое достижение: :title\n:description\n\nПоделитесь им с друзьями — перешлите это сообщение или нажмите кнопку ниже.",
    'caption_no_link' => "🏆 Новое достижение: :title\n:description\n\nПерешлите это сообщение друзьям, чтобы похвастаться.",
    'share_button' => 'Поделиться',
    'share_text' => 'Я получил(а) достижение «:title» в LexiFlow! Учим английский в Telegram.',

];
