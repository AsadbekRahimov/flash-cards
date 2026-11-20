import { afterEach, describe, expect, it } from 'vitest'
import { setLocale, t } from '@/i18n'
import { tierLabelKey } from '../leagueTiers'

afterEach(() => setLocale('ru'))

describe('tierLabelKey', () => {
  it.each([
    ['bronze', 'league.tier.bronze'],
    ['silver', 'league.tier.silver'],
    ['gold', 'league.tier.gold'],
    ['platinum', 'league.tier.platinum'],
    ['diamond', 'league.tier.diamond'],
  ] as const)('%s -> %s', (key, expected) => {
    expect(tierLabelKey(key)).toBe(expected)
  })

  it('falls back to bronze for a key the build does not know', () => {
    expect(tierLabelKey('legend')).toBe('league.tier.bronze')
    expect(tierLabelKey('')).toBe('league.tier.bronze')
    expect(tierLabelKey('Silver')).toBe('league.tier.bronze')
  })

  it('does not resolve names of Object.prototype members', () => {
    for (const key of ['constructor', 'toString', 'hasOwnProperty', '__proto__']) {
      expect(tierLabelKey(key), key).toBe('league.tier.bronze')
    }
  })

  it('points to real names in both languages', () => {
    expect(t(tierLabelKey('silver'))).toBe('Серебро')
    expect(t(tierLabelKey('unknown'))).toBe('Бронза')

    setLocale('uz')
    expect(t(tierLabelKey('diamond'))).toBe('Olmos')
    expect(t(tierLabelKey('unknown'))).toBe('Bronza')
  })
})
