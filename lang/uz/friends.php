<?php

declare(strict_types=1);

return [

    'anonymous' => 'Tanishingiz',

    // `/start f_<code>`, docs/13 §7.5: the link owner is only an address, the opener is asked first.
    'offer' => [
        'text' => ":name'ning taklif havolasini ochdingiz. :name'ga do'stlik so'rovini yuboraylikmi?\n\nDo'stlar bir-birining seriyasini va o'rganilgan so'zlar sonini ko'radi va musobaqa tashlashi mumkin: hafta davomida kim ko'proq takrorlaydi.",
        'send' => "So'rov yuborish",
        'add' => "Do'stlarga qo'shish",
        'skip' => 'Hozir emas',
    ],

    // A request addressed to the learner (a DM, or a pending request met by /start f_<code>).
    'incoming' => [
        'text' => ":name sizni LexiFlow'da do'st sifatida qo'shmoqchi.",
        'accept' => 'Qabul qilish',
        'decline' => 'Rad etish',
    ],

    'request_sent' => "So'rov yuborildi: :name. Qabul qilishsa, xabar beramiz.",
    'already_sent' => "Siz :name'ga allaqachon so'rov yuborgansiz — javob kutilmoqda.",
    'skipped' => "Xo'p, so'rov yuborilmadi.",
    'accepted' => "Tayyor: endi siz va :name do'stsiz. Do'stlar ro'yxati LexiFlow ilovasida.",
    'declined' => 'Xo\'p, taklif rad etildi.',
    'already' => "Siz va :name allaqachon do'stsiz.",

    // Why a link / button was refused, by FriendshipException::$errorCode.
    'errors' => [
        'invalid_link' => 'Havola yaroqsiz yoki eskirgan.',
        'friendship_self' => "Bu sizning o'z havolangiz. Uni do'stingizga yuboring.",
        'friend_unavailable' => 'Bu havola mavjud emas.',
        'friend_blocked' => 'Bu havola mavjud emas.',
        'friendship_not_found' => 'Bu taklif endi amal qilmaydi.',
        'not_recipient' => 'Taklifga faqat taklif qilingan kishi javob bera oladi.',
        'friend_limit' => "Sizda do'stlar soni allaqachon maksimal.",
        'friend_limit_other' => "Do'stingizda do'stlar soni allaqachon maksimal.",
        'generic' => "Bajarib bo'lmadi. Keyinroq urinib ko'ring.",
    ],

    'metric' => [
        'reviews' => "ko'proq kartochka takrorlaydi",
        'new_words' => "ko'proq yangi so'z o'rganadi",
    ],

    // DMs (background): a friend accepted, a challenge proposed / accepted / finished.
    'dm' => [
        'friend_accepted' => ":name taklifingizni qabul qildi: endi siz do'stsiz!",
        'challenge_proposed' => ':name sizga musobaqa tashlamoqda: :days kun ichida kim :metric? Qabul qilsangiz, musobaqa ertaga boshlanadi.',
        'challenge_accepted' => ':name musobaqangizni qabul qildi! Boshlanishi — :starts, tugashi — :ends.',
        'challenge_won' => ":name bilan musobaqa yakunlandi: siz g'olibsiz! Hisob :mine : :theirs.",
        'challenge_lost' => ":name bilan musobaqa yakunlandi: :name g'alaba qozondi. Hisob :mine : :theirs. Qayta bellashuv — ilovada!",
        'challenge_draw' => ':name bilan musobaqa durang bilan tugadi: :mine : :theirs.',
        'button' => 'LexiFlow ilovasini ochish',
    ],

    'challenge' => [
        'accept' => 'Musobaqani qabul qilish',
        'decline' => 'Rad etish',
        'accepted' => 'Musobaqa qabul qilindi! Boshlanishi — :starts, tugashi — :ends.',
        'declined' => "Xo'p, musobaqa rad etildi.",
        'errors' => [
            'challenge_not_found' => 'Bu musobaqa endi amal qilmaydi.',
            'challenge_closed' => 'Bu musobaqa endi amal qilmaydi.',
            'not_opponent' => 'Musobaqaga faqat unga yo\'llangan kishi javob bera oladi.',
            'not_friends' => "Musobaqani faqat do'stga tashlash mumkin.",
            'generic' => "Bajarib bo'lmadi. Keyinroq urinib ko'ring.",
        ],
    ],

];
