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
  'answer_not_accepted',
  'quality_not_accepted',
  'exercise_pro_required',
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
  'already_perpetual',
  'subscribe_too_early',
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
  'exam_simulator_pro_required',
  'course_not_exam',
  'not_enough_words',
  'not_enough_distractors',
  'friendship_self',
  'friend_unavailable',
  'friendship_not_found',
  'not_recipient',
  'friend_limit',
  'friend_limit_other',
  'friend_blocked',
  'challenge_not_found',
  'not_friends',
  'challenge_exists',
  'challenge_invalid',
  'not_opponent',
  'challenge_closed',
  // Practice and battle (docs/16 §7.6); `course_not_found`, `lesson_not_found`, `friendship_not_found` and `not_friends` are above.
  'practice_unavailable',
  'practice_pro_required',
  'practice_mode_disabled',
  'practice_not_enough_material',
  'practice_session_active',
  'practice_session_not_found',
  'practice_session_closed',
  'element_not_current',
  'answer_already_accepted',
  'pair_already_matched',
  'invalid_answer_shape',
  'unknown_element_id',
  'request_id_reused',
  'replace_not_allowed',
  'retry_not_available',
  'practice_audio_unavailable',
  'lesson_not_in_course',
  'battle_disabled',
  'battle_busy',
  'battle_friend_no_access',
  'battle_material_unavailable',
  'battle_not_found',
  'battle_not_recipient',
  'battle_not_challenger',
  'battle_closed',
  'battle_wrong_state',
  'round_not_open',
  'round_closed',
] as const

export type KnownErrorCode = (typeof KNOWN_ERROR_CODES)[number]

export type ErrorContext = 'training' | 'exam' | 'catalog' | 'today' | 'settings' | 'decks' | 'practice' | 'battle'

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
