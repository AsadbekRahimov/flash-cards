import type { StringKey } from '@/i18n/ru'
import type { Battle, BattleResult } from '@/api/types'

/**
 * The title of the end of a battle, in one place for the result screen and for the line a screen reader is told.
 *
 * A battle that ended without a result (`outcome: none`) is, for the most part, one that never took place: declined, withdrawn, not
 * accepted in time, the room not used. Two ways of ending say that it DID begin and was broken off: both players gone
 * (`both_absent`, which only a match that is on can come to) and a voided one that had its countdown (`starts_at` is set). Those are
 * "The match was interrupted", with the reason under it; "The match did not take place" would be untrue (docs/16 §11.2).
 */
export function resultTitleKey(battle: Pick<Battle, 'starts_at'>, result: Pick<BattleResult, 'outcome' | 'end_reason'>): StringKey {
  if (result.outcome === 'none') {
    const broken = result.end_reason === 'both_absent' || (result.end_reason === 'voided' && battle.starts_at !== null)
    return broken ? 'battle.result.title.aborted' : 'battle.result.title.none'
  }
  return `battle.result.title.${result.outcome}`
}
