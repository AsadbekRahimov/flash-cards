import { ApiClientError } from '@/api/client'
import { t, tn } from '@/i18n'
import { errorMessage } from '@/i18n/errors'
import { cupFailure, type CupFailureKind } from '@/composables/cupInfo'
import { limitHitFrom } from '@/composables/practiceLimits'
import type { CupBoardResponse, CupDuel, CupDuelAvailability, PracticeLimitHit } from '@/api/types'

/**
 * The duels of a cup (docs/33 §5.7, §11.9, docs/04 A.23d): what the screens of the duels share - the refusals of the server in the
 * learner's words, the kind of a duel, why a challenge is not possible today and how a duel ended. The server decides everything; the
 * words only tell what it said.
 */

/**
 * What a refusal of a duel route means for a screen, on top of the kinds of the cup (`cupFailure`):
 * - `disabled` - 409 `cup_duel_disabled`: the duels are off, the section is not shown at all (remembered for the session);
 * - `not_found` - 404 `cup_duel_not_found`: a duel of somebody else or a missing one: the screen goes back to the cup;
 * - `limit` - 403 `practice_limit_reached`: a friendly match takes one of the free battles of the day of the challenger - the paywall.
 */
export type DuelFailureKind = CupFailureKind | 'disabled' | 'not_found' | 'limit'

export interface DuelFailure {
  kind: DuelFailureKind
  /** Localized. */
  message: string
  /** The request may or may not have reached the server (network, 5xx, 429): a repeat of a challenge carries the same `request_id`. */
  uncertain: boolean
  /** `cup_duel_already_open {duel_id}`: my open challenge, to show instead of a new one. */
  duelId: number | null
  /** `battle_busy {battle_id}`: my match that is in the way. */
  battleId: number | null
  /** `cup_duel_rating_changed {rated}`: what the duel is now. */
  rated: boolean | null
  /** `practice_limit_reached`: the numbers the paywall quotes. */
  limitHit: PracticeLimitHit | null
}

const isId = (value: unknown): value is number => typeof value === 'number' && Number.isInteger(value) && value > 0
const isCount = (value: unknown): value is number => typeof value === 'number' && Number.isInteger(value) && value >= 0

function duelFailureOf(kind: DuelFailureKind, message: string, err: unknown, extra: Partial<DuelFailure> = {}): DuelFailure {
  const uncertain = err instanceof ApiClientError ? err.kind === 'network' || err.kind === 'server' || err.kind === 'rate_limited' : true
  return { kind, message, uncertain, duelId: null, battleId: null, rated: null, limitHit: null, ...extra }
}

/** A state the screen draws without a refusal of the server (a link of a stranger, the duels off for the session). */
export function localDuelFailure(kind: DuelFailureKind, message: string): DuelFailure {
  return { kind, message, uncertain: false, duelId: null, battleId: null, rated: null, limitHit: null }
}

/** `cup_duel_limit {scope, limit}`: which limit of the day the challenge hit, with the number of the server. */
function limitMessage(scope: unknown, limit: unknown): string {
  if (!isCount(limit)) return t('cup.duel.failure.limit')
  switch (scope) {
    case 'daily':
      return tn('cup.duel.failure.limit_daily', limit)
    case 'pair':
      return tn('cup.duel.failure.limit_pair', limit)
    case 'public_participant':
      return tn('cup.duel.failure.limit_public_participant', limit)
    case 'public_chat':
      return tn('cup.duel.failure.limit_public_chat', limit)
    default:
      return t('cup.duel.failure.limit')
  }
}

