import { t } from '@/i18n'
import type { StringKey } from '@/i18n/ru'
import type { PhraseInfo } from '@/api/types'

/**
 * Long text of a phrase card (docs/35 §3.3 d, docs/06 §16): a phrase is up to 100 characters, so it does not take the 52 px of a word.
 * The size steps down with the length and the text wraps by words (nothing is clipped: at 320 px a 100-character phrase is four lines
 * of 22 px). The classes are written out in full, so that Tailwind finds them in the source.
 */

/** Up to this many characters the text still reads as one big line (two at most at 320 px). */
const SHORT_MAX = 18
const MEDIUM_MAX = 36
const LONG_MAX = 60

/**
 * The classes of the phrase as the question of a card (flash, meaning, pretest, its reveal): the size by the length; `compact` - one step
 * smaller, once the answer panel is open (the options must stay above it).
 */
export function phraseHeadingClass(text: string, compact = false): string {
  const length = text.length
  if (compact) {
    if (length <= SHORT_MAX) return 'text-[30px] leading-9 tracking-[-0.5px] short:text-[26px] short:leading-8'
    if (length <= MEDIUM_MAX) return 'text-[26px] leading-8 tracking-[-0.4px] short:text-[22px] short:leading-7'
    if (length <= LONG_MAX) return 'text-[22px] leading-7 tracking-[-0.2px] short:text-[20px] short:leading-[26px]'
    return 'text-[20px] leading-[26px] short:text-[18px] short:leading-6'
  }
  if (length <= SHORT_MAX) return 'text-[36px] leading-[42px] tracking-[-0.6px] short:text-[30px] short:leading-9'
  if (length <= MEDIUM_MAX) return 'text-[30px] leading-9 tracking-[-0.5px] short:text-[26px] short:leading-8'
  if (length <= LONG_MAX) return 'text-[26px] leading-8 tracking-[-0.4px] short:text-[22px] short:leading-7'
  return 'text-[22px] leading-7 tracking-[-0.2px] short:text-[20px] short:leading-[26px]'
}

/**
 * A text of a game task (docs/18) that is longer than any word: a phrase (`translate_fwd`, `gap`) or the translation of one. The run sends
 * no sign of a phrase, so the length decides; a word and its translation stay below it and keep their size.
 */
export const GAME_LONG_TEXT_MIN = 30

export function isLongGameText(text: string): boolean {
  return text.length >= GAME_LONG_TEXT_MIN
}

const KIND_KEYS: Record<NonNullable<PhraseInfo['kind']>, StringKey> = {
  formula: 'phrase.kind.formula',
  fixed: 'phrase.kind.fixed',
  idiom: 'phrase.kind.idiom',
  phrasal_verb: 'phrase.kind.phrasal_verb',
  proverb: 'phrase.kind.proverb',
}

const REGISTER_KEYS: Record<NonNullable<PhraseInfo['register']>, StringKey> = {
  neutral: 'phrase.register.neutral',
  informal: 'phrase.register.informal',
  formal: 'phrase.register.formal',
  slang: 'phrase.register.slang',
  rude: 'phrase.register.rude',
}

export interface PhraseLabel {
  key: 'register' | 'variant' | 'kind'
  /** Already localized. */
  label: string
}

/**
 * The small pills under a phrase, in this order: the register («разговорное»), UK / US (`both` has none) and the kind («идиома»). A value
 * this build does not know (a newer server) is left out rather than shown raw.
 */
export function phraseLabels(phrase: PhraseInfo): PhraseLabel[] {
  const labels: PhraseLabel[] = []
  const register = phrase.register === null ? undefined : REGISTER_KEYS[phrase.register]
  if (register !== undefined) labels.push({ key: 'register', label: t(register) })
  if (phrase.variant === 'uk' || phrase.variant === 'us') labels.push({ key: 'variant', label: t(`phrase.variant.${phrase.variant}`) })
  const kind = phrase.kind === null ? undefined : KIND_KEYS[phrase.kind]
  if (kind !== undefined) labels.push({ key: 'kind', label: t(kind) })
  return labels
}
