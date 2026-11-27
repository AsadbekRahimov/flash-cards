import { afterEach, describe, expect, it } from 'vitest'
import { setLocale } from '../index'
import { useStreakI18n } from '../streak'
import { streakRu } from '../streak/ru'
import { streakUz } from '../streak/uz'

/*
 * The dictionaries of F4 (docs/40 §2.5) live in the lazy chunk of the screen: the same rules as the app's dictionaries - the same keys
 * and kinds in both languages, the same placeholders, ru plurals one/few/many, uz in Latin script with the ASCII apostrophe.
 */

const placeholders = (text: string): string[] => [...text.matchAll(/\{(\w+)\}/g)].map((m) => m[1] ?? '').sort()

describe('streak i18n', () => {
  afterEach(() => setLocale('ru'))

  it('has exactly the same keys, kinds and placeholders in uz as in ru', () => {
    expect(Object.keys(streakUz).sort()).toEqual(Object.keys(streakRu).sort())
    for (const [key, ru] of Object.entries(streakRu)) {
      const uz = streakUz[key as keyof typeof streakUz]
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
    for (const [key, value] of Object.entries(streakUz)) {
      const texts = typeof value === 'string' ? [value] : Object.values(value)
      for (const text of texts) {
        expect(text.trim(), key).not.toBe('')
        expect(/\p{Script=Cyrillic}/u.test(text), key).toBe(false)
        expect(/[‘’ʻʼ]/.test(text), key).toBe(false)
      }
    }
  })

  it('follows the language of the app, with plural forms', () => {
    const { t, tn } = useStreakI18n()

    expect(tn('streak.saved.lead', 1)).toBe('Вчера вы пропустили день, но заморозка сохранила серию — 1 день.')
    expect(tn('streak.saved.lead', 3)).toBe('Вчера вы пропустили день, но заморозка сохранила все 3 дня.')
    expect(tn('streak.lost.lead', 21)).toBe('Было 21 день подряд.')
    expect(t('streak.saved.freezes_left', { count: 2 })).toBe('Осталось заморозок: 2')
    setLocale('uz')
    expect(t('streak.lost.restart')).toBe('Qaytadan boshlash')
    expect(tn('streak.lost.lead', 5)).toBe("5 kun ketma-ket shug'ullangan edingiz.")
  })
})