/** The refusal of any route of the duels in the learner's words. */
export function duelFailure(err: unknown): DuelFailure {
  if (err instanceof ApiClientError) {
    const details = err.details
    switch (err.code) {
      case 'cup_duel_disabled':
        return duelFailureOf('disabled', t('cup.duel.failure.disabled'), err)
      case 'cup_duel_not_found':
        return duelFailureOf('not_found', t('cup.duel.failure.not_found'), err)
      case 'cup_duel_unavailable':
        return duelFailureOf('refused', t('cup.duel.failure.unavailable'), err)
      case 'cup_duel_not_recipient':
        return duelFailureOf('refused', t('cup.duel.failure.not_recipient'), err)
      case 'cup_duel_closed':
        return duelFailureOf('refused', t('cup.duel.failure.closed'), err)
      case 'cup_duel_own':
        return duelFailureOf('refused', t('cup.duel.failure.own'), err)
      case 'cup_duel_already_open':
        return duelFailureOf('refused', t('cup.duel.failure.already_open'), err, { duelId: isId(details?.duel_id) ? details.duel_id : null })
      case 'cup_duel_rating_changed': {
        const rated = typeof details?.rated === 'boolean' ? details.rated : null
        const message = rated === null ? t('cup.duel.failure.rating_changed') : rated ? t('cup.duel.failure.rating_changed_rated') : t('cup.duel.failure.rating_changed_friendly')
        return duelFailureOf('refused', message, err, { rated })
      }
      case 'cup_duel_limit':
        return duelFailureOf('refused', limitMessage(details?.scope, details?.limit), err)
      case 'cup_duel_opponent_busy':
        return duelFailureOf('refused', t('cup.duel.failure.opponent_busy'), err)
      case 'cup_duel_self':
        return duelFailureOf('refused', t('cup.duel.failure.self'), err)
      case 'cup_duel_mode_invalid':
        return duelFailureOf('refused', t('cup.duel.failure.mode_invalid'), err)
      case 'practice_limit_reached': {
        const hit = limitHitFrom(details)
        return duelFailureOf(hit !== null ? 'limit' : 'refused', errorMessage(err, 'battle'), err, { limitHit: hit })
      }
      case 'battle_busy':
        return duelFailureOf('refused', t('cup.duel.failure.battle_busy'), err, { battleId: isId(details?.battle_id) ? details.battle_id : null })
      case 'battle_disabled':
      case 'practice_mode_disabled':
      case 'battle_material_unavailable':
      case 'practice_pro_required':
        return duelFailureOf('refused', errorMessage(err, 'battle'), err)
    }
  }
  const cup = cupFailure(err)
  return duelFailureOf(cup.kind, cup.message, err)
}

/** The kind of a duel as the screens show it: what the acceptance made of it, or what the challenge offers until then. */
export function duelRated(duel: Pick<CupDuel, 'rated' | 'rated_offered'>): boolean {
  return duel.rated ?? duel.rated_offered
}

/**
 * Why "Challenge to a duel" is not offered today (`availability.can_challenge` is false), from the hint of the server and the state of
 * the cup; null when a challenge is possible or my open challenge is shown instead.
 */
export function duelBlockReason(availability: CupDuelAvailability, board: Pick<CupBoardResponse, 'cup' | 'me'>): string | null {
  if (availability.can_challenge || availability.open_duel_id !== null) return null
  const { cup, me } = board
  if (me !== null && me.status !== 'active') return t('cup.duel.reason.left')
  if (cup.paused !== null && (cup.status === 'scheduled' || cup.status === 'running')) return t('cup.duel.reason.paused')
  if (cup.status === 'scheduled' || cup.status === 'draft') return t('cup.duel.reason.not_started')
  if (cup.status !== 'running' || availability.day === null) return t('cup.duel.reason.not_running')
  if (availability.challenges_left <= 0) return t('cup.duel.reason.no_challenges')
  return t('cup.duel.reason.other')
}

/** A duel that is not open any more and will not change: its outcome is told. */
export function duelOver(duel: Pick<CupDuel, 'status'>): boolean {
  return duel.status !== 'open' && duel.status !== 'accepted'
}

/** How a duel that is over ended, in one line (the score of a played one is drawn by the screen). */
export function duelOutcomeText(duel: Pick<CupDuel, 'status' | 'end_reason' | 'role' | 'rated'>): string {
  switch (duel.status) {
    case 'open':
      return t('cup.duel.state.open')
    case 'accepted':
      return t('cup.duel.state.accepted')
    case 'done':
      if (duel.end_reason === 'cup_closed') return t('cup.duel.outcome.done_cup_closed')
      return duel.rated === true ? t('cup.duel.outcome.done_rated') : t('cup.duel.outcome.done_friendly')
    case 'void':
      switch (duel.end_reason) {
        case 'material':
          return t('cup.duel.outcome.void_material')
        case 'battle_gone':
          return t('cup.duel.outcome.void_battle_gone')
        default:
          return t('cup.duel.outcome.void')
      }
    case 'declined':
      return duel.role === 'opponent' ? t('cup.duel.outcome.declined_by_me') : t('cup.duel.outcome.declined')
    case 'expired':
      return duel.end_reason === 'cup_over' ? t('cup.duel.outcome.cup_over') : t('cup.duel.outcome.expired')
    case 'canceled':
      switch (duel.end_reason) {
        case 'left':
          return t('cup.duel.outcome.canceled_left')
        case 'challenger_limit':
          return t('cup.duel.outcome.canceled_limit')
        case 'cup_closed':
          return t('cup.duel.outcome.cup_closed')
        case 'cup_over':
          return t('cup.duel.outcome.cup_over')
        default:
          return duel.role === 'challenger' ? t('cup.duel.outcome.canceled_by_me') : t('cup.duel.outcome.canceled')
      }
  }
}

/** "4:59" of the time left (milliseconds); 0:00 once it is over. */
export function clockText(ms: number): string {
  const seconds = Math.max(0, Math.ceil(ms / 1000))
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}
