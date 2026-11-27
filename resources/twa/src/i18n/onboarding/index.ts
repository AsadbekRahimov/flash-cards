import { useI18n, type Params } from '../index'
import { onboardingRu, type OnboardingPluralKey, type OnboardingStringKey, type OnboardingTranslations } from './ru'
import { onboardingUz } from './uz'

/**
 * `t` / `tn` of the onboarding (docs/40 §2.1): the same rules as the app's (`{name}` substitution, CLDR plural forms, Russian when a key
 * is missing), on the onboarding's own dictionaries, in the current language of the app - reactive, as the app's `t` is (they read the
 * locale ref). The common texts ("Retry") stay with the app's `t`.
 */
const dictionaries: Record<'ru' | 'uz', OnboardingTranslations> = { ru: onboardingRu, uz: onboardingUz }

const pluralRules = new Map<string, Intl.PluralRules>()

function interpolate(template: string, params?: Params): string {
  if (!params) return template
  return template.replace(/\{(\w+)\}/g, (match, name: string) => (name in params ? String(params[name]) : match))
}

export function useOnboardingI18n() {
  const { locale } = useI18n()

  function t(key: OnboardingStringKey, params?: Params): string {
    const value = dictionaries[locale.value][key] ?? onboardingRu[key]
    return interpolate(value, params)
  }

  function tn(key: OnboardingPluralKey, count: number, params?: Params): string {
    let rules = pluralRules.get(locale.value)
    if (!rules) {
      rules = new Intl.PluralRules(locale.value)
      pluralRules.set(locale.value, rules)
    }
    const forms = dictionaries[locale.value][key] ?? onboardingRu[key]
    return interpolate(forms[rules.select(count)] ?? forms.other, { ...params, count })
  }

  return { t, tn }
}

export type { OnboardingPluralKey, OnboardingStringKey }
