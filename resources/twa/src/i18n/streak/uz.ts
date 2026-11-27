import type { StreakTranslations } from './ru'

/** The texts of F4 in Uzbek (Latin script, the ASCII apostrophe); exactly the keys of `ru.ts`. Not yet checked by a native speaker. */
export const streakUz: StreakTranslations = {
  'streak.saved.title': 'Ketma-ketlik saqlandi!',
  'streak.saved.lead': {
    one: "Kecha bir kunni o'tkazib yubordingiz, lekin zaxira kun {count} kunlik ketma-ketlikni saqlab qoldi.",
    other: "Kecha bir kunni o'tkazib yubordingiz, lekin zaxira kun {count} kunlik ketma-ketlikni saqlab qoldi.",
  },
  'streak.saved.lead_missed': {
    one: "{count} kunni o'tkazib yubordingiz, lekin zaxira kunlar ketma-ketlikni saqlab qoldi.",
    other: "{count} kunni o'tkazib yubordingiz, lekin zaxira kunlar ketma-ketlikni saqlab qoldi.",
  },
  'streak.saved.freezes_left': 'Qolgan zaxira kunlar: {count}',
  'streak.saved.continue': 'Davom ettirish',
  'streak.lost.title': 'Ketma-ketlik uzildi',
  'streak.lost.lead': {
    one: "{count} kun ketma-ket shug'ullangan edingiz.",
    other: "{count} kun ketma-ket shug'ullangan edingiz.",
  },
  'streak.lost.text': "Yangi ketma-ketlik bugun boshlanadi: kunlik maqsadni bajaring — birinchi muhr o'z joyida.",
  'streak.lost.pro_offer': "Zaxira kun ketma-ketlikni saqlab qolgan bo'lardi — Pro'da",
  'streak.lost.pro_more': 'Batafsil',
  'streak.lost.pro_note': "Zaxira kunlar yetmadi: o'tkazib yuborilgan kunlar zaxira kunlardan ko'p edi.",
  'streak.lost.restart': 'Qaytadan boshlash',
  'streak.week.label': 'Shu hafta',
  'streak.week.day.1': 'Du',
  'streak.week.day.2': 'Se',
  'streak.week.day.3': 'Ch',
  'streak.week.day.4': 'Pa',
  'streak.week.day.5': 'Ju',
  'streak.week.day.6': 'Sh',
  'streak.week.day.7': 'Ya',
  'streak.week.state.done': '{day}: kun hisoblandi',
  'streak.week.state.freeze': '{day}: zaxira kun',
  'streak.week.state.today': '{day}: bugun',
  'streak.week.state.future': '{day}: oldinda',
  'streak.week.state.missed': "{day}: o'tkazib yuborilgan",
}
