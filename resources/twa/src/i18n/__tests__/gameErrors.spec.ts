import { afterEach, describe, expect, it } from 'vitest'
import { ApiClientError } from '@/api/client'
import { errorMessage, KNOWN_ERROR_CODES } from '../errors'
import { setLocale, t } from '../index'

afterEach(() => setLocale('ru'))

/** The codes of the map and of the start of a run (docs/18 §12.8), each with a text of its own in both languages. */
const GAME_CODES = [
  'game_unavailable',
  'game_limit_reached',
  'game_pro_required',
  'game_run_active',
  'game_daily_done',
  'boss_not_ready',
  'stop_locked',
  'game_not_enough_material',
  'course_not_found',
  'lesson_not_found',
  'stage_not_found',
  'lesson_not_in_course',
  'stage_not_in_course',
] as const

const err = (code: string) => new ApiClientError('conflict', 409, code, 'English backend message')

describe('error codes of the game', () => {
  it.each(GAME_CODES)('knows %s and explains it in Russian and Uzbek', (code) => {
    expect(KNOWN_ERROR_CODES).toContain(code)
    for (const locale of ['ru', 'uz'] as const) {
      setLocale(locale)
      const text = errorMessage(err(code), 'game')
      expect(text, `${locale}:${code}`).not.toContain('English backend message')
      expect(text, `${locale}:${code}`).not.toBe(t('error.generic'))
    }
  })

  it('has the texts of the "game" context for a closed run and a refused access', () => {
    expect(errorMessage(new ApiClientError('gone', 410, 'x', 'm'), 'game')).toBe('Этот забег уже закрыт.')
    expect(errorMessage(new ApiClientError('forbidden', 403, 'forbidden', 'm'), 'game')).toBe('У вас нет доступа к каравану.')
    setLocale('uz')
    expect(errorMessage(new ApiClientError('gone', 410, 'x', 'm'), 'game')).toBe('Bu reys allaqachon yopilgan.')
  })
})
