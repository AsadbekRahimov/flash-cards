import { afterEach, describe, expect, it, vi } from 'vitest'
import { formatDate, formatNumber, type DateStyle } from '../format'

// Local time without an offset, so the day does not move with the time zone of the machine running the tests.
const NOON = '2026-10-28T12:00:00'
const EVENING = '2026-10-28T18:05:00'

afterEach(() => vi.restoreAllMocks())

/** Intl separates parts with (narrow) no-break spaces. */
const plain = (text: string) => text.replace(/[\s  ]+/g, ' ')

/** Records the locale of every `new Intl.<kind>(...)` while still building the real formatter. */
function countConstructions(kind: 'DateTimeFormat' | 'NumberFormat'): unknown[] {
  const created: unknown[] = []
  const Original = Intl[kind] as new (...args: unknown[]) => unknown
  vi.spyOn(Intl, kind).mockImplementation(function (...args: unknown[]) {
    created.push(args[0])
    return new Original(...args) as never
  })
  return created
}

describe('formatDate', () => {
  it.each<[DateStyle, string]>([
    ['dayMonth', '28 октября'],
    ['dayMonthYear', '28 октября 2026 г.'],
    ['dayMonthShort', '28 окт.'],
    ['dayMonthShortYear', '28 окт. 2026 г.'],
    ['dayMonthShortTime', '28 окт., 18:05'],
    ['weekdayTime', 'ср 18:05'],
    ['weekdayDayMonth', 'среда, 28 октября'],
  ])('ru %s', (style, expected) => {
    expect(plain(formatDate(EVENING, style, 'ru'))).toBe(expected)
  })

  it.each<[DateStyle, string]>([
    ['dayMonth', '28-oktabr'],
    ['dayMonthYear', '28-oktabr, 2026'],
    ['dayMonthShort', '28-okt'],
    ['dayMonthShortTime', '28-okt, 18:05'],
    ['weekdayDayMonth', 'chorshanba, 28-oktabr'],
  ])('uz (Latin) %s', (style, expected) => {
    expect(plain(formatDate(EVENING, style, 'uz'))).toBe(expected)
  })

  it('formats Uzbek as Latin script, never Cyrillic', () => {
    expect(/\p{Script=Cyrillic}/u.test(formatDate(NOON, 'dayMonthYear', 'uz'))).toBe(false)
  })

  it('takes a calendar date as that day in the device time zone', () => {
    expect(formatDate('2026-10-26', 'dayMonthShort', 'ru')).toBe('26 окт.')
    expect(formatDate('2026-11-01', 'dayMonth', 'ru')).toBe('1 ноября')
  })

  it('reads the moment of an ISO string with an offset', () => {
    const iso = '2026-10-28T12:00:00+00:00'
    const expected = new Intl.DateTimeFormat('ru', { day: 'numeric', month: 'long' }).format(new Date(iso))

    expect(formatDate(iso, 'dayMonth', 'ru')).toBe(expected)
  })

  it('returns an empty string for a value that is not a date instead of throwing', () => {
    expect(formatDate('', 'dayMonth', 'ru')).toBe('')
    expect(formatDate('not a date', 'dayMonthYear', 'uz')).toBe('')
  })

  it('builds one Intl formatter per locale and style', async () => {
    vi.resetModules()
    const created = countConstructions('DateTimeFormat')
    const fresh = await import('../format')

    for (let i = 0; i < 5; i++) fresh.formatDate(NOON, 'dayMonth', 'ru')
    fresh.formatDate(NOON, 'dayMonthShort', 'ru')
    fresh.formatDate(NOON, 'dayMonth', 'uz')
    fresh.formatDate(NOON, 'dayMonth', 'uz')

    expect(created).toEqual(['ru', 'ru', 'uz-Latn'])
  })
})

describe('formatNumber', () => {
  it('groups digits per locale', () => {
    expect(plain(formatNumber(1300, 'ru'))).toBe('1 300')
    expect(plain(formatNumber(1234567, 'uz'))).toBe('1 234 567')
    expect(formatNumber(0, 'ru')).toBe('0')
  })

  it('builds one Intl formatter per locale', async () => {
    vi.resetModules()
    const created = countConstructions('NumberFormat')
    const fresh = await import('../format')

    fresh.formatNumber(1, 'ru')
    fresh.formatNumber(2, 'ru')
    fresh.formatNumber(3, 'uz')
    fresh.formatNumber(4, 'uz')

    expect(created).toEqual(['ru', 'uz-Latn'])
  })
})
