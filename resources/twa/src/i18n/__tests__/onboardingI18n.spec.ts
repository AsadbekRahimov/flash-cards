import { afterEach, describe, expect, it } from 'vitest'
import { setLocale } from '../index'
import { useOnboardingI18n } from '../onboarding'
import { onboardingRu } from '../onboarding/ru'
import { onboardingUz } from '../onboarding/uz'

/*
 * The dictionaries of the onboarding (docs/40 §2.1) live in its lazy chunk: the same rules as the app's dictionaries - the same keys and
 * kinds in both languages, the same placeholders, ru plurals one/few/many, uz in Latin script with the ASCII apostrophe.
 */

const placeholders = (text: string): string[] => [...text.matchAll(/\{(\w+)\}/g)].map((m) => m[1] ?? '').sort()

describe('onboarding i18n', () => {
  afterEach(() => setLocale('ru'))

  it('has exactly the same keys, kinds and placeholders in uz as in ru', () => {
    expect(Object.keys(onboardingUz).sort()).toEqual(Object.keys(onboardingRu).sort())
    for (const [key, ru] of Object.entries(onboardingRu)) {
      const uz = onboardingUz[key as keyof typeof onboardingUz]
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
    for (const [key, value] of Object.entries(onboardingUz)) {
      const texts = typeof value === 'string' ? [value] : Object.values(value)
      for (const text of texts) {
        expect(text.trim(), key).not.toBe('')
        expect(/\p{Script=Cyrillic}/u.test(text), key).toBe(false)
        expect(/[‘’ʻʼ]/.test(text), key).toBe(false)
      }
    }
  })

  it('follows the language of the app, with plural forms', () => {
    const { t, tn } = useOnboardingI18n()

    expect(t('onboarding.continue')).toBe('Продолжить')
    expect(tn('onboarding.plan.course_modules', 20, { title: 'B1' })).toBe('B1 · 20 модулей')
    expect(tn('onboarding.plan.goal_value', 2)).toBe('2 карточки в день')
    setLocale('uz')
    expect(t('onboarding.continue')).toBe('Davom etish')
    expect(tn('onboarding.plan.goal_value', 20)).toBe('kuniga 20 ta kartochka')
  })
})
