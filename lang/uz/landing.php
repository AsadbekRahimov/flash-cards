<?php

declare(strict_types=1);

return [

    'meta' => [
        'title' => 'LexiFlow - Telegram orqali inglizcha so\'zlarni o\'rganing',
        'description' => 'LexiFlow - Telegramdagi bot va mini-ilova: kartochkalar, ovozli talaffuz va aqlli takrorlash orqali inglizcha so\'zlarni o\'rganing. Bepul: kuniga 20 ta yangi so\'z.',
    ],

    'nav' => [
        'how' => 'Qanday ishlaydi',
        'pricing' => 'Tariflar',
        'teachers' => 'O\'quv markazlari uchun',
        'label' => 'Asosiy navigatsiya',
    ],

    'hero' => [
        'title' => 'Esda qoladigan inglizcha so\'zlar',
        'subtitle' => 'Ingliz tilini bevosita Telegramda o\'rganing: ovozli va misolli kartochkalar, bot esa so\'zlarni unuta boshlagan paytingizda takrorlashni eslatadi.',
        'cta_bot' => 'Botni ochish',
        'cta_unavailable' => 'Bot tez orada ochiladi',
        'note' => 'Bepul: kuniga 20 ta yangi so\'z, takrorlash esa cheklovsiz.',
    ],

    'how' => [
        'title' => 'Qanday ishlaydi',
        'steps' => [
            ['title' => 'Botni oching', 'text' => 'Telegramda «Start» tugmasini bosing va interfeys tilini tanlang. Hech narsa o\'rnatish shart emas.'],
            ['title' => 'Kartochkalar bilan o\'rganing', 'text' => 'Mini-ilova Telegramning o\'zida ochiladi: so\'z, tarjima, misol va ovozli talaffuz.'],
            ['title' => 'O\'z vaqtida takrorlang', 'text' => 'Intervalli takrorlash so\'zni yangilash vaqtini tanlaydi, bot esa eslatma yuboradi.'],
        ],
    ],

    'features' => [
        'title' => 'Ichida nimalar bor',
        'items' => [
            ['title' => 'Bot va mini-ilova', 'text' => 'Bot - boshlash va eslatmalar uchun, mini-ilova - mashg\'ulotlar uchun. Hammasi bitta messenjerda.'],
            ['title' => 'Aqlli takrorlash', 'text' => 'Har bir so\'z tobora uzayib boruvchi oraliqlarda qaytadi - shu tarzda u uzoq muddatli xotiraga o\'tadi.'],
            ['title' => 'Ovoz va misollar', 'text' => 'Talaffuzni tinglang va so\'z gapda qanday ishlatilishini ko\'ring.'],
            ['title' => 'O\'zbek va rus tillari', 'text' => 'Bot va mini-ilova interfeysi o\'zbek va rus tillarida mavjud.'],
        ],
    ],

    'pricing' => [
        'title' => 'Tariflar',
        'subtitle' => 'Bepul boshlang. Pullik imkoniyatlar keyinroq paydo bo\'ladi - bunda yutuqlaringiz saqlanib qoladi.',
        'soon' => 'Tez orada',
        'free' => [
            'name' => 'Free',
            'tagline' => 'Kundalik mashq uchun',
            'features' => [
                'Ommaviy kurslar',
                'Kuniga 20 ta yangi so\'z',
                'Cheklovsiz takrorlash',
                'Bot va mini-ilova',
            ],
        ],
        'pro' => [
            'name' => 'Pro',
            'tagline' => 'Ko\'proq istaganlar uchun',
            // Only what the code really gates behind Pro (config/billing.php features, config/decks.php limits).
            'features' => [
                'Yangi so\'zlar uchun kunlik limitsiz',
                'Mashqlar: «So\'zni yozish» va «Misoldagi bo\'sh joy»',
                'Shaxsiy to\'plamlar: 2000 tadan so\'zli 20 tagacha to\'plam',
                'Seriyani muzlatish: o\'tkazib yuborilgan kun seriyani uzmaydi',
                'IELTS va SAT sinov testlari',
            ],
            'note' => 'Pro tarkibi va narxlari hozircha aniqlanmoqda.',
        ],
        'centers' => [
            'name' => 'O\'quv markazlari uchun',
            'tagline' => 'Oyiga bir marta qat\'iy to\'lov',
            'features' => [
                'Markaz oyiga bir marta qat\'iy summa to\'laydi',
                'Markaz o\'quvchilari markazda ekan, to\'liq Pro oladi',
                'Telegramdagi guruhlar, o\'qituvchilar uchun mashg\'ulot va imtihonlar',
            ],
            'contact' => 'Markazni ulash:',
        ],
    ],

    'footer' => [
        'tagline' => 'LexiFlow - Telegramda ingliz tili.',
        'contact' => 'Qo\'llab-quvvatlash:',
        'language' => 'Til',
        'legal_label' => 'Hujjatlar',
    ],

];
