import { useI18n, type Params } from '../index'
import { memoryRu, type MemoryPluralKey, type MemoryStringKey, type MemoryTranslations } from './ru'
import { memoryUz } from './uz'

/**
 * `t` / `tn` of the memory strength of a word (F1 / F2, docs/40 §2.2-2.3): the same rules as the app's (`{name}` substitution, CLDR plural
 * forms, Russian when a key is missing), on its own dictionaries, in the current language of the app - reactive, as the app's `t` is.
 */
const dictionaries: Record<'ru' | 'uz', MemoryTranslations> = { ru: memoryRu, uz: memoryUz }

const pluralRules = new Map<string, Intl.PluralRules>()

function interpolate(template: string, params?: Params): string {
  if (!params) return template
  return template.replace(/\{(\w+)\}/g, (match, name: string) => (name in params ? String(params[name]) : match))
}

export function useMemoryI18n() {
  const { locale } = useI18n()

  function t(key: MemoryStringKey, params?: Params): string {
    const value = dictionaries[locale.value][key] ?? memoryRu[key]
    return interpolate(value, params)
  }

  function tn(key: MemoryPluralKey, count: number, params?: Params): string {
    let rules = pluralRules.get(locale.value)
    if (!rules) {
      rules = new Intl.PluralRules(locale.value)
      pluralRules.set(locale.value, rules)
    }
    const forms = dictionaries[locale.value][key] ?? memoryRu[key]
    return interpolate(forms[rules.select(count)] ?? forms.other, { ...params, count })
  }

  return { t, tn }
}

export type { MemoryPluralKey, MemoryStringKey }
