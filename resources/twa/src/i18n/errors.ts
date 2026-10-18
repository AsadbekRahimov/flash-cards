import { ApiClientError } from '@/api/client'
import { t } from './index'

/**
 * Backend `error.code` values the TWA knows how to explain. The server's
 * `error.message` is English and is never shown — the client localizes by code.
 * Every entry must have an `error.code.<code>` message in each dictionary
 * (checked by the compiler through the template-literal key below).
 */
export const KNOWN_ERROR_CODES = [
  'session_closed',
  'session_not_found',
  'student_not_found',
  'group_inactive',
  'group_mismatch',
  'session_context_required',
  'invalid_init_data',
  'invalid_token',
  'missing_token',
  'lesson_not_found',
  'question_not_found',
  'question_not_served',
  'already_answered',
  'word_mismatch',
  'wrong_lesson',
  'card_not_started',
  'card_not_due',
  'exam_still_open',
  'tts_unavailable',
] as const

export type KnownErrorCode = (typeof KNOWN_ERROR_CODES)[number]

export type ErrorContext = 'training' | 'exam'

function isKnownCode(code: string): code is KnownErrorCode {
  return (KNOWN_ERROR_CODES as readonly string[]).includes(code)
}

/** Human-readable, localized text for anything thrown by the API layer. */
export function errorMessage(err: unknown, context: ErrorContext): string {
  if (err instanceof ApiClientError) {
    if (err.kind === 'gone') return t(`error.closed.${context}`)
    if (err.kind === 'network') return t('error.network')
    if (err.code === 'forbidden') return t(`error.forbidden.${context}`)
    if (isKnownCode(err.code)) return t(`error.code.${err.code}`)
    if (err.kind === 'forbidden') return t(`error.forbidden.${context}`)
    if (err.kind === 'rate_limited') return t('error.rate_limited')
    if (err.kind === 'server') return t('error.server')
    return t('error.generic')
  }

  if (err instanceof Error && err.message === 'opened_outside_telegram') {
    return t('error.outside_telegram')
  }

  return t('error.unexpected')
}
