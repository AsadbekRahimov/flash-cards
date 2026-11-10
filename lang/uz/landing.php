<?php

declare(strict_types=1);

return [

    'meta' => [
        'title' => 'LexiFlow — Telegramda inglizcha so\'zlarni o\'rganing',
        // `:free` = Free's daily allowance as a phrase (`limits.free_new_words`), from config: LandingPricing::limits().
        'description' => 'LexiFlow — Telegramdagi bot va mini-ilova: kartochkalar, ovozli talaffuz va aqlli takrorlash orqali inglizcha so\'zlarni o\'rganing. Bepul: :free.',
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
        'note' => 'Bepul: :free, takrorlash esa cheklovsiz.',
        // Decorative phone mock-up next to the headline: a flash card of the Mini App (docs/14 §6.2, §8 grades).
        'mockup' => [
            'progress' => '7 / 20',
            'chip' => 'Takrorlash',
            'word' => 'breakfast',
            'ipa' => '/ˈbrekfəst/',
            'translation' => 'nonushta',
            'example' => 'I have breakfast at eight.',
            'question' => 'So\'zni qanchalik yaxshi eslaysiz?',
            'grade' => [
                'forgot' => 'Eslay olmadim',
                'remembered' => 'Esladim',
                'easy' => 'Oson',
            ],
        ],
    ],

    'how' => [
        'title' => 'Qanday ishlaydi',
        'lead' => 'So\'z birdan emas, asta-sekin unutiladi. LexiFlow uni unutilishidan oldin qaytaradi — har safar tanaffus uzayib boradi.',
        // The path of one word through its reviews: approximate moments in words, not the exact intervals of the engine.
        'timeline' => [
            'word' => 'breakfast',
            'points' => [
                ['when' => 'Bugun', 'what' => 'Yangi so\'z'],
                ['when' => 'Ertaga', 'what' => 'Birinchi takrorlash'],
                ['when' => 'Bir haftadan so\'ng', 'what' => 'Ikkinchisi'],
                ['when' => 'Ikki haftadan so\'ng', 'what' => 'Uchinchisi'],
                ['when' => 'Bir oydan so\'ng', 'what' => 'So\'z uzoq vaqt esda qoladi'],
            ],
            'note' => 'Eslay olmasangiz — so\'z ertaroq qaytadi. Oson eslasangiz — kechroq.',
        ],
        'steps' => [
            ['title' => 'Botni oching', 'text' => 'Telegramda «Start» tugmasini bosing va interfeys tilini tanlang. Hech narsa o\'rnatish shart emas.'],
            ['title' => 'Kartochkalar bilan o\'rganing', 'text' => 'Mini-ilova Telegramning o\'zida ochiladi: so\'z, tarjima, misol va ovozli talaffuz.'],
            ['title' => 'O\'z vaqtida takrorlang', 'text' => 'Intervalli takrorlash so\'zni qachon takrorlash kerakligini o\'zi aniqlaydi, bot esa eslatma yuboradi.'],
        ],
    ],

    'features' => [
        'title' => 'Ichida nimalar bor',
        'items' => [
            ['title' => 'Bot va mini-ilova', 'text' => 'Bot — boshlash va eslatmalar uchun, mini-ilova — mashg\'ulotlar uchun. Hammasi bitta messenjerda.'],
            ['title' => 'Aqlli takrorlash', 'text' => 'Har bir so\'z tobora uzayib boruvchi oraliqlarda qaytadi — shu tarzda u uzoq muddatli xotiraga o\'tadi.'],
            ['title' => 'Ovoz va misollar', 'text' => 'Talaffuzni tinglang va so\'z gapda qanday ishlatilishini ko\'ring.'],
            ['title' => 'O\'zbek va rus tillari', 'text' => 'Bot va mini-ilova interfeysi o\'zbek va rus tillarida mavjud.'],
        ],
    ],

    // The limits this page quotes (`hero.mockup.progress` is a drawing, not one of them). Their numbers are read from
    // config by LandingPricing::limits() — Free's `billing.plans.free.limits.new_words_per_day` (null = no daily
    // limit) and `decks.limits.{free,pro}` — so none is typed in this file. A tick of a plan card that is just
    // `:new_words` or `:decks` is replaced by the line below; a plan with nothing to say there loses the tick.
    'limits' => [
        // Free's daily allowance; also the `:free` of `meta.description` and `hero.note` (so it reads mid-sentence).
        // Uzbek does not inflect nouns after numerals: a single form for any count.
        'free_new_words' => 'kuniga :count ta yangi so\'z',
        'free_new_words_unlimited' => 'kuniga istagancha yangi so\'z',
        // Pro's first tick: only while Free has a daily limit that Pro lacks.
        'pro_new_words' => 'Kuniga istagancha yangi so\'z',
        // Pro's practice tick: only while practice is open to everybody and Pro has something to lift there - a Free daily limit, or
        // practice that is Pro's as a whole (ProPromises, the rule of the Mini App too); the second sentence while the battles are switched
        // off (PRACTICE_BATTLE=false). The same two sentences as `benefits.practice*` of the Mini App.
        'pro_practice' => 'Mustahkamlash mashqlari va bellashuvlar cheklovsiz',
        'pro_practice_solo' => 'Mustahkamlash mashqlari cheklovsiz',
        // One deck / many (a rule of the plan: `{1}` is exactly one, 21 is many).
        'decks' => '{1} Shaxsiy to\'plam: :deck_words tagacha so\'z|[2,*] Shaxsiy to\'plamlar: :decks tagacha, har birida :deck_words tagacha so\'z',
    ],

    'pricing' => [
        'title' => 'Tariflar',
        // `subtitle`, `soon` and `pro.note` are shown while Pro is not for sale (billing.paywall.mode = coming_soon);
        // `subtitle_open`, `pro.price*` and `pro.note_open` once it is (LandingPricing).
        'subtitle' => 'Bepul boshlang. Pullik imkoniyatlar keyinroq paydo bo\'ladi — natijalaringiz saqlanib qoladi.',
        'subtitle_open' => 'Bepul boshlang. Pro — ko\'proq istaganingizda.',
        'soon' => 'Tez orada',
        'free' => [
            'name' => 'Free',
            'tagline' => 'Kundalik mashq uchun',
            'features' => [
                'Ochiq kurslar',
                ':new_words',
                'Cheklovsiz takrorlash',
                ':decks',
                'Bot va mini-ilova',
            ],
        ],
        'pro' => [
            'name' => 'Pro',
            'tagline' => 'Ko\'proq istaganlar uchun',
            // Only what the code really gates behind Pro (config/billing.php features, config/decks.php limits).
            'features' => [
                ':new_words',
                ':practice',
                'Mashqlar: «So\'zni yozish» va «Misoldagi bo\'sh joy»',
                ':decks',
                "Bir kun shug'ullanmasangiz ham, ketma-ketlikni saqlab qolish",
                'IELTS va SAT sinov imtihonlari',
            ],
            'price' => 'Boshlang\'ich narx — oyiga :price',
            'price_stars' => 'yoki Telegram Stars orqali :price',
            'note' => 'Pro tarkibi va narxlari hozircha aniqlanmoqda.',
            'note_open' => 'Pro\'ni botda /pro buyrug\'i orqali yoki mini-ilovada rasmiylashtirishingiz mumkin.',
        ],
        'centers' => [
            'name' => 'O\'quv markazlari uchun',
            'tagline' => 'Qat\'iy to\'lov — oylik yoki yillik',
            'features' => [
                'Markaz har bir o\'quvchi uchun emas, qat\'iy summa to\'laydi',
                'Markaz o\'quvchilari markazda ekan, tarifdagi o\'rinlar doirasida Pro oladi',
                "Telegramda guruh mashg'ulotlari va imtihonlar",
            ],
            'contact' => 'Markazni ulash:',
        ],
    ],

    // The section for teachers and learning centers (#teachers). The tiers and their seats come from config (LandingPricing::orgTiers()).
    'teachers' => [
        'title' => 'O\'qituvchilar va o\'quv markazlari uchun',
        'lead' => 'Mashg\'ulot va imtihonlar sinfning Telegram guruhida o\'tadi, o\'quvchilar faolligi esa markaz kabinetida ko\'rinadi.',
        'steps' => [
            ['title' => 'Ariza qoldiring', 'text' => 'Markaz kabinetini ochib beramiz va botni guruhga qanday qo\'shishni tushuntiramiz.'],
            ['title' => 'Botni sinf guruhiga qo\'shing', 'text' => 'O\'quvchilar mashg\'ulotga bitta tugma bilan kiradi — hech narsa o\'rnatish shart emas.'],
            ['title' => 'Mashg\'ulot va imtihonlarni boshlang', 'text' => 'Guruhda bitta buyruq bilan. Imtihon natijalarini botning o\'zi e\'lon qiladi.'],
        ],
        'tiers_title' => 'Markazlar uchun tariflar',
        // Uzbek does not inflect nouns after numerals: a single form for any count.
        'seats' => ':count tagacha o\'quvchi',
        'seats_unlimited' => 'o\'rinlar cheklanmagan',
        'seat_note' => 'O\'rin — markaz hisobidan Pro oladigan bitta o\'quvchi.',
        'year_discount' => 'Yillik to\'lovda — :percent% chegirma.',
        'cta' => 'Markazni ulash',
        'login' => 'Kabinetga kirish',
    ],

    'footer' => [
        'tagline' => 'LexiFlow — Telegramda ingliz tili.',
        'contact' => 'Qo\'llab-quvvatlash:',
        'language' => 'Til',
        'legal_label' => 'Hujjatlar',
    ],

];
