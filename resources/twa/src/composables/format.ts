/**
 * Date and number formatting for the screens (docs/14 K3): one cached `Intl` formatter per locale + style
 * instead of a `new Intl.DateTimeFormat(...)` on every render.
 *
 * Dates are shown in the device's time zone, unless a screen names another one (the learner's own, `timeZone`).
 * Examples are for `ru`:
 * - `dayMonth` — 28 октября
 * - `dayMonthYear` — 28 октября 2026 г.
 * - `dayMonthShort` — 28 окт.
 * - `dayMonthShortYear` — 28 окт. 2026 г.
 * - `dayMonthShortTime` — 28 окт., 18:00
 * - `weekdayTime` — пн 00:00
 * - `weekdayDayMonth` — вторник, 28 октября
 * - `time` — 00:00
 */
export type DateStyle =
  | 'dayMonth'
  | 'dayMonthYear'
  | 'dayMonthShort'
  | 'dayMonthShortYear'
  | 'dayMonthShortTime'
  | 'weekdayTime'
  | 'weekdayDayMonth'
  | 'time'

const DATE_OPTIONS: Record<DateStyle, Intl.DateTimeFormatOptions> = {
  dayMonth: { day: 'numeric', month: 'long' },
  dayMonthYear: { day: 'numeric', month: 'long', year: 'numeric' },
  dayMonthShort: { day: 'numeric', month: 'short' },
  dayMonthShortYear: { day: 'numeric', month: 'short', year: 'numeric' },
  dayMonthShortTime: { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' },
  weekdayTime: { weekday: 'short', hour: '2-digit', minute: '2-digit' },
  weekdayDayMonth: { weekday: 'long', day: 'numeric', month: 'long' },
  time: { hour: '2-digit', minute: '2-digit' },
}

/** The interface locale (`ru` / `uz`) as a BCP 47 tag; Uzbek is always Latin script. */
function intlTag(locale: string): string {
  return locale === 'uz' ? 'uz-Latn' : locale
}

/**
 * Uzbek by hand, for a WebView whose ICU has no Uzbek data: there `Intl` does not fail, it answers "M11 10, Tue" (seen in a
 * headless Chromium, 2026-11). The layouts follow what a full ICU prints for `uz-Latn`.
 */
const UZ_MONTHS = ['yanvar', 'fevral', 'mart', 'aprel', 'may', 'iyun', 'iyul', 'avgust', 'sentabr', 'oktabr', 'noyabr', 'dekabr']
const UZ_WEEKDAYS = ['yakshanba', 'dushanba', 'seshanba', 'chorshanba', 'payshanba', 'juma', 'shanba']
const UZ_WEEKDAYS_SHORT = ['Yak', 'Dush', 'Sesh', 'Chor', 'Pay', 'Jum', 'Shan']
const EN_WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

let uzbekKnown: boolean | null = null
const handFormatters = new Map<string, Intl.DateTimeFormat>()

/** Whether this browser can name Uzbek months itself (asked once). */
function knowsUzbek(): boolean {
  if (uzbekKnown === null) {
    try {
      uzbekKnown = new Intl.DateTimeFormat('uz-Latn', { month: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(2026, 10, 10))).toLowerCase() === 'noyabr'
    } catch {
      uzbekKnown = false
    }
  }
  return uzbekKnown
}

/** For the tests: forget what was learnt about the browser. */
export function resetUzbekProbe(): void {
  uzbekKnown = null
}

function formatUzbekByHand(date: Date, style: DateStyle, zone: string): string {
  let formatter = handFormatters.get(zone)
  if (!formatter) {
    formatter = createDateFormatter(
      'en-GB',
      { weekday: 'short', day: 'numeric', month: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' },
      zone,
    )
    handFormatters.set(zone, formatter)
  }
  const parts = formatter.formatToParts(date)
  const part = (type: Intl.DateTimeFormatPartTypes): string => parts.find((item) => item.type === type)?.value ?? ''
  const day = part('day')
  const month = UZ_MONTHS[Number(part('month')) - 1] ?? ''
  const short = month.slice(0, 3)
  const weekday = Math.max(0, EN_WEEKDAYS.indexOf(part('weekday')))
  const time = `${part('hour')}:${part('minute')}`

  switch (style) {
    case 'dayMonth':
      return `${day}-${month}`
    case 'dayMonthYear':
      return `${day}-${month}, ${part('year')}`
    case 'dayMonthShort':
      return `${day}-${short}`
    case 'dayMonthShortYear':
      return `${day}-${short}, ${part('year')}`
    case 'dayMonthShortTime':
      return `${day}-${short}, ${time}`
    case 'weekdayTime':
      return `${UZ_WEEKDAYS_SHORT[weekday]} ${time}`
    case 'weekdayDayMonth':
      return `${UZ_WEEKDAYS[weekday]}, ${day}-${month}`
    case 'time':
      return time
  }
}

const dateFormatters = new Map<string, Intl.DateTimeFormat>()

/** A formatter for `zone` (an IANA id); '' or an id this browser does not know gives the device's zone. */
function createDateFormatter(tag: string, options: Intl.DateTimeFormatOptions, zone: string): Intl.DateTimeFormat {
  if (zone !== '') {
    try {
      return new Intl.DateTimeFormat(tag, { ...options, timeZone: zone })
    } catch {
      // RangeError: not a zone this browser knows
    }
  }
  return new Intl.DateTimeFormat(tag, options)
}
const numberFormatters = new Map<string, Intl.NumberFormat>()

const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/

/**
 * Formats an ISO 8601 moment (`2026-10-28T18:00:00+05:00`) or a calendar date (`2026-10-28`, taken as that day
 * in the device's time zone, not as UTC midnight). Returns '' for a value that is not a date.
 *
 * @param locale interface locale, `ru` or `uz` (`getLocale()` / `useI18n().locale.value`)
 * @param timeZone IANA id to show a moment in (`Asia/Tashkent`); the device's zone when omitted or not known to the
 *   browser. A calendar date has no zone and is never moved by it.
 */
export function formatDate(iso: string, style: DateStyle, locale: string, timeZone?: string): string {
  const calendarDate = DATE_ONLY.test(iso)
  const date = new Date(calendarDate ? `${iso}T00:00:00` : iso)
  if (Number.isNaN(date.getTime())) return ''

  const tag = intlTag(locale)
  const zone = calendarDate ? '' : (timeZone ?? '')
  if (locale === 'uz' && !knowsUzbek()) return formatUzbekByHand(date, style, zone)
  const key = `${tag}|${style}|${zone}`
  let formatter = dateFormatters.get(key)
  if (!formatter) {
    formatter = createDateFormatter(tag, DATE_OPTIONS[style], zone)
    dateFormatters.set(key, formatter)
  }
  return formatter.format(date)
}

const dayFormatters = new Map<string, Intl.DateTimeFormat>()

/**
 * The calendar day of a moment in a zone as `2026-10-28` (the device's zone when `timeZone` is omitted or not known to the browser), to
 * tell whether two moments are on the same day or on neighbouring ones. '' for a value that is not a date.
 */
export function calendarDay(at: number | string | Date, timeZone?: string): string {
  const date = at instanceof Date ? at : new Date(at)
  if (Number.isNaN(date.getTime())) return ''

  const zone = timeZone ?? ''
  let formatter = dayFormatters.get(zone)
  if (!formatter) {
    // Latin digits in every interface language; the key is put together from the parts, so the layout of a locale does not matter.
    formatter = createDateFormatter('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit' }, zone)
    dayFormatters.set(zone, formatter)
  }
  const parts = formatter.formatToParts(date)
  const part = (type: Intl.DateTimeFormatPartTypes): string => parts.find((item) => item.type === type)?.value ?? ''
  return `${part('year')}-${part('month')}-${part('day')}`
}

/** `1300` -> «1 300» (grouping per locale, cached formatter). */
export function formatNumber(value: number, locale: string): string {
  const tag = intlTag(locale)
  let formatter = numberFormatters.get(tag)
  if (!formatter) {
    formatter = new Intl.NumberFormat(tag)
    numberFormatters.set(tag, formatter)
  }
  return formatter.format(value)
}
