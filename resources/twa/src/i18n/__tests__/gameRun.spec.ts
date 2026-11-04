import { readFileSync } from 'node:fs'
import { join } from 'node:path'
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

const SHOP_CODES = [
  'item_not_found',
  'item_already_owned',
  'item_not_purchasable',
  'booster_not_owned',
  'booster_limit_reached',
  'booster_already_used',
  'booster_not_applicable',
] as const

describe('i18n: "My caravan" and the boosters (docs/05 §5b.5)', () => {
  it('knows every error code of the shop and of the boosters, in both languages, with its own text', () => {
    for (const locale of ['ru', 'uz'] as const) {
      setLocale(locale)
      for (const code of SHOP_CODES) {
        expect(KNOWN_ERROR_CODES, code).toContain(code)
        const text = errorMessage(new ApiClientError('conflict', 409, code, 'English message'), 'game')
        expect(text, `${locale}:${code}`).toBe(t(`error.code.${code}`))
        expect(text, `${locale}:${code}`).not.toMatch(/^error\./)
      }
    }
  })

  it('has the same gameCaravan.* keys in ru and uz, none of them empty, and the same placeholders', () => {
    const keys = (dictionary: Record<string, unknown>) => Object.keys(dictionary).filter((key) => key.startsWith('gameCaravan.')).sort()
    const ruDict = ru as unknown as Record<string, string>
    const uzDict = uz as unknown as Record<string, string>

    expect(keys(ru)).toEqual(keys(uz))
    expect(keys(ru).length).toBeGreaterThan(40)
    for (const key of [...keys(ru), 'paywall.lead_game_boosters', 'paywall.lead_game_boosters_soon']) {
      expect(ruDict[key], key).toBeTruthy()
      expect(uzDict[key], key).toBeTruthy()
      const slots = (text: string) => [...(text.match(/\{\w+\}/g) ?? [])].sort()
      expect(slots(uzDict[key]!), key).toEqual(slots(ruDict[key]!))
    }
  })

  it('lists every new uz key in the review document', () => {
    const doc = readFileSync(join(process.cwd(), '..', '..', 'docs', 'I18N_UZ_REVIEW.md'), 'utf8')
    for (const key of Object.keys(uz).filter((k) => k.startsWith('gameCaravan.') || k.startsWith('paywall.lead_game_boosters'))) {
      expect(doc, key).toContain(`| \`${key}\` |`)
    }
  })
})
