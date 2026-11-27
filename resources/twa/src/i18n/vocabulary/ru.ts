import type { Message, PluralForms } from '../ru'

/**
 * The texts of "My dictionary" (docs/40 §2.3, kit F2-moy-slovar) in Russian - the source of truth for its keys. A dictionary of its own,
 * loaded with the screen (its lazy chunk), so the main chunk does not carry them; read through `useVocabularyI18n` (`./index.ts`), in the
 * language of the app. `uz.ts` is typed against this file. The common texts ("Retry", the memory dots) stay with the app's `t`.
 */
export const vocabularyRu = {
  'vocabulary.title': 'Мой словарь',
  'vocabulary.total': {
    one: '{count} слово',
    few: '{count} слова',
    many: '{count} слов',
    other: '{count} слова',
  },
  'vocabulary.search': 'Найти слово или перевод',
  'vocabulary.filters': 'Какие слова показать',
  'vocabulary.filter.all': 'Все',
  'vocabulary.filter.weak': 'Слабые',
  'vocabulary.filter.strong': 'Сильные',
  'vocabulary.filter_count': '{label} · {count}',
  'vocabulary.listen': 'Прослушать: {word}',
  'vocabulary.more': 'Показать ещё',
  'vocabulary.empty': 'Пройдите первый урок — слова появятся здесь',
  'vocabulary.not_found': 'Ничего не нашли',
  'vocabulary.not_found_text': 'Проверьте написание или поищите по переводу.',
  'vocabulary.filter_empty': 'Здесь пока нет слов',
} satisfies Record<string, Message>

export type VocabularyTranslations = { [K in keyof typeof vocabularyRu]: (typeof vocabularyRu)[K] extends string ? string : PluralForms }
export type VocabularyPluralKey = { [K in keyof typeof vocabularyRu]: (typeof vocabularyRu)[K] extends string ? never : K }[keyof typeof vocabularyRu]
export type VocabularyStringKey = Exclude<keyof typeof vocabularyRu, VocabularyPluralKey>
