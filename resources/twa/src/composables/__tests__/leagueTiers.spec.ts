import { afterEach, describe, expect, it } from 'vitest'
import { setLocale, t } from '@/i18n'
import { TIER_COLORS, tierColor, tierLabelKey } from '../leagueTiers'

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

describe('TIER_COLORS and tierColor', () => {
  it('has the colours of docs/14 §6.7', () => {
    expect(TIER_COLORS).toEqual({
      bronze: '#cd7f32',
      silver: '#9ca3af',
      gold: '#eab308',
      platinum: '#14b8a6',
      diamond: '#6366f1',
    })
  })

  it('gives the colour of a tier', () => {
    expect(tierColor('gold')).toBe('#eab308')
    expect(tierColor('diamond')).toBe('#6366f1')
  })

  it('falls back to the bronze colour, never to undefined', () => {
    for (const key of ['legend', '', 'constructor', '__proto__', 'toString']) {
      expect(tierColor(key), key).toBe('#cd7f32')
    }
  })

  it('every coloured tier has its own name key', () => {
    for (const key of Object.keys(TIER_COLORS)) {
      expect(tierLabelKey(key), key).toBe(`league.tier.${key}`)
    }
  })
})
