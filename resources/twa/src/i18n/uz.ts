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
  'landing.in_telegram': "Mashg'ulotni Telegram guruhidagi tugma orqali oching.",
  'landing.outside_telegram':
    "Bu sahifa faqat Telegram Web App ichida ishlaydi. O'qituvchidan mashg'ulot havolasini guruhga yuborishini so'rang.",

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
  'training.closed_title': "🔒 Mashg'ulot o'qituvchi tomonidan yakunlandi",

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

  // ---- errors (by kind / context) ----
  'error.closed.training': "O'qituvchi mashg'ulotni yakunladi.",
  'error.closed.exam': "Imtihon o'qituvchi tomonidan yakunlandi.",
  'error.forbidden.training': "Sizda bu mashg'ulotga kirish huquqi yo'q.",
  'error.forbidden.exam': "Sizda bu imtihonga kirish huquqi yo'q.",
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
  'error.code.question_not_found': 'Savol topilmadi.',
  'error.code.question_not_served': "Savol hali ko'rsatilmagan. Ekranni yangilang.",
  'error.code.already_answered': 'Siz bu savolga allaqachon javob berdingiz.',
  'error.code.word_mismatch': 'Javob savolga mos kelmaydi. Ekranni yangilang.',
  'error.code.wrong_lesson': "Bu so'z darsga tegishli emas.",
  'error.code.card_not_started': "Bu kartochka hali ko'rsatilmagan. Ekranni yangilang.",
  'error.code.card_not_due': 'Bu kartochka allaqachon takrorlangan, vaqti hali kelmadi.',
  'error.code.exam_still_open': 'Imtihon hali davom etmoqda, natijalar tugagandan keyin chiqadi.',
  'error.code.tts_unavailable': 'Ovozli talaffuz hozircha mavjud emas.',
  'error.code.promo_not_found': 'Promokod topilmadi. Xato yozilmaganini tekshiring.',
  'error.code.promo_expired': 'Promokodning amal qilish muddati tugagan.',
  'error.code.promo_exhausted': "Bu promokoddan foydalanish limiti tugagan.",
  'error.code.promo_already_redeemed': 'Siz bu promokodni allaqachon faollashtirgansiz.',
  'error.code.promo_not_applicable': "Sizda allaqachon muddatsiz Pro bor — promokod kerak emas.",
}
