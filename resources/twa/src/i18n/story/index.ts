import { useI18n, type Params } from '../index'
import { storyRu, type StoryPluralKey, type StoryStringKey, type StoryTranslations } from './ru'
import { storyUz } from './uz'

/**
 * `t` / `tn` of F3 "Поделиться в сторис" (docs/40 §2.4): the same rules as the app's (`{name}` substitution, CLDR plural
 * forms, Russian when a key is missing), on the screen's own dictionaries, in the current language of the app - reactive, as the app's
 * `t` is. The common texts stay with the app's `t`.
 */
const dictionaries: Record<'ru' | 'uz', StoryTranslations> = { ru: storyRu, uz: storyUz }

const pluralRules = new Map<string, Intl.PluralRules>()

function interpolate(template: string, params?: Params): string {
  if (!params) return template
  return template.replace(/\{(\w+)\}/g, (match, name: string) => (name in params ? String(params[name]) : match))
}

export function useStoryI18n() {
  const { locale } = useI18n()

  function t(key: StoryStringKey, params?: Params): string {
    const value = dictionaries[locale.value][key] ?? storyRu[key]
    return interpolate(value, params)
  }

  function tn(key: StoryPluralKey, count: number, params?: Params): string {
    let rules = pluralRules.get(locale.value)
    if (!rules) {
      rules = new Intl.PluralRules(locale.value)
      pluralRules.set(locale.value, rules)
    }
    const forms = dictionaries[locale.value][key] ?? storyRu[key]
    return interpolate(forms[rules.select(count)] ?? forms.other, { ...params, count })
  }

  return { t, tn }
}

export type { StoryPluralKey, StoryStringKey }
