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
    /* "Samarqandga yetib keldim" uchun jo'nalish kelishigi (CityNames::toward): faqat `card.*` matnlarida ishlatiladi. */
    'cities_gen' => [
        1 => 'Toshkentga',
        2 => "Qo'qonga",
        3 => 'Samarqandga',
        4 => 'Buxoroga',
        5 => 'Xivaga',
        6 => 'Shahrisabzga',
        7 => 'Termizga',
        8 => 'Balxga',
        9 => 'Marvga',
        10 => 'Nishopurga',
        11 => 'Tusga',
        12 => 'Rayga',
        13 => 'Hamadonga',
        14 => "Bag'dodga",
        15 => 'Damashqqa',
        16 => 'Halabga',
        17 => 'Antioxiyaga',
        18 => 'Konstantinopolga',
        19 => 'Qashqarga',
        20 => "Xo'tanga",
        21 => 'Dunxuanga',
        22 => 'Turfonga',
        23 => 'Loyanga',
        24 => "Chang'anga",
    ],
    'city_fallback_gen' => ':number-shaharga',

    /* "Samarqandga yetib keldim" kartochkasi (shahar qo'riqchisi ustidan birinchi g'alaba): rasm, shaxsiy xabar sarlavhasi va "Ulashish" tugmasi. */
    'card' => [
        'title' => ':city yetib keldim',
        'description' => "«Karvon» o'yinida darvoza qo'riqchisini yengdim",
        'caption' => "🐪 :city yetib keldim! Darvoza qo'riqchisi yengildi.\n\nDo'stlaringizga ham ko'rsating: pastdagi tugmani bosing yoki xabarni uzating.",
        'caption_no_link' => "🐪 :city yetib keldim! Darvoza qo'riqchisi yengildi.\n\nMaqtanish uchun bu xabarni do'stlaringizga uzating.",
        'share_button' => 'Ulashish',
        'share_text' => "LexiFlow'dagi «Karvon» o'yinida :city yetib keldim! Telegramda ingliz tilini o'rganamiz.",
    ],

    /* Hamyon yozuvlarining nomlari (`GET /game/wallet`, `ledger[].title`): yozuv turi bo'yicha (LedgerKind). */
    'ledger' => [
        'run_reward' => 'Bekat uchun mukofot',
        'boss_reward' => "Qo'riqchi uchun mukofot",
        'daily_reward' => 'Kun karvoni uchun mukofot',
        'class_reward' => 'Sinf karvoni mukofoti',
        'bazaar_hint' => 'Bozordagi maslahat',
        'item_purchase' => "Do'kondan xarid",
        'admin_grant' => "Qo'llab-quvvatlash qo'shdi",
        'admin_revoke' => "Qo'llab-quvvatlash yechdi",
        'refund' => 'Qaytarish',
    ],

    /* Sinf karvoni (docs/18 §12.11): hafta yakunlari guruh chatiga (`PostClassCaravanSummaryJob`, HTML). Bot buyruqlari matnlari - G2.9b (`bot.php`). */
    'class' => [
        // Bot va /org (G2.9b): guruh chatiga matnlar, HTMLsiz oddiy matn.
        'started' => "🐪 Sinf karvoni yo'lga chiqdi! Hafta maqsadi :target ⭐, karvon :until gacha yo'lda.\nHar bir tugagan yurish yulduz qo'shadi. Maqsadga yetsangiz, sinf qo'riqchisi ochiladi.",
        'open_button' => 'Karvonni ochish',
        'already_active' => "Bu guruhning karvoni allaqachon yo'lda — :until gacha.",
        'canceled' => "Sinf karvoni bekor qilindi. Hafta natijalari e'lon qilinmaydi, ishtirokchilarning yurishlari o'zlarida qoladi.",
        'no_active' => "Bu guruhda hozir yo'ldagi karvon yo'q.",
        'not_teacher' => "Karvonni faqat shu guruhning o'qituvchisi boshlashi va bekor qilishi mumkin.",
        'group_inactive' => "Guruh faollashtirilmagan, shuning uchun karvonni boshlab bo'lmaydi.",
        'centers_only' => "Sinf karvoni faqat o'quv markazlari uchun. Guruhni obunasi faol o'quv markaziga ulang.",
        'failed' => "Karvon buyrug'ini bajarib bo'lmadi. Keyinroq qayta urinib ko'ring.",
        'not_delivered' => "Chatga xabar yuborib bo'lmadi, shuning uchun karvon boshlanmadi. Bot guruhga yoza olishini tekshirib, qayta urinib ko'ring.",
        'summary' => [
            'title' => '🏁 «:group» sinf karvoni haftani yakunladi!',
            'stars' => '⭐ Yig\'ilgan yulduzlar: <b>:stars</b> / :target',
            'runs' => '🐪 Yurishlar: <b>:runs</b>',
            'boss_defeated' => "👹 Sinf qo'riqchisi yengildi!",
            'boss_survived' => "👹 Sinf qo'riqchisi bardosh berdi. Keyingi safar albatta uddalaymiz!",
            'boss_locked' => "👹 Sinf qo'riqchisigacha yetib bormadik: hafta maqsadi bajarilmadi.",
            'top' => 'Eng yaxshi karvonchilar:',
            'row' => ':place :name — <b>:stars</b> ⭐ (yurishlar: :runs)',
            'nobody' => 'Bu hafta hech kim yo\'lga chiqmadi. Yana urinib ko\'ramiz!',
        ],
    ],
];
