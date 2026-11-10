<?php

declare(strict_types=1);

return [

    // `/start org_<code>` (docs/13 §6.2): the confirmation shown after the onboarding (or at once for a learner who has finished it).
    'offer' => [
        'text' => "«:organization» o'quv markazi sizni LexiFlow'ga taklif qilmoqda. Markaz beradigan Pro imkoniyatini olish uchun qo'shilasizmi?",
        'join' => "Qo'shilish",
        'skip' => 'Hozir emas',
    ],

    'already' => 'Siz allaqachon «:organization» markazidasiz.',

    // A membership offered by the center's staff from the cabinet (audit 2026-11-10 M2): the learner decides in the bot.
    'invite' => [
        'text' => "«:organization» o'quv markazi Sizni qo'shilishga taklif qilmoqda. Qo'shilsangiz, markazda bo'sh o'rin bo'lsa, markaz bergan Pro'ni olasiz, markaz esa LexiFlow'dagi faolligingizni ko'radi. Qo'shilasizmi?",
        'accept' => "Qo'shilish",
        'decline' => 'Rad etish',
        'declined' => "Mayli, Siz «:organization» markazining taklifini rad etdingiz. Hech narsa o'zgarmadi.",
    ],

    'skipped' => "Mayli. Markaz havolasi orqali istalgan vaqtda qo'shilishingiz mumkin.",

    // DM after joining (invite / added by an administrator) and when a waiting place becomes free.
    'joined' => [
        'seated' => "Siz «:organization» markazidasiz. Pro faol — cheklovlarsiz shug'ullaning.",
        'waitlisted' => "Siz «:organization» markazidasiz, lekin hozircha bo'sh joy yo'q. Markaz administratoriga murojaat qiling — joy bo'shashi bilan Pro avtomatik yoqiladi.",
        'button' => "LexiFlow'ni ochish",
    ],

    'seated' => [
        'text' => "«:organization» markazida siz uchun joy bo'shadi. Pro faol!",
        'button' => "LexiFlow'ni ochish",
    ],

    // Why a link / button was refused, by OrganizationMembershipException::$errorCode.
    'errors' => [
        'invite_invalid' => "Markaz havolasi yaroqsiz yoki o'chirilgan. Administratordan yangisini so'rang.",
        'invite_expired' => "Markaz havolasining amal qilish muddati tugagan. Administratordan yangisini so'rang.",
        'invite_exhausted' => "Markaz havolasi ruxsat etilgan maksimal marta ishlatilgan. Administratordan yangisini so'rang.",
        'organization_suspended' => "Markaz hozircha o'quvchilarni qabul qilmaydi. Markaz administratoriga murojaat qiling.",
        'not_a_member' => 'Siz endi bu markazda emassiz.',
        'membership_not_found' => 'Siz endi bu markazda emassiz.',
        'membership_via_group' => "Siz «:organization» markazidasiz — guruh orqali. Markazdan chiqish uchun Telegram'da markaz guruhini tark eting.",
        'invite_not_pending' => 'Bu taklif endi amal qilmaydi.',
        'generic' => "Bajarilmadi. Keyinroq qayta urinib ko'ring.",
    ],

    // `/settings` -> "My centers".
    'settings_button' => 'Mening markazlarim',

    'list' => [
        'title' => 'Sizning markazlaringiz:',
        'empty' => 'Siz hech qaysi markazda emassiz.',
        'line' => '• «:organization» — :state',
        'state' => [
            'seated' => 'Pro faol',
            'waitlisted' => 'joy kutilmoqda',
        ],
        'group_note' => "Guruh orqali qo'shilgan markazlarni bu yerdan tark etib bo'lmaydi: Telegram'da markaz guruhini tark eting.",
        'leave' => '«:organization» markazini tark etish',
    ],

    'leave' => [
        'confirm' => "«:organization» markazini tark etasizmi? Markaz bergan Pro o'chadi, shaxsiy obunangiz (agar bo'lsa) saqlanadi.",
        'yes' => 'Ha, tark etaman',
        'no' => 'Bekor qilish',
        'done' => 'Siz «:organization» markazini tark etdingiz.',
        'cancelled' => "Mayli, hech narsa o'zgarmadi.",
    ],

];
