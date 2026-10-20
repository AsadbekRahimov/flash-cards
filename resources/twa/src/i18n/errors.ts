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
  'learner_not_found',
  'learner_blocked',
  'not_a_member',
  'group_inactive',
  'invalid_init_data',
  'invalid_token',
  'missing_token',
  'lesson_not_found',
  'lesson_mismatch',
  'invalid_mode',
  'question_not_found',
  'question_not_served',
  'already_answered',
  'word_mismatch',
  'out_of_scope',
  'card_not_started',
  'card_not_due',
  'exam_still_open',
  'tts_unavailable',
  'promo_not_found',
  'promo_expired',
  'promo_exhausted',
  'promo_already_redeemed',
  'promo_not_applicable',
  'plan_unknown',
  'course_not_found',
  'deck_not_found',
  'deck_word_not_found',
  'deck_limit_reached',
  'deck_words_limit_reached',
  'deck_import_empty',
  'deck_title_invalid',
  'timezone_change_too_soon',
  'timezone_revert_unavailable',
  'price_not_configured',
  'provider_disabled',
  'provider_not_supported',
  'checkout_conflict',
  'too_many_pending',
  'offer_not_applicable',
  'offer_busy',
  'invalid_period',
  'invalid_tier',
  'not_cancelable',
  'not_resumable',
  'provider_capability',
  'provider_not_registered',
  'provider_unavailable',
  'order_not_found',
  'membership_not_found',
  'membership_via_group',
] as const

export type KnownErrorCode = (typeof KNOWN_ERROR_CODES)[number]

export type ErrorContext = 'training' | 'exam' | 'catalog' | 'today' | 'settings' | 'decks'

function isKnownCode(code: string): code is KnownErrorCode {
  return (KNOWN_ERROR_CODES as readonly string[]).includes(code)
}

/** Human-readable, localized text for anything thrown by the API layer. */
export function errorMessage(err: unknown, context: ErrorContext): string {
  if (err instanceof ApiClientError) {
    // Promo codes answer 410 (expired) too — that is not a closed session.
    if (err.code.startsWith('promo_') && isKnownCode(err.code)) return t(`error.code.${err.code}`)
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
