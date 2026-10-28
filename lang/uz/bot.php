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
            "• /paysupport — to'lov bo'yicha savollar\n\n".
            "O'qituvchilar uchun:\n".
            "• Botni guruhga qo'shing va administrator qiling, so'ng LexiFlow administratoridan guruhni faollashtirishni so'rang.\n".
            "• Guruhda: /start_training — mashg'ulot, /start_exam — imtihon, /close_exam — imtihonni yakunlash.\n".
            '• /stats — 7 kunlik hisobotingiz (shu yerda, shaxsiy chatda).',
        'group' => "LexiFlow guruhda.\n\nO'qituvchi buyruqlari:\n".
            "/start_training — mashg'ulotni boshlash\n".
            "/start_exam — imtihonni boshlash\n".
            "/close_exam — imtihonni yopish\n".
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
