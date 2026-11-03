<?php

declare(strict_types=1);

/*
 * "Karvon" o'yini (docs/18 §12): server matnlari. Lotin yozuvi, ASCII apostrof; yangi satrlar docs/I18N_UZ_REVIEW.md ga kiritilgan.
 *
 * `cities` - Buyuk Ipak yo'li shaharlari modul raqami bo'yicha (`stages.number`, CityNames): 24 nom, undan keyin `city_fallback`.
 */

return [
    'cities' => [
        1 => 'Toshkent',
        2 => "Qo'qon",
        3 => 'Samarqand',
        4 => 'Buxoro',
        5 => 'Xiva',
        6 => 'Shahrisabz',
        7 => 'Termiz',
        8 => 'Balx',
        9 => 'Marv',
        10 => 'Nishopur',
        11 => 'Tus',
        12 => 'Ray',
        13 => 'Hamadon',
        14 => "Bag'dod",
        15 => 'Damashq',
        16 => 'Halab',
        17 => 'Antioxiya',
        18 => 'Konstantinopol',
        19 => 'Qashqar',
        20 => "Xo'tan",
        21 => 'Dunxuan',
        22 => 'Turfon',
        23 => 'Loyan',
        24 => "Chang'an",
    ],
    'city_fallback' => ':number-shahar',

    /* "Men yetib keldim: ..." kartochkasi (shahar bossi ustidan birinchi g'alaba): rasm, shaxsiy xabar sarlavhasi va "Ulashish" tugmasi. */
    'card' => [
        'title' => 'Men yetib keldim: :city',
        'description' => "\"Karvon\" o'yinida darvoza qo'riqchisini yengdim",
        'caption' => "🐪 Men yetib keldim: :city! Darvoza qo'riqchisi yengildi.\n\nDo'stlaringiz bilan ulashing - quyidagi tugmani bosing yoki shu xabarni yuboring.",
        'caption_no_link' => "🐪 Men yetib keldim: :city! Darvoza qo'riqchisi yengildi.\n\nMaqtanish uchun shu xabarni do'stlaringizga yuboring.",
        'share_button' => 'Ulashish',
        'share_text' => "Men \"Karvon\" o'yinida yetib keldim: :city - LexiFlow! Telegramda ingliz tili.",
    ],

    /* Hamyon yozuvlarining nomlari (`GET /game/wallet`, `ledger[].title`): yozuv turi bo'yicha (LedgerKind). */
    'ledger' => [
        'run_reward' => "To'xtash uchun mukofot",
        'boss_reward' => 'Boss uchun mukofot',
        'daily_reward' => 'Kun karvoni uchun mukofot',
        'class_reward' => 'Sinf karvoni mukofoti',
        'bazaar_hint' => 'Bozordagi maslahat',
        'item_purchase' => "Do'kondan xarid",
        'admin_grant' => "Qo'llab-quvvatlash qo'shgan",
        'admin_revoke' => "Qo'llab-quvvatlash yechgan",
        'refund' => 'Qaytarish',
    ],
];
