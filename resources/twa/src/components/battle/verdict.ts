import type { IconName } from '@/components/ui/icons'
import type { StringKey } from '@/i18n/ru'
import type { BattleVerdict } from '@/api/types'

/**
 * How the verdict of a round looks in the reveal and in the list of rounds (docs/14 §3: a colour is never alone — a
 * symbol and a word go with it). `ink` is the colour of the symbol in the list of rounds, `chip` the chip with the word. The tones of the
 * kit Muhrla: right - success with a check, wrong - danger with a cross; a typo - the soft accent (nearly right); no answer or no time -
 * neutral (the kit has no warning colour).
 */
export interface VerdictLook {
  icon: IconName
  chip: string
  ink: string
  label: StringKey
}

export const VERDICT_LOOK: Record<BattleVerdict, VerdictLook> = {
  correct: { icon: 'check', chip: 'chip-success', ink: 'text-success-ink', label: 'battle.verdict.correct' },
  typo: { icon: 'exclamation-circle', chip: 'chip-accent', ink: 'text-accent-ink', label: 'battle.verdict.typo' },
  wrong: { icon: 'x-mark', chip: 'chip-danger', ink: 'text-danger-ink', label: 'battle.verdict.wrong' },
  skipped: { icon: 'light-bulb', chip: 'chip-neutral', ink: 'text-muted', label: 'battle.verdict.skipped' },
  timeout: { icon: 'clock', chip: 'chip-neutral', ink: 'text-muted', label: 'battle.verdict.timeout' },
}
