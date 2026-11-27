import { useI18n, type Params } from '../index'
import { vocabularyRu, type VocabularyPluralKey, type VocabularyStringKey, type VocabularyTranslations } from './ru'
import { vocabularyUz } from './uz'

/**
 * `t` / `tn` of "My dictionary" (docs/40 §2.3): the same rules as the app's (`{name}` substitution, CLDR plural forms, Russian when a key
 * is missing), on the screen's own dictionaries, in the current language of the app - reactive, as the app's `t` is (they read the locale
 * ref). The common texts ("Retry", the memory dots) stay with the app's `t`.
 */
const dictionaries: Record<'ru' | 'uz', VocabularyTranslations> = { ru: vocabularyRu, uz: vocabularyUz }

const pluralRules = new Map<string, Intl.PluralRules>()

function interpolate(template: string, params?: Params): string {
  if (!params) return template
  return template.replace(/\{(\w+)\}/g, (match, name: string) => (name in params ? String(params[name]) : match))
}

export function useVocabularyI18n() {
  const { locale } = useI18n()

  function t(key: VocabularyStringKey, params?: Params): string {
    const value = dictionaries[locale.value][key] ?? vocabularyRu[key]
    return interpolate(value, params)
  }

  function tn(key: VocabularyPluralKey, count: number, params?: Params): string {
    let rules = pluralRules.get(locale.value)
    if (!rules) {
      rules = new Intl.PluralRules(locale.value)
      pluralRules.set(locale.value, rules)
    }
    const forms = dictionaries[locale.value][key] ?? vocabularyRu[key]
    return interpolate(forms[rules.select(count)] ?? forms.other, { ...params, count })
  }

  return { t, tn }
}

export type { VocabularyPluralKey, VocabularyStringKey }
