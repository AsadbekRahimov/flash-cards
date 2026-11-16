import type { GameAccess, ProPromise } from '@/api/types'
import type { StringKey } from '@/i18n/ru'

/**
 * What Pro gives, as the keys of `benefits.*`: the ONE place that decides which lines may be said to this learner. `practice` stands for the line
 * about practice, which `practice.pro_promise` of `/me` picks (or leaves out); `game` for the line about the game, which is there only while
 * `game.pro_promise` is `full`. The list of the paywall (`ProBenefits`) and the tip after a round (`FinishedView`) both read it, so neither
 * promises battles or a caravan that are switched off.
 */
export type BenefitSlot = StringKey | 'practice' | 'game'

/** The sentence of each word of `practice.pro_promise` that sells something; `none` — and a word this app does not know yet — has none. */
const PRACTICE_LINES: Partial<Record<ProPromise, StringKey>> = {
  both: 'benefits.practice',
  solo: 'benefits.practice_solo',
}

export function resolveBenefits(
  slots: readonly BenefitSlot[],
  practice: ProPromise,
  game: Pick<GameAccess, 'pro_promise'>['pro_promise'],
): StringKey[] {
  const practiceLine = PRACTICE_LINES[practice]
  const gameLine: StringKey | undefined = game === 'full' ? 'benefits.game' : undefined

  return slots.flatMap((slot) => {
    if (slot === 'practice') return practiceLine ? [practiceLine] : []
    if (slot === 'game') return gameLine ? [gameLine] : []
    return [slot]
  })
}
