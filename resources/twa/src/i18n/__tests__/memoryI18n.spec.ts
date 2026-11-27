import { afterEach, describe, expect, it } from 'vitest'
import { setLocale } from '../index'
import { useMemoryI18n } from '../memory'
import { memoryRu } from '../memory/ru'
import { memoryUz } from '../memory/uz'

/*
 * The memory strength of a word (F1 / F2, docs/40 §2.2-2.3) lives in the lazy chunks of the lesson and "My dictionary": the same keys and
 * kinds in both languages, the same placeholders, ru plurals one/few/many, uz in Latin script with the ASCII apostrophe.
 */

const placeholders = (text: string): string[] => [...text.matchAll(/\{(\w+)\}/g)].map((m) => m[1] ?? '').sort()

describe('memory i18n', () => {
  afterEach(() => setLocale('ru'))

  it('has exactly the same keys, kinds and placeholders in uz as in ru', () => {
    expect(Object.keys(memoryUz).sort()).toEqual(Object.keys(memoryRu).sort())
    for (const [key, ru] of Object.entries(memoryRu)) {
      const uz = memoryUz[key as keyof typeof memoryUz]
      expect(typeof uz, key).toBe(typeof ru)
      if (typeof ru === 'string') {
        expect(placeholders(uz as string), key).toEqual(placeholders(ru))
      } else {
        expect(Object.keys(ru).sort(), key).toEqual(['few', 'many', 'one', 'other'])
        expect(Object.keys(uz as object).sort(), key).toEqual(['one', 'other'])
        expect(placeholders((uz as { other: string }).other), key).toEqual(placeholders(ru.other))
      }
    }
    for (const value of Object.values(memoryUz)) {
      for (const text of typeof value === 'string' ? [value] : Object.values(value)) {
        expect(/\p{Script=Cyrillic}/u.test(text)).toBe(false)
        expect(/[‘’ʻʼ]/.test(text)).toBe(false)
      }
    }
  })

  it('follows the language of the app, with plural forms', () => {
    const { t, tn } = useMemoryI18n()

    expect(t('card.memory.label', { strength: 3 })).toBe('Память: 3 из 5')
    expect(tn('card.memory.next_days', 4)).toBe('повтор через 4 дня')
    setLocale('uz')
    expect(t('card.memory.label', { strength: 3 })).toBe('Xotira: 5 dan 3')
    expect(t('card.memory.today')).toBe('bugun takrorlanadi')
  })
})
