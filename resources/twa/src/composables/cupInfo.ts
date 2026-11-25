import { ApiClientError } from '@/api/client'
import { t } from '@/i18n'
import { errorMessage } from '@/i18n/errors'
import { formatDate } from '@/composables/format'
import type { CupFields, CupTask } from '@/api/types'
import type { GameWordElement } from '@/api/gameRunTypes'

/**
 * The friends' cup of a group (docs/33 §7, docs/04 A.23): what the screens of the cup share - the refusals of the server in the
 * learner's words, the start of the next day of a cup and the task of a round as the components of the game draw it.
 */

/**
 * What a refusal of the cup means for a screen:
 * - `unavailable` - 403 `cup_not_member` (also "no such cup", a stranger's code, a code the router did not take): ONE state for all,
 *   the server answers them alike and the screen does not try to tell them apart;
 * - `off` - 403 `cup_unavailable`: the cup is switched off (or not open for this chat);
 * - `telegram` - 503 `cup_unverifiable {telegram_unavailable}`: Telegram did not answer, "try later" with a retry;
 * - `busy` - 429 `cup_check_busy`: another check of the same learner is on its way; a retry by hand, never a loop;
 * - `bot_not_admin` - 409 `cup_unverifiable {bot_not_admin}` / `cup_bot_not_admin`: ask the administrator of the group;
 * - `paused` - 409 `cup_paused {reason}`; `not_admin` - 403 `cup_not_chat_admin`; `closed` - 410 `cup_closed`;
 * - `refused` - any other refusal of this action (its own words); `other` - the connection, the server, the unknown.
 */
export type CupFailureKind = 'unavailable' | 'off' | 'telegram' | 'busy' | 'bot_not_admin' | 'paused' | 'not_admin' | 'closed' | 'refused' | 'other'

export interface CupFailure {
  kind: CupFailureKind
  /** Localized. */
  message: string
  /** "Retry" makes sense (the same request may pass later). */
  retry: boolean
}

const failure = (kind: CupFailureKind, message: string, retry: boolean): CupFailure => ({ kind, message, retry })

/**
 * Why a name in the tournament was refused (`details.reason` of 422 `cup_display_name_invalid`): the chat would make a link, a mention,
 * a command or a tag of it, too many marks over a letter; the length or nothing printable (and an older server without a reason) - the
 * general words.
 */
function nameRefusal(reason: unknown): string {
  switch (reason) {
    case 'link':
      return t('cup.failure.name_link')
    case 'mention':
    case 'command':
    case 'tag':
      return t('cup.failure.name_symbol')
    case 'marks':
      return t('cup.failure.name_marks')
    default:
      return t('cup.failure.name_invalid')
  }
}

/** The refusal of any route of the cup in the learner's words. */
export function cupFailure(err: unknown): CupFailure {
  if (!(err instanceof ApiClientError)) return failure('other', errorMessage(err, 'game'), true)
  const reason = err.details?.reason
  switch (err.code) {
    case 'cup_not_member':
      return failure('unavailable', t('cup.failure.unavailable'), false)
    case 'cup_unavailable':
      return failure('off', t('cup.failure.off'), false)
    case 'cup_check_busy':
      return failure('busy', t('cup.failure.busy'), true)
    case 'cup_unverifiable':
      return reason === 'bot_not_admin' || err.status === 409
        ? failure('bot_not_admin', t('cup.failure.bot_not_admin'), true)
        : failure('telegram', t('cup.failure.telegram'), true)
    case 'cup_bot_not_admin':
      return failure('bot_not_admin', t('cup.failure.bot_not_admin_publish'), true)
    case 'cup_paused':
      return failure('paused', reason === 'bot_removed' ? t('cup.failure.paused_bot_removed') : t('cup.failure.paused'), false)
    case 'cup_not_chat_admin':
      return failure('not_admin', t('cup.failure.not_admin'), false)
    case 'cup_closed':
      return failure('closed', t('cup.failure.closed'), false)
    case 'cup_not_open':
      return failure('refused', t('cup.failure.not_open'), false)
    case 'cup_not_started':
      return failure('refused', t('cup.failure.not_started'), false)
    case 'cup_round_unavailable':
      return failure('refused', t('cup.failure.round_unavailable'), false)
    case 'cup_round_done':
      return failure('refused', t('cup.failure.round_done'), false)
    case 'cup_display_name_invalid':
      return failure('refused', nameRefusal(reason), false)
    case 'cup_rename_limit':
      return failure('refused', t('cup.failure.rename_limit'), false)
    case 'cup_material_unavailable':
      return failure('refused', t('cup.failure.material'), false)
    case 'cup_level_invalid':
      return failure('refused', t('cup.failure.level_invalid'), false)
    case 'cup_already_published':
      return failure('refused', t('cup.failure.already_published'), false)
    case 'cup_already_active':
      return failure('refused', t('cup.failure.already_active'), false)
  }
  // `/cups/by-code/{code}` with a code of a wrong shape is a 404 of the router: the same "unavailable" as a stranger's code.
  if (err.kind === 'not_found') return failure('unavailable', t('cup.failure.unavailable'), false)
  if (err.kind === 'forbidden') return failure('unavailable', t('cup.failure.unavailable'), false)
  if (err.kind === 'server' && err.status === 503) return failure('telegram', t('cup.failure.telegram'), true)
  return failure('other', errorMessage(err, 'game'), true)
}

