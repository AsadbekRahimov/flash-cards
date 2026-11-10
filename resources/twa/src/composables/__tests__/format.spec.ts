import { afterEach, describe, expect, it, vi } from 'vitest'
import { calendarDay, formatDate, formatNumber, type DateStyle } from '../format'

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
    ['time', '18:05'],
  ])('ru %s', (style, expected) => {
    expect(plain(formatDate(EVENING, style, 'ru'))).toBe(expected)
  })

  it.each<[DateStyle, string]>([
    ['dayMonth', '28-oktabr'],
    ['dayMonthYear', '28-oktabr, 2026'],
    ['dayMonthShort', '28-okt'],
    ['dayMonthShortTime', '28-okt, 18:05'],
    ['weekdayDayMonth', 'chorshanba, 28-oktabr'],
    ['time', '18:05'],
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

  it('shows a moment in the time zone it is given instead of the device one', () => {
    // 01:30 on the 29th in Tashkent, 09:30 on the 28th in Pago Pago: whatever the device zone is, one of them differs from it.
    const moment = '2026-10-28T20:30:00Z'

    expect(plain(formatDate(moment, 'weekdayDayMonth', 'ru', 'Asia/Tashkent'))).toBe('четверг, 29 октября')
    expect(plain(formatDate(moment, 'dayMonthShortTime', 'ru', 'Asia/Tashkent'))).toBe('29 окт., 01:30')
    expect(plain(formatDate(moment, 'weekdayDayMonth', 'ru', 'Pacific/Pago_Pago'))).toBe('среда, 28 октября')
    expect(plain(formatDate(moment, 'dayMonth', 'uz', 'Asia/Tashkent'))).toBe('29-oktabr')
  })

  it('never moves a calendar date with a zone: a date has none', () => {
    expect(formatDate('2026-10-28', 'dayMonth', 'ru', 'Pacific/Kiritimati')).toBe('28 октября')
    expect(formatDate('2026-10-28', 'dayMonth', 'ru', 'Pacific/Pago_Pago')).toBe('28 октября')
  })

  it('falls back to the device zone for a zone the browser does not know, without throwing', () => {
    expect(formatDate(NOON, 'dayMonthShortTime', 'ru', 'Mars/Olympus')).toBe(formatDate(NOON, 'dayMonthShortTime', 'ru'))
    expect(formatDate(NOON, 'dayMonthShortTime', 'ru', '')).toBe(formatDate(NOON, 'dayMonthShortTime', 'ru'))
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

    // The second 'uz-Latn' is the one question whether this browser knows Uzbek at all.
    expect(created).toEqual(['ru', 'ru', 'uz-Latn', 'uz-Latn'])
  })

  it('builds one Intl formatter per zone as well, also for a zone it does not know', async () => {
    vi.resetModules()
    const zones: unknown[] = []
    const Original = Intl.DateTimeFormat as new (...args: unknown[]) => unknown
    vi.spyOn(Intl, 'DateTimeFormat').mockImplementation(function (...args: unknown[]) {
      zones.push((args[1] as Intl.DateTimeFormatOptions | undefined)?.timeZone ?? null)
      return new Original(...args) as never
    })
    const fresh = await import('../format')

    for (let i = 0; i < 3; i++) fresh.formatDate('2026-10-28T20:30:00Z', 'dayMonth', 'ru', 'Asia/Tashkent')
    fresh.formatDate('2026-10-28T20:30:00Z', 'dayMonth', 'ru')
    // An unknown zone is tried once (it throws), then the device's formatter is kept under that key.
    for (let i = 0; i < 3; i++) fresh.formatDate('2026-10-28T20:30:00Z', 'dayMonth', 'ru', 'Mars/Olympus')

    expect(zones).toEqual(['Asia/Tashkent', null, 'Mars/Olympus', null])
  })
})

