import type { Translations } from './ru'

/**
 * Uzbek (Latin script) dictionary. Must have exactly the keys of `ru.ts`
 * (enforced by the `Translations` type). Apostrophe is the ASCII `'`.
 *
 * Uzbek does not inflect a noun after a numeral ("5 ta so'z"), so plural
 * entries only need `one` and `other` (which are identical in meaning).
 */
export const uz: Translations = {
  // ---- common ----
  'common.close': 'Yopish',
  'common.loading': 'Yuklanmoqda…',
  'common.retry': 'Qayta urinish',
  'common.error_title': 'Xatolik',

  // ---- landing ----
  'landing.outside_telegram':
    "Bu sahifa faqat Telegram Web App ichida ishlaydi. O'qituvchidan mashg'ulot havolasini guruhga yuborishini so'rang.",

  // ---- today ----
  'today.title': 'Bugun',
  'today.plan.free': 'Free',
  'today.plan.pro': 'Pro',
  'today.goal.title': 'Kunlik maqsad',
  'today.goal.progress': '{target} tadan {done} tasi',
  'today.goal.met': 'Maqsad bajarildi',
  'today.goal.left': {
    one: '{count} ta kartochka qoldi',
    other: '{count} ta kartochka qoldi',
  },
  'today.streak.title': 'Seriya',
  'today.streak.days': {
    one: '{count} kun',
    other: '{count} kun',
  },
  'today.streak.none': 'Seriyani boshlash uchun kunlik maqsadni bajaring',
  'today.streak.at_risk': 'Seriya xavf ostida: maqsadni yarim tungacha bajaring',
  'today.streak.safe': 'Bugungi kun uchun seriya uzaytirildi',
  'today.streak.best': 'Rekord: {best}',
  'today.review.title': 'Takrorlash',
  'today.review.due': {
    one: "Takrorlash uchun {count} ta so'z",
    other: "Takrorlash uchun {count} ta so'z",
  },
  'today.review.none': "Hozircha takrorlaydigan narsa yo'q",
  'today.review.start': 'Takrorlash',
  'today.review.continue': 'Davom etish',
  'today.new.remaining': "Bugun yana {count} ta yangi so'z o'rganish mumkin",
  'today.new.exhausted': "Bugungi yangi so'zlar limiti tugadi",
  'today.course.title': 'Kurs',
  'today.course.next': 'Keyingi dars',
  'today.course.completed': "Kursdagi barcha so'zlar ko'rsatildi",
  'today.course.none': "Yangi so'zlarni o'rganish uchun kursni tanlang",
  'today.onboarding_hint': 'Sozlashni yakunlang: botga /start yuboring',

  // ---- flashcard ----
  'card.kind.new': "Yangi so'z",
  'card.kind.learning': 'Mustahkamlash',
  'card.kind.due': 'Takrorlash',
  'card.speak': 'Talaffuz qilish',
  'card.reveal': "Tarjimani ko'rsatish",
  'card.translation_fallback': "Mashg'ulot tilida tarjima hali yo'q",
  'card.grade_group': 'Baho',
  'card.grade.hard': 'Qiyin',
  'card.grade.medium': "O'rtacha",
  'card.grade.easy': 'Oson',

  // ---- training ----
  'training.lesson_header': 'Stage {stage} · Lesson {lesson}',
  'training.daily_header': 'Takrorlash',
  'training.closed_title': "🔒 Mashg'ulot yakunlandi",
  'training.restart': 'Qaytadan boshlash',

  // ---- exam ----
  'exam.closed_title': "🔒 Imtihon o'qituvchi tomonidan yakunlandi",

  // ---- exam result ----
  'result.title': 'Imtihon natijalari',
  'result.your_score': 'Sizning natijangiz',
  'result.rank': "{total} ishtirokchi ichida {rank}-o'rin",
  'result.pending': 'Natijalar hali hisoblanmoqda…',
  'result.top': 'Eng yaxshi ishtirokchilar',

  // ---- finished (training) ----
  'finished.title': 'Ajoyib!',
  'finished.reviewed': {
    one: "Siz {count} ta so'zni takrorladingiz.",
    other: "Siz {count} ta so'zni takrorladingiz.",
  },
  'finished.reason.lesson_complete': "Darsdagi barcha so'zlar o'tildi.",
  'finished.reason.nothing_due': "Bugun takrorlaydigan narsa yo'q.",
  'finished.reason.new_quota_exhausted': "Bugungi yangi so'zlar limiti tugadi. Yangi so'zlar ertaga ochiladi.",
  'finished.reason.session_cap': "Bu uzun mashg'ulot bo'ldi — biroz dam oling.",
  'finished.home': 'Bosh sahifaga',
  'finished.goal': 'Kunlik maqsad: {target} tadan {done} tasi',
  'finished.goal_met': 'Kunlik maqsad bajarildi!',
  'finished.next_label': 'Keyingi takrorlash',
  'finished.next_today': 'Bugun',
  'finished.next_in_days': {
    one: '{count} kundan keyin',
    other: '{count} kundan keyin',
  },

  // ---- promo code ----
  'promo.toggle': 'Promokodingiz bormi?',
  'promo.placeholder': 'Promokod',
  'promo.submit': 'Faollashtirish',
  'promo.success_until': 'Pro {date} gacha faol.',
  'promo.success_forever': 'Pro muddatsiz faol.',

  // ---- paywall (Free quota, mode coming_soon; docs/12 §5.4) ----
  'paywall.title': 'Pro tez orada',
  'paywall.lead_quota': "Bugungi yangi so'zlar limiti tugadi — takrorlash cheklovsiz.",
  'paywall.soon': 'Pro tez orada ochiladi.',
  'paywall.free': "Hozir sizda Free tarif: kuniga {count} ta yangi so'z, takrorlash cheklovsiz.",
  'paywall.free_generic': "Hozir sizda Free tarif: yangi so'zlar kunlik limit bilan cheklangan, takrorlash cheklovsiz.",
  'paywall.contact': "Markazda o'qiysizmi? Markaz administratoridan LexiFlow Pro'ni ulashni so'rang:",
  'paywall.dismiss': 'Tushunarli',
  'paywall.open': 'Pro haqida bilib oling',
  'paywall.review': "So'zlarni takrorlash",

  // ---- course catalog ----
  'catalog.title': 'Kurslar',
  'catalog.open': 'Kurslar katalogi',
  'catalog.empty': "Hozircha kurslar yo'q. Keyinroq qaytib ko'ring.",
  'catalog.choose': 'Tanlash',
  'catalog.active': 'Faol',
  'catalog.words': {
    one: "{count} ta so'z",
    other: "{count} ta so'z",
  },
  'catalog.progress': "{total} tadan {done} tasi o'rganildi",
  'catalog.goal.general': 'Umumiy ingliz tili',
  'catalog.goal.school': 'Maktab',
  'catalog.goal.ielts': 'IELTS',
  'catalog.goal.sat': 'SAT',
  'course.back': 'Kurslarga',
  'course.module': '{number}-modul',
  'course.lesson': '{number}-dars',
  'course.learn': "O'rganish",
  'course.no_lessons': "Kursda hali darslar yo'q.",

  // ---- settings ----
  'settings.title': 'Sozlamalar',
  'settings.language': 'Interfeys tili',
  'settings.timezone': 'Vaqt mintaqasi',
  'settings.reminder': 'Eslatmalar',
  'settings.reminder.off': 'Eslatmalarsiz',
  'settings.reminder.hint': "Vaqt sizning vaqt mintaqangiz bo'yicha.",
  'settings.daily_goal': 'Kunlik maqsad',
  'settings.daily_goal.option': {
    one: '{count} ta kartochka',
    other: '{count} ta kartochka',
  },
  'settings.course': 'Faol kurs',
  'settings.course.none': 'Kurs tanlanmagan',
  'settings.saved': 'Saqlandi',

  // ---- errors (by kind / context) ----
  'error.closed.training': "Bu mashg'ulot allaqachon yopilgan: u yakunlangan yoki sessiya vaqti tugagan.",
  'error.closed.exam': "Imtihon o'qituvchi tomonidan yakunlandi.",
  'error.closed.catalog': 'Kurs endi mavjud emas.',
  'error.closed.today': 'Bugun ekrani endi mavjud emas.',
  'error.closed.settings': 'Sozlamalar endi mavjud emas.',
  'error.forbidden.training': "Sizda bu mashg'ulotga kirish huquqi yo'q.",
  'error.forbidden.exam': "Sizda bu imtihonga kirish huquqi yo'q.",
  'error.forbidden.catalog': "Sizda kurslar katalogiga kirish huquqi yo'q.",
  'error.forbidden.today': "Sizda Bugun ekraniga kirish huquqi yo'q.",
  'error.forbidden.settings': "Sizda sozlamalarga kirish huquqi yo'q.",
  'error.network': "Server bilan aloqa yo'q. Qayta urinib ko'ring.",
  'error.rate_limited': "Juda ko'p so'rov yuborildi. Biroz kutib, qayta urinib ko'ring.",
  'error.server': "Server vaqtincha mavjud emas. Keyinroq urinib ko'ring.",
  'error.generic': 'Nimadir xato ketdi.',
  'error.unexpected': 'Kutilmagan xatolik.',
  'error.outside_telegram': 'Ilovani Telegram orqali oching.',

  // ---- errors (by API error.code) ----
  'error.code.session_closed': 'Sessiya allaqachon yopilgan.',
  'error.code.session_not_found': "Sessiya topilmadi. O'qituvchidan uni qaytadan boshlashini so'rang.",
  'error.code.learner_not_found': 'Profilingiz topilmadi. Ilovani Telegram orqali qaytadan oching.',
  'error.code.learner_blocked': "Akkauntga kirish cheklangan. Qo'llab-quvvatlash xizmatiga murojaat qiling.",
  'error.code.not_a_member': "Siz bu guruh a'zosi emassiz. Guruhga qo'shiling va havolani qaytadan oching.",
  'error.code.group_inactive': "Guruh faollashtirilmagan. O'qituvchiga murojaat qiling.",
  'error.code.invalid_init_data': "Telegram sessiyasini tasdiqlab bo'lmadi. Ilovani qaytadan oching.",
  'error.code.invalid_token': 'Sessiya eskirgan. Ilovani qaytadan oching.',
  'error.code.missing_token': 'Siz tizimga kirmagansiz. Ilovani Telegram orqali qaytadan oching.',
  'error.code.lesson_not_found': 'Dars topilmadi.',
  'error.code.lesson_mismatch': 'Bu rejim tanlangan darsga mos kelmaydi.',
  'error.code.invalid_mode': "Noma'lum mashg'ulot rejimi.",
  'error.code.question_not_found': 'Savol topilmadi.',
  'error.code.question_not_served': "Savol hali ko'rsatilmagan. Ekranni yangilang.",
  'error.code.already_answered': 'Siz bu savolga allaqachon javob berdingiz.',
  'error.code.word_mismatch': 'Javob savolga mos kelmaydi. Ekranni yangilang.',
  'error.code.out_of_scope': "Bu so'z ushbu mashg'ulotga tegishli emas.",
  'error.code.card_not_started': "Bu kartochka hali ko'rsatilmagan. Ekranni yangilang.",
  'error.code.card_not_due': 'Bu kartochka allaqachon takrorlangan, vaqti hali kelmadi.',
  'error.code.exam_still_open': 'Imtihon hali davom etmoqda, natijalar tugagandan keyin chiqadi.',
  'error.code.tts_unavailable': 'Ovozli talaffuz hozircha mavjud emas.',
  'error.code.promo_not_found': 'Promokod topilmadi. Xato yozilmaganini tekshiring.',
  'error.code.promo_expired': 'Promokodning amal qilish muddati tugagan.',
  'error.code.promo_exhausted': "Bu promokoddan foydalanish limiti tugagan.",
  'error.code.promo_already_redeemed': 'Siz bu promokodni allaqachon faollashtirgansiz.',
  'error.code.promo_not_applicable': "Sizda allaqachon muddatsiz Pro bor — promokod kerak emas.",
  'error.code.plan_unknown': "Bu promokod hozircha mavjud emas. Qo'llab-quvvatlash xizmatiga yozing.",
  'error.code.course_not_found': 'Kurs topilmadi yoki mavjud emas.',
}
