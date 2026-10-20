<?php

declare(strict_types=1);

/*
 * Markaz kabineti `/org` (docs/13 §6.3). Lotin yozuvi; tekshiruv ro'yxati — docs/I18N_UZ_REVIEW.md.
 */
return [

    'nav' => [
        'dashboard' => 'Umumiy ko\'rinish',
        'members' => 'O\'quvchilar',
        'groups' => 'Guruhlar',
        'invites' => 'Takliflar',
        'teachers' => 'O\'qituvchilar',
        'assignments' => 'Vazifalar',
        'reports' => 'Hisobotlar',
        'billing' => 'To\'lov',
        'settings' => 'Markaz sozlamalari',
    ],

    'members' => [
        'singular' => 'O\'quvchi',
        'plural' => 'O\'quvchilar',
        'columns' => [
            'learner' => 'O\'quvchi',
            'username' => 'Username',
            'seat' => 'O\'rin',
            'status' => 'Holat',
            'joined_via' => 'Qanday qo\'shildi',
            'joined_at' => 'Qo\'shilgan',
            'last_seen' => 'Oxirgi faollik',
            'seated_at' => 'O\'rin olingan',
            'streak' => 'Kunlar seriyasi',
        ],
        'seat' => [
            'seated' => 'O\'rin egallagan',
            'waitlisted' => 'Navbatda',
            'none' => 'O\'rinsiz',
        ],
        'status' => [
            'active' => 'Faol',
            'left' => 'Chiqib ketgan',
            'removed' => 'Chiqarilgan',
        ],
        'via' => [
            'invite' => 'Kod orqali',
            'group' => 'Guruh orqali',
            'admin' => 'Qo\'lda qo\'shilgan',
        ],
        'actions' => [
            'add' => 'O\'quvchi qo\'shish',
            'remove' => 'Markazdan chiqarish',
            'remove_confirm' => 'O\'quvchi o\'rnini va markaz bergan Pro-ni yo\'qotadi. Bo\'shagan o\'rin navbatdagi birinchi o\'quvchiga o\'tadi.',
        ],
        'fields' => [
            'telegram' => 'O\'quvchining Telegrami',
            'telegram_help' => 'Botga allaqachon yozgan o\'quvchining @username yoki raqamli Telegram ID si. Faqat aniq moslik bo\'yicha qidiriladi.',
        ],
        'errors' => [
            'learner_not_found' => 'Bunday Telegramli o\'quvchi topilmadi. U avval botni ishga tushirishi kerak.',
            'organization_suspended' => 'Markaz to\'xtatilgan — yangi o\'quvchilar qo\'shilmaydi.',
            'not_a_member' => 'O\'quvchi markaz a\'zosi emas.',
            'invite_invalid' => 'Taklif kodi yaroqsiz.',
            'invite_expired' => 'Taklif kodi muddati tugagan.',
            'invite_exhausted' => 'Taklif kodining foydalanishlari tugagan.',
        ],
        'notifications' => [
            'added' => 'O\'quvchi qo\'shildi: :seat.',
            'removed' => 'O\'quvchi markazdan chiqarildi.',
        ],
    ],

    'groups' => [
        'singular' => 'Guruh',
        'plural' => 'Guruhlar',
        'columns' => [
            'title' => 'Nomi',
            'status' => 'Holat',
            'learners' => 'O\'quvchilar',
            'teachers' => 'O\'qituvchilar',
            'since' => 'Qo\'shilgan',
        ],
        'status' => [
            'active' => 'Faol',
            'pending' => 'Faollashtirish kutilmoqda',
            'disabled' => 'O\'chirilgan',
        ],
        'actions' => [
            'link' => 'Guruhni bog\'lash',
            'link_help' => 'Bot guruhga allaqachon qo\'shilgan bo\'lishi, siz esa uning yaratuvchisi yoki administratori bo\'lishingiz kerak. Profilda Telegram ID ko\'rsatilgan bo\'lsin.',
            'unlink' => 'Ajratish',
            'unlink_confirm' => 'Markazga shu guruh orqali qo\'shilgan o\'quvchilar, boshqa guruhlarda bo\'lmasa, o\'rnini yo\'qotadi.',
        ],
        'fields' => [
            'chat_id' => 'Chat ID',
            'chat_id_help' => 'Guruhning raqamli ID si, masalan -1001234567890. Bot uni guruhda /help buyrug\'i bilan ko\'rsatadi.',
        ],
        'errors' => [
            'group_not_found' => 'Bot bunday guruhni bilmaydi. Avval botni guruhga qo\'shing.',
            'group_taken' => 'Guruh boshqa markazga bog\'langan.',
            'owner_telegram_missing' => 'Profilda Telegram ID ni ko\'rsating — usiz guruhdagi huquqlarni tekshirib bo\'lmaydi.',
            'not_chat_admin' => 'Siz bu guruhning administratori emassiz. Faqat o\'z guruhingizni bog\'lash mumkin.',
            'telegram_unavailable' => 'Telegram javob bermadi. Bir daqiqadan so\'ng qayta urinib ko\'ring.',
            'not_linked' => 'Bu guruh markazga bog\'lanmagan.',
        ],
        'notifications' => [
            'linked' => '«:title» guruhi markazga bog\'landi.',
            'unlinked' => 'Guruh markazdan ajratildi.',
        ],
    ],

    'invites' => [
        'singular' => 'Taklif',
        'plural' => 'Takliflar',
        'columns' => [
            'code' => 'Kod',
            'label' => 'Yorliq',
            'group' => 'Guruh',
            'uses' => 'Foydalanishlar',
            'expires_at' => 'Amal qilish muddati',
            'active' => 'Faol',
            'link' => 'Havola',
        ],
        'copied' => 'Havola nusxalandi',
        'actions' => [
            'create' => 'Taklif yaratish',
            'deactivate' => 'O\'chirish',
        ],
        'fields' => [
            'label' => 'Yorliq (masalan, «7B guruhi»)',
            'max_uses' => 'Maksimal foydalanishlar (bo\'sh — cheklovsiz)',
            'expires_at' => 'Amal qilish muddati (bo\'sh — muddatsiz)',
            'group' => 'Guruh',
            'group_help' => 'Faqat hisobotlar uchun yorliq: chatga o\'quvchi o\'zi qo\'shiladi.',
        ],
        'errors' => [
            'foreign_group' => 'Bu guruh markazga tegishli emas.',
        ],
        'notifications' => [
            'created' => ':code taklifi yaratildi.',
            'deactivated' => 'Taklif o\'chirildi.',
        ],
    ],

    'teachers' => [
        'singular' => 'Xodim',
        'plural' => 'O\'qituvchilar',
        'columns' => [
            'name' => 'Ism',
            'email' => 'Email',
            'role' => 'Rol',
            'telegram' => 'Telegram bog\'langan',
            'groups' => 'Guruhlar',
            'last_login' => 'Oxirgi kirish',
        ],
        'never_logged_in' => 'kirmagan',
        'role' => [
            'owner' => 'Egasi',
            'teacher' => 'O\'qituvchi',
        ],
        'actions' => [
            'invite' => 'O\'qituvchini taklif qilish',
            'groups' => 'Guruhlar',
            'role' => 'Rol',
            'resend' => 'Taklifni qayta yuborish',
            'remove' => 'Markazdan chiqarish',
        ],
        'fields' => [
            'email' => 'Email',
            'name' => 'Ism (ixtiyoriy)',
        ],
        'errors' => [
            'already_staff' => 'Bu email markaz xodimlari ro\'yxatida allaqachon bor.',
            'not_staff' => 'Xodim bu markazda topilmadi.',
            'last_owner' => 'Markazda kamida bitta egasi qolishi kerak.',
            'foreign_group' => 'Tanlangan guruhlardan biri markazga tegishli emas.',
            'invalid_email' => 'To\'g\'ri email kiriting.',
        ],
        'notifications' => [
            'invited' => 'Taklif emailga yuborildi.',
            'groups_saved' => 'O\'qituvchi guruhlari saqlandi.',
            'role_saved' => 'Rol o\'zgartirildi.',
            'resent' => 'Taklif qayta yuborildi.',
            'removed' => 'Xodim markazdan chiqarildi.',
        ],
    ],

    'settings' => [
        'fields' => [
            'name' => 'Markaz nomi',
            'contact_email' => 'Aloqa uchun email',
            'contact_phone' => 'Aloqa uchun telefon',
            'contact_telegram' => 'Telegramdagi aloqa',
            'timezone' => 'Vaqt mintaqasi',
            'locale' => 'Kabinet tili',
        ],
    ],

    'profile' => [
        'fields' => [
            'telegram_user_id' => 'Mening Telegram ID m',
            'telegram_user_id_help' => 'Telegram akkauntingizning raqamli ID si (bot uni /stats bo\'yicha ko\'rsatadi). Guruhlardagi o\'qituvchi buyruqlari va guruhlarni bog\'lash uchun kerak.',
        ],
    ],

    'assignments' => [
        'singular' => 'Vazifa',
        'plural' => 'Vazifalar',
        'columns' => [
            'group' => 'Guruh',
            'lesson' => 'Dars',
            'title' => 'Nomi',
            'due_at' => 'Muddat',
            'status' => 'Holati',
            'progress' => 'Bajarganlar',
            'threshold' => 'Chegara',
            'created_at' => 'Yaratilgan',
            'learner' => 'O\'quvchi',
            'progress_pct' => 'Jarayon',
            'completed_at' => 'Bajarilgan',
            'result' => 'Natija',
        ],
        'status' => [
            'active' => 'Faol',
            'overdue' => 'Muddat o\'tgan',
            'closed' => 'Yopilgan',
        ],
        'result' => [
            'done' => 'Bajarilgan',
            'late' => 'Kechikib bajarilgan',
            'in_progress' => 'Jarayonda',
            'not_started' => 'Boshlanmagan',
        ],
        'lesson_option' => ':stage-bosqich, :lesson-dars: :title',
        'progress_of' => ':total dan :done',
        'actions' => [
            'create' => 'Vazifa yaratish',
            'close' => 'Yopish',
            'close_confirm' => 'Yopilgan vazifani qayta ochib bo\'lmaydi. Yakuniy natija hisobotda saqlanadi.',
        ],
        'fields' => [
            'group' => 'Guruh',
            'group_help' => 'Faqat kursi tanlangan markaz guruhlari.',
            'lesson' => 'Dars',
            'lesson_help' => 'Tanlangan guruh kursining darslari.',
            'title' => 'Nomi',
            'title_help' => 'Bo\'sh qoldirilsa — dars nomi. O\'quvchilarga va guruhda ko\'rsatiladi.',
            'due_at' => 'Bajarish muddati',
            'threshold' => 'Bajarilish chegarasi, %',
            'threshold_help' => 'Vazifa bajarilgan hisoblanishi uchun dars so\'zlarining qancha qismi kamida bir marta o\'tilgan bo\'lishi kerak.',
        ],
        'results' => [
            'heading' => 'O\'quvchilar natijalari',
            'privacy' => 'Faqat shu dars so\'zlari bo\'yicha jarayon ko\'rsatiladi; o\'quvchilarning shaxsiy mashg\'ulotlari ochilmaydi.',
        ],
        'errors' => [
            'foreign_group' => 'Bu guruh markazga tegishli emas.',
            'group_without_course' => 'Guruhga kurs tanlanmagan — dars berish uchun guruh kursini tanlang.',
            'lesson_not_in_course' => 'Bu dars guruh kursiga kirmaydi.',
            'invalid_due' => 'Muddat kelajakda bo\'lishi kerak.',
            'invalid_threshold' => 'Chegara 1 dan 100 gacha bo\'lishi kerak.',
        ],
        'notifications' => [
            'created' => 'Vazifa yaratildi. E\'lon guruhga yuboriladi.',
            'closed' => 'Vazifa yopildi.',
        ],
    ],

    'reports' => [
        'filters' => [
            'group' => 'Guruh',
            'all_groups' => 'Barcha guruhlar',
            'period' => 'Davr',
            'period_7' => '7 kun',
            'period_30' => '30 kun',
            'period_custom' => 'O\'z davrim',
            'from' => 'Dan',
            'to' => 'Gacha',
        ],
        'period_label' => 'Davr: :from — :to (shu kunlar ham kiradi)',
        'errors' => [
            'invalid_range' => 'To\'g\'ri davrni kiriting: "Dan" sanasi "Gacha" sanasidan keyin bo\'lmasligi kerak.',
            'range_too_long' => 'Davr :days kundan uzun bo\'lishi mumkin emas.',
        ],
        'learners' => [
            'heading' => 'O\'quvchilar',
            'empty' => 'Hisobot uchun o\'quvchilar yo\'q.',
        ],
        'groups' => [
            'heading' => 'Guruhlar',
            'empty' => 'Hisobot uchun guruhlar yo\'q.',
        ],
        'columns' => [
            'learner' => 'O\'quvchi',
            'reviews' => 'Takrorlashlar',
            'new_words' => 'Yangi so\'zlar',
            'days_active' => 'Faol kunlar',
            'goal_met_days' => 'Maqsad bajarilgan kunlar',
            'streak' => 'Ketma-ket kunlar',
            'assignments' => 'Vazifalar (bajarilgan / jami)',
            'last_seen' => 'Oxirgi faollik',
            'group' => 'Guruh',
            'learners' => 'O\'quvchilar soni',
            'active_learners' => 'Faollar',
        ],
        'actions' => [
            'csv_learners' => 'CSV yuklab olish (o\'quvchilar)',
            'csv_groups' => 'CSV yuklab olish (guruhlar)',
        ],
        'csv' => [
            'learner' => 'O\'quvchi',
            'username' => 'Username',
            'reviews' => 'Takrorlashlar',
            'new_words' => 'Yangi so\'zlar',
            'days_active' => 'Faol kunlar',
            'goal_met_days' => 'Maqsad bajarilgan kunlar',
            'streak' => 'Ketma-ket kunlar',
            'assignments_done' => 'Bajarilgan vazifalar',
            'assignments_total' => 'Jami vazifalar',
            'last_seen' => 'Oxirgi faollik',
            'group' => 'Guruh',
            'learners' => 'O\'quvchilar soni',
            'active_learners' => 'Faol o\'quvchilar',
        ],
        'privacy' => 'Hisobotda faqat kunlik yakunlar (takrorlashlar, yangi so\'zlar, faol kunlar) va vazifa natijalari bor; o\'quvchilarning shaxsiy mashg\'ulotlari ko\'rsatilmaydi.',
        'never' => 'hech qachon',
    ],

    'stubs' => [
        'billing' => 'Tarif tanlash va to\'lov keyingi yangilanishda paydo bo\'ladi. Hozircha tarifni platforma administratori faollashtiradi.',
    ],

    'billing' => [
        'rows' => [
            'tier' => 'Tarif',
            'status' => 'Obuna holati',
            'ends_at' => 'To\'langan muddat',
            'seats' => 'O\'rinlar (band / limit)',
            'waitlisted' => 'O\'rin kutayotganlar',
        ],
        'none' => 'yo\'q',
        'status' => [
            'trialing' => 'Sinov davri',
            'active' => 'Faol',
            'past_due' => 'To\'lov kutilmoqda',
            'canceled' => 'Bekor qilingan (davr oxirigacha)',
        ],
    ],

    'dashboard' => [
        'seats' => 'O\'rinlar',
        'seats_over_limit' => 'Limit oshib ketgan: tarifni oshiring',
        'seats_free' => 'Bo\'sh: :count',
        'not_sponsoring' => 'Faol obuna yo\'q — o\'quvchilar Pro-siz',
        'waitlisted' => 'O\'rin kutayotganlar',
        'waitlisted_help' => 'O\'rin bo\'shaganda oladilar',
        'subscription' => 'Obuna',
        'subscription_none' => 'Faol obuna yo\'q',
        'subscription_perpetual' => 'Muddatsiz',
        'subscription_until' => ':date gacha',
        'members' => 'Markazdagi o\'quvchilar',
        'groups_count' => 'Guruhlar: :count',
        'active_7d' => '7 kunda faollar',
        'active_7d_help' => 'Takrorlash yoki yangi so\'zlari bo\'lgan o\'quvchilar',
        'reviews_7d' => '7 kundagi takrorlashlar',
        'new_words_7d' => 'Yangi so\'zlar: :count',
    ],

    'mail' => [
        'invitation' => [
            'subject' => '«:organization» markazi kabinetiga taklif',
            'heading' => 'Sizni «:organization» markaziga taklif qilishdi',
            'intro' => ':inviter sizni LexiFlow Pro platformasidagi «:organization» markazi kabinetiga qo\'shdi.',
            'set_password' => 'Quyidagi havola orqali parol o\'rnating — u :hours soat amal qiladi va bir marta ishlaydi.',
            'button_set_password' => 'Parol o\'rnatish',
            'existing_account' => 'Odatdagi parolingiz bilan kiring — markaz kabinetda allaqachon mavjud.',
            'button_open' => 'Kabinetni ochish',
            'ignore' => 'Agar bu xatni kutmagan bo\'lsangiz, shunchaki e\'tiborsiz qoldiring.',
        ],
    ],

    'bot' => [
        'not_staff' => '/org buyrug\'i o\'quv markazlari xodimlari uchun. Telegramingiz hech bir kabinetga bog\'lanmagan: kabinet profilida Telegram ID ni ko\'rsating.',
        'header' => 'Sizning markazlaringiz:',
        'line' => ":name — :role\nO'rinlar: :seats · 7 kunda faollar: :active\n:url",
        'line_teacher' => ":name — :role\n7 kunda faollar: :active\n:url",
        'seats_unlimited' => 'cheklovsiz',
        'seats_none' => 'obuna yo\'q',
    ],

];
