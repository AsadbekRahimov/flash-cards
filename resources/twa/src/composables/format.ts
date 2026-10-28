/**
 * Date and number formatting for the screens (docs/14 K3): one cached `Intl` formatter per locale + style
 * instead of a `new Intl.DateTimeFormat(...)` on every render.
 *
 * Dates are shown in the device's time zone. Examples are for `ru`:
 * - `dayMonth` — 28 октября
 * - `dayMonthYear` — 28 октября 2026 г.
 * - `dayMonthShort` — 28 окт.
 * - `dayMonthShortYear` — 28 окт. 2026 г.
 * - `dayMonthShortTime` — 28 окт., 18:00
 * - `weekdayTime` — пн 00:00
 * - `weekdayDayMonth` — вторник, 28 октября
 */
export type DateStyle =
  | 'dayMonth'
  | 'dayMonthYear'
  | 'dayMonthShort'
  | 'dayMonthShortYear'
  | 'dayMonthShortTime'
  | 'weekdayTime'
  | 'weekdayDayMonth'

const DATE_OPTIONS: Record<DateStyle, Intl.DateTimeFormatOptions> = {
  dayMonth: { day: 'numeric', month: 'long' },
  dayMonthYear: { day: 'numeric', month: 'long', year: 'numeric' },
  dayMonthShort: { day: 'numeric', month: 'short' },
  dayMonthShortYear: { day: 'numeric', month: 'short', year: 'numeric' },
  dayMonthShortTime: { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' },
  weekdayTime: { weekday: 'short', hour: '2-digit', minute: '2-digit' },
  weekdayDayMonth: { weekday: 'long', day: 'numeric', month: 'long' },
}

/** The interface locale (`ru` / `uz`) as a BCP 47 tag; Uzbek is always Latin script. */
function intlTag(locale: string): string {
  return locale === 'uz' ? 'uz-Latn' : locale
}

const dateFormatters = new Map<string, Intl.DateTimeFormat>()
const numberFormatters = new Map<string, Intl.NumberFormat>()

const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/

/**
 * Formats an ISO 8601 moment (`2026-10-28T18:00:00+05:00`) or a calendar date (`2026-10-28`, taken as that day
 * in the device's time zone, not as UTC midnight). Returns '' for a value that is not a date.
 *
 * @param locale interface locale, `ru` or `uz` (`getLocale()` / `useI18n().locale.value`)
 */
export function formatDate(iso: string, style: DateStyle, locale: string): string {
  const date = new Date(DATE_ONLY.test(iso) ? `${iso}T00:00:00` : iso)
  if (Number.isNaN(date.getTime())) return ''

  const tag = intlTag(locale)
  const key = `${tag}|${style}`
  let formatter = dateFormatters.get(key)
  if (!formatter) {
    formatter = new Intl.DateTimeFormat(tag, DATE_OPTIONS[style])
    dateFormatters.set(key, formatter)
  }
  return formatter.format(date)
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
