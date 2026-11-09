<?php

declare(strict_types=1);

return [

    // Title and description of every achievement: the TWA list, the share card and the DM use them.
    'items' => [
        'first_session' => ['title' => 'Birinchi qadam', 'description' => 'Birinchi kartochka baholandi'],
        'streak_7' => ['title' => '7 kun ketma-ket', 'description' => 'Bir hafta kun qoldirmay'],
        'streak_30' => ['title' => '30 kun ketma-ket', 'description' => 'Bir oy kun qoldirmay'],
        'streak_100' => ['title' => '100 kun ketma-ket', 'description' => "Yuz kun to'xtovsiz — mustahkam odat"],
        'words_100' => ['title' => "100 ta so'z", 'description' => "100 ta so'z o'rganildi"],
        'words_500' => ['title' => "500 ta so'z", 'description' => "500 ta so'z o'rganildi"],
        'words_1000' => ['title' => "1000 ta so'z", 'description' => "1000 ta so'z o'rganildi"],
        'league_top1' => ['title' => "Liga g'olibi", 'description' => "Hafta yakunida liga guruhida 1-o'rin"],
        'first_friend' => ['title' => "Birinchi do'st", 'description' => "LexiFlow'dagi birinchi do'stingiz"],
        'game_first_boss' => ['title' => 'Qo\'riqchi yengildi', 'description' => "«Karvon» o'yinida shahar qo'riqchisi ustidan birinchi g'alaba"],
    ],

    // Text on the card under the description.
    'card' => [
        'brand' => 'LexiFlow',
        'tagline' => "Telegramda ingliz tilini o'rganamiz",
    ],

    // DM with the card; :title / :description from `items`.
    'caption' => "🏆 Yangi yutuq: :title\n:description\n\nDo'stlaringizga ham ko'rsating: pastdagi tugmani bosing yoki xabarni uzating.",
    'caption_no_link' => "🏆 Yangi yutuq: :title\n:description\n\nMaqtanish uchun bu xabarni do'stlaringizga uzating.",
    'share_button' => 'Ulashish',
    'share_text' => "Men LexiFlow'da «:title» yutug'ini oldim! Telegramda ingliz tilini o'rganamiz.",

];
