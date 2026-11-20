<?php

declare(strict_types=1);

return [

    // Title and description of every achievement: the TWA list, the share card and the DM use them.
    'items' => [
        'first_session' => ['title' => 'Birinchi qadam', 'description' => 'Birinchi kartochkani baholadingiz'],
        'streak_7' => ['title' => '7 kunlik ketma-ketlik', 'description' => 'Bir hafta kun qoldirmay shug\'ullanish'],
        'streak_30' => ['title' => '30 kunlik ketma-ketlik', 'description' => 'Bir oy kun qoldirmay shug\'ullanish'],
        'streak_100' => ['title' => '100 kunlik ketma-ketlik', 'description' => 'Ketma-ket yuz kun — mustahkam odat'],
        'words_100' => ['title' => "100 ta so'z", 'description' => "100 ta so'z o'rganildi"],
        'words_500' => ['title' => "500 ta so'z", 'description' => "500 ta so'z o'rganildi"],
        'words_1000' => ['title' => "1000 ta so'z", 'description' => "1000 ta so'z o'rganildi"],
        'league_top1' => ['title' => "Liga g'olibi", 'description' => "Hafta yakunida liga guruhingizda birinchi o'rinni egalladingiz"],
        'first_friend' => ['title' => "Birinchi do'st", 'description' => "LexiFlow'da birinchi do'st paydo bo'ldi"],
        'game_first_boss' => ['title' => 'Qo\'riqchi yengildi', 'description' => "«Karvon» o'yinida shahar qo'riqchisi ustidan birinchi g'alaba"],
    ],

    // Text on the card under the description.
    'card' => [
        'brand' => 'LexiFlow',
        'tagline' => "Telegramda ingliz tilini o'rganamiz",
    ],

    // DM with the card; :title / :description from `items`.
    'caption' => "🏆 Yangi yutuq: :title\n:description\n\nDo'stlaringiz bilan ulashing — bu xabarni uzating yoki pastdagi tugmani bosing.",
    'caption_no_link' => "🏆 Yangi yutuq: :title\n:description\n\nYutug'ingizni do'stlaringiz bilan bo'lishish uchun ularga shu xabarni yuboring.",
    'share_button' => 'Ulashish',
    'share_text' => "Men LexiFlow'da «:title» yutug'ini oldim! Telegramda ingliz tilini o'rganamiz.",

];
