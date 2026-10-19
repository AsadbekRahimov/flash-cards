<?php

declare(strict_types=1);

return [

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
            'checkout_conflict' => "Sizda Telegram Stars avtoyangilash yoqilgan. Boshqa usulda to'lashdan oldin uni /pro orqali o'chiring.",
            'too_many_pending' => "Sizda to'lanmagan hisoblar bor. Ulardan birini to'lang yoki kuting — ular o'zi yopiladi.",
            'offer_not_applicable' => "Chegirma bu xaridga qo'llanilmaydi.",
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

];