describe('calendarDay', () => {
  const moment = '2026-10-28T20:30:00Z'

  it('is the day of a moment in the zone it is given: the same moment is on two different days in two zones', () => {
    expect(calendarDay(moment, 'Asia/Tashkent')).toBe('2026-10-29')
    expect(calendarDay(moment, 'Pacific/Pago_Pago')).toBe('2026-10-28')
    expect(calendarDay(moment, 'UTC')).toBe('2026-10-28')
  })

  it('takes a moment as a string, as a number of milliseconds and as a Date', () => {
    expect(calendarDay(Date.parse(moment), 'Asia/Tashkent')).toBe('2026-10-29')
    expect(calendarDay(new Date(moment), 'Asia/Tashkent')).toBe('2026-10-29')
  })

  it('tells the last minute of a day from the first of the next one, to the minute', () => {
    expect(calendarDay('2026-10-28T18:59:00Z', 'Asia/Tashkent')).toBe('2026-10-28')
    expect(calendarDay('2026-10-28T19:00:00Z', 'Asia/Tashkent')).toBe('2026-10-29')
  })

  it('falls back to the device zone for a zone the browser does not know, or none, without throwing', () => {
    expect(calendarDay(moment, 'Mars/Olympus')).toBe(calendarDay(moment))
    expect(calendarDay(moment, '')).toBe(calendarDay(moment))
    expect(calendarDay(moment)).toMatch(/^\d{4}-\d{2}-\d{2}$/)
  })

  it('is an empty string for a value that is not a moment', () => {
    expect(calendarDay('not a date', 'UTC')).toBe('')
    expect(calendarDay(Number.NaN)).toBe('')
  })

  it('builds one Intl formatter per zone', async () => {
    vi.resetModules()
    const zones: unknown[] = []
    const Original = Intl.DateTimeFormat as new (...args: unknown[]) => unknown
    vi.spyOn(Intl, 'DateTimeFormat').mockImplementation(function (...args: unknown[]) {
      zones.push((args[1] as Intl.DateTimeFormatOptions | undefined)?.timeZone ?? null)
      return new Original(...args) as never
    })
    const fresh = await import('../format')

    for (let i = 0; i < 4; i++) fresh.calendarDay(moment, 'Asia/Tashkent')
    for (let i = 0; i < 2; i++) fresh.calendarDay(moment)

    expect(zones).toEqual(['Asia/Tashkent', null])
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

describe('formatDate — Uzbek in a browser whose Intl has no Uzbek data', () => {
  afterEach(async () => {
    vi.restoreAllMocks()
    ;(await import('../format')).resetUzbekProbe()
  })

  it('names the day and the month itself instead of printing "M11 10, Tue"', async () => {
    const { formatDate: format, resetUzbekProbe } = await import('../format')
    const Native = Intl.DateTimeFormat
    // The browser of the finding: asked for uz-Latn it answers with the root locale ("M11").
    vi.spyOn(Intl, 'DateTimeFormat').mockImplementation(function (tag?: Intl.LocalesArgument, options?: Intl.DateTimeFormatOptions) {
      return new Native(tag === 'uz-Latn' ? 'en-GB' : tag, options) as never
    })
    resetUzbekProbe()

    expect(format('2026-11-10', 'weekdayDayMonth', 'uz')).toBe('seshanba, 10-noyabr')
    expect(format('2026-11-10', 'dayMonth', 'uz')).toBe('10-noyabr')
    expect(format('2026-11-10', 'dayMonthShortYear', 'uz')).toBe('10-noy, 2026')
    expect(format('2026-11-10T18:05:00+05:00', 'dayMonthShortTime', 'uz', 'Asia/Tashkent')).toBe('10-noy, 18:05')
    expect(format('2026-11-10T18:05:00+05:00', 'weekdayTime', 'uz', 'Asia/Tashkent')).toBe('Sesh 18:05')
    // Russian is untouched.
    expect(format('2026-11-10', 'dayMonth', 'ru')).toBe('10 ноября')
  })
})
