import type { Message, PluralForms } from '../ru'

/**
 * The texts of F4 "Серия спасена" / "Серия прервалась" (docs/40 §2.5), in Russian - the source of truth for its keys. A dictionary of its
 * own, loaded with the screen (its lazy chunk), as the onboarding's is, so the main chunk does not carry them for every open; read through
 * `useStreakI18n` (`./index.ts`). `uz.ts` is typed against this file. The unit of the stamp ("ДНЕЙ") is the app's `today.streak.unit`.
 */
export const streakRu = {
  'streak.saved.title': 'Серия спасена!',
  'streak.saved.lead': {
    one: 'Вчера вы пропустили день, но заморозка сохранила серию — {count} день.',
    few: 'Вчера вы пропустили день, но заморозка сохранила все {count} дня.',
    many: 'Вчера вы пропустили день, но заморозка сохранила все {count} дней.',
    other: 'Вчера вы пропустили день, но заморозка сохранила все {count} дня.',
  },
  'streak.saved.lead_missed': {
    one: 'Вы пропустили {count} день, но заморозки сохранили серию.',
    few: 'Вы пропустили {count} дня, но заморозки сохранили серию.',
    many: 'Вы пропустили {count} дней, но заморозки сохранили серию.',
    other: 'Вы пропустили {count} дня, но заморозки сохранили серию.',
  },
  'streak.saved.freezes_left': 'Осталось заморозок: {count}',
  'streak.saved.continue': 'Продолжить серию',
  'streak.saved.share': 'Поделиться серией',
  'streak.lost.title': 'Серия прервалась',
  'streak.lost.lead': {
    one: 'Было {count} день подряд.',
    few: 'Было {count} дня подряд.',
    many: 'Было {count} дней подряд.',
    other: 'Было {count} дня подряд.',
  },
  'streak.lost.text': 'Новая серия начинается сегодня: выполните цель дня — и первая печать на месте.',
  'streak.lost.pro_offer': 'Заморозка сохранила бы серию — в Pro',
  'streak.lost.pro_more': 'Подробнее',
  'streak.lost.pro_note': 'Заморозок не хватило: пропущено дней больше, чем было заморозок.',
  'streak.lost.restart': 'Начать заново',
  'streak.week.label': 'Эта неделя',
  'streak.week.day.1': 'Пн',
  'streak.week.day.2': 'Вт',
  'streak.week.day.3': 'Ср',
  'streak.week.day.4': 'Чт',
  'streak.week.day.5': 'Пт',
  'streak.week.day.6': 'Сб',
  'streak.week.day.7': 'Вс',
  'streak.week.state.done': '{day}: день засчитан',
  'streak.week.state.freeze': '{day}: заморозка',
  'streak.week.state.today': '{day}: сегодня',
  'streak.week.state.future': '{day}: впереди',
  'streak.week.state.missed': '{day}: пропущен',
} satisfies Record<string, Message>

export type StreakDictionary = typeof streakRu
export type StreakMessageKey = keyof StreakDictionary

/** Keys whose value is a set of plural forms (use with `tn`). */
export type StreakPluralKey = { [K in StreakMessageKey]: StreakDictionary[K] extends string ? never : K }[StreakMessageKey]

/** Keys whose value is a plain string (use with `t`). */
export type StreakStringKey = Exclude<StreakMessageKey, StreakPluralKey>

/** Shape the Uzbek dictionary must have: same keys, same string/plural kind. */
export type StreakTranslations = {
  [K in StreakMessageKey]: StreakDictionary[K] extends string ? string : PluralForms
}