const HOUR_MS = 60 * 60 * 1000
const DAY_MS = 24 * HOUR_MS

/** The hour of `at` on the clock of `timeZone` (0…23); null when the zone is not known to this browser. */
function hourIn(at: number, timeZone: string): number | null {
  try {
    const text = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', hourCycle: 'h23', timeZone }).format(at)
    const hour = Number(text)
    return Number.isFinite(hour) ? hour : null
  } catch {
    return null
  }
}

/**
 * The moment the next day of a running cup begins (ISO), or null: not running, the last day, no start. The days of a cup are the local
 * midnights of its zone (docs/33 §3.1): the start of day 1 (`starts_at`, from the server) moved by whole days, set back on midnight when a
 * change of the clock of that zone falls in between. Nothing is decided by it: it only says when the round of tomorrow opens.
 */
export function nextDayStart(cup: Pick<CupFields, 'status' | 'starts_at' | 'timezone' | 'day' | 'days'>): string | null {
  if (cup.status !== 'running' || cup.day === null || cup.day >= cup.days || cup.starts_at === null) return null
  const start = Date.parse(cup.starts_at)
  if (!Number.isFinite(start)) return null
  let at = start + cup.day * DAY_MS
  const hour = hourIn(at, cup.timezone)
  if (hour === 23) at += HOUR_MS
  else if (hour === 1) at -= HOUR_MS
  return new Date(at).toISOString()
}

/**
 * A task of a round (`CupRoundResource.current`) as the task components of the game take it (`ChoiceTask`, `SpellTask`, `GapTask`):
 * the same projection, the right answer is never in it. A field the server left out gets the empty value of its kind.
 */
export function cupTaskElement(task: CupTask): GameWordElement {
  const options = task.options ?? []
  switch (task.kind) {
    case 'translate_fwd':
      return { id: task.id, kind: 'translate_fwd', prompt: task.prompt ?? '', audio: task.audio === true, options }
    case 'translate_back':
      return { id: task.id, kind: 'translate_back', prompt: task.prompt ?? '', options }
    case 'listen':
      return { id: task.id, kind: 'listen', audio: true, audio_url: task.audio_url ?? '', options }
    case 'spell':
      return { id: task.id, kind: 'spell', prompt: task.prompt ?? '', answer_length: task.answer_length ?? 0 }
    case 'gap':
      return { id: task.id, kind: 'gap', masked: task.masked ?? '', translation: task.translation ?? null }
  }
}

/** The text of the right answer of a review entry (`reveal.correct_text`); '' when the server did not send it. */
export function revealText(reveal: Record<string, unknown> | null | undefined): string {
  const text = reveal?.correct_text
  return typeof text === 'string' ? text : ''
}

/**
 * The state of a cup in one line (docs/33 §3.1): "Waiting for the start: 1 Dec", "Day 3 of 7", "The cup is over", "The cup is
 * canceled", "Not published yet"; a pause says so first. Dates are the days of the cup: on the calendar of its zone.
 */
export function cupStatusLine(cup: CupFields, locale: string): string {
  if (cup.paused !== null && (cup.status === 'scheduled' || cup.status === 'running')) {
    return cup.paused === 'bot_removed' ? t('cup.status.paused_bot_removed') : t('cup.status.paused')
  }
  switch (cup.status) {
    case 'draft':
      return t('cup.status.draft')
    case 'scheduled':
      return cup.starts_at ? t('cup.status.scheduled', { date: formatDate(cup.starts_at, 'dayMonth', locale, cup.timezone) }) : t('cup.status.scheduled_soon')
    case 'running':
      return cup.day !== null ? t('cup.status.running', { day: cup.day, days: cup.days }) : t('cup.status.running_closing')
    case 'finished':
      return t('cup.status.finished')
    case 'canceled':
      return t('cup.status.canceled')
  }
}

/** "1 Dec – 7 Dec" of a published cup, on the calendar of its zone (the last day is the one before `ends_at`); '' before the publication. */
export function cupPeriod(cup: CupFields, locale: string): string {
  if (!cup.starts_at || !cup.ends_at) return ''
  const end = Date.parse(cup.ends_at)
  if (!Number.isFinite(end)) return ''
  const lastDay = new Date(end - 1).toISOString()
  return t('cup.period', { from: formatDate(cup.starts_at, 'dayMonthShort', locale, cup.timezone), to: formatDate(lastDay, 'dayMonthShort', locale, cup.timezone) })
}
