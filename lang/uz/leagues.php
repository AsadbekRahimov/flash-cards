<?php

declare(strict_types=1);

return [

    'tiers' => [
        'bronze' => 'Bronza',
        'silver' => 'Kumush',
        'gold' => 'Oltin',
        'platinum' => 'Platina',
        'diamond' => 'Olmos',
    ],

    'result' => [
        'promoted' => "🏆 «:tier» ligasida hafta yakuni: siz :points ochko bilan :rank-o'rinni egalladingiz va «:next_tier» ligasiga o'tdingiz! Tabriklaymiz!",
        'stayed' => "🏅 «:tier» ligasida hafta yakuni: siz :points ochko bilan :rank-o'rinni egalladingiz va «:tier» ligasida qolasiz. Bu hafta yanada yuqoriga!",
        'demoted' => "📉 «:tier» ligasida hafta yakuni: :rank-o'rin, :points ochko — bu hafta siz «:next_tier» ligasidasiz. Kuniga bir-ikki mashg'ulot sizni yana yuqoriga qaytaradi.",
    ],

    'month' => [
        'names' => [
            1 => 'yanvar',
            2 => 'fevral',
            3 => 'mart',
            4 => 'aprel',
            5 => 'may',
            6 => 'iyun',
            7 => 'iyul',
            8 => 'avgust',
            9 => 'sentyabr',
            10 => 'oktyabr',
            11 => 'noyabr',
            12 => 'dekabr',
        ],
        'label' => ':year-yil :name',
        'points' => ':count ball',
        'won' => "🏆 Tabriklaymiz! Siz :month reytingida :place-o'rinni egalladingiz — :points. Sovg'angiz: :prize. Uni topshirish uchun Telegram orqali siz bilan bog'lanamiz.",
        'won_plain' => "🏆 Tabriklaymiz! Siz :month reytingida :place-o'rinni egalladingiz — :points.",
    ],

    'open_button' => 'Ligani ochish',

];
