import { useOnboardingI18n } from '@/i18n/onboarding'
import { graphemeCount } from '@/composables/cupInfo'

/**
 * The name of O2 (docs/40 §2.1): the server checks it by the rules of a name in the cup (`DisplayName::validate`: 1-32 graphemes,
 * no links, no `@` / `/` / `#` at the start of a word, no "Zalgo"); the field checks the same simple rules on the way, so a name from
 * Telegram that would be refused is marked before "Continue". The server decides (422 `display_name_invalid {reason}`).
 */

export const NAME_MAX = 32

/** Code points: the server's second limit (an emoji sequence is one grapheme of several code points). */
const NAME_MAX_CODE_POINTS = 64

/** reason => pattern, as `DisplayName::RULES`, in the order the server reports them. */
const RULES: readonly (readonly [string, RegExp])[] = [
  ['link', /:\/\/|\bwww\.|[\p{L}\p{N}]\.\p{L}/iu],
  ['mention', /(?<![\p{L}\p{N}_])@[\p{L}\p{N}_]/u],
  ['command', /(?<![\p{L}\p{N}_/])\/[\p{L}\p{N}_]/u],
  ['tag', /(?<![\p{L}\p{N}_])(?:#\p{N}*[\p{L}_]|\$[A-Za-z])/u],
]

/** Why the name would be refused (`empty`, `too_long`, `marks`, `link`, `mention`, `command`, `tag`); null - it looks fine. */
export function nameProblem(text: string): string | null {
  const name = text.trim()
  if (name === '') return 'empty'
  if (graphemeCount(name) > NAME_MAX || Array.from(name).length > NAME_MAX_CODE_POINTS) return 'too_long'
  if (/\p{Mn}{3,}/u.test(name)) return 'marks'
  for (const [reason, pattern] of RULES) {
    if (pattern.test(name)) return reason
  }
  return null
}

/** A refused name in the learner's words; an unknown reason (or none) - the general words. */
export function nameProblemText(reason: string | null): string {
  const { t } = useOnboardingI18n()
  switch (reason) {
    case 'empty':
      return t('onboarding.name.error.empty')
    case 'too_long':
      return t('onboarding.name.error.too_long', { max: NAME_MAX })
    case 'marks':
      return t('onboarding.name.error.marks')
    case 'link':
      return t('onboarding.name.error.link')
    case 'mention':
    case 'command':
    case 'tag':
      return t('onboarding.name.error.symbols')
    default:
      return t('onboarding.name.error.invalid')
  }
}
