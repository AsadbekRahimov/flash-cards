<?php

declare(strict_types=1);

return [

    // Uzbek does not inflect nouns after numerals: a single form for any count.
    'review' => "Muhrla'da :count ta so'zni takrorlash vaqti keldi.\n\nEslatma vaqtini o'zgartirish yoki eslatmalarni o'chirish uchun /settings buyrug'ini yuboring.",
    // :count = current streak in days.
    'streak_at_risk' => "Ketma-ket :count kun shug'ullandingiz. Shu natijani saqlash uchun bugungi maqsadni bajaring.\n\nEslatma vaqtini o'zgartirish yoki eslatmalarni o'chirish uchun /settings buyrug'ini yuboring.",
    // Break in learning (owner decision 2026-11-16, docs/12 §7.5). :count = local days without lessons, :due = due words.
    'inactive' => "Muhrla: :count kundan beri shug'ullanmadingiz. Bugun 5 daqiqa ajratib, avvalgi so'zlarni yana bir bor mashq qiling.\n\nEslatma vaqtini o'zgartirish yoki eslatmalarni o'chirish uchun /settings buyrug'ini yuboring.",
    'inactive_due' => "Muhrla: :count kundan beri shug'ullanmadingiz. Takrorlash uchun :due ta so'z bor. Bugun 5 daqiqalik mashg'ulotdan boshlang.\n\nEslatma vaqtini o'zgartirish yoki eslatmalarni o'chirish uchun /settings buyrug'ini yuboring.",
    'never_started' => "Siz Muhrla'da hali shug'ullanishni boshlamadingiz. Birinchi mashg'ulot uchun 5 daqiqa ajrating.\n\nEslatma vaqtini o'zgartirish yoki eslatmalarni o'chirish uchun /settings buyrug'ini yuboring.",
    'new_words' => "Bugun Muhrla'da sizni yangi so'zlar kutmoqda. 5 daqiqa yetarli.\n\nEslatma vaqtini o'zgartirish yoki eslatmalarni o'chirish uchun /settings buyrug'ini yuboring.",
    'choose_course' => "Shug'ullanishni boshlash uchun Muhrla'da kursni tanlang.\n\nEslatma vaqtini o'zgartirish yoki eslatmalarni o'chirish uchun /settings buyrug'ini yuboring.",
    'open_button' => "Muhrla'ni ochish",

    // The reminder after a lesson (docs/31 §5б): :count = the new words of the lesson met today, :lesson = its title. About fixing
    // the words, never about points.
    'lesson_nudge' => [
        'text' => "«:lesson» darsi tugadi: :count ta yangi so'z. Ularni «Vaqtga juftlar» o'yinida mustahkamlang — bir daqiqa, va so'zlar yaxshiroq esda qoladi.\n\nEslatma vaqtini o'zgartirish yoki eslatmalarni o'chirish uchun /settings buyrug'ini yuboring.",
        'text_untitled' => "Dars tugadi: :count ta yangi so'z. Ularni «Vaqtga juftlar» o'yinida mustahkamlang — bir daqiqa, va so'zlar yaxshiroq esda qoladi.\n\nEslatma vaqtini o'zgartirish yoki eslatmalarni o'chirish uchun /settings buyrug'ini yuboring.",
    ],

    // The reminder of the day after a lesson (docs/31 §5б, ReviewScoreHint): replaces the `review` text when the words of yesterday's
    // lesson are due and the word points count. Honest and without numbers: a review in time earns points (within the daily limits).
    'score_due' => "Kechagi dars so'zlarini takrorlash vaqti keldi. Ularni bugun takrorlang: o'z vaqtida takrorlash uchun reyting ballari beriladi.\n\nEslatma vaqtini o'zgartirish yoki eslatmalarni o'chirish uchun /settings buyrug'ini yuboring.",

    // The reminder as a question with four buttons (owner decision 2026-11-17, docs/12 §7.5). Plain text, no markup.
    'quiz' => [
        'lead' => [
            'review' => "Muhrla'da :count ta so'zni takrorlash vaqti keldi. Birinchisidan shu yerning o'zida boshlaymiz.",
            'streak_at_risk' => "Ketma-ket :count kun shug'ullandingiz. Natijani saqlash uchun bugungi maqsadni bajaring. Mashq:",
            'inactive' => "Muhrla: :count kundan beri shug'ullanmadingiz. Bitta so'zni tekshirib ko'ramiz:",
            'new_words' => "Yangi so'zlardan oldin mashq:",
            'score_due' => "Kechagi dars so'zlarini takrorlash vaqti keldi — o'z vaqtida takrorlash uchun ball beriladi. Birinchisidan shu yerning o'zida boshlaymiz.",
        ],
        'question' => [
            'meaning' => '«:word» nima degani?',
            'reverse' => '«:translation» inglizchada qanday?',
        ],
        'footer' => "Eslatma vaqtini o'zgartirish yoki eslatmalarni o'chirish uchun /settings buyrug'ini yuboring.",
        'correct' => "✅ To'g'ri! :word — :answer.",
        'wrong' => '❌ Xato. :word — :answer.',
        'after_due' => "Ilovada sizni :count ta so'z kutmoqda — 5 daqiqa yetarli.",
        'after_none' => "Ilovada davom eting — u yerda yangi so'zlar bor.",
        'open_more' => "Muhrla'ni ochish",
        'expired' => "Bu savol eskirgan. Ilovani oching — u yerda yangi so'zlar bor.",
    ],

];
