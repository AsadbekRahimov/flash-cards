<?php

declare(strict_types=1);

return [

    'start' => [
        'greeting' => "Salom, :name! Siz LexiFlow'ga o'qituvchi sifatida kirdingiz. Guruh buyruqlarini ko'rish uchun /help yuboring.",
    ],

    'help' => [
        'private' => "LexiFlow bilan inglizcha so'zlarni Telegramda o'rganing. Har bir kartochkada talaffuz bor, bot esa takrorlash vaqtini eslatib turadi.\n\n".
            "Qanday shug'ullanish kerak:\n".
            "• Xabar maydoni yonidagi «LexiFlow» tugmasi yoki /today — bugungi mashg'ulot\n".
            "• /settings — til, maqsad, daraja, vaqt mintaqasi, eslatmalar\n".
            "• /invite — do'stingizni taklif qiling va birga Pro oling\n".
            "• /pro — Pro obuna, /promo KOD — promokod\n".
            "• /paysupport — to'lov bo'yicha savollar\n".
            "• /delete_account — akkauntni o'chirish",
        // Only for teachers (an active User with this Telegram id); the learner part is `private`.
        'private_teacher' => "O'qituvchilar uchun:\n".
            "• Botni guruhga qo'shing va administrator qiling, so'ng LexiFlow administratoridan guruhni faollashtirishni so'rang.\n".
            "• /start_training — guruh mashg'ulotini boshlash\n".
            "• /start_exam — imtihonni boshlash, /close_exam — imtihonni yakunlash\n".
            ':caravan'.
            '• /stats — 7 kunlik hisobotingiz (shu yerda, shaxsiy chatda).',
        'group' => "LexiFlow guruh buyruqlari\n\nO'qituvchilar uchun:\n".
            "/start_training — guruh mashg'ulotini boshlash\n".
            "/start_exam — imtihonni boshlash\n".
            "/close_exam — imtihonni yakunlash\n".
            ':caravan'.
            "/stats — bot bilan shaxsiy chatda hisobot\n".
            "/help — buyruqlar ro'yxati",
        // The line of `:caravan` in `private_teacher` (the part «O'qituvchilar uchun»): shown only with GAME_ACCESS=all, like `group_caravan`.
        'private_caravan' => "• Sinf karvoni: /start_caravan — boshlash, /cancel_caravan — bekor qilish.\n",
        // The lines of `:caravan` in `group`: shown only with GAME_ACCESS=all (G2.9b, G2.13).
        'group_caravan' => "/start_caravan — sinf karvonini boshlash\n".
            "/cancel_caravan — sinf karvonini bekor qilish\n",
    ],

    'group' => [
        'bot_not_admin' => "⚠️ Botni guruh administratori qiling. Aks holda bot xabarlarni va a'zolarning chiqib ketishini ko'rmaydi, LexiFlow to'liq ishlamaydi. ".
            "Guruh → Boshqarish → Administratorlar → Administrator qo'shish.",
    ],

    'stats' => [
        'not_linked' => "Bu Telegram akkaunti LexiFlow'dagi o'qituvchi profiliga ulanmagan.\nTelegram ID raqamingiz: :id",
    ],

    'account' => [
        'restored' => "Yana xush kelibsiz! Akkauntingiz tiklandi: so'zlaringiz va natijalaringiz joyida.",
        'restored_pro' => "Akkauntni o'chirganingizda Pro avtoyangilashi o'chirilgan edi. Uni «Obuna» bo'limida qayta yoqishingiz mumkin.",
    ],

    'delete_account' => [
        'settings_button' => "Akkauntni o'chirish",
        'confirm' => "LexiFlow akkauntini o'chirasizmi?\n\nMa'lumotlaringiz :days kundan keyin butunlay o'chiriladi. Shu muddat ichida qaytishingiz mumkin: botga /start yuborsangiz kifoya. To'lovlar tarixi qonunga ko'ra saqlanadi, Pro obunasi to'xtatiladi.",
        'yes_button' => "Ha, o'chirish",
        'no_button' => 'Bekor qilish',
        'canceled' => "Mayli, akkaunt o'chirilmadi.",
        'done' => "Akkaunt o'chirildi. Ma'lumotlaringiz :days kundan keyin butunlay o'chiriladi. Fikringiz o'zgarsa, /start yuboring va akkaunt tiklanadi.",
        'none' => "Akkaunt yo'q yoki allaqachon o'chirilgan. Qaytish uchun /start yuboring.",
        'blocked' => "Akkauntga kirish cheklangan. Qo'llab-quvvatlash xizmatiga murojaat qiling.",
        'use_private' => "Akkaunt faqat shaxsiy chatda o'chiriladi. Bot bilan chatni oching va /delete_account yuboring.",
    ],

    'promo' => [
        'usage' => 'Promokodni shunday yuboring: /promo KOD',
        'use_private' => "Promokodni guruhda yozmagan ma'qul. Bot bilan shaxsiy chatni oching va /promo KOD yuboring.",
        'blocked' => "Akkauntga kirish cheklangan. Qo'llab-quvvatlash xizmatiga murojaat qiling.",
        'rate_limited' => "Juda ko'p urinish. Bir daqiqa kutib, qayta urinib ko'ring.",
        'success_until' => 'Tayyor! LexiFlow Pro :date gacha faol.',
        'success_forever' => 'Tayyor! LexiFlow Pro muddatsiz faol.',
        'errors' => [
            'promo_not_found' => "Promokod topilmadi. To'g'ri yozilganini tekshiring.",
            'promo_expired' => 'Promokod muddati tugagan.',
            'promo_exhausted' => 'Bu promokoddan foydalanish limiti tugagan.',
            'promo_already_redeemed' => 'Siz bu promokodni allaqachon faollashtirgansiz.',
            'promo_not_applicable' => 'Sizda allaqachon muddatsiz Pro bor — promokod kerak emas.',
            'plan_unknown' => "Bu promokod hozir ishlamaydi. Qo'llab-quvvatlash xizmatiga yozing.",
        ],
    ],

];
