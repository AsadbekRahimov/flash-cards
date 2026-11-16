<?php

declare(strict_types=1);

return [

    // Uzbek does not inflect nouns after numerals: a single form for any count.
    'review' => "LexiFlow'da :count ta so'zni takrorlash vaqti keldi.\n\nVaqtni o'zgartirish yoki o'chirish: /settings",
    // :count = current streak in days.
    'streak_at_risk' => "Ketma-ket :count kun shug'ullandingiz. Shu natijani saqlash uchun bugungi maqsadni bajaring.\n\nVaqtni o'zgartirish yoki o'chirish: /settings",
    // Break in learning (owner decision 2026-11-16, docs/12 §7.5). :count = local days without lessons, :due = due words.
    'inactive' => "LexiFlow: :count kundan beri shug'ullanmadingiz. So'zlar takrorlanmasa unutiladi — qaytish uchun 5 daqiqa yetarli.\n\nVaqtni o'zgartirish yoki o'chirish: /settings",
    'inactive_due' => "LexiFlow: :count kundan beri shug'ullanmadingiz. Takrorlash kerak bo'lgan so'zlar: :due. Qaytish uchun 5 daqiqa yetarli.\n\nVaqtni o'zgartirish yoki o'chirish: /settings",
    'never_started' => "Siz LexiFlow'da hali shug'ullanishni boshlamadingiz. Birinchi mashg'ulot atigi 5 daqiqa oladi.\n\nVaqtni o'zgartirish yoki o'chirish: /settings",
    'new_words' => "Bugun LexiFlow'da sizni yangi so'zlar kutmoqda. 5 daqiqa yetarli.\n\nVaqtni o'zgartirish yoki o'chirish: /settings",
    'choose_course' => "Shug'ullanishni boshlash uchun LexiFlow'da kursni tanlang.\n\nVaqtni o'zgartirish yoki o'chirish: /settings",
    'open_button' => "LexiFlow'ni ochish",

];
