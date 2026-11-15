<?php

declare(strict_types=1);

return [

    'teacher_not_linked' => "Bu buyruq — guruh o'qituvchisi uchun. O'qituvchi: markaz kabinetida Profil bo'limini oching va o'z Telegram ID raqamingizni kiriting (uni bot bilan shaxsiy chatda /stats buyrug'i ko'rsatadi).",
    'teacher_not_linked_ask_admin' => "Bu buyruq — guruh o'qituvchisi uchun. O'qituvchi: markaz kabinetida Profil bo'limini oching va o'z Telegram ID raqamingizni kiriting (uni bot bilan shaxsiy chatda /stats buyrug'i ko'rsatadi).",

    // `:support` — the line with the support contact (BotSupportContact::line), empty without a contact.
    'group_inactive' => "Guruh hali faollashtirilmagan. Qo'llab-quvvatlash xizmatiga yozing — faollashtiramiz.:support",
    'stage_not_found' => "Ko'rsatilgan modul topilmadi.",
    'lesson_not_found' => 'Dars topilmadi.',

    'training' => [
        'not_teacher' => "Guruh mashg'ulotini faqat shu guruhning o'qituvchisi boshlay oladi.",
        'failed' => "Guruh mashg'ulotini boshlab bo'lmadi. Buyruqni bir daqiqadan keyin takrorlang.",
        'not_delivered' => "Tugma yuborilmadi, guruh mashg'uloti bekor qilindi. Bot guruhga qo'shilganini va xabar yoza olishini tekshirib, buyruqni qayta yuboring.",
    ],

    'exam' => [
        'not_teacher' => "Imtihonni faqat shu guruhning o'qituvchisi boshlay oladi.",
        'not_enough_words' => "Darsda imtihon uchun so'zlar yetarli emas.",
        'not_enough_distractors' => "Javob variantlari uchun so'zlar yetarli emas: darsga yoki shu modulning boshqa darslariga ko'proq so'z qo'shing.",
        'already_open' => 'Guruhda imtihon allaqachon davom etmoqda. Uni /close_exam orqali yakunlang.',
        'invalid_duration' => "Imtihon davomiyligi 1 daqiqadan 30 daqiqagacha bo'lishi kerak.",
        'failed' => "Imtihonni boshlab bo'lmadi. Buyruqni bir daqiqadan keyin takrorlang.",
        'not_delivered' => "Tugmani guruhga yuborib bo'lmadi: imtihon bekor qilindi. Bot guruhda ekanini va yoza olishini tekshiring, so'ng buyruqni takrorlang.",
        'close_not_teacher' => "Imtihonni faqat shu guruhning o'qituvchisi yakunlashi mumkin.",
        'no_open_exam' => "Hozir guruhda ochiq imtihon yo'q.",
        'close_failed' => "Imtihonni yakunlab bo'lmadi. Buyruqni bir daqiqadan keyin takrorlang.",
    ],

];
