<?php

declare(strict_types=1);

// The friends' cup in a Telegram group (docs/33 §6, K6): the messages of the bot in the chat are in the language of the cup
// (`cups.locale`), the toasts under the buttons in the language of the one who tapped. Plain text, no HTML: the names of the
// participants (`display_name`) go in as they are. `:brand` is config('app.brand').
return [
    'photo' => [
        'label' => 'Guruhdagi turnir',
        'title' => "Do'stlar kubogi",
        'description' => ":days · kuniga :tasks ta so'z · :best hisobga olinadi",
        'final_title' => 'Kubok yakunlari',
        'final_description' => "Do'stlar kubogi · :level daraja",
    ],

    'days' => ':count kun',
    'best_days' => ':count ta eng yaxshi kun',

    'proposal' => [
        'text' => "🏆 :brand do'stlar kubogi\n«:group» · :level daraja\n\nKetma-ket :days — har kuni :tasks ta so'zdan iborat raund, har bir to'g'ri javob uchun 2 ball. :best hisobga olinadi. Bu chatdagi har kim qatnashishi mumkin.\n\nKubokni chat administratori e'lon qila oladi.",
    ],

    'card' => [
        'title' => "🏆 :brand do'stlar kubogi · «:group» · :level",
        'scheduled' => 'Boshlanish :start, 00:00 · yakun :end, 00:00',
        'running' => ':days kundan :day-kun · yakun :end, 00:00',
        'finished' => 'Kubok yakunlandi',
        'canceled' => 'Kubok bekor qilindi',
        'expired' => "Taklif muddati o'tdi",
        'no_players' => "Kubok bekor qilindi: ikki ishtirokchi to'planmadi",
        'join_hint' => '«Qatnashish» tugmasini bosing. Boshlash uchun kamida ikki ishtirokchi kerak.',
        'empty' => "Hozircha hech kim ball to'plamadi.",
        'row' => ':place. :name — :points',
        'left' => '(chiqib ketgan)',
        'more' => '…va yana :count',
        'participants' => 'Ishtirokchilar: :count',
        'summary_at' => 'kun yakuni :time da',
        'bot_not_admin' => "⚠️ Bot chat administratori emas — ishtirokni tekshirib bo'lmaydi. Botni administrator qiling.",
    ],

    'summary' => [
        'title' => "📊 Do'stlar kubogi · :days kundan :day-kun yakunlari",
        'footer' => 'Yakun :end, 00:00. Yangi kun raundi yarim tunda ochiladi.',
        'footer_last' => "Bu oxirgi kun: raundni yarim tungacha o'ynash mumkin. Yakun :end, 00:00 — undan keyin kubok natijalari.",
    ],

    'final' => [
        'title' => "🏆 Do'stlar kubogi yakunlandi!",
        'group' => '«:group» · :level daraja',
        'winner' => 'Tabriklaymiz: :names!',
        'empty' => "Hech kim ball to'plamadi.",
    ],

    // A duel of the cup published in the chat (docs/33 §6.1, §5.7): one message, edited with the outcome - never a new one.
    'duel' => [
        'open_any' => '⚔️ :challenger kubokning istalgan ishtirokchisini duelga chaqiradi',
        'open_to' => '⚔️ :challenger duelga chaqiradi: :opponent',
        'rated' => "Hisobli duel: har kimga o'z to'g'ri javobi uchun kubok bali, :max tagacha.",
        'friendly' => "O'rtoqlik o'yini: kubok ballarisiz.",
        'until' => 'Chaqiruv :time gacha amal qiladi.',
        'playing' => '⚔️ Duel davom etmoqda: :challenger — :opponent',
        'result' => '⚔️ Duel: :challenger :challenger_points : :opponent_points :opponent',
        'winner' => "G'alaba: :name",
        'draw' => 'Durang',
        'not_counted' => 'Natija kubok yakunlangandan keyin keldi va hisobga olinmadi.',
        'friendly_done' => "O'rtoqlik o'yini, kubok ballarisiz.",
        'fell_through' => "Chaqiruv bo'lib o'tmadi.",
    ],

    // The DMs of a duel (docs/33 §5.7, D2): a private challenge to the one challenged; "accepted" to the challenger - the room of the
    // match waits only 30 seconds for him.
    'duel_dm' => [
        'challenged' => '⚔️ :name sizni «:group» kubogida duelga chaqirmoqda.',
        'rated' => "Hisobli duel: har bir o'z to'g'ri javobingiz uchun kubok bali.",
        'friendly' => "O'rtoqlik o'yini: kubok ballarisiz.",
        'accepted' => "⚔️ :name «:group» kubogidagi chaqiruvingizni qabul qildi. O'yin kutmoqda — 30 soniya ichida kiring.",
    ],

    'command' => [
        'exists' => 'Chatda allaqachon «:group» kubogi bor. Jadval va qatnashish — quyidagi tugma orqali.',
        'draft' => "Chatda «:group» kubogining qoralamasi bor. Chat administratori uni quyidagi tugma orqali sozlab, e'lon qilishi mumkin.",
        'material' => "Hozir kubok mavjud emas: bu daraja uchun so'zlar yetarli emas.",
    ],

    'welcome' => [
        'line' => "🏆 Do'stlar bilan bellashmoqchimisiz? Do'stlar kubogini yarating: shu chatning o'zida bir hafta qisqa raundlar.",
    ],

    'toast' => [
        'not_admin' => 'Buni faqat chat administratori qila oladi.',
        'stale' => 'Bu tugma endi ishlamaydi.',
        'exists' => "Bu chatda allaqachon kubok bor — uning kartasini ko'ring.",
        'published' => "Kubok e'lon qilindi!",
        'canceled' => 'Kubok bekor qilindi.',
        'bot_not_admin' => 'Botni chat administratori qiling. Maxsus huquqlar kerak emas.',
        'unverifiable' => "Huquqlarni tekshirib bo'lmadi, yana urinib ko'ring.",
        'material' => "Bu daraja uchun hozircha so'zlar yetarli emas.",
        'cooldown' => 'Kubokni 10 daqiqada bir marta taklif qilish mumkin.',
        'paused' => 'Bu chatda kuboklar hozir mavjud emas.',
        'failed' => "Bo'lmadi. Keyinroq urinib ko'ring.",
    ],
];
