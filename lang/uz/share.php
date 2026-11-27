<?php

declare(strict_types=1);

/*
 * Bot orqali yuboriladigan xabar (docs/36 §1в): Mini App tayyorlagan xabar (`WebApp.shareMessage`). Matn faqat shu yerdan,
 * mijozdan matn qabul qilinmaydi. Izohda havola va o'quvchi ismi yo'q: havola xabar ostidagi tugmada.
 */
return [
    'button' => [
        'start' => "🚀 O'rganishni boshlash",
        'add_friend' => "🤝 Do'st bo'lish",
    ],

    'caption' => [
        'achievement' => "🏆 Men Muhrla'da «:title» yutug'ini oldim! Telegramda ingliz tilini o'rganamiz.",
        'invite' => "Muhrla'da ingliz tilini o'rganyapman — qo'shiling, birinchi :trial Pro bepul!",
        'invite_plain' => "Muhrla'da ingliz tilini o'rganyapman — qo'shiling!",
        'friend_invite' => "Muhrla'da do'st bo'laylik: seriyalarni solishtiramiz va musobaqa qilamiz!",
        'game_city' => "Men Muhrla'ning «Karvon» o'yinida yetib keldim: :city! Telegramda ingliz tilini o'rganamiz.",
    ],

    'title' => [
        'achievement' => "«:title» yutug'i",
        'invite' => "Muhrla'ga taklif qilish",
        'friend_invite' => "Muhrla'da do'st bo'lish",
        'game_city' => 'Men yetib keldim: :city',
    ],

    // Stories (docs/40 §2.4): 9:16 kartochka matnlari va izoh. `text` — havolasiz ibora: server « · <havola>» ni o'zi qo'shadi va
    // izohni 200 belgida ushlab turadi.
    'story' => [
        'days' => ':count kun',
        'cards' => ':count ta kartochka',
        'streak' => [
            'title' => ':days ketma-ket',
            'description' => "Muhrla'da ingliz tilini o'rganyapman — har kuni :cards",
            'text' => "Muhrla'da :days ketma-ket",
        ],
        'achievement' => [
            'text' => "Muhrla'da «:title» yutug'i",
        ],
    ],
];
