<?php

declare(strict_types=1);

return [

    // `/start org_<code>` (docs/13 §6.2): the confirmation shown after the onboarding (or at once for a learner who has finished it).
    'offer' => [
        'text' => "«:organization» o'quv markazi sizni LexiFlow'ga taklif qilyapti. Markazdan Pro olish uchun qo'shilasizmi?",
        'join' => "Qo'shilish",
        'skip' => 'Hozir emas',
    ],

    'already' => 'Siz allaqachon «:organization» markazidasiz.',

    'skipped' => "Mayli. Markaz havolasi orqali istalgan vaqtda qo'shilishingiz mumkin.",

    // DM after joining (invite / added by an administrator) and when a waiting place becomes free.
    'joined' => [
        'seated' => "Siz «:organization» markazidasiz. Pro yoqildi, endi cheklovsiz shug'ullaning.",
        'waitlisted' => "Siz «:organization» markazidasiz, lekin hozircha bo'sh joy yo'q. Markaz administratoriga xabar bering: joy bo'shashi bilan Pro avtomatik yoqiladi.",
        'button' => "LexiFlow'ni ochish",
    ],

    'seated' => [
        'text' => "«:organization» markazida siz uchun joy bo'shadi. Pro faol!",
        'button' => "LexiFlow'ni ochish",
    ],

    // Why a link / button was refused, by OrganizationMembershipException::$errorCode.
    'errors' => [
        'invite_invalid' => "Markaz havolasi yaroqsiz yoki o'chirilgan. Administratordan yangisini so'rang.",
        'invite_expired' => "Markaz havolasining muddati tugagan. Administratordan yangisini so'rang.",
        'invite_exhausted' => "Bu markaz havolasidan foydalanish limiti tugagan. Administratordan yangisini so'rang.",
        'organization_suspended' => "Markaz hozir o'quvchilarni qabul qilmayapti. Markaz administratoriga murojaat qiling.",
        'not_a_member' => 'Siz endi bu markazda emassiz.',
        'membership_not_found' => 'Siz endi bu markazda emassiz.',
        'membership_via_group' => "Siz «:organization» markaziga guruh orqali qo'shilgansiz. Markazdan chiqish uchun Telegram'da markaz guruhidan chiqing.",
        'generic' => "Bajarilmadi. Keyinroq qayta urinib ko'ring.",
    ],

    // `/settings` -> "My centers".
    'settings_button' => 'Markazlarim',

    'list' => [
        'title' => 'Markazlaringiz:',
        'empty' => 'Siz hech qaysi markazda emassiz.',
        'line' => '• «:organization» — :state',
        'state' => [
            'seated' => 'Pro faol',
            'waitlisted' => 'joy navbatida',
        ],
        'group_note' => "Guruh orqali qo'shilgan markazdan bu yerda chiqib bo'lmaydi: Telegram'da markaz guruhidan chiqing.",
        'leave' => '«:organization» markazidan chiqish',
    ],

    'leave' => [
        'confirm' => "«:organization» markazidan chiqasizmi? Markaz bergan Pro o'chadi, shaxsiy obunangiz (agar bo'lsa) saqlanib qoladi.",
        'yes' => 'Ha, chiqaman',
        'no' => 'Bekor qilish',
        'done' => 'Siz «:organization» markazidan chiqdingiz.',
        'cancelled' => "Mayli, hech narsa o'zgarmadi.",
    ],

];
