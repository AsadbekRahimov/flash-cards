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
    ['dayMonthTime', '28 октября в 18:05'],
  ])('ru %s', (style, expected) => {
    expect(plain(formatDate(EVENING, style, 'ru'))).toBe(expected)
  })

  describe('uz (Latin), put together without Uzbek data of Intl', () => {
    // 2026-11-09 is a Monday; 07:05 in Tashkent is 02:05 UTC. The expectations are what ICU of Node gives for `uz-Latn`.
    const MOMENT = '2026-11-09T02:05:00Z'
    const ZONE = 'Asia/Tashkent'

    const EXPECTED: Array<[DateStyle, string]> = [
      ['dayMonth', '9-noyabr'],
      ['dayMonthYear', '9-noyabr, 2026'],
      ['dayMonthShort', '9-noy'],
      ['dayMonthShortYear', '9-noy, 2026'],
      ['dayMonthShortTime', '9-noy, 07:05'],
      ['weekdayTime', 'Dush, 07:05'],
      ['weekdayDayMonth', 'dushanba, 9-noyabr'],
      ['time', '07:05'],
      ['dayMonthTime', '9-noyabr, 07:05'],
    ]

    it.each(EXPECTED)('%s', (style, expected) => {
      expect(formatDate(MOMENT, style, 'uz', ZONE)).toBe(expected)
    })

    it('names every month and every weekday', () => {
      const months = [...Array(12).keys()].map((m) => formatDate(`2026-${String(m + 1).padStart(2, '0')}-15`, 'dayMonth', 'uz'))
      expect(months).toEqual([
        '15-yanvar', '15-fevral', '15-mart', '15-aprel', '15-may', '15-iyun',
        '15-iyul', '15-avgust', '15-sentabr', '15-oktabr', '15-noyabr', '15-dekabr',
      ])
      const shortMonths = [...Array(12).keys()].map((m) => formatDate(`2026-${String(m + 1).padStart(2, '0')}-15`, 'dayMonthShort', 'uz'))
      expect(shortMonths).toEqual(['15-yan', '15-fev', '15-mar', '15-apr', '15-may', '15-iyn', '15-iyl', '15-avg', '15-sen', '15-okt', '15-noy', '15-dek'])

      // 2026-11-08 is a Sunday.
      const days = [8, 9, 10, 11, 12, 13, 14].map((d) => formatDate(`2026-11-${String(d).padStart(2, '0')}`, 'weekdayDayMonth', 'uz').split(',')[0])
      expect(days).toEqual(['yakshanba', 'dushanba', 'seshanba', 'chorshanba', 'payshanba', 'juma', 'shanba'])
      const shortDays = [8, 9, 10, 11, 12, 13, 14].map((d) => formatDate(`2026-11-${String(d).padStart(2, '0')}T12:00:00`, 'weekdayTime', 'uz').split(',')[0])
      expect(shortDays).toEqual(['Yak', 'Dush', 'Sesh', 'Chor', 'Pay', 'Jum', 'Shan'])
    })

    it('shows midnight as 00:00 and moves the day with the zone', () => {
      // 19:00 UTC on the 8th is 00:00 on the 9th in Tashkent.
      expect(formatDate('2026-11-08T19:00:00Z', 'dayMonthShortTime', 'uz', ZONE)).toBe('9-noy, 00:00')
      expect(formatDate('2026-11-08T19:00:00Z', 'weekdayTime', 'uz', 'UTC')).toBe('Yak, 19:00')
    })

    it('stays Uzbek when the browser has no Uzbek data (Chrome in Telegram Web gives «M11 9, Mon»)', async () => {
      vi.resetModules()
      const requested: unknown[] = []
      const Original = Intl.DateTimeFormat as new (...args: unknown[]) => unknown
      vi.spyOn(Intl, 'DateTimeFormat').mockImplementation(function (...args: unknown[]) {
        requested.push(args[0])
        // No `uz` data: such a browser answers with the root locale, whatever tag it is asked for.
        const tag = String(args[0]).startsWith('uz') ? 'und' : args[0]
        return new Original(tag, args[1]) as never
      })
      const fresh = await import('../format')

      for (const [style, expected] of EXPECTED) expect(fresh.formatDate(MOMENT, style, 'uz', ZONE)).toBe(expected)
      expect(requested.filter((tag) => String(tag).startsWith('uz'))).toEqual([])
    })
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

  it('builds one Intl formatter per locale and style (Uzbek: one en-US parts formatter per zone)', async () => {
    vi.resetModules()
    const created = countConstructions('DateTimeFormat')
    const fresh = await import('../format')

    for (let i = 0; i < 5; i++) fresh.formatDate(NOON, 'dayMonth', 'ru')
    fresh.formatDate(NOON, 'dayMonthShort', 'ru')
    fresh.formatDate(NOON, 'dayMonth', 'uz')
    fresh.formatDate(NOON, 'dayMonth', 'uz')

    expect(created).toEqual(['ru', 'ru', 'en-US'])
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
