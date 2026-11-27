import { useI18n, type Params } from '../index'
import { streakRu, type StreakPluralKey, type StreakStringKey, type StreakTranslations } from './ru'
import { streakUz } from './uz'

/**
 * `t` / `tn` of F4 "Серия спасена" / "Серия прервалась" (docs/40 §2.5): the same rules as the app's (`{name}` substitution, CLDR plural
 * forms, Russian when a key is missing), on the screen's own dictionaries, in the current language of the app - reactive, as the app's
 * `t` is. The common texts stay with the app's `t`.
 */
const dictionaries: Record<'ru' | 'uz', StreakTranslations> = { ru: streakRu, uz: streakUz }

const pluralRules = new Map<string, Intl.PluralRules>()

function interpolate(template: string, params?: Params): string {
  if (!params) return template
  return template.replace(/\{(\w+)\}/g, (match, name: string) => (name in params ? String(params[name]) : match))
}

export function useStreakI18n() {
  const { locale } = useI18n()

  function t(key: StreakStringKey, params?: Params): string {
    const value = dictionaries[locale.value][key] ?? streakRu[key]
    return interpolate(value, params)
  }

  function tn(key: StreakPluralKey, count: number, params?: Params): string {
    let rules = pluralRules.get(locale.value)
    if (!rules) {
      rules = new Intl.PluralRules(locale.value)
      pluralRules.set(locale.value, rules)
    }
    const forms = dictionaries[locale.value][key] ?? streakRu[key]
    return interpolate(forms[rules.select(count)] ?? forms.other, { ...params, count })
  }

  return { t, tn }
}

export type { StreakPluralKey, StreakStringKey }
