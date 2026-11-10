<?php

declare(strict_types=1);

return [

    'start' => [
        'greeting' => "Assalomu alaykum, :name! O'qituvchi akkauntingiz ulangan.\n\nKeyingi qadamlar:\n1. Botni sinf guruhiga qo'shing va administrator qiling.\n2. Guruhda /start_training — guruh mashg'uloti, /start_exam — imtihon yuboring.\n3. /stats — 7 kunlik hisobot, /org — markaz kabineti.",
    ],

    'help' => [
        'private' => "LexiFlow bilan inglizcha so'zlarni Telegramda o'rganing. Kartochkalardan talaffuzni eshitasiz, bot esa takrorlashni eslatadi.\n\n".
            "Qanday shug'ullanish kerak:\n".
            "• Bugungi mashg'ulot uchun xabar yozish joyining yonidagi «LexiFlow» tugmasini bosing yoki /today yuboring.\n".
            "• /settings — til, maqsad, daraja, vaqt mintaqasi, eslatmalar\n".
            "• /invite — do'stingizni taklif qiling va birgalikda Pro oling\n".
            "• /pro — Pro obuna, /promo KOD — promokod\n".
            "• /paysupport — to'lov bo'yicha savollar\n".
            "• /delete_account — akkauntni o'chirish",
        // Instead of `private` for a linked teacher (their DM has no learner commands). `:caravan` as in `group`.
        'private_teacher' => "LexiFlow — o'qituvchi buyruqlari.\n\n".
            "• Botni guruhga qo'shing va administrator qiling. Buyruqlar guruh faollashtirilgandan keyin ishlaydi: hali faol bo'lmasa, qo'llab-quvvatlash xizmatiga yozing.\n".
            "• Guruhda: /start_training — guruh mashg'uloti, /start_exam — imtihon, /close_exam — imtihonni yakunlash.\n".
            ':caravan'.
            "• /stats — 7 kunlik hisobotingiz\n".
            '• /org — markaz kabineti',
        // The last line of `private` for everybody who is not a linked teacher; left out without a support contact.
        'teacher_hint' => "Siz o'qituvchi yoki markaz egasimisiz? Bizga yozing: :contact",
        'group' => "LexiFlow guruh buyruqlari\n\nO'qituvchilar uchun:\n".
            "/start_training — guruh mashg'ulotini boshlash\n".
            "/start_exam — imtihonni boshlash\n".
            "/close_exam — imtihonni yakunlash\n".
            ':caravan'.
            "/stats — bot bilan shaxsiy chatda hisobot\n".
            "/help — buyruqlar ro'yxatini ko'rish\n\n".
            "O'quv markazining guruhimi? Uni markaz kabinetidan bog'lang: Guruhlar → «Guruhni bog'lash».",
        // The line of `:caravan` in `private` (the part «O'qituvchilar uchun»): shown only with GAME_ACCESS=all, like `group_caravan`.
        'private_caravan' => "• Sinf karvoni: /start_caravan — boshlash, /cancel_caravan — bekor qilish.\n",
        // The lines of `:caravan` in `group`: shown only with GAME_ACCESS=all (G2.9b, G2.13).
        'group_caravan' => "/start_caravan — sinf karvonini boshlash\n".
            "/cancel_caravan — sinf karvonini bekor qilish\n",
    ],

    'support' => [
        'line' => "Qo'llab-quvvatlash xizmati: :contact",
    ],

    'teacher' => [
        'learner_command' => "Bu buyruq — o'quvchilar uchun. O'qituvchi buyruqlaringiz: /help",
    ],

    'group' => [
        'untitled' => 'Nomsiz guruh',
        // HTML. Sent once when the bot is added to a group that waits for activation (`:title` is escaped by the caller).
        'welcome' => "👋 LexiFlow «:title» guruhiga qo'shildi.\n\nKeyingi qadamlar:\n1. Botni guruh administratori qiling.\n2. O'quv markazining guruhimi? Uni markaz kabinetidan bog'lang: Guruhlar → «Guruhni bog'lash» — obunasi bor markazda guruh darhol ishlay boshlaydi.\n3. Aks holda guruh LexiFlow administratori tomonidan faollashtirilishini kutadi — shundan keyin o'qituvchi buyruqlari ishlaydi.",
        // HTML. The answer to a teacher command in a group that is not active yet.
        'pending_command' => 'Guruh hali faollashtirilmagan, shuning uchun buyruqlar hozircha ishlamaydi.',
        'pending_help' => "Guruh hali faollashtirilmagan, shuning uchun buyruqlar hozircha ishlamaydi.\n\nO'quv markazining guruhimi? Uni markaz kabinetidan bog'lang: Guruhlar → «Guruhni bog'lash».",
        'bot_not_admin' => "⚠️ Botni guruh administratori qiling. Aks holda bot xabarlarni va a'zolarning chiqib ketishini ko'rmaydi, LexiFlow to'liq ishlamaydi. ".
            "Guruh → Boshqarish → Administratorlar → Administrator qo'shish.",
    ],

    'stats' => [
        // HTML (`:id` in <code>).
        'not_linked' => "Hisobot o'qituvchilar uchun.\nMarkaz xodimi: Telegramni kabinetda ulang — Profil → «Telegramni ulash». Kabinetsiz o'qituvchi: administratorga Telegram ID raqamingizni bering: <code>:id</code>.\nAgar Siz o'quvchi bo'lsangiz — /today ni oching.",
    ],

    // Deep links of the `/org` cabinet (docs/13 §6.2): `/start link_<token>` connects a staff user's Telegram (answered in the
    // sender's Telegram language), `/start grp_<token>` / `/link <token>` in a group links it to the center (answered in the
    // center's language; every refusal — one neutral text, the reason is shown in the cabinet).
    'link' => [
        'account_linked' => "✅ Telegram :name akkauntiga ulandi.\n\nEndi bot Sizni o'qituvchi sifatida taniydi: guruhlardagi buyruqlar Sizning nomingizdan ishlaydi, bu chatda esa bot Sizga o'qituvchi sifatida javob beradi — /help ga qarang.",
        'account_taken' => "Bu Telegram kabinetning boshqa akkauntiga ulangan. Avval uni o'sha yerda uzing (Profil → «Uzish»), so'ng havolani qayta oching.",
        'account_refused' => 'Ulash havolasi yaroqsiz yoki eskirgan. Markaz kabinetini oching → Profil → «Telegramni ulash» va yangi havola oling.',
        'group_linked' => "✅ «:title» guruhi «:organization» markaziga bog'landi.",
        'group_next_active' => "Guruh faol: o'qituvchi buyruqlari ishlaydi. Endi kabinetda guruh kursini tanlang: Guruhlar → «Guruh kursi».",
        'group_next_pending' => "Guruh LexiFlow administratori tomonidan faollashtirilishini kutmoqda — shundan keyin o'qituvchi buyruqlari ishlaydi. Guruh kursini hoziroq tanlash mumkin: kabinet → Guruhlar → «Guruh kursi».",
        'group_refused' => "Guruhni bog'lab bo'lmadi. Markaz kabinetini oching → Guruhlar → «Guruhni bog'lash»: u yerda sababi ko'rinadi va yangi havola olish mumkin.",
    ],

    'account' => [
        'restored' => "Qaytganingizdan xursandmiz! Akkauntingiz tiklandi — so'zlaringiz va natijalaringiz joyida.",
        'restored_pro' => "Akkaunt o'chirilganda Pro avtoyangilashi o'chirilgan edi. Uni «Obuna» bo'limida qayta yoqishingiz mumkin.",
    ],

    'delete_account' => [
        'settings_button' => "Akkauntni o'chirish",
        'confirm' => "LexiFlow akkauntini o'chirasizmi?\n\nMa'lumotlaringiz :days kundan keyin butunlay o'chiriladi. Bu muddat tugamaguncha qaytishingiz mumkin — botga /start yuborishning o'zi kifoya. To'lov yozuvlari qonunga ko'ra saqlanadi, Pro obuna to'xtatiladi.",
        'yes_button' => "Ha, o'chirish",
        'no_button' => 'Bekor qilish',
        'canceled' => "Mayli, akkaunt o'chirilmadi.",
        'done' => "Akkaunt o'chirildi. Ma'lumotlar :days kundan keyin butunlay o'chiriladi. Fikringiz o'zgarsa — /start yuboring, akkaunt qaytadi.",
        'none' => "Akkaunt yo'q yoki allaqachon o'chirilgan. Qaytish uchun /start yuboring.",
        'blocked' => "Akkauntga kirish cheklangan. Qo'llab-quvvatlash xizmatiga murojaat qiling: /paysupport.",
        'use_private' => "Akkaunt faqat shaxsiy chatda o'chiriladi. Bot bilan chatni oching va /delete_account yuboring.",
    ],

    'promo' => [
        'usage' => 'Promokodni quyidagicha yuboring: /promo KOD',
        'use_private' => "Promokodni guruhda ko'rsatmagan ma'qul. Bot bilan shaxsiy chatni oching va /promo KOD yuboring.",
        'blocked' => "Akkauntga kirish cheklangan. Qo'llab-quvvatlash xizmatiga murojaat qiling: /paysupport.",
        'rate_limited' => "Juda ko'p urinish. Bir daqiqa kutib, qayta urinib ko'ring.",
        'success_until' => 'Tayyor! LexiFlow Pro :date gacha faol.',
        'success_forever' => 'Tayyor! LexiFlow Pro muddatsiz faol.',
        'errors' => [
            'promo_not_found' => 'Promokod topilmadi. Xato yozilmaganini tekshiring.',
            'promo_expired' => 'Promokodning amal qilish muddati tugagan.',
            'promo_exhausted' => 'Bu promokoddan foydalanish limiti tugagan.',
            'promo_already_redeemed' => 'Siz bu promokodni allaqachon faollashtirgansiz.',
            'promo_not_applicable' => 'Sizda allaqachon muddatsiz Pro bor — promokod kerak emas.',
            'plan_unknown' => "Bu promokod hozircha ishlamayapti. Qo'llab-quvvatlash xizmatiga yozing.",
        ],
    ],

];
