import type { IconName } from '@/components/ui/icons'
import type { StringKey } from '@/i18n/ru'
import type { BattleVerdict } from '@/api/types'

/**
 * How the verdict of a round looks in the reveal and in the list of rounds (docs/14 §3: a colour is never alone — a
 * symbol and a word go with it). `soft` is the circle behind the symbol, `chip` the chip with the word.
 */
export interface VerdictLook {
  icon: IconName
  chip: string
  soft: string
  label: StringKey
}

export const VERDICT_LOOK: Record<BattleVerdict, VerdictLook> = {
  correct: { icon: 'check', chip: 'chip-success', soft: 'bg-success-soft text-success-ink', label: 'battle.verdict.correct' },
  typo: { icon: 'exclamation-circle', chip: 'chip-warning', soft: 'bg-warning-soft text-warning-ink', label: 'battle.verdict.typo' },
  wrong: { icon: 'x-mark', chip: 'chip-danger', soft: 'bg-danger-soft text-danger-ink', label: 'battle.verdict.wrong' },
  skipped: { icon: 'light-bulb', chip: 'chip-accent', soft: 'bg-accent-soft text-accent-ink', label: 'battle.verdict.skipped' },
  timeout: { icon: 'clock', chip: 'chip-neutral', soft: 'bg-track text-tg-subtitle', label: 'battle.verdict.timeout' },
}
