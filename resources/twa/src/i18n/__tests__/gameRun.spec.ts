import { afterEach, describe, expect, it } from 'vitest'
import { setLocale, t } from '../index'
import { ru } from '../ru'
import { uz } from '../uz'
import { ApiClientError } from '@/api/client'
import { errorMessage, KNOWN_ERROR_CODES } from '../errors'

afterEach(() => setLocale('ru'))

const RUN_CODES = [
  'game_run_not_found',
  'game_run_closed',
  'element_not_current',
  'answer_already_accepted',
  'pair_already_matched',
  'request_id_reused',
  'hint_not_available',
  'coins_insufficient',
  'invalid_answer_shape',
  'unknown_element_id',
  'unknown_option_id',
] as const

describe('i18n: a run of "Karvon" (docs/05 §5b.3)', () => {
  it('knows every error code of a run, in both languages, with its own text', () => {
    for (const locale of ['ru', 'uz'] as const) {
      setLocale(locale)
      for (const code of RUN_CODES) {
        expect(KNOWN_ERROR_CODES, code).toContain(code)
        const text = errorMessage(new ApiClientError('conflict', 409, code, 'English message'), 'practice')
        expect(text, `${locale}:${code}`).toBe(t(`error.code.${code}`))
        expect(text, `${locale}:${code}`).not.toMatch(/^error\./)
      }
    }
  })

  it('has the same gameRun.* keys in ru and uz, and none of them is empty', () => {
    const keys = (dictionary: Record<string, unknown>) => Object.keys(dictionary).filter((key) => key.startsWith('gameRun.')).sort()

    expect(keys(ru)).toEqual(keys(uz))
    expect(keys(ru).length).toBeGreaterThan(40)
  })

  it('has a text for every event, kind of task and verdict of the summary', () => {
    for (const key of [
      'gameRun.event.sandstorm',
      'gameRun.event.oasis',
      'gameRun.event.bazaar',
      'gameRun.task.translate_fwd',
      'gameRun.task.translate_back',
      'gameRun.task.listen',
      'gameRun.task.spell',
      'gameRun.task.gap',
      'gameRun.summary.verdict.typo',
      'gameRun.summary.verdict.wrong',
      'gameRun.summary.verdict.skipped',
    ] as const) {
      expect(key in ru, key).toBe(true)
      expect(key in uz, key).toBe(true)
    }
  })
})
