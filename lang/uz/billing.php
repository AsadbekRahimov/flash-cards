<?php

declare(strict_types=1);

return [

    /* DM after leaving a center (docs/13 §6.5): the discounted buy buttons of /pro follow the text. */
    'offer_dm' => [
        'text' => "Siz «:organization» markazidan chiqdingiz. Shaxsiy Muhrla Pro birinchi oyga :pct% chegirma bilan — :date gacha.\nTo'lov usulini tanlang:",
        'text_generic' => "Markazdagi joyingiz tugadi. Shaxsiy Muhrla Pro birinchi oyga :pct% chegirma bilan — :date gacha.\nTo'lov usulini tanlang:",
    ],

    'pro' => [
        'blocked' => "Akkauntga kirish cheklangan. Qo'llab-quvvatlash xizmatiga murojaat qiling.",
        'rate_limited' => "Juda ko'p urinish. Bir daqiqa kutib, qayta urinib ko'ring.",
        'status_free' => "Hozir sizda Free tarif: kuniga :count ta yangi so'z, takrorlash cheklovsiz.",
        'status_free_generic' => "Siz bepul tarifdasiz. Kuniga o'rganiladigan yangi so'zlar soni cheklangan. Avvalgi so'zlarni esa istagancha takrorlashingiz mumkin.",
        'status_pro_until' => 'Sizda Muhrla Pro :date gacha.',
        'status_pro_forever' => 'Sizda Muhrla Pro muddatsiz.',
        'renewal_auto' => "Avtomatik uzaytirish yoqilgan: keyingi davr uchun to'lovni Telegram o'zi yechib oladi.",
        'renewal_canceled' => "Avtomatik uzaytirish o'chirilgan — Pro to'langan davr oxirigacha amal qiladi.",
        'soon' => 'Muhrla Pro tez orada ochiladi. Promokodingiz bormi? /promo KOD yuboring.',
        'contact' => "Markazda o'qiysizmi? Markaz administratoridan Muhrla Pro'ni ulashni so'rang: :contact",
        'choose' => "Muddat va to'lov usulini tanlang:",
        'price_line' => ':period — :prices',
        'offer' => "Birinchi to'lovga :pct% chegirma :date gacha amal qiladi.",
        'buy_button' => ':provider · :period · :price',
        'cancel_button' => "Avtomatik uzaytirishni o'chirish",
        'resume_button' => 'Avtomatik uzaytirishni yoqish',
        'checkout_ready' => "To'lov tayyor: :period, :price.\nQuyidagi tugma orqali to'lang — tasdiq kelishi bilan Pro o'zi yoqiladi.",
        'pay_button' => "To'lash",
        'canceled' => "Avtomatik uzaytirish o'chirildi. Pro :date gacha amal qiladi.",
        'resumed' => 'Avtomatik uzaytirish qayta yoqildi.',
        'errors' => [
            'price_not_configured' => "Bu to'lov varianti hozircha ishlamayapti.",
            'provider_disabled' => "Bu to'lov usuli hozir ishlamayapti. Boshqasini tanlang.",
            'provider_not_supported' => "Bu to'lov usulidan foydalana olmaysiz.",
            'checkout_conflict' => "Sizda Telegram Stars orqali obunani avtomatik uzaytirish yoqilgan. Yangi to'lovdan oldin uni /pro orqali o'chiring.",
            'already_perpetual' => "Sizda Muhrla Pro muddatsiz faol — hech narsa to'lash shart emas.",
            'subscribe_too_early' => "Pro allaqachon uzoq muddatga to'langan. Stars obunasini oxirgi kunlarda rasmiylashtirish mumkin; hozircha 3 oylik yoki bir yillik bir martalik to'lovni tanlang.",
            'too_many_pending' => "Sizda yakunlanmagan to'lovlar bor. Ulardan birini to'lang yoki kuting — ular o'zi yopiladi.",
            'offer_not_applicable' => "Chegirma bu xaridga qo'llanilmaydi.",
            'offer_busy' => "Chegirmali oldingi to'lov hali yakunlanmagan — bir daqiqadan so'ng urinib ko'ring.",
            'invalid_period' => "Bunday to'lov varianti yo'q.",
            'invalid_tier' => "Bunday to'lov varianti yo'q.",
            'not_cancelable' => 'Obunangiz uchun avtomatik uzaytirish yoqilmagan.',
            'not_resumable' => "Avtomatik uzaytirishni hozir yoqib bo'lmaydi: u allaqachon yoqilgan yoki to'langan davr tugagan.",
            'provider_capability' => "To'lov usulingizda bu amalni bajarib bo'lmaydi.",
            'provider_not_registered' => "To'lov usulingizda bu amalni bajarib bo'lmaydi.",
            'provider_unavailable' => "To'lov xizmati javob bermayapti. Bir necha daqiqadan keyin urinib ko'ring.",
            'generic' => "Amalni bajarib bo'lmadi. Keyinroq urinib ko'ring yoki qo'llab-quvvatlash xizmatiga yozing: /paysupport.",
        ],
    ],

    // The welcome trial (docs/13 §7.1 "2C.9"), DM once after the onboarding; `:days` is "14 kun" (billing.trial.days),
    // `:reward` is "7 kun" (referrals.milestone_days), `:friends` is "3 ta do'stni".
    'trial' => [
        'welcome' => '🎁 Sizga :days Pro bepul ochildi — :date gacha. :friends taklif qiling — yana :reward Pro olasiz: /invite',
        'welcome_plain' => '🎁 Sizga :days Pro bepul ochildi — :date gacha.',
    ],

    'paysupport' => [
        'with_contact' => "Muhrla to'lovlar bo'yicha yordam.\n\n".
            "To'lov va obunani bekor qilish masalalari: :contact\n".
            "Pul faqat qo'llab-quvvatlash xizmati orqali qaytariladi: har bir murojaat alohida ko'rib chiqiladi. Muammoni yozing va chek yoki to'lov raqamini ilova qiling. Shartlar ofertada: :offer\n".
            "Stars orqali obunani avtomatik uzaytirishni /pro buyrug'i orqali o'chirish mumkin.",
        'without_contact' => "Muhrla to'lovlar bo'yicha yordam.\n\n".
            "Yordam kontakti hozircha ko'rsatilmagan. Pul faqat qo'llab-quvvatlash xizmati orqali qaytariladi, har bir murojaat alohida ko'rib chiqiladi. Shartlar ofertada: :offer\n".
            "Stars orqali obunani avtomatik uzaytirishni /pro buyrug'i orqali o'chirish mumkin.",
    ],

    // Telegram Stars (docs/13 §4.1): the invoice, pre-checkout refusals and the payment / refund replies.
    'stars' => [
        'invoice' => [
            // Telegram limits: title ≤ 32, label ≤ 32, description ≤ 255 characters.
            'title' => 'Muhrla Pro — :period',
            'label' => 'Muhrla Pro, :period',
            'description_month' => "Muhrla Pro: yangi so'zlar cheklovsiz. Yozish, gap to'ldirish, 20 tagacha to'plam (har biriga 2000 so'zgacha), zaxira kunlar, IELTS va SAT sinov imtihonlari. Har 30 kunda avtomatik uzayadi. /pro orqali o'chiring.",
            'description_year' => "Muhrla Pro 1 yilga (365 kun): yangi so'zlar cheklovsiz. Yozish, gap to'ldirish, 20 tagacha to'plam (har biriga 2000 so'zgacha), zaxira kunlar, IELTS va SAT sinov imtihonlari. Bir martalik to'lov. Obuna avtomatik uzaymaydi.",
            // The same two with the practice and battles of Pro, chosen by TelegramStarsProvider only while practice is open to everybody
            // (ProPromises::publicPractice()); the limit of Telegram is 255 characters, so the tail of the month text is shorter
            // and the year text does not repeat its length in days ("1 yilga" already says it).
            'description_month_practice' => "Muhrla Pro: yangi so'zlar, mustahkamlash mashqlari va bellashuvlar cheklovsiz. Yozish, gap to'ldirish, 20 tagacha to'plam (har biriga 2000 so'zgacha), zaxira kunlar, IELTS va SAT sinov imtihonlari. Har 30 kunda avtomatik uzayadi. /pro orqali o'chiring.",
            'description_year_practice' => "Muhrla Pro 1 yilga: yangi so'zlar, mustahkamlash mashqlari va bellashuvlar cheklovsiz. Yozish, gap to'ldirish, 20 tagacha to'plam (har biriga 2000 so'zgacha), zaxira kunlar, IELTS va SAT sinov imtihonlari. Bir martalik to'lov. Obuna avtomatik uzaymaydi.",
            // The same with the practice alone, while the battles are switched off (PRACTICE_BATTLE=false): no battles are sold that do not exist.
            'description_month_practice_solo' => "Muhrla Pro: yangi so'zlar va mustahkamlash mashqlari cheklovsiz. Yozish, gap to'ldirish, 20 tagacha to'plam (har biriga 2000 so'zgacha), zaxira kunlar, IELTS va SAT sinov imtihonlari. Har 30 kunda avtomatik uzayadi. /pro orqali o'chiring.",
            'description_year_practice_solo' => "Muhrla Pro 1 yilga: yangi so'zlar va mustahkamlash mashqlari cheklovsiz. Yozish, gap to'ldirish, 20 tagacha to'plam (har biriga 2000 so'zgacha), zaxira kunlar, IELTS va SAT sinov imtihonlari. Bir martalik to'lov. Obuna avtomatik uzaymaydi.",
            // The one-off quarter (3 months) in the same three variants.
            'description_quarter' => "Muhrla Pro 3 oyga (90 kun): yangi so'zlar cheklovsiz. Yozish, gap to'ldirish, 20 tagacha to'plam (har biriga 2000 so'zgacha), zaxira kunlar, IELTS va SAT sinov imtihonlari. Bir martalik to'lov. Obuna avtomatik uzaymaydi.",
            'description_quarter_practice' => "Muhrla Pro 3 oyga: yangi so'zlar, mustahkamlash mashqlari va bellashuvlar cheklovsiz. Yozish, gap to'ldirish, 20 tagacha to'plam (har biriga 2000 so'zgacha), zaxira kunlar, IELTS va SAT sinov imtihonlari. Bir martalik to'lov. Obuna avtomatik uzaymaydi.",
            'description_quarter_practice_solo' => "Muhrla Pro 3 oyga: yangi so'zlar va mustahkamlash mashqlari cheklovsiz. Yozish, gap to'ldirish, 20 tagacha to'plam (har biriga 2000 so'zgacha), zaxira kunlar, IELTS va SAT sinov imtihonlari. Bir martalik to'lov. Obuna avtomatik uzaymaydi.",
        ],
        'precheckout' => [
            'order_not_found' => 'To\'lov topilmadi. /pro buyrug\'ini yuborib, yangisini yarating.',
            'foreign' => "Bu to'lov boshqa foydalanuvchi uchun yaratilgan. /pro buyrug'ini yuborib, o'zingiznikini yarating.",
            'blocked' => "Akkauntga kirish cheklangan. Qo'llab-quvvatlash xizmatiga murojaat qiling: /paysupport.",
            'order_closed' => "Bu to'lov allaqachon amalga oshirilgan yoki yopilgan. /pro buyrug'ini yuboring.",
            'order_expired' => 'To\'lov muddati tugagan. /pro buyrug\'ini yuborib, yangisini yarating.',
            'amount_mismatch' => 'To\'lov summasi buyurtmaga mos kelmaydi. /pro buyrug\'ini yuborib, yangisini yarating.',
            'provider_disabled' => "Telegram Stars orqali to'lov hozir ishlamayapti. /pro orqali boshqa usulni tanlang.",
            'already_subscribed' => "Sizda Telegram Stars orqali obunani avtomatik uzaytirish allaqachon yoqilgan — ikkinchi to'lov kerak emas. Uni /pro orqali o'chirishingiz mumkin.",
            'unavailable' => "Xizmat vaqtincha ishlamayapti. Bir daqiqadan keyin urinib ko'ring.",
        ],
        'paid' => "To'lov qabul qilindi! Muhrla Pro :date gacha faol.",
        'renewed' => 'Obuna uzaytirildi: Muhrla Pro :date gacha faol.',
        'paid_forever' => "To'lov qabul qilindi! Sizda Muhrla Pro muddatsiz.",
        'open_button' => 'Muhrla\'ni ochish',
        'paid_unresolved' => "To'lov qabul qilindi, lekin uni avtomatik hisobga olib bo'lmadi. Tekshirib, Pro'ni yoqamiz yoki Stars'ni qaytaramiz. Tezroq hal bo'lishi uchun qo'llab-quvvatlash xizmatiga yozing: /paysupport.",
        'refunded' => ":amount qaytarildi. Agar bu to'lov bilan joriy Pro davri to'langan bo'lsa, u yakunlandi.",
    ],

    'period' => [
        'month' => '1 oy',
        'quarter' => '3 oy',
        'year' => '1 yil',
    ],

    'provider' => [
        'telegram_stars' => '⭐ Stars',
        'payme' => 'Payme',
        'click' => 'Click',
    ],

    'currency' => [
        'XTR' => ':amount ⭐',
        'UZS' => ":amount so'm",
    ],

    // Renewal reminders (docs/13 §4.4): DM to a learner / an organization's owner, e-mail to owners.
    'renewal' => [
        'dm' => '{1} Muhrla Pro ertaga tugaydi (:date).|[2,*] Muhrla Pro :count kundan keyin tugaydi (:date).',
        'org_dm' => '{1} «:organization» markazining obunasi ertaga tugaydi (:date).|[2,*] «:organization» markazining obunasi :count kundan keyin tugaydi (:date).',
        'hint' => 'Yangi so\'zlar kunlik limit bilan cheklanib qolmasligi uchun obunani oldindan uzaytiring.',
        'hint_canceled' => 'Avtomatik uzaytirish o\'chirilgan, shuning uchun Pro o\'zi uzaymaydi. Cheklovsiz imkoniyatlarni yo\'qotmaslik uchun oldindan uzaytiring.',
        // The welcome trial (docs/13 §7.1 "2C.9"): not a renewal — the first purchase.
        'trial_dm' => '{1} Muhrla Pro sinov muddati ertaga tugaydi (:date).|[2,*] Muhrla Pro sinov muddati :count kundan keyin tugaydi (:date).',
        'hint_trial' => 'Pro\'ni yo\'qotmaslik uchun obunani rasmiylashtiring (/pro) yoki do\'stlaringizni taklif qiling (/invite).',
        'org_hint' => 'Obuna tugagach, markaz o\'quvchilari yana Free tarifiga o\'tadi.',
        'button' => "Pro'ni uzaytirish",
        'mail' => [
            'subject' => '{1} «:organization» markazining obunasi ertaga tugaydi|[2,*] «:organization» markazining obunasi :count kundan keyin tugaydi',
            'greeting' => 'Assalomu alaykum!',
            'body' => '{1} «:organization» markazining Muhrla Pro obunasi ertaga tugaydi (:date).|[2,*] «:organization» markazining Muhrla Pro obunasi :count kundan keyin tugaydi (:date).',
            'keep' => 'Markaz o\'quvchilari Pro\'dan mahrum bo\'lmasligi uchun obunani oldindan uzaytiring. Obuna tugagach, ular yana Free tarifiga o\'tadi.',
            'action' => 'Markaz kabinetini ochish',
            'contact' => 'To\'lov bo\'yicha savollar: :contact',
        ],
    ],

    // Payme (docs/13 §4.2): the fiscal receipt line of CheckPerformTransaction and the return page `/billing/return`.
    'payme' => [
        'receipt_item' => 'Muhrla Pro — :period',
    ],

    'return' => [
        'title' => "To'lov holati",
        'open_bot' => "Muhrla'ga qaytish",
        'note' => "Bu sahifani yopish mumkin: to'lov holati botda va ilovada ko'rinadi.",
        'support' => "To'lov bo'yicha savollar:",
        'paid' => [
            'heading' => "To'lov qabul qilindi",
            'text' => 'Muhrla Pro yoqildi. Botga qayting yoki ilovani oching — Pro imkoniyatlari allaqachon ochiq.',
        ],
        'pending' => [
            'heading' => "To'lov tasdiqlanishini kutmoqdamiz",
            'text' => "To'lov tekshirilmoqda. Odatda bu bir daqiqadan kam vaqt oladi; to'lov tizimi tasdiqlashi bilan Pro avtomatik yoqiladi.",
        ],
        'expired' => [
            'heading' => "To'lov muddati o'tdi",
            'text' => "Bu to'lov endi amal qilmaydi. Botda /pro buyrug'ini yuborib, yangisini yarating — muddati o'tgan to'lov bo'yicha pul yechilmaydi.",
        ],
        'canceled' => [
            'heading' => 'To\'lov bekor qilindi',
            'text' => "Bu to'lov bekor qilingan. Kerak bo'lsa, botda /pro buyrug'i bilan yangisini yarating.",
        ],
        'unknown' => [
            'heading' => "To'lov holati noma'lum",
            'text' => "Bunday to'lovni topa olmadik. Holatni botda /pro buyrug'i bilan tekshiring yoki qo'llab-quvvatlash xizmatiga yozing.",
        ],
    ],

    // Admin dashboard revenue widget: cash flow by event date, cohort net kept apart.
    'report' => [
        'heading' => "So'nggi 30 kun tushumi",
        'net_cash' => 'Pul harakatining sof natijasi :currency',
        'description' => "To'lovlar: :charges, brutto :gross · qaytarishlar (qaytarilgan sana bo'yicha): :refund_count, :refunds · kogorta netto (davr to'lovlari ulardan qaytarilganini ayirib): :cohort",
    ],

];
