import { readonly, ref } from 'vue'
import { ru } from './ru'
import type { PluralKey, StringKey, Translations } from './ru'
import { uz } from './uz'

/**
 * Tiny dependency-free i18n for the TWA.
 *
 * - Interface languages: Russian (default) and Uzbek (Latin). The learned
 *   language is always English and is never translated.
 * - `t(key, params)` — plain strings with `{name}` substitution.
 * - `tn(key, count, params)` — plural forms via `Intl.PluralRules`
 *   (ru: one/few/many, uz: one/other); `{count}` is always available.
 * - The locale is chosen once at startup from Telegram's `language_code`
 *   (see `initLocale`) and kept in a ref so templates stay reactive.
 */

export type Locale = 'ru' | 'uz'

export const SUPPORTED_LOCALES: readonly Locale[] = ['ru', 'uz']
export const DEFAULT_LOCALE: Locale = 'ru'

export type Params = Record<string, string | number>

const dictionaries: Record<Locale, Translations> = { ru, uz }

const current = ref<Locale>(DEFAULT_LOCALE)

/**
 * Telegram `language_code` (IETF tag: `uz`, `uz-UZ`, `ru`, `en-GB`…) → locale.
 * `uz*` → uz, anything else (or nothing) → ru.
 */
export function resolveLocale(languageCode?: string | null): Locale {
  const primary = (languageCode ?? '').trim().toLowerCase().split(/[-_]/)[0]
  return primary === 'uz' ? 'uz' : DEFAULT_LOCALE
}

export function setLocale(locale: Locale): void {
  current.value = locale
  if (typeof document !== 'undefined') {
    document.documentElement.lang = locale
  }
}

/** Picks the locale from a Telegram language code and applies it (`<html lang>` too). */
export function initLocale(languageCode?: string | null): Locale {
  const locale = resolveLocale(languageCode)
  setLocale(locale)
  return locale
}

export function getLocale(): Locale {
  return current.value
}

function interpolate(template: string, params?: Params): string {
  if (!params) return template
  return template.replace(/\{(\w+)\}/g, (match, name: string) =>
    name in params ? String(params[name]) : match,
  )
}

export function t(key: StringKey, params?: Params): string {
  const value = dictionaries[current.value][key] ?? dictionaries[DEFAULT_LOCALE][key]
  return interpolate(typeof value === 'string' ? value : key, params)
}

const pluralRules = new Map<Locale, Intl.PluralRules>()

function pluralCategory(locale: Locale, count: number): Intl.LDMLPluralRule {
  let rules = pluralRules.get(locale)
  if (!rules) {
    rules = new Intl.PluralRules(locale)
    pluralRules.set(locale, rules)
  }
  return rules.select(count)
}

export function tn(key: PluralKey, count: number, params?: Params): string {
  const locale = current.value
  const forms = dictionaries[locale][key] ?? dictionaries[DEFAULT_LOCALE][key]
  const template = forms[pluralCategory(locale, count)] ?? forms.other
  return interpolate(template, { ...params, count })
}

export function useI18n() {
  return {
    locale: readonly(current),
    t,
    tn,
    setLocale,
  }
}
