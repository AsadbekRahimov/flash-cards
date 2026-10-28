<?php

declare(strict_types=1);

return [

    /* DM after leaving a center (docs/13 §6.5): the discounted buy buttons of /pro follow the text. */
    'offer_dm' => [
        'text' => "Siz «:organization» markazidan chiqdingiz. Shaxsiy LexiFlow Pro birinchi oyga :pct% chegirma bilan — :date gacha.\nTo'lov usulini tanlang:",
        'text_generic' => "Markazdagi joyingiz tugadi. Shaxsiy LexiFlow Pro birinchi oyga :pct% chegirma bilan — :date gacha.\nTo'lov usulini tanlang:",
    ],

    'pro' => [
        'blocked' => "Akkauntga kirish cheklangan. Qo'llab-quvvatlash xizmatiga murojaat qiling.",
        'rate_limited' => "Juda ko'p urinish. Bir daqiqa kutib, qayta urinib ko'ring.",
        'status_free' => "Hozir sizda Free tarif: kuniga :count ta yangi so'z, takrorlash cheklovsiz.",
        'status_free_generic' => "Hozir sizda Free tarif: yangi so'zlar kunlik limit bilan cheklangan, takrorlash cheklovsiz.",
        'status_pro_until' => 'Sizda LexiFlow Pro :date gacha.',
        'status_pro_forever' => 'Sizda LexiFlow Pro muddatsiz.',
        'renewal_auto' => "Avtoyangilash yoqilgan: keyingi davr uchun to'lovni Telegram o'zi yechib oladi.",
        'renewal_canceled' => "Avtoyangilash o'chirilgan — Pro to'langan davr oxirigacha amal qiladi.",
        'soon' => 'LexiFlow Pro tez orada ochiladi. Promokodingiz bormi? /promo KOD yuboring.',
        'contact' => "Markazda o'qiysizmi? Markaz administratoridan LexiFlow Pro'ni ulashni so'rang: :contact",
        'choose' => "Muddat va to'lov usulini tanlang:",
        'price_line' => ':period — :prices',
        'offer' => "Birinchi to'lovga :pct% chegirma :date gacha amal qiladi.",
        'buy_button' => ':provider · :period · :price',
        'cancel_button' => "Avtoyangilashni o'chirish",
        'resume_button' => 'Avtoyangilashni yoqish',
        'checkout_ready' => "Hisob yaratildi: :period, :price.\nQuyidagi tugma orqali to'lang — tasdiq kelishi bilan Pro o'zi yoqiladi.",
        'pay_button' => "To'lash",
        'canceled' => "Avtoyangilash o'chirildi. Pro :date gacha amal qiladi.",
        'resumed' => 'Avtoyangilash qayta yoqildi.',
        'errors' => [
            'price_not_configured' => "Bu to'lov varianti hozircha mavjud emas.",
            'provider_disabled' => "Bu to'lov usuli hozir mavjud emas. Boshqasini tanlang.",
            'provider_not_supported' => "Bu to'lov usuli siz uchun mavjud emas.",
            'checkout_conflict' => "Sizda Telegram Stars avtoyangilash yoqilgan. Yangi to'lovdan oldin uni /pro orqali o'chiring.",
            'already_perpetual' => "Sizda LexiFlow Pro muddatsiz faol — hech narsa to'lash shart emas.",
            'subscribe_too_early' => "Pro allaqachon uzoq muddatga to'langan. Stars obunasini oxirgi kunlarda rasmiylashtirish mumkin; hozircha bir yillik bir martalik to'lovni tanlang.",
            'too_many_pending' => "Sizda to'lanmagan hisoblar bor. Ulardan birini to'lang yoki kuting — ular o'zi yopiladi.",
            'offer_not_applicable' => "Chegirma bu xaridga qo'llanilmaydi.",
            'offer_busy' => "Chegirmali oldingi to'lov hali qayta ishlanmoqda — bir daqiqadan so'ng urinib ko'ring.",
            'invalid_period' => "Bunday to'lov varianti yo'q.",
            'invalid_tier' => "Bunday to'lov varianti yo'q.",
            'not_cancelable' => "Hozir o'chiriladigan avtoyangilash yo'q.",
            'not_resumable' => "Avtoyangilashni hozir yoqib bo'lmaydi: u allaqachon yoqilgan yoki to'langan davr tugagan.",
            'provider_capability' => "Bu amal sizning to'lov usulingiz uchun mavjud emas.",
            'provider_not_registered' => "Bu amal sizning to'lov usulingiz uchun mavjud emas.",
            'provider_unavailable' => "To'lov xizmati javob bermayapti. Bir necha daqiqadan keyin urinib ko'ring.",
            'generic' => "Amalni bajarib bo'lmadi. Keyinroq urinib ko'ring yoki qo'llab-quvvatlash xizmatiga yozing: /paysupport.",
        ],
    ],

    'paysupport' => [
        'with_contact' => "LexiFlow to'lovlar bo'yicha yordam.\n\n".
            "To'lov, pulni qaytarish va obunani bekor qilish masalalari: :contact\n".
            "Muammoni yozing va chek yoki to'lov raqamini ilova qiling. Pulni qaytarish qoidalari ofertada: :offer\n".
            "Stars avtoyangilashini /pro buyrug'i orqali o'chirish mumkin.",
        'without_contact' => "LexiFlow to'lovlar bo'yicha yordam.\n\n".
            "Yordam kontakti hozircha ko'rsatilmagan. Pulni qaytarish qoidalari ofertada: :offer\n".
            "Stars avtoyangilashini /pro buyrug'i orqali o'chirish mumkin.",
    ],

    // Telegram Stars (docs/13 §4.1): the invoice, pre-checkout refusals and the payment / refund replies.
    'stars' => [
        'invoice' => [
            // Telegram limits: title ≤ 32, label ≤ 32, description ≤ 255 characters.
            'title' => 'LexiFlow Pro — :period',
            'label' => 'LexiFlow Pro, :period',
            'description_month' => "LexiFlow Pro obunasi: yangi so'zlar cheklovsiz, so'zni yozish va misoldagi bo'sh joy mashqlari, 20 tagacha to'plam (2000 tadan so'z), seriyani muzlatish, IELTS va SAT testlari. Har 30 kunda avtomatik yangilanadi; /pro buyrug'i orqali o'chirish mumkin.",
            'description_year' => "LexiFlow Pro 1 yilga (365 kun): yangi so'zlar cheklovsiz, so'zni yozish va misoldagi bo'sh joy mashqlari, 20 tagacha to'plam (2000 tadan so'z), seriyani muzlatish, IELTS va SAT testlari. Bir martalik to'lov, avtoyangilashsiz.",
        ],
        'precheckout' => [
            'order_not_found' => 'Hisob topilmadi. /pro ni oching va yangisini yarating.',
            'foreign' => "Bu hisob boshqa foydalanuvchiga yozilgan. /pro ni oching va o'zingiznikini yarating.",
            'blocked' => "Akkauntga kirish cheklangan. Qo'llab-quvvatlash xizmatiga murojaat qiling: /paysupport.",
            'order_closed' => "Bu hisob allaqachon to'langan yoki yopilgan. /pro ni oching.",
            'order_expired' => 'Hisob muddati tugagan. /pro ni oching va yangisini yarating.',
            'amount_mismatch' => 'Hisob summasi buyurtma bilan mos kelmaydi. /pro ni oching va yangisini yarating.',
            'provider_disabled' => "Telegram Stars orqali to'lov hozir mavjud emas. /pro da boshqa usulni tanlang.",
            'already_subscribed' => "Sizda Telegram Stars avtoyangilash allaqachon yoqilgan — ikkinchi hisob kerak emas. /pro ni oching: u yerda uni o'chirish mumkin.",
            'unavailable' => "Xizmat vaqtincha ishlamayapti. Bir daqiqadan keyin urinib ko'ring.",
        ],
        'paid' => "To'lov qabul qilindi! LexiFlow Pro :date gacha faol.",
        'renewed' => 'Obuna yangilandi: LexiFlow Pro :date gacha faol.',
        'paid_forever' => "To'lov qabul qilindi! Sizda LexiFlow Pro muddatsiz.",
        'open_button' => 'LexiFlow ni ochish',
        'paid_unresolved' => "To'lov qabul qilindi, lekin uni avtomatik hisobga olib bo'lmadi. Biz aniqlab, Pro ni yoqamiz yoki Stars ni qaytaramiz. Tezlashtirish uchun qo'llab-quvvatlash xizmatiga yozing: /paysupport.",
        'refunded' => ":amount qaytarildi. Agar bu to'lov bilan joriy Pro davri to'langan bo'lsa, u yakunlandi.",
    ],

    'period' => [
        'month' => '1 oy',
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
        'dm' => '{1} LexiFlow Pro ertaga tugaydi (:date).|[2,*] LexiFlow Pro :count kundan keyin tugaydi (:date).',
        'org_dm' => '{1} «:organization» markazining obunasi ertaga tugaydi (:date).|[2,*] «:organization» markazining obunasi :count kundan keyin tugaydi (:date).',
        'hint' => 'Yangi so\'zlar kunlik limit bilan cheklanib qolmasligi uchun oldindan yangilang.',
        'hint_canceled' => 'Avtoyangilash o\'chirilgan, shuning uchun Pro o\'zi yangilanmaydi. Cheklovsiz imkoniyatni yo\'qotmaslik uchun oldindan yangilang.',
        'org_hint' => 'Obuna tugagach, markaz ishtirokchilarida yana Free tarifi bo\'ladi.',
        'button' => "Pro'ni uzaytirish",
        'mail' => [
            'subject' => '{1} «:organization» markazining obunasi ertaga tugaydi|[2,*] «:organization» markazining obunasi :count kundan keyin tugaydi',
            'greeting' => 'Assalomu alaykum!',
            'body' => '{1} «:organization» markazining LexiFlow Pro obunasi ertaga tugaydi (:date).|[2,*] «:organization» markazining LexiFlow Pro obunasi :count kundan keyin tugaydi (:date).',
            'keep' => 'Markaz o\'quvchilari Pro ni yo\'qotmasligi uchun obunani oldindan yangilang. Tugagach, ularda yana Free tarifi bo\'ladi.',
            'action' => 'Markaz kabinetini ochish',
            'contact' => 'To\'lov bo\'yicha savollar: :contact',
        ],
    ],

    // Payme (docs/13 §4.2): the fiscal receipt line of CheckPerformTransaction and the return page `/billing/return`.
    'payme' => [
        'receipt_item' => 'LexiFlow Pro — :period',
    ],

    'return' => [
        'title' => "To'lov holati",
        'open_bot' => "LexiFlow'ga qaytish",
        'note' => "Bu sahifani yopish mumkin: to'lov holati botda va ilovada ko'rinadi.",
        'support' => "To'lov bo'yicha savollar:",
        'paid' => [
            'heading' => "To'lov qabul qilindi",
            'text' => 'LexiFlow Pro yoqildi. Botga qayting yoki ilovani oching — kirish allaqachon ishlaydi.',
        ],
        'pending' => [
            'heading' => "To'lov tasdiqlanishini kutmoqdamiz",
            'text' => "To'lov qayta ishlanmoqda. Odatda bu bir daqiqadan kam vaqt oladi; to'lov tizimi to'lovni tasdiqlashi bilan Pro avtomatik yoqiladi.",
        ],
        'expired' => [
            'heading' => "Hisob muddati o'tdi",
            'text' => "Bu hisob endi amal qilmaydi. Botda /pro buyrug'ini oching va yangisini yarating — muddati o'tgan hisob bo'yicha pul yechilmaydi.",
        ],
        'canceled' => [
            'heading' => 'Hisob bekor qilindi',
            'text' => "Bu hisob bekor qilingan. Kerak bo'lsa, botda /pro buyrug'i bilan yangisini yarating.",
        ],
        'unknown' => [
            'heading' => "To'lov holati noma'lum",
            'text' => "Bunday hisobni topa olmadik. Holatni botda /pro buyrug'i bilan tekshiring yoki qo'llab-quvvatlash xizmatiga yozing.",
        ],
    ],

    // Admin dashboard revenue widget: cash flow by event date, cohort net kept apart.
    'report' => [
        'heading' => "So'nggi 30 kun tushumi",
        'net_cash' => 'Pul harakatining sof natijasi :currency',
        'description' => "To'lovlar: :charges, brutto :gross · qaytarishlar (qaytarilgan sana bo'yicha): :refund_count, :refunds · kogorta netto (davr to'lovlari ulardan qaytarilganini ayirib): :cohort",
    ],

];
