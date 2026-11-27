import type { Message, PluralForms } from '../ru'

/**
 * The texts of the "memory strength" of a word (F1 / F2 of the kit, docs/40 §2.2-2.3), in Russian - the source of truth for its keys. A
 * dictionary of its own, loaded with the screens that show the dots (the lesson, "My dictionary") in their lazy chunks, so the main chunk
 * does not carry it; read through `useMemoryI18n` (`./index.ts`). `uz.ts` is typed against this file.
 */
export const memoryRu = {
  'card.memory.label': 'Память: {strength} из 5',
  'card.memory.next_days': {
    one: 'повтор через {count} день',
    few: 'повтор через {count} дня',
    many: 'повтор через {count} дней',
    other: 'повтор через {count} дня',
  },
  'card.memory.today': 'повтор сегодня',
  'card.memory.this_session': 'повтор в этой сессии',
} satisfies Record<string, Message>

export type MemoryDictionary = typeof memoryRu
export type MemoryMessageKey = keyof MemoryDictionary

/** Keys whose value is a set of plural forms (use with `tn`). */
export type MemoryPluralKey = { [K in MemoryMessageKey]: MemoryDictionary[K] extends string ? never : K }[MemoryMessageKey]

/** Keys whose value is a plain string (use with `t`). */
export type MemoryStringKey = Exclude<MemoryMessageKey, MemoryPluralKey>

/** Shape the Uzbek dictionary must have: same keys, same string/plural kind. */
export type MemoryTranslations = {
  [K in MemoryMessageKey]: MemoryDictionary[K] extends string ? string : PluralForms
}
