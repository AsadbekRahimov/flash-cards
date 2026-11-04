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
    /* Rus tilida "до Самарканда" uchun kelishik kerak; o'zbekchada shahar nomi o'zgarmaydi (CityNames::toward). */
    'cities_gen' => [
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
    'city_fallback_gen' => ':number-shahar',

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

    /* Sinf karvoni (docs/18 §12.11): hafta yakunlari guruh chatiga (`PostClassCaravanSummaryJob`, HTML). Bot buyruqlari matnlari - G2.9b (`bot.php`). */
    'class' => [
        // Bot va /org (G2.9b): guruh chatiga matnlar, HTMLsiz oddiy matn.
        'started' => "🐪 Sinf karvoni yo'lga chiqdi! Hafta maqsadi — :target ⭐, karvon :until gacha yo'lda.\nHar bir yakunlangan yurish yulduz qo'shadi; maqsadga yetilsa, sinf bossi ochiladi.",
        'open_button' => 'Karvonni ochish',
        'already_active' => "Bu guruhning karvoni allaqachon yo'lda — :until gacha.",
        'canceled' => "Sinf karvoni bekor qilindi. Yakunlar bo'lmaydi, ishtirokchilarning yurishlari o'zlarida qoladi.",
        'no_active' => "Bu guruhning yo'ldagi karvoni yo'q.",
        'not_teacher' => "Karvonni faqat shu guruhning o'qituvchisi ishga tushirishi va bekor qilishi mumkin.",
        'group_inactive' => "Guruh faollashtirilmagan — karvonni ishga tushirib bo'lmaydi.",
        'centers_only' => "Sinf karvoni — markazlar uchun: guruhni amaldagi obunali o'quv markaziga ulang.",
        'failed' => "Karvon buyrug'ini bajarib bo'lmadi. Keyinroq qayta urinib ko'ring.",
        'not_delivered' => "Chatga xabar yuborib bo'lmadi, shuning uchun karvon ishga tushmadi. Bot guruhga yoza olishini tekshirib, qayta urinib ko'ring.",
        'summary' => [
            'title' => '🏁 «:group» sinf karvoni haftani yakunladi!',
            'stars' => '⭐ Yig\'ilgan yulduzlar: <b>:stars</b> / :target',
            'runs' => '🐪 Yurishlar: <b>:runs</b>',
            'boss_defeated' => '👹 Sinf bossi yengildi!',
            'boss_survived' => '👹 Sinf bossi bardosh berdi — keyingi safar chiqadi.',
            'boss_locked' => '👹 Sinf bossigacha yetib bormadik — hafta maqsadi olinmadi.',
            'top' => 'Eng yaxshi karvonchilar:',
            'row' => ':place :name — <b>:stars</b> ⭐ (yurishlar: :runs)',
            'nobody' => 'Bu hafta hech kim yo\'lga chiqmadi. Yana urinib ko\'ramiz!',
        ],
    ],
];
