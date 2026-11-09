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
 * - `dayMonthTime` — 28 октября в 18:00 (ICU of the browser; the `в` may differ)
 *
 * Uzbek is put together here, not by `Intl`: a browser may have no `uz` data (Chrome in Telegram Web gives «M11 9, Mon»).
 * The same for every browser, as `uz-Latn` ICU gives it:
 * - `dayMonth` — 28-oktabr
 * - `dayMonthYear` — 28-oktabr, 2026
 * - `dayMonthShort` — 28-okt
 * - `dayMonthShortYear` — 28-okt, 2026
 * - `dayMonthShortTime` — 28-okt, 18:00
 * - `weekdayTime` — Chor, 18:00
 * - `weekdayDayMonth` — chorshanba, 28-oktabr
 * - `time` — 18:00
 * - `dayMonthTime` — 28-oktabr, 18:00
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
  | 'dayMonthTime'

const DATE_OPTIONS: Record<DateStyle, Intl.DateTimeFormatOptions> = {
  dayMonth: { day: 'numeric', month: 'long' },
  dayMonthYear: { day: 'numeric', month: 'long', year: 'numeric' },
  dayMonthShort: { day: 'numeric', month: 'short' },
  dayMonthShortYear: { day: 'numeric', month: 'short', year: 'numeric' },
  dayMonthShortTime: { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' },
  weekdayTime: { weekday: 'short', hour: '2-digit', minute: '2-digit' },
  weekdayDayMonth: { weekday: 'long', day: 'numeric', month: 'long' },
  time: { hour: '2-digit', minute: '2-digit' },
  dayMonthTime: { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' },
}

/** The interface locale (`ru` / `uz`) as a BCP 47 tag; Uzbek is always Latin script. */
function intlTag(locale: string): string {
  return locale === 'uz' ? 'uz-Latn' : locale
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
const UZ_MONTHS = ['yanvar', 'fevral', 'mart', 'aprel', 'may', 'iyun', 'iyul', 'avgust', 'sentabr', 'oktabr', 'noyabr', 'dekabr']
const UZ_MONTHS_SHORT = ['yan', 'fev', 'mar', 'apr', 'may', 'iyn', 'iyl', 'avg', 'sen', 'okt', 'noy', 'dek']
// From Sunday, as `Date#getDay()` and the English short weekday of `en-US` go.
const EN_WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
const UZ_WEEKDAYS = ['yakshanba', 'dushanba', 'seshanba', 'chorshanba', 'payshanba', 'juma', 'shanba']
const UZ_WEEKDAYS_SHORT = ['Yak', 'Dush', 'Sesh', 'Chor', 'Pay', 'Jum', 'Shan']

const UZ_PARTS_OPTIONS: Intl.DateTimeFormatOptions = {
  year: 'numeric',
  month: 'numeric',
  day: 'numeric',
  weekday: 'short',
  hour: '2-digit',
  minute: '2-digit',
  hourCycle: 'h23',
}

/** Zone -> an `en-US` formatter whose parts (Latin digits, 24 h) the Uzbek text is put together from. */
const uzPartsFormatters = new Map<string, Intl.DateTimeFormat>()

/** The date in Uzbek (Latin), from the `en-US` parts of a moment in `zone`; the names are ours, `Intl` has no say in them. */
function formatUz(date: Date, style: DateStyle, zone: string): string {
  let formatter = uzPartsFormatters.get(zone)
  if (!formatter) {
    formatter = createDateFormatter('en-US', UZ_PARTS_OPTIONS, zone)
    uzPartsFormatters.set(zone, formatter)
  }
  const parts = formatter.formatToParts(date)
  const part = (type: Intl.DateTimeFormatPartTypes): string => parts.find((item) => item.type === type)?.value ?? ''

  const month = Number(part('month')) - 1
  const weekday = Math.max(0, EN_WEEKDAYS.indexOf(part('weekday')))
  const day = `${part('day')}-${UZ_MONTHS[month]}`
  const dayShort = `${part('day')}-${UZ_MONTHS_SHORT[month]}`
  const time = `${part('hour')}:${part('minute')}`

  switch (style) {
    case 'dayMonth':
      return day
    case 'dayMonthYear':
      return `${day}, ${part('year')}`
    case 'dayMonthShort':
      return dayShort
    case 'dayMonthShortYear':
      return `${dayShort}, ${part('year')}`
    case 'dayMonthShortTime':
      return `${dayShort}, ${time}`
    case 'weekdayTime':
      return `${UZ_WEEKDAYS_SHORT[weekday]}, ${time}`
    case 'weekdayDayMonth':
      return `${UZ_WEEKDAYS[weekday]}, ${day}`
    case 'time':
      return time
    case 'dayMonthTime':
      return `${day}, ${time}`
  }
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

  const zone = calendarDate ? '' : (timeZone ?? '')
  if (locale === 'uz') return formatUz(date, style, zone)

  const tag = intlTag(locale)
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
  // ponytail: Uzbek numbers take the `ru` tag: a browser without `uz` ICU data (Chrome in Telegram Web) falls back to the root locale
  // and groups «1,300»; `ru` and `uz-Latn` both give «1 300» (U+00A0) wherever the data is there. Dates need no such trick, they are ours (`formatUz`).
  const tag = locale === 'uz' ? 'ru' : locale
  let formatter = numberFormatters.get(tag)
  if (!formatter) {
    formatter = new Intl.NumberFormat(tag)
    numberFormatters.set(tag, formatter)
  }
  return formatter.format(value)
}

/** A person's name as shown: trimmed; a name with no letter or digit (".", "-", an emoji only) is empty, i.e. "no name" - the caller falls back to its `*.anonymous` label. */
export const realName = (name: string | null | undefined): string => {
  const trimmed = name?.trim() ?? ''
  return /[\p{L}\p{N}]/u.test(trimmed) ? trimmed : ''
}
