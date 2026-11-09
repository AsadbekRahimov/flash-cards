<?php

declare(strict_types=1);

return [

    /* DM after leaving a center (docs/13 §6.5): the discounted buy buttons of /pro follow the text. */
    'offer_dm' => [
        'text' => "Siz «:organization» markazidan chiqdingiz. Shaxsiy LexiFlow Pro'ning birinchi oyiga :pct% chegirma :date gacha amal qiladi.\nTo'lov usulini tanlang:",
        'text_generic' => "Markazdagi o'rningiz endi faol emas. Shaxsiy LexiFlow Pro'ning birinchi oyiga :pct% chegirma :date gacha amal qiladi.\nTo'lov usulini tanlang:",
    ],

    'pro' => [
        'blocked' => "Akkauntga kirish cheklangan. Qo'llab-quvvatlash xizmatiga murojaat qiling.",
        'rate_limited' => "Juda ko'p urinish. Bir daqiqa kutib, qayta urinib ko'ring.",
        'status_free' => "Hozir sizda bepul tarif: kuniga :count ta yangi so'z, takrorlash esa cheklovsiz.",
        'status_free_generic' => "Hozir sizda bepul tarif: yangi so'zlar soni kuniga cheklangan, takrorlash esa cheklovsiz.",
        'status_pro_until' => 'Sizda LexiFlow Pro :date gacha.',
        'status_pro_forever' => 'Sizda LexiFlow Pro muddatsiz.',
        'renewal_auto' => "Avtoyangilash yoqilgan: keyingi davr uchun to'lovni Telegram o'zi yechib oladi.",
        'renewal_canceled' => "Avtoyangilash o'chirilgan — Pro to'langan davr oxirigacha amal qiladi.",
        'soon' => 'LexiFlow Pro tez orada ochiladi. Promokodingiz bormi? /promo KOD yuboring.',
        'contact' => "O'quv markazida o'qiysizmi? Markaz administratoridan LexiFlow Pro'ni ulab berishini so'rang: :contact",
        'choose' => "Muddat va to'lov usulini tanlang:",
        'price_line' => ':period — :prices',
        'offer' => "Birinchi to'lovga :pct% chegirma :date gacha amal qiladi.",
        'buy_button' => ':provider · :period · :price',
        'cancel_button' => "Avtoyangilashni o'chirish",
        'resume_button' => 'Avtoyangilashni yoqish',
        'checkout_ready' => "To'lov hisobi yaratildi: :period, :price.\nPastdagi tugma orqali to'lang. To'lov tasdiqlanishi bilan Pro o'zi yoqiladi.",
        'pay_button' => "To'lash",
        'canceled' => "Avtoyangilash o'chirildi. Pro :date gacha amal qiladi.",
        'resumed' => 'Avtoyangilash qayta yoqildi.',
        'errors' => [
            'price_not_configured' => "Bu to'lov varianti hozircha yo'q.",
            'provider_disabled' => "Bu to'lov usuli hozir ishlamayapti. Boshqasini tanlang.",
            'provider_not_supported' => "Bu to'lov usulidan foydalana olmaysiz.",
            'checkout_conflict' => "Sizda Telegram Stars avtoyangilashi yoqilgan. Yangi to'lovdan oldin uni /pro orqali o'chiring.",
            'already_perpetual' => "Sizda LexiFlow Pro muddatsiz faol — hech narsa to'lash shart emas.",
            'subscribe_too_early' => "Pro allaqachon uzoq muddatga to'langan. Stars obunasini Pro tugashiga oz qolganda ulash mumkin. Hozircha 1 yillik bir martalik to'lovni tanlang.",
            'too_many_pending' => "Sizda to'lanmagan to'lov hisoblari bor. Ulardan birini to'lang yoki biroz kuting, ular o'zi yopiladi.",
            'offer_not_applicable' => 'Bu xaridga chegirma amal qilmaydi.',
            'offer_busy' => "Chegirmali oldingi to'lov hali qayta ishlanmoqda — bir daqiqadan so'ng urinib ko'ring.",
            'invalid_period' => "Bunday to'lov varianti yo'q.",
            'invalid_tier' => "Bunday to'lov varianti yo'q.",
            'not_cancelable' => "Hozir o'chiriladigan avtoyangilash yo'q.",
            'not_resumable' => "Avtoyangilashni hozir yoqib bo'lmaydi: u allaqachon yoqilgan yoki to'langan davr tugagan.",
            'provider_capability' => "Bu amal siz tanlagan to'lov usulida ishlamaydi.",
            'provider_not_registered' => "Bu amal siz tanlagan to'lov usulida ishlamaydi.",
            'provider_unavailable' => "To'lov xizmati javob bermayapti. Bir necha daqiqadan keyin urinib ko'ring.",
            'generic' => "Amalni bajarib bo'lmadi. Keyinroq urinib ko'ring yoki qo'llab-quvvatlash xizmatiga yozing: /paysupport.",
        ],
    ],

    'paysupport' => [
        'with_contact' => "LexiFlow: to'lovlar bo'yicha yordam.\n\n".
            "To'lov va obunani bekor qilish bo'yicha savollar: :contact\n".
            "Pul faqat qo'llab-quvvatlash xizmati orqali qaytariladi: har bir murojaat alohida ko'rib chiqiladi. Muammoni yozing va chek yoki to'lov raqamini ilova qiling. Shartlar ofertada: :offer\n".
            "Stars avtoyangilashini /pro buyrug'i orqali o'chirish mumkin.",
        'without_contact' => "LexiFlow: to'lovlar bo'yicha yordam.\n\n".
            "Yordam kontakti hozircha ko'rsatilmagan. Pul faqat qo'llab-quvvatlash xizmati orqali qaytariladi, har bir murojaat alohida ko'rib chiqiladi. Shartlar ofertada: :offer\n".
            "Stars avtoyangilashini /pro buyrug'i orqali o'chirish mumkin.",
    ],

    // Telegram Stars (docs/13 §4.1): the invoice, pre-checkout refusals and the payment / refund replies.
    'stars' => [
        'invoice' => [
            // Telegram limits: title ≤ 32, label ≤ 32, description ≤ 255 characters.
            'title' => 'LexiFlow Pro — :period',
            'label' => 'LexiFlow Pro, :period',
            'description_month' => "LexiFlow Pro: yangi so'zlar cheklovsiz, yozma mashqlar, 20 tagacha o'z to'plamingiz (har biri 2000 so'zgacha), zaxira kunlar, IELTS va SAT sinov testlari. Har 30 kunda avtomatik uzaytiriladi, /pro orqali o'chirsa bo'ladi.",
            'description_year' => "LexiFlow Pro 1 yilga (365 kun): yangi so'zlar cheklovsiz, yozma mashqlar, 20 tagacha o'z to'plamingiz (har biri 2000 so'zgacha), zaxira kunlar, IELTS va SAT sinov testlari. Bir martalik to'lov, avtomatik uzaymaydi.",
            // The same two with the practice and battles of Pro, chosen by TelegramStarsProvider only while practice is open to everybody
            // (ProPromises::publicPractice()); the limit of Telegram is 255 characters, so the tail of the month text is shorter
            // and the year text does not repeat its length in days ("1 yilga" already says it).
            'description_month_practice' => "LexiFlow Pro: yangi so'zlar, mustahkamlash mashqlari va bellashuvlar cheklovsiz, yozma mashqlar, 20 tagacha o'z to'plamingiz (har biri 2000 so'zgacha), zaxira kunlar, IELTS va SAT sinov testlari. Har 30 kunda avtomatik uzaytiriladi, o'chirish: /pro.",
            'description_year_practice' => "LexiFlow Pro 1 yilga: yangi so'zlar, mustahkamlash mashqlari va bellashuvlar cheklovsiz, yozma mashqlar, 20 tagacha o'z to'plamingiz (har biri 2000 so'zgacha), zaxira kunlar, IELTS va SAT sinov testlari. Bir martalik to'lov, avtomatik uzaymaydi.",
            // The same with the practice alone, while the battles are switched off (PRACTICE_BATTLE=false): no battles are sold that do not exist.
            'description_month_practice_solo' => "LexiFlow Pro: yangi so'zlar va mustahkamlash mashqlari cheklovsiz, yozma mashqlar, 20 tagacha o'z to'plamingiz (har biri 2000 so'zgacha), zaxira kunlar, IELTS va SAT sinov testlari. Har 30 kunda avtomatik uzaytiriladi, o'chirish: /pro.",
            'description_year_practice_solo' => "LexiFlow Pro 1 yilga: yangi so'zlar va mustahkamlash mashqlari cheklovsiz, yozma mashqlar, 20 tagacha o'z to'plamingiz (har biri 2000 so'zgacha), zaxira kunlar, IELTS va SAT sinov testlari. Bir martalik to'lov, avtomatik uzaymaydi.",
        ],
        'precheckout' => [
            'order_not_found' => "To'lov hisobi topilmadi. /pro ni oching va yangisini yarating.",
            'foreign' => "Bu to'lov hisobi boshqa foydalanuvchiga tegishli. /pro ni oching va o'zingiznikini yarating.",
            'blocked' => "Akkauntga kirish cheklangan. Qo'llab-quvvatlash xizmatiga murojaat qiling: /paysupport.",
            'order_closed' => "Bu to'lov hisobi allaqachon to'langan yoki yopilgan. /pro ni oching.",
            'order_expired' => "To'lov hisobining muddati tugagan. /pro ni oching va yangisini yarating.",
            'amount_mismatch' => "To'lov summasi buyurtmaga mos kelmaydi. /pro ni oching va yangisini yarating.",
            'provider_disabled' => "Telegram Stars orqali to'lov hozir ishlamayapti. /pro da boshqa usulni tanlang.",
            'already_subscribed' => "Sizda Telegram Stars avtoyangilashi allaqachon yoqilgan, ikkinchi to'lov hisobi kerak emas. Uni /pro da o'chirish mumkin.",
            'unavailable' => "Xizmat vaqtincha ishlamayapti. Bir daqiqadan keyin urinib ko'ring.",
        ],
        'paid' => "To'lov qabul qilindi! LexiFlow Pro :date gacha faol.",
        'renewed' => 'Obuna uzaytirildi: LexiFlow Pro :date gacha faol.',
        'paid_forever' => "To'lov qabul qilindi! Sizda LexiFlow Pro muddatsiz.",
        'open_button' => "LexiFlow'ni ochish",
        'paid_unresolved' => "To'lov qabul qilindi, lekin uni avtomatik hisobga olib bo'lmadi. Tekshirib, Pro'ni yoqamiz yoki Stars'ni qaytaramiz. Tezroq hal qilish uchun qo'llab-quvvatlash xizmatiga yozing: /paysupport.",
        'refunded' => ":amount qaytarildi. Agar joriy Pro davri shu to'lov bilan to'langan bo'lsa, u tugatildi.",
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
        'hint' => "Yangi so'zlarga kunlik cheklov qo'yilmasligi uchun Pro'ni oldindan uzaytiring.",
        'hint_canceled' => "Avtoyangilash o'chirilgan, shuning uchun Pro o'zi uzaymaydi. Cheklovsiz imkoniyatlarni yo'qotmaslik uchun oldindan uzaytiring.",
        'org_hint' => "Obuna tugagach, markaz a'zolari yana bepul tarifga o'tadi.",
        'button' => "Pro'ni uzaytirish",
        'mail' => [
            'subject' => '{1} «:organization» markazining obunasi ertaga tugaydi|[2,*] «:organization» markazining obunasi :count kundan keyin tugaydi',
            'greeting' => 'Assalomu alaykum!',
            'body' => '{1} «:organization» markazining LexiFlow Pro obunasi ertaga tugaydi (:date).|[2,*] «:organization» markazining LexiFlow Pro obunasi :count kundan keyin tugaydi (:date).',
            'keep' => "Markaz o'quvchilari Pro'dan ayrilib qolmasligi uchun obunani oldindan uzaytiring. Obuna tugasa, ular yana bepul tarifga o'tadi.",
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
            'text' => 'LexiFlow Pro yoqildi. Botga qayting yoki ilovani oching, Pro allaqachon ishlayapti.',
        ],
        'pending' => [
            'heading' => "To'lov tasdiqlanishini kutmoqdamiz",
            'text' => "To'lov qayta ishlanmoqda. Odatda bu bir daqiqadan kam vaqt oladi; to'lov tizimi to'lovni tasdiqlashi bilan Pro avtomatik yoqiladi.",
        ],
        'expired' => [
            'heading' => "To'lov hisobi muddati o'tdi",
            'text' => "Bu to'lov hisobi endi amal qilmaydi. Botda /pro buyrug'i orqali yangisini yarating. Muddati o'tgan hisob bo'yicha pul yechilmaydi.",
        ],
        'canceled' => [
            'heading' => "To'lov hisobi bekor qilindi",
            'text' => "Bu to'lov hisobi bekor qilingan. Kerak bo'lsa, botda /pro buyrug'i bilan yangisini yarating.",
        ],
        'unknown' => [
            'heading' => "To'lov holati noma'lum",
            'text' => "Bunday to'lov hisobini topa olmadik. Holatni botda /pro buyrug'i bilan tekshiring yoki qo'llab-quvvatlash xizmatiga yozing.",
        ],
    ],

    // Admin dashboard revenue widget: cash flow by event date, cohort net kept apart.
    'report' => [
        'heading' => "So'nggi 30 kun tushumi",
        'net_cash' => 'Pul harakatining sof natijasi :currency',
        'description' => "To'lovlar: :charges, brutto :gross · qaytarishlar (qaytarilgan sana bo'yicha): :refund_count, :refunds · kogorta netto (davr to'lovlari ulardan qaytarilganini ayirib): :cohort",
    ],

];
