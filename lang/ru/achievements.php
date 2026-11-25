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
        'first_friend' => ['title' => 'Первый друг', 'description' => 'Появился первый друг в Muhrla'],
        'game_first_boss' => ['title' => 'Хранитель побеждён', 'description' => 'Первая победа над боссом города в игре «Караван»'],
    ],

    // Texts of the share card (docs/36 §2б): the small capitals over the title and the tagline in the footer. The brand is
    // config('app.brand'), not a line here.
    'card' => [
        'label' => 'Достижение',
        'tagline' => 'Учим английский в Telegram',
        // The card of the brand: the photo of a shared invitation (docs/36 §2б, A4); :trial is the length of the welcome trial.
        'invite' => [
            'label' => 'Приглашение',
            'title' => 'Учим английский в Telegram',
            'description' => 'Первые :trial Pro бесплатно',
            'description_plain' => 'Слова, уроки и игра каждый день',
        ],
    ],

    // DM with the card; :title / :description from `items`.
    'caption' => "🏆 Новое достижение: :title\n:description\n\nПоделитесь им с друзьями — перешлите это сообщение или нажмите кнопку ниже.",
    'caption_no_link' => "🏆 Новое достижение: :title\n:description\n\nПерешлите это сообщение друзьям, чтобы похвастаться.",
    'share_button' => 'Поделиться',
    'share_text' => 'Я получил(а) достижение «:title» в Muhrla! Учим английский в Telegram.',

];
