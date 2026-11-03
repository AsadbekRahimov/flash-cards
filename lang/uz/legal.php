<?php

declare(strict_types=1);

// DRAFT legal texts (docs/12 §9, task 1.7), Uzbek (Latin, ASCII apostrophe, "Siz"). Not yet checked by a native speaker, and
// they must be checked by a lawyer before launch. The notice `draft_notice` is shown while SITE_LEGAL_DRAFT=true. Same structure
// as lang/ru/legal.php (the sections must match one to one); the open points for the lawyer are listed there.
//
// Tokens: {contact} = support contact, {requisites} = the filled SITE_LEGAL_* fields. Square brackets mark a place the owner /
// the lawyer must fill or confirm.

return [

    'draft_notice' => 'Qoralama. Matn yurist tekshiruvida va hozircha amaldagi hujjat hisoblanmaydi.',

    'placeholder' => 'Bo\'lim matni xizmat egasi tomonidan taqdim etiladi.',

    'back' => 'Bosh sahifaga',

    'contact_fallback' => 'LexiFlow botidagi /paysupport buyrug\'i',

    'requisites_missing' => '[Xizmat egasi: ijrochi rekvizitlarini kiriting - nomi, STIR, manzil, bank rekvizitlari, telefon, e-mail]',

    'requisite' => [
        'name' => 'Nomi',
        'inn' => 'STIR',
        'address' => 'Manzil',
        'bank' => 'Bank',
        'bank_account' => 'Hisob raqami',
        'mfo' => 'MFO',
        'phone' => 'Telefon',
        'email' => 'E-mail',
    ],

    'offer' => [
        'title' => 'Ommaviy oferta',
        'sections' => [
            [
                'title' => 'Umumiy qoidalar',
                'text' => <<<'TXT'
                    Ushbu hujjat LexiFlow xizmatidan («Xizmat») foydalanish bo'yicha ommaviy oferta (shartnoma tuzish taklifi) hisoblanadi. Ijrochi — rekvizitlari oxirgi bo'limda ko'rsatilgan shaxs.

                    Xizmat ingliz tilidagi so'zlarni o'rganish uchun Telegram-bot va Telegram Mini App'dan, shuningdek o'quv markazlari va o'qituvchilar kabinetidan iborat. Foydalanuvchi («Siz») botdan yoki Mini App'dan foydalanishni boshlash yoki pullik tarif uchun to'lash bilan ofertani qabul qiladi.

                    Shartlarga rozi bo'lmasangiz, Xizmatdan foydalanmang.
                    TXT,
            ],
            [
                'title' => 'Shartnoma predmeti',
                'text' => <<<'TXT'
                    Ijrochi Sizga Xizmatdan foydalanish imkonini beradi: oraliqli takrorlash bilan so'z kartochkalari, kundalik mashq, imtihonlar va sinov testlari, mashq va bahslar (battle), ligalar, do'stlar, yutuqlar va referal dasturi. Imkoniyatlar tarifga bog'liq.

                    Xizmat — o'quv vositasi. Ijrochi aniq o'qish natijasini (bilim darajasi, imtihon bali) kafolatlamaydi: natija Sizning mashg'ulotlaringizga bog'liq.

                    O'quv markazlari va o'qituvchilar alohida kabinetdan (/org) foydalanadi: ular o'z o'quvchilarining natijalarini ko'radi va topshiriq bera oladi. Markazlar uchun shartlar — markazga beriladigan tariflar va hisob-fakturalar — ushbu oferta bilan birga amal qiladi.
                    TXT,
            ],
            [
                'title' => 'Ro\'yxatdan o\'tish va hisob',
                'text' => <<<'TXT'
                    Alohida ro'yxatdan o'tish yo'q: hisob bot yoki Mini App birinchi marta ishga tushirilganda avtomatik yaratiladi va Telegram akkauntingizga bog'lanadi. Kirish Telegram vositalari orqali amalga oshadi. Telegram akkauntingiz xavfsizligi uchun Siz javobgarsiz.

                    O'quv markazlari xodimlari taklifnoma bo'yicha e-mail va parol bilan kabinetga kiradi; markaz egalari uchun ikki bosqichli autentifikatsiya majburiy.

                    Minimal yosh: [yurist uchun: foydalanuvchining minimal yoshini va voyaga yetmaganlar uchun ota-ona yoki qonuniy vakillar roziligi tartibini ko'rsating; o'quvchilar orasida maktab o'quvchilari bor].

                    Taqiqlanadi: kirishni boshqalarga berish, natijalarni (reyting, liga, bahslar) soxtalashtirish uchun Xizmatdan foydalanish, Xizmat ishiga xalaqit berish, boshqa foydalanuvchilar huquqlarini buzish. Qoidabuzarlik uchun kirish cheklanishi mumkin.
                    TXT,
            ],
            [
                'title' => 'Tariflar, to\'lov va qaytarish tartibi',
                'text' => <<<'TXT'
                    Tariflar. Free — bepul tarif: kuniga yangi so'zlar soni va imkoniyatlar to'plami cheklangan. Pro — pullik tarif: kunlik limitsiz yangi so'zlar, kengaytirilgan mashq va qo'shimcha topshiriqlar, seriyani muzlatish, sinov testlari va boshqa imkoniyatlar. Pro'ning joriy tarkibi va narxi to'lovdan oldin botda va saytda ko'rsatiladi.

                    To'lov. Boshlanishida Pro Telegram Stars orqali to'lanadi. Bir oylik obuna — 30 kunlik Telegram obunasi: Siz uni o'chirmaguningizcha avtomatik yangilanadi (botdagi /pro buyrug'i yoki Telegram'dagi Stars obunalari sozlamalari). Yillik Pro 365 kunga bir martalik to'lanadi va avtomatik yangilanmaydi. Payme va Click orqali karta bilan to'lov keyinroq ulanishi mumkin; bu haqda botda va saytda xabar beramiz. Ijrochi bank kartalari ma'lumotlarini olmaydi.

                    Promokodlar va markazdan Pro. Promokod unda ko'rsatilgan muddatga Pro'dan foydalanish imkonini beradi. O'quv markazi o'z o'quvchilari uchun Pro'ni to'lashi mumkin: markaz obunasi amal qilar ekan va Siz uning o'quvchilari qatorida ekansiz, Sizda Pro bor.

                    Muddat tugashi. To'langan davr tugagach (avtoyangilanishda — yangilanish muvaffaqiyatsiz bo'lib, imtiyozli davr o'tgach) kirish Free tarifiga qaytadi; yutuqlaringiz saqlanadi.

                    Pulni qaytarish. Pulni qaytarishga avtomatik huquq yo'q. Pul faqat qo'llab-quvvatlash xizmati orqali qaytariladi: bizga yozing ({contact}) yoki botdagi /paysupport buyrug'idan foydalaning, chek yoki to'lov raqamini ilova qiling. Har bir murojaat alohida ko'rib chiqiladi. Qaytarish ma'qullansa, Telegram Stars orqali to'langan to'lov Stars bilan Telegram'dagi hisobingizga qaytariladi; shu to'lov bilan to'langan Pro davri tugatiladi, avtoyangilanish to'xtatiladi. Boshqa usul bilan to'langan to'lovlarni qaytarish muddati va usuli murojaat natijasiga ko'ra belgilanadi.

                    Avtoyangilanishni bekor qilish allaqachon to'langan davr uchun pulni qaytarmaydi — Pro shu davr oxirigacha amal qiladi.

                    Telegram Stars shartlari (Stars sotib olish, saqlash va ishlatish) Telegram tomonidan belgilanadi.
                    TXT,
            ],
            [
                'title' => 'Tomonlarning huquq va majburiyatlari',
                'text' => <<<'TXT'
                    Ijrochi majburiyatlari: tarifingiz doirasida Xizmatdan foydalanishni ta'minlash; qo'llab-quvvatlashga murojaatlarni ko'rib chiqish; ma'lumotlaringizni Maxfiylik siyosatiga muvofiq himoya qilish.

                    Ijrochi huquqlari: Xizmat imkoniyatlari va dizaynini o'zgartirish; vaqtincha ishlamay qoladigan texnik ishlar o'tkazish; qoidabuzarlarning kirishini cheklash; kelgusi uchun tarif va narxlarni o'zgartirish — to'langan davr uchun narx o'zgarmaydi.

                    Sizning majburiyatlaringiz: ishonchli ma'lumot ko'rsatish, qonun va uchinchi shaxslar huquqlarini buzmaslik, Xizmat ishiga aralashmaslik va tarif cheklovlarini chetlab o'tmaslik.
                    TXT,
            ],
            [
                'title' => 'Tomonlarning javobgarligi',
                'text' => <<<'TXT'
                    Xizmat «mavjud holicha» taqdim etiladi. Ijrochi uzluksiz ishlash uchun oqilona choralar ko'radi, lekin nosozliklar yo'qligini, shu jumladan Telegram, xosting-provayder yoki to'lov xizmatlari aybi bilan yuzaga keladiganlarini kafolatlamaydi.

                    Ijrochi Telegram'ning ishlamasligi, to'lov xizmatlari va banklar harakatlari, bilvosita zararlar uchun javobgar emas. Ijrochining javobgarligi qonun ruxsat etgan doirada Siz oxirgi pullik davr uchun to'lagan summa bilan cheklanadi.

                    Tomonlar yengib bo'lmas kuch holatlari tufayli majburiyatlarni bajarmaganlik uchun javobgarlikdan ozod etiladi.
                    TXT,
            ],
            [
                'title' => 'Shaxsiy ma\'lumotlar',
                'text' => <<<'TXT'
                    Qanday ma'lumotlarni va nima uchun qayta ishlashimiz Maxfiylik siyosatida (/privacy sahifasi) tavsiflangan. Ofertani qabul qilib, Siz u bilan tanishganingizni tasdiqlaysiz.
                    TXT,
            ],
            [
                'title' => 'Nizolarni hal qilish',
                'text' => <<<'TXT'
                    Tomonlar nizolarni muzokaralar yo'li bilan hal qilishga harakat qiladi. Avval qo'llab-quvvatlash xizmatiga yozing ({contact}); murojaatga oqilona muddatda javob beramiz [yurist uchun: da'voga javob muddatini ko'rsating].

                    Kelishib bo'lmasa, nizo Ijrochining joylashgan joyidagi sudda O'zbekiston Respublikasi qonunchiligiga muvofiq ko'riladi [yurist uchun: qo'llaniladigan huquq va sudlovga taalluqlilikni tasdiqlang].
                    TXT,
            ],
            [
                'title' => 'Amal qilish muddati va shartlarni o\'zgartirish',
                'text' => <<<'TXT'
                    Oferta saytda e'lon qilingan paytdan to chaqirib olingunga qadar amal qiladi. Ijrochi yangi tahrirni ushbu sahifada e'lon qilib, shartlarni o'zgartirishi mumkin; yangi tahrir e'lon qilingan paytdan, to'langan davr uchun esa keyingi davrdan qo'llaniladi. Xizmatdan foydalanishni davom ettirib, Siz yangi tahrirni qabul qilasiz.
                    TXT,
            ],
            [
                'title' => 'Ijrochining rekvizitlari',
                'text' => <<<'TXT'
                    {requisites}

                    Murojaatlar va savollar: {contact}
                    TXT,
            ],
        ],
    ],

    'privacy' => [
        'title' => 'Maxfiylik siyosati',
        'sections' => [
            [
                'title' => 'Umumiy qoidalar',
                'text' => <<<'TXT'
                    Ushbu siyosat LexiFlow xizmati (Telegram-bot, Mini App va /org markazlar kabineti) qanday ma'lumotlarni to'plashi, nima uchun va ularni qanday himoya qilishimizni tavsiflaydi. Ma'lumotlar operatori — rekvizitlari ommaviy ofertada ko'rsatilgan Ijrochi.

                    Biz o'qish va to'lov uchun zarur bo'lgan minimal ma'lumotni to'playmiz. Reklama ko'rsatmaymiz va ma'lumotlarni reklama tarmoqlariga bermaymiz.
                    TXT,
            ],
            [
                'title' => 'Qanday ma\'lumotlarni qayta ishlaymiz',
                'text' => <<<'TXT'
                    O'quvchilar (Telegram va Sizning harakatlaringizdan): Telegram identifikatori, ism va familiya, foydalanuvchi nomi (username), Telegram til kodi, interfeys tili, vaqt mintaqasi, maqsad va daraja, kunlik maqsad, eslatma vaqti, o'tish manbasi (masalan, referal havola), texnik belgilar (oxirgi faollik, botni bloklash).

                    O'quv faoliyati: o'rganilgan so'zlar va ularni takrorlash jadvali, mashqlar, imtihonlar, amaliyot va bahslardagi javoblar va natijalar, mashg'ulotlar seriyasi, ligalar va ochkolar, yutuqlar, kunlik statistika.

                    Ijtimoiy aloqalar: do'stlar va chaqiriqlar, referal takliflar, bot ulangan Telegram guruhlari va markazlarning o'quv guruhlaridagi a'zolik. Bahs raqibi va liga ishtirokchilari Sizning ismingizni ko'radi; username, identifikator va natijalaringiz boshqa foydalanuvchilarga ko'rsatilmaydi.

                    To'lovlar: tarif, provayder, summa, valyuta, holat va to'lov identifikatorlari, ishlatilgan promokodlar. Bank kartalari ma'lumotlarini olmaymiz va saqlamaymiz: Stars to'lovini Telegram qabul qiladi.

                    Markaz xodimlari: ism, e-mail, parol xeshi, ikki bosqichli autentifikatsiya ma'lumotlari, rol va markaz hamda guruhlarga bog'lanish, harakatlar jurnali (IP-manzil va brauzer ma'lumotlari bilan).

                    Jurnallar va analitika: yuborilgan eslatmalar jurnali, foydalanishning shaxsiz hodisalari (masalan, mashq boshlandi), server va xatolar texnik jurnallari. Telefon raqami va profil rasmini saqlamaymiz.
                    TXT,
            ],
            [
                'title' => 'Qayta ishlash maqsadlari va asoslari',
                'text' => <<<'TXT'
                    Ma'lumotlarni quyidagilar uchun qayta ishlaymiz: Xizmatni taqdim etish va natijangizni hisoblash; takrorlash haqida eslatmalar yuborish (ularni sozlamalarda o'chirish mumkin); to'lovni qabul qilish va Pro'ni berish; o'qituvchi va markazga o'z o'quvchilarining natijalarini ko'rsatish; xavfsizlikni ta'minlash va suiiste'molliklarga qarshi kurashish; xatolarni topish va tuzatish; shaxsiz statistika asosida Xizmatni yaxshilash.

                    Asos — Xizmatdan foydalanishni boshlash bilan bildirilgan Sizning rozilingiz va shartnoma (oferta) ijrosi [yurist uchun: qayta ishlash asoslarini tasdiqlang].
                    TXT,
            ],
            [
                'title' => 'Ma\'lumotlarni uchinchi shaxslarga berish',
                'text' => <<<'TXT'
                    Ma'lumotlarni sotmaymiz. Ma'lumotlarni faqat Xizmat ishlashi uchun zarur ishlov beruvchilar oladi:

                    Telegram — bot va Mini App ishlaydigan platforma; Stars to'lovini ham u qabul qiladi.

                    Sentry xatolar monitoringi xizmati — xatolar haqidagi texnik ma'lumotni oladi; yuborishdan oldin shaxsiy ma'lumotlar va sirlar olib tashlanadi yoki yashiriladi.

                    Pochta xizmati — markaz xodimlariga xatlar (takliflar, bildirishnomalar, hisob-fakturalar) uchun [egasiga: pochta provayderini ko'rsating].

                    Ovoz berish xizmati — amaliyotdagi diktant uchun so'zlar nutq sintezatori (edge-tts) bilan ovozlantiriladi, unga faqat ibora matni beriladi, foydalanuvchi ma'lumotlari berilmaydi.

                    Xosting-provayder — ma'lumotlar uning serverlarida saqlanadi [egasiga: provayderni ko'rsating].

                    Payme va Click to'lov xizmatlari — ulanganda; ular to'lov uchun zarur ma'lumotni oladi.

                    Bundan tashqari, ma'lumotlar davlat organlarining qonuniy talabi bo'yicha berilishi mumkin. O'qituvchilar va markazlar o'z o'quvchilarining ma'lumotlarini /org kabineti doirasida ko'radi.
                    TXT,
            ],
            [
                'title' => 'Bolalar va o\'smirlar',
                'text' => <<<'TXT'
                    Xizmat foydalanuvchilari orasida maktab o'quvchilari bor. Biz faqat Telegram profili ma'lumotlarini saqlaymiz, telefon, manzil va rasmlarni yig'maymiz hamda reklama ko'rsatmaymiz.

                    Minimal yosh: [yurist uchun: minimal yoshni va ota-ona yoki qonuniy vakillar roziligi tartibini ko'rsating]. Ota-ona yoki qonuniy vakil bola ma'lumotlarini ko'rsatish yoki o'chirish iltimosi bilan qo'llab-quvvatlash xizmatiga murojaat qilishi mumkin.
                    TXT,
            ],
            [
                'title' => 'Ma\'lumotlarni saqlash va himoya qilish',
                'text' => <<<'TXT'
                    Ma'lumotlarni saqlash joyi: [egasiga: xosting mamlakati va provayderini ko'rsating; yurist uchun: O'zbekiston Respublikasining «Shaxsga doir ma'lumotlar to'g'risida»gi qonuni (O'RQ-547), 27-1-moddasi O'zbekiston fuqarolarining shaxsiy ma'lumotlarini O'zbekiston hududidagi bazalarda saqlashni talab qiladi].

                    Muddatlar. Hisob ma'lumotlari va natijalaringizni Xizmatdan foydalanayotganingizda va so'rovingiz bilan o'chirilgunga qadar saqlaymiz. Foydalanish hodisalari 400 kungacha, texnik jurnallar — 30 kun, xodimlar harakatlari jurnali — 2 yil saqlanadi. Ma'lumotlar bazasining zaxira nusxalari 14 kun saqlanadi, so'ng o'chiriladi; o'chirilgan ma'lumotlar shu muddatgacha nusxalarda qolishi mumkin. To'lov yozuvlari qonun talab qilsa, uzoqroq saqlanishi mumkin [yurist uchun: muddatni ko'rsating].

                    Himoya. Kabinetlarga kirish parol va ikki bosqichli autentifikatsiya bilan himoyalangan, ulanish shifrlangan (HTTPS), xodimlar kirishi rollar bilan cheklangan, sirlar va tokenlar jurnallarga tushmaydi. Hech bir tizim to'liq himoyalanmagan; jiddiy hodisalar haqida qonunda belgilangan tartibda xabar beramiz.
                    TXT,
            ],
            [
                'title' => 'Foydalanuvchi huquqlari',
                'text' => <<<'TXT'
                    Siz so'rashingiz mumkin: Siz haqingizda qanday ma'lumotlar saqlanayotgani, ularni tuzatish, o'chirish, rozilikni qaytarib olish. Qo'llab-quvvatlash xizmatiga yozing: {contact}. Xizmatda alohida o'chirish tugmasi hozircha yo'q, so'rovlar qo'lda ko'rib chiqiladi.

                    O'chirish. So'rovdan keyin hisobni o'chirilgan deb belgilaymiz, ma'lumotlarni esa 30 kungacha bo'lgan muddatda butunlay o'chiramiz [yurist uchun: muddatni tasdiqlang]. Hisob bilan birga natijalaringiz o'chiriladi; to'lov yozuvlari qonun talab qilsa saqlanishi mumkin. Agar yakuniy o'chirishdan oldin botni yana ishga tushirsangiz, hisob tiklanadi.

                    Eslatmalarni bot sozlamalarida (/settings) o'chirish mumkin.
                    TXT,
            ],
            [
                'title' => 'Cookie fayllar va analitika',
                'text' => <<<'TXT'
                    Saytning ommaviy sahifalari (bosh sahifa, oferta, siyosat) cookie ishlatmaydi, uchinchi tomon hisoblagichlari va reklamasini ulamaydi.

                    /admin va /org kabinetlari faqat kirish va so'rovlarni soxtalashtirishdan himoya uchun zarur sessiya cookie'larini ishlatadi. Mini App Telegram ichida ishlaydi va kirishingizni Telegram imzolagan ma'lumotlar bilan tekshiradi.

                    Foydalanish statistikasini o'z serverlarimizda, uchinchi tomon analitika tizimlarisiz yig'amiz.
                    TXT,
            ],
            [
                'title' => 'Siyosatni o\'zgartirish',
                'text' => <<<'TXT'
                    Siyosatni yangilashimiz mumkin. Yangi tahrir ushbu sahifada e'lon qilinadi va e'lon qilingan paytdan amal qiladi. Jiddiy o'zgarishlar haqida botda xabar berishga harakat qilamiz.
                    TXT,
            ],
            [
                'title' => 'Ma\'lumotlar operatorining aloqa ma\'lumotlari',
                'text' => <<<'TXT'
                    Shaxsiy ma'lumotlar bo'yicha savollar: {contact}

                    {requisites}
                    TXT,
            ],
        ],
    ],

];
