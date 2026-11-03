<?php

declare(strict_types=1);

return [

    'start' => [
        'greeting' => "Salom, :name! Siz LexiFlow o'qituvchisi sifatida tizimga kirdingiz. Guruhdagi buyruqlar: /help",
    ],

    'help' => [
        'private' => "LexiFlow — Telegramda inglizcha so'zlar: ovozli kartochkalar va aqlli takrorlash.\n\n".
            "Qanday shug'ullanish kerak:\n".
            "• Xabar yozish maydonining chap tomonidagi «LexiFlow» tugmasi yoki /today — bugungi mashg'ulot\n".
            "• /settings — til, maqsad, daraja, vaqt mintaqasi, eslatmalar\n".
            "• /invite — do'stingizni taklif qiling va birgalikda Pro oling\n".
            "• /pro — Pro obuna, /promo KOD — promokod\n".
            "• /paysupport — to'lov bo'yicha savollar\n".
            "• /delete_account — akkauntni o'chirish\n\n".
            "O'qituvchilar uchun:\n".
            "• Botni guruhga qo'shing va administrator qiling, so'ng LexiFlow administratoridan guruhni faollashtirishni so'rang.\n".
            "• Guruhda: /start_training — trening, /start_exam — imtihon, /close_exam — imtihonni yakunlash.\n".
            '• /stats — 7 kunlik hisobotingiz (shu yerda, shaxsiy chatda).',
        'group' => "LexiFlow guruhda.\n\nO'qituvchi buyruqlari:\n".
            "/start_training — treningni boshlash\n".
            "/start_exam — imtihonni boshlash\n".
            "/close_exam — imtihonni yakunlash\n".
            "/stats — bot bilan shaxsiy chatda hisobot\n".
            '/help — ushbu yordam',
    ],

    'group' => [
        'bot_not_admin' => "⚠️ Botni guruh administratori qiling. Aks holda bot xabarlarni va a'zolarning chiqib ketishini ko'rmaydi, LexiFlow to'liq ishlamaydi. ".
            "Guruh → Boshqarish → Administratorlar → Administrator qo'shish.",
    ],

    'stats' => [
        'not_linked' => "Bu Telegram akkaunt LexiFlow o'qituvchisiga biriktirilmagan.\nSizning Telegram ID'ngiz: :id",
    ],

    'delete_account' => [
        'settings_button' => "Akkauntni o'chirish",
        'confirm' => "LexiFlow akkauntini o'chirasizmi?\n\nMa'lumotlaringiz :days kundan keyin butunlay o'chiriladi. Bu muddat tugamaguncha qaytishingiz mumkin — botga /start yuborishning o'zi kifoya. To'lov yozuvlari qonunga ko'ra saqlanadi, Pro obuna to'xtatiladi.",
        'yes_button' => "Ha, o'chirish",
        'no_button' => 'Bekor qilish',
        'canceled' => "Mayli, akkaunt o'chirilmadi.",
        'done' => "Akkaunt o'chirildi. Ma'lumotlar :days kundan keyin butunlay o'chiriladi. Fikringiz o'zgarsa — /start yuboring, akkaunt qaytadi.",
        'none' => "Akkaunt yo'q yoki allaqachon o'chirilgan. Qaytish uchun /start yuboring.",
        'blocked' => "Akkauntga kirish cheklangan. Qo'llab-quvvatlash xizmatiga murojaat qiling.",
        'use_private' => "Akkaunt faqat shaxsiy chatda o'chiriladi. Bot bilan chatni oching va /delete_account yuboring.",
    ],

    'promo' => [
        'usage' => 'Promokodni quyidagicha yuboring: /promo KOD',
        'use_private' => "Promokodni guruhda ko'rsatmaslik yaxshi. Bot bilan shaxsiy chatni oching va /promo KOD yuboring.",
        'blocked' => "Akkauntga kirish cheklangan. Qo'llab-quvvatlash xizmatiga murojaat qiling.",
        'rate_limited' => "Juda ko'p urinish. Bir daqiqa kutib, qayta urinib ko'ring.",
        'success_until' => 'Tayyor! LexiFlow Pro :date gacha faol.',
        'success_forever' => 'Tayyor! LexiFlow Pro muddatsiz faol.',
        'errors' => [
            'promo_not_found' => 'Promokod topilmadi. Xato yozilmaganini tekshiring.',
            'promo_expired' => 'Promokodning amal qilish muddati tugagan.',
            'promo_exhausted' => 'Bu promokoddan foydalanish limiti tugagan.',
            'promo_already_redeemed' => 'Siz bu promokodni allaqachon faollashtirgansiz.',
            'promo_not_applicable' => 'Sizda allaqachon muddatsiz Pro bor — promokod kerak emas.',
            'plan_unknown' => "Bu promokod hozircha mavjud emas. Qo'llab-quvvatlash xizmatiga yozing.",
        ],
    ],

];
