import type { Message, PluralForms } from '../ru'

/**
 * The texts of F3 "Поделиться в сторис" (docs/40 §2.4), in Russian - the source of truth for its keys. A dictionary of its own, loaded
 * with the screen `/share/story` (its lazy chunk) and with the button "В сторис" of Achievements, so the main chunk does not carry them;
 * read through `useStoryI18n` (`./index.ts`). `uz.ts` is typed against this file. No "+100 баллов лиги" of the kit (docs/40 §3, В4):
 * the reward for friends is days of Pro.
 */
export const storyRu = {
  'story.heading': 'Поделиться в сторис',
  'story.preview_note': 'Примерно так будет выглядеть сторис',
  'story.streak.title': {
    one: '{count} день подряд',
    few: '{count} дня подряд',
    many: '{count} дней подряд',
    other: '{count} дня подряд',
  },
  'story.streak.text': {
    one: 'Учу английский в {brand} — каждый день по {count} карточке.',
    few: 'Учу английский в {brand} — каждый день по {count} карточки.',
    many: 'Учу английский в {brand} — каждый день по {count} карточек.',
    other: 'Учу английский в {brand} — каждый день по {count} карточки.',
  },
  'story.streak.share_text': {
    one: '{count} день подряд учу английский в {brand}!',
    few: '{count} дня подряд учу английский в {brand}!',
    many: '{count} дней подряд учу английский в {brand}!',
    other: '{count} дня подряд учу английский в {brand}!',
  },
  'story.hint': 'Покажите печать друзьям — за друзей, которые начнут заниматься, вы получите дни Pro.',
  'story.share': 'Поделиться в сторис',
  'story.share_plain': 'Поделиться',
  'story.preparing': 'Готовим картинку…',
  'story.battle': 'Позвать друга на баттл',
  'story.no_link': 'Поделиться пока не получится: ссылка ещё не готова. Попробуйте позже.',
  'story.to_story': 'В сторис',
} satisfies Record<string, Message>

export type StoryDictionary = typeof storyRu
export type StoryMessageKey = keyof StoryDictionary

/** Keys whose value is a set of plural forms (use with `tn`). */
export type StoryPluralKey = { [K in StoryMessageKey]: StoryDictionary[K] extends string ? never : K }[StoryMessageKey]

/** Keys whose value is a plain string (use with `t`). */
export type StoryStringKey = Exclude<StoryMessageKey, StoryPluralKey>

/** Shape the Uzbek dictionary must have: same keys, same string/plural kind. */
export type StoryTranslations = {
  [K in StoryMessageKey]: StoryDictionary[K] extends string ? string : PluralForms
}
