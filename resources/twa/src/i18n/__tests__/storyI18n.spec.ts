import { afterEach, describe, expect, it } from 'vitest'
import { setLocale } from '../index'
import { useStoryI18n } from '../story'
import { storyRu } from '../story/ru'
import { storyUz } from '../story/uz'

/*
 * The dictionaries of F3 (docs/40 §2.4) live in the lazy chunk of the screen: the same rules as the app's dictionaries - the same keys
 * and kinds in both languages, the same placeholders, ru plurals one/few/many, uz in Latin script with the ASCII apostrophe.
 */

const placeholders = (text: string): string[] => [...text.matchAll(/\{(\w+)\}/g)].map((m) => m[1] ?? '').sort()

describe('story i18n', () => {
  afterEach(() => setLocale('ru'))

  it('has exactly the same keys, kinds and placeholders in uz as in ru', () => {
    expect(Object.keys(storyUz).sort()).toEqual(Object.keys(storyRu).sort())
    for (const [key, ru] of Object.entries(storyRu)) {
      const uz = storyUz[key as keyof typeof storyUz]
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
    for (const [key, value] of Object.entries(storyUz)) {
      const texts = typeof value === 'string' ? [value] : Object.values(value)
      for (const text of texts) {
        expect(text.trim(), key).not.toBe('')
        expect(/\p{Script=Cyrillic}/u.test(text), key).toBe(false)
        expect(/[‘’ʻʼ]/.test(text), key).toBe(false)
      }
    }
  })

  it('promises days of Pro for friends, never league points (docs/40 §3, В4)', () => {
    for (const value of [...Object.values(storyRu), ...Object.values(storyUz)]) {
      const texts = typeof value === 'string' ? [value] : Object.values(value)
      for (const text of texts) expect(text).not.toMatch(/балл|ball|\+100/i)
    }
  })

  it('follows the language of the app, with plural forms', () => {
    const { t, tn } = useStoryI18n()

    expect(tn('story.streak.title', 1)).toBe('1 день подряд')
    expect(tn('story.streak.title', 3)).toBe('3 дня подряд')
    expect(tn('story.streak.title', 12)).toBe('12 дней подряд')
    expect(tn('story.streak.text', 20, { brand: 'Muhrla' })).toBe('Учу английский в Muhrla — каждый день по 20 карточек.')
    expect(t('story.preview_note')).toBe('Примерно так будет выглядеть сторис')
    setLocale('uz')
    expect(tn('story.streak.title', 7)).toBe('7 kun ketma-ket')
    expect(t('story.share')).toBe('Storisga ulashish')
    expect(t('story.preview_note')).toBe("Storis taxminan shunday ko'rinadi")
  })
})
