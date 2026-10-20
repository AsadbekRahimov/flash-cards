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

    'stubs' => [
        'assignments' => 'Guruhlar uchun vazifalar kabinetning keyingi yangilanishida paydo bo\'ladi.',
        'reports' => 'O\'quvchilar faolligi hisoboti kabinetning keyingi yangilanishida paydo bo\'ladi.',
    ],

    'billing' => [
        'current' => [
            'heading' => 'Joriy obuna',
        ],
        'rows' => [
            'tier' => 'Tarif',
            'status' => 'Obuna holati',
            'ends_at' => "To'langan muddat",
            'seats' => "O'rinlar (band / limit)",
            'waitlisted' => "O'rin kutayotganlar",
        ],
        'none' => "yo'q",
        'perpetual' => 'muddatsiz',
        'status' => [
            'trialing' => 'Sinov davri',
            'active' => 'Faol',
            'past_due' => "To'lov kutilmoqda",
            'canceled' => 'Bekor qilingan (davr oxirigacha)',
        ],
        'tiers' => [
            'tutor' => ['name' => 'Repetitor'],
            'center_s' => ['name' => 'Markaz S'],
            'center_m' => ['name' => 'Markaz M'],
            'center_l' => ['name' => 'Markaz L'],
        ],
        'seats_count' => "o'rinlar: :count",
        'seats_unlimited' => "o'rinlar cheklanmagan",
        'over_limit' => "Limitdan oshib ketdi: :limit o'rindan :seated tasi band (:over taga ko'p). Hech kim chiqarilmadi — tarifni oshirguningizcha yoki joy bo'shaguncha yangi o'quvchilar navbatda turadi.",
        'not_sponsoring' => "Faol obuna yo'q — markaz o'quvchilarida Pro yo'q. Quyida tarifni tanlang.",
        'waitlist_hint' => "O'rin navbatida: :count. Tarifni oshiring — ularga Pro avtomatik yoqiladi.",
        'choose' => [
            'heading' => "Tarif va to'lov",
            'description' => "To'lov obunani tanlangan muddatga uzaytiradi. Tarif almashishi to'lovdan so'ng darhol kuchga kiradi.",
            'tier' => 'Tarif',
            'period' => 'Muddat',
        ],
        'by_request' => 'kelishuv asosida (platforma administratori ulaydi)',
        'below_seated' => 'hozir bandidan (:seated) kam',
        'not_purchasable' => "Bu tarif yoki muddatni onlayn to'lab bo'lmaydi. Hisob so'rang — biz siz bilan bog'lanamiz.",
        'to_pay' => "To'lov summasi: :amount",
        'downgrade_warning' => "Hozir :seated ta o'rin band, tarifda esa :limit ta. Hech kim chiqarilmaydi, lekin joy bo'shaguncha yangi o'quvchilar kutib turadi.",
        'no_providers' => "Onlayn to'lov hozir mavjud emas. Quyida hisob so'rang.",
        'pay_with' => ":provider orqali to'lash",
        'invoice' => [
            'heading' => "Hisob so'rash",
            'description' => "Naqd pulsiz to'lov uchun: so'rovni platforma administratoriga yuboramiz, u hisob yuboradi va to'lovdan so'ng tarifni ulaydi.",
            'comment' => 'Izoh (ixtiyoriy)',
            'button' => "Hisob so'rash",
        ],
        'notifications' => [
            'invoice_requested' => "So'rov yuborildi. Biz siz bilan email orqali bog'lanamiz.",
        ],
        'history' => [
            'orders' => 'Buyurtmalar',
            'payments' => "To'lovlar",
            'empty' => "Hozircha bo'sh.",
            'number' => 'Raqam',
            'date' => 'Sana',
            'amount' => 'Summa',
            'provider' => 'Usul',
            'status' => 'Holat',
            'invoice' => 'Hisob',
            'kind' => 'Turi',
            'period_end' => "To'langan muddat",
        ],
        'order_status' => [
            'pending' => "To'lov kutilmoqda",
            'paid' => "To'langan",
            'canceled' => 'Bekor qilingan',
            'expired' => "Muddati o'tgan",
            'failed' => 'Xatolik',
        ],
        'payment_kind' => [
            'charge' => "To'lov",
            'renewal' => 'Uzaytirish',
            'refund' => 'Qaytarish',
        ],
        'payment_status' => [
            'succeeded' => "O'tkazilgan",
            'refunded' => 'Qaytarilgan',
            'failed' => "O'tkazilmagan",
        ],
        'errors' => [
            'price_not_configured' => "Bu to'lov varianti hozircha mavjud emas.",
            'provider_disabled' => "Bu to'lov usuli hozir mavjud emas.",
            'provider_not_supported' => "Bu to'lov usuli markazlar uchun mavjud emas. Payme yoki Click'ni tanlang.",
            'checkout_conflict' => "Hozir to'lov qilib bo'lmaydi: markazda avtoyangilanadigan obuna bor. Qo'llab-quvvatlashga murojaat qiling.",
            'too_many_pending' => "Markazda to'lanmagan buyurtmalar bor. Birini to'lang yoki kuting — ular o'zi yopiladi.",
            'offer_not_applicable' => "Chegirma bu xaridga qo'llanilmaydi.",
            'invalid_period' => "Bunday to'lov muddati yo'q.",
            'invalid_tier' => "Bunday tarif yo'q.",
            'provider_unavailable' => "To'lov xizmati javob bermayapti. Bir necha daqiqadan keyin urinib ko'ring.",
            'not_owner' => "To'lov faqat markaz egasiga ochiq.",
            'tier_not_purchasable' => "Bu tarifni onlayn to'lab bo'lmaydi. Hisob so'rang.",
            'invoice_not_found' => 'Buyurtma topilmadi.',
            'invoice_not_configured' => "Hisob so'rash hozir mavjud emas. Qo'llab-quvvatlashga yozing.",
            'invoice_request_throttled' => "Bunday so'rov allaqachon yuborilgan. Bir necha daqiqa kuting.",
            'unknown' => "Amalni bajarib bo'lmadi. Keyinroq urinib ko'ring.",
        ],
    ],

    'invoice' => [
        'title' => 'Hisob :number',
        'heading' => "To'lov uchun hisob № :number",
        'date' => 'Sana',
        'status' => [
            'pending' => "to'lov kutilmoqda",
            'paid' => "to'langan",
            'canceled' => 'bekor qilingan',
            'expired' => "muddati o'tgan",
            'failed' => 'xatolik',
        ],
        'seller' => 'Ijrochi',
        'seller_empty' => "Ijrochi rekvizitlari platforma administratori tomonidan ko'rsatiladi.",
        'seller_fields' => [
            'name' => 'Nomi',
            'inn' => 'STIR',
            'address' => 'Manzil',
            'bank' => 'Bank',
            'bank_account' => 'Hisob raqami',
            'mfo' => 'MFO',
            'phone' => 'Telefon',
            'email' => 'Email',
        ],
        'payer' => "To'lovchi",
        'payer_fields' => [
            'name' => 'Nomi',
            'email' => 'Email',
            'phone' => 'Telefon',
            'telegram' => 'Telegram',
        ],
        'item' => 'Xizmat nomi',
        'sum' => 'Summa',
        'service' => "Markaz uchun LexiFlow Pro'ga kirish — «:tier» tarifi",
        'period' => 'muddat: :days kun',
        'total' => "Jami to'lov",
        'note' => "To'lov markaz obunasini ko'rsatilgan muddatga uzaytiradi. Summa so'mda ko'rsatilgan.",
        'print_hint' => 'Chop etish yoki PDF saqlash uchun Ctrl+P (⌘P) bosing.',
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
        'invoice_request' => [
            'subject' => "«:organization» markazidan hisob so'rovi",
            'heading' => "Hisob so'rovi: «:organization»",
            'intro' => ":user «:organization» markazi uchun hisob yozishni so'ramoqda (kabinet: /org/:slug).",
            'tier' => 'Tarif',
            'period' => 'Muddat',
            'amount' => 'Summa',
            'amount_none' => 'kelishuv asosida',
            'comment' => 'Izoh',
            'next' => "Ushbu xatga javob bering (Reply-To — markaz egasining manzili), hisob yuboring va to'lovdan so'ng tarifni adminkada ulang: «Obunalar» → «Berish», tarifni tanlang.",
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
