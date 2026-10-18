<?php

declare(strict_types=1);

return [

    'start' => [
        'not_linked' => "Bu Telegram akkaunt LexiFlow'dagi o'qituvchiga biriktirilmagan. Administratordan Telegram ID'ngizni kiritishini so'rang: :id.",
        'greeting' => "Salom, :name! Siz LexiFlow o'qituvchisi sifatida tizimga kirdingiz. Guruhdagi buyruqlar: /help",
    ],

    'help' => [
        'private' => "LexiFlow — ingliz tilini o'rganish yordamchisi.\n\n".
            "O'qituvchilar uchun: botni guruhga qo'shing, administrator uni faollashtiradi.\n".
            "Guruhdagi mavjud buyruqlar: /start_training, /start_exam, /close_exam, /help\n".
            "Shaxsiy hisobot: /stats\n".
            'Pro uchun promokod: /promo KOD',
        'group' => "LexiFlow Bot guruhda.\n\nO'qituvchi buyruqlari:\n".
            "/start_training — mashg'ulotni boshlash\n".
            "/start_exam — imtihonni boshlash\n".
            "/close_exam — imtihonni yopish\n".
            "/stats — bot bilan shaxsiy chatda hisobot\n".
            '/help — ushbu yordam',
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
        ],
    ],

];
