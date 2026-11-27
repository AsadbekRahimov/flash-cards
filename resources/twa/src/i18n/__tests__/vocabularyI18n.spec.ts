import { afterEach, describe, expect, it } from 'vitest'
import { setLocale } from '../index'
import { useVocabularyI18n } from '../vocabulary'
import { vocabularyRu } from '../vocabulary/ru'
import { vocabularyUz } from '../vocabulary/uz'

/*
 * The dictionary of "My dictionary" (docs/40 §2.3) lives in the lazy chunk of its screen: the same rules as the app's dictionaries - the
 * same keys and kinds in both languages, the same placeholders, ru plurals one/few/many, uz in Latin script with the ASCII apostrophe.
 */

const placeholders = (text: string): string[] => [...text.matchAll(/\{(\w+)\}/g)].map((m) => m[1] ?? '').sort()

describe('vocabulary i18n', () => {
  afterEach(() => setLocale('ru'))

  it('has exactly the same keys, kinds and placeholders in uz as in ru', () => {
    expect(Object.keys(vocabularyUz).sort()).toEqual(Object.keys(vocabularyRu).sort())
    for (const [key, ru] of Object.entries(vocabularyRu)) {
      const uz = vocabularyUz[key as keyof typeof vocabularyUz]
      expect(typeof uz, key).toBe(typeof ru)
      if (typeof ru === 'string') {
        expect(placeholders(uz as string), key).toEqual(placeholders(ru))
      } else {
        expect(Object.keys(ru).sort(), key).toEqual(['few', 'many', 'one', 'other'])
        expect(Object.keys(uz as object).sort(), key).toEqual(['one', 'other'])
        expect(placeholders((uz as { other: string }).other), key).toEqual(placeholders(ru.other))
      }
    }
  })

  it('keeps uz in Latin script with the ASCII apostrophe, no empty strings', () => {
    for (const [key, value] of Object.entries(vocabularyUz)) {
      const texts = typeof value === 'string' ? [value] : Object.values(value)
      for (const text of texts) {
        expect(text.trim(), key).not.toBe('')
        expect(/\p{Script=Cyrillic}/u.test(text), key).toBe(false)
        expect(/[‘’ʻʼ]/.test(text), key).toBe(false)
      }
    }
  })

  it('follows the language of the app, with plural forms', () => {
    const { t, tn } = useVocabularyI18n()

    expect(t('vocabulary.title')).toBe('Мой словарь')
    expect(tn('vocabulary.total', 1)).toBe('1 слово')
    expect(tn('vocabulary.total', 3)).toBe('3 слова')
    expect(tn('vocabulary.total', 120)).toBe('120 слов')
    setLocale('uz')
    expect(t('vocabulary.title')).toBe("Mening lug'atim")
    expect(tn('vocabulary.total', 120)).toBe("120 ta so'z")
  })
})
