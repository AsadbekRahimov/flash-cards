<?php

declare(strict_types=1);

return [

    'blocked' => "Akkauntingizga kirish cheklangan. Qo'llab-quvvatlash xizmatiga murojaat qiling: /paysupport.",

    // The second paragraph of the bilingual welcome of a new person (the first one is `welcome` of lang/ru).
    'welcome' => "Salom! Men Muhrla botiman. Har kuni 5–10 daqiqa inglizcha so'zlarni birga mashq qilamiz. Avval bir nechta savolga javob bering.",
    'step_counter' => ':total qadamdan :n-qadam',
    'invited_by_organization' => 'Sizni «:organization» markazi taklif qildi',
    'invited_by_learner' => 'Sizni :name taklif qildi',

    // The onboarding steps (the goal one only while several goals are enabled; no time zone step) (docs/12 §7.1), one message each.
    'steps' => [
        'locale' => 'Interfeys tilini tanlang',
        'region' => 'Qayerda yashaysiz? Bu viloyatingiz reytingi uchun kerak.',
        'region_list' => 'Viloyatingizni tanlang',
        'goal' => "Ingliz tilini nima uchun o'rganmoqchisiz?",
        'level' => "Ingliz tilini qay darajada bilasiz? O'zingizga mos darajani tanlang. Keyin uni sozlamalarda o'zgartirishingiz mumkin.",
        'reminder' => "So'zlarni takrorlashni qachon eslatib turay?",
    ],

    'options' => [
        'region' => [
            'uzbekistan' => "🇺🇿 O'zbekiston",
            'other' => '🌍 Boshqa davlat',
            'none' => "Ko'rsatmaslik",
            'back' => '← Orqaga',
        ],
        'goal' => [
            'general' => 'Umumiy ingliz tili',
            'ielts' => 'IELTS',
            'sat' => 'SAT',
            'school' => 'Maktab',
        ],
        'level' => [
            'A1' => 'A1 — boshlayapman',
            'A2' => 'A2 — boshlang\'ich',
            'B1' => "B1 — o'rta",
            'B2' => "B2 — o'rtadan yuqori",
            'C1' => "C1 — ilg'or",
            'C2' => 'C2 — erkin',
            'unknown' => 'Bilmayman',
        ],
        'reminder' => [
            'time' => ':time',
            'off' => 'Eslatmasiz',
        ],
    ],

    'done' => [
        'text' => "Tayyor! Kursingiz: :course. Birinchi mashg'ulotni boshlash uchun quyidagi tugmani bosing. Mashg'ulot bir necha daqiqa davom etadi.\n\nDo'stlaringizni taklif qiling — Pro'ni bepul oling: /invite",
        'text_plain' => "Tayyor! Birinchi mashg'ulotni boshlash uchun quyidagi tugmani bosing. Mashg'ulot bir necha daqiqa davom etadi.\n\nDo'stlaringizni taklif qiling — Pro'ni bepul oling: /invite",
        'button' => "🎯 Birinchi mashg'ulotni boshlash",
    ],

    // `/start c_<slug>` of a learner who has already finished the onboarding: the course opens in the app.
    'course_link' => [
        'text' => "«:course» kursi Muhrla'da mavjud. Uni quyidagi tugma bilan oching — o'zingiz tanlamaguningizcha joriy kurs o'zgarmaydi.",
        'button' => 'Kursni ochish',
    ],

    // Minimal "Today" in the bot; the full screen is in the app.
    'today' => [
        // Uzbek does not inflect nouns after numerals: a single form for any count.
        'due' => "Bugun :count ta so'zni takrorlash vaqti keldi.",
        'empty' => "Bugun takrorlanadigan so'z yo'q. Yangi so'zlarni o'rganish uchun Muhrla'ni oching.",
        'button' => "Muhrla'ni ochish",
    ],

    'menu' => [
        'text' => "Nima qilmoqchisiz?\n/today — bugungi mashg'ulot\n/settings — sozlamalar\n/language — til\n/invite — do'stlarni taklif qilish va Pro olish\n/promo KOD — Pro uchun promokod\n/help — yordam",
    ],

    'settings' => [
        'menu' => "Nimani o'zgartirmoqchisiz?",
        'language_title' => 'Tilni tanlang',
        'saved' => 'Saqlandi.',
        'timezone_reverted' => 'Avvalgi vaqt mintaqasi tiklandi.',
        'timezone_revert_unavailable' => "Vaqt mintaqasi o'zgarishini endi bekor qilib bo'lmaydi: 10 daqiqadan ko'proq o'tdi yoki u allaqachon bekor qilingan.",
        'region_saved' => 'Saqlandi: :region.',
        'region_none' => "viloyat ko'rsatilmagan",
        'region_undo' => '↩️ Bekor qilish',
        'region_too_soon' => "Viloyatni 30 kunda bir marta o'zgartirish mumkin. Viloyatni :date dan boshlab o'zgartirishingiz mumkin.",
        'region_reverted' => 'Avvalgi viloyat tiklandi.',
        'region_revert_unavailable' => "Viloyat o'zgarishini endi bekor qilib bo'lmaydi: 10 daqiqadan ko'proq o'tdi yoki u allaqachon bekor qilingan.",
        'buttons' => [
            'locale' => 'Til',
            'region' => 'Viloyat',
            'goal' => 'Maqsad',
            'level' => 'Daraja',
            'reminder' => 'Eslatmalar',
        ],
    ],

];
