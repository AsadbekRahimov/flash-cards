<?php

declare(strict_types=1);

return [

    'blocked' => "Akkauntingizga kirish cheklangan. Qo'llab-quvvatlash xizmatiga murojaat qiling.",

    // The five onboarding steps (docs/12 §7.1), one message each.
    'steps' => [
        'locale' => 'Interfeys tilini tanlang',
        'goal' => "Ingliz tilini nima uchun o'rganmoqchisiz?",
        'level' => "Darajangiz qanday? O'zingizni baholang — keyinroq o'zgartirish mumkin.",
        'timezone' => "Qaysi vaqt mintaqasida yashaysiz? Eslatmalar o'z vaqtida kelishi uchun.",
        'reminder' => "So'zlarni takrorlashni qachon eslatib turay?",
    ],

    'options' => [
        'goal' => [
            'general' => 'Umumiy ingliz tili',
            'ielts' => 'IELTS',
            'sat' => 'SAT',
            'school' => 'Maktab',
        ],
        'level' => [
            'A1' => 'A1 — boshlayapman',
            'A2' => 'A2 — elementar',
            'B1' => "B1 — o'rta",
            'B2' => "B2 — o'rtadan yuqori",
            'unknown' => 'Bilmayman',
        ],
        // City names; `:offset` is filled in from the current UTC offset of the zone.
        'timezone' => [
            'tashkent' => 'Toshkent (:offset)',
            'almaty' => 'Almati (:offset)',
            'bishkek' => 'Bishkek (:offset)',
            'dushanbe' => 'Dushanbe (:offset)',
            'ashgabat' => 'Ashxobod (:offset)',
            'moscow' => 'Moskva (:offset)',
            'istanbul' => 'Istanbul (:offset)',
        ],
        'reminder' => [
            'time' => ':time',
            'off' => 'Eslatmasiz',
        ],
    ],

    'done' => [
        'text' => "Tayyor! Kursingiz: :course. Tugmani bosing — birinchi mashg'ulot bir necha daqiqa oladi.",
        'text_plain' => "Tayyor! Tugmani bosing — birinchi mashg'ulot bir necha daqiqa oladi.",
        'button' => "🎯 Birinchi mashg'ulotni boshlash",
    ],

    // `/start c_<slug>` of a learner who has already finished the onboarding: the course opens in the app.
    'course_link' => [
        'text' => "«:course» kursi LexiFlow'da mavjud. Uni quyidagi tugma bilan oching — o'zingiz tanlamaguningizcha joriy kurs o'zgarmaydi.",
        'button' => 'Kursni ochish',
    ],

    // Minimal "Today" in the bot; the full screen is in the app.
    'today' => [
        // Uzbek does not inflect nouns after numerals: a single form for any count.
        'due' => "Bugun takrorlash uchun: :count ta so'z.",
        'empty' => "Bugun takrorlanadigan so'z yo'q. Yangi so'zlarni o'rganish uchun LexiFlow'ni oching.",
        'button' => "LexiFlow'ni ochish",
    ],

    'menu' => [
        'text' => "Keyingi qadam?\n/today — bugungi mashg'ulot\n/settings — sozlamalar\n/promo KOD — Pro uchun promokod\n/help — yordam",
    ],

    'settings' => [
        'menu' => "Nimani o'zgartirmoqchisiz?",
        'saved' => 'Saqlandi.',
        'timezone_too_soon' => "Vaqt mintaqasini kuniga bir martadan ko'p o'zgartirib bo'lmaydi. Keyingi o'zgartirish — :when dan keyin (joriy vaqtingiz bo'yicha).",
        'timezone_undo' => 'Bekor qilish',
        'timezone_reverted' => 'Vaqt mintaqasi qaytarildi.',
        'timezone_revert_unavailable' => "Vaqt mintaqasi o'zgarishini endi bekor qilib bo'lmaydi: 10 daqiqadan ko'proq o'tdi yoki u allaqachon bekor qilingan.",
        'buttons' => [
            'locale' => 'Til',
            'goal' => 'Maqsad',
            'level' => 'Daraja',
            'timezone' => 'Vaqt mintaqasi',
            'reminder' => 'Eslatmalar',
        ],
    ],

];
