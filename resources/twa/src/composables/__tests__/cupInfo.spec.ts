import { afterEach, describe, expect, it } from 'vitest'
import { ApiClientError } from '@/api/client'
import { cupFields, cupTask } from '@/api/__tests__/cupFixtures'
import { cupFailure, cupPeriod, cupStatusLine, cupTaskElement, nextDayStart, revealText } from '../cupInfo'
import { setLocale, tn } from '@/i18n'
import { ru } from '@/i18n/ru'
import { uz } from '@/i18n/uz'

const err = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)

afterEach(() => setLocale('ru'))

describe('cupFailure', () => {
  it.each([
    [err('forbidden', 403, 'cup_not_member'), 'unavailable', false],
    [err('not_found', 404, 'not_found'), 'unavailable', false],
    [err('forbidden', 403, 'cup_unavailable'), 'off', false],
    [err('server', 503, 'cup_unverifiable', { reason: 'telegram_unavailable' }), 'telegram', true],
    [err('server', 503, 'service_unavailable'), 'telegram', true],
    [err('rate_limited', 429, 'cup_check_busy'), 'busy', true],
    [err('conflict', 409, 'cup_unverifiable', { reason: 'bot_not_admin' }), 'bot_not_admin', true],
    [err('conflict', 409, 'cup_bot_not_admin'), 'bot_not_admin', true],
    [err('conflict', 409, 'cup_paused', { reason: 'blocked' }), 'paused', false],
    [err('forbidden', 403, 'cup_not_chat_admin'), 'not_admin', false],
    [err('gone', 410, 'cup_closed'), 'closed', false],
    [err('conflict', 409, 'cup_rename_limit'), 'refused', false],
    [err('network', 0, 'network'), 'other', true],
  ])('%s -> %s', (error, kind, retry) => {
    const failure = cupFailure(error)
    expect(failure.kind).toBe(kind)
    expect(failure.retry).toBe(retry)
    expect(failure.message).not.toBe('')
  })

  it('a stranger and a missing cup read the same words', () => {
    expect(cupFailure(err('forbidden', 403, 'cup_not_member')).message).toBe(cupFailure(err('not_found', 404, 'not_found')).message)
  })

  it('anything that is not an API error is "other"', () => {
    expect(cupFailure(new Error('x')).kind).toBe('other')
  })
})

describe('nextDayStart', () => {
  it('the next local midnight of the cup zone (Tashkent: 19:00 UTC)', () => {
    expect(nextDayStart(cupFields({ day: 3 }))).toBe('2026-12-03T19:00:00.000Z')
  })

  it('null on the last day, before the start and after the end', () => {
    expect(nextDayStart(cupFields({ day: 7 }))).toBeNull()
    expect(nextDayStart(cupFields({ status: 'scheduled', day: null }))).toBeNull()
    expect(nextDayStart(cupFields({ status: 'finished', day: null }))).toBeNull()
  })

  it('a change of the clock of the zone: still midnight (Berlin, 29 March 2026)', () => {
    // Day 1 is 27 March in Berlin (UTC+1, midnight = 23:00 UTC); summer time begins on 29 March - midnight of day 4 is 22:00 UTC.
    const next = nextDayStart(cupFields({ timezone: 'Europe/Berlin', starts_at: '2026-03-26T23:00:00.000Z', day: 3 }))
    expect(next).toBe('2026-03-29T22:00:00.000Z')
  })
})

describe('the task of a round as the game draws it', () => {
  it('maps each kind; the right answer is never invented', () => {
    expect(cupTaskElement(cupTask())).toEqual({ id: 'e1', kind: 'translate_fwd', prompt: 'apple', audio: false, options: cupTask().options })
    expect(cupTaskElement(cupTask({ kind: 'translate_back', prompt: 'яблоко' }))).toMatchObject({ kind: 'translate_back', prompt: 'яблоко' })
    expect(cupTaskElement(cupTask({ kind: 'listen', prompt: undefined, audio_url: '/x' }))).toMatchObject({ kind: 'listen', audio: true, audio_url: '/x' })
    expect(cupTaskElement(cupTask({ kind: 'spell', prompt: 'груша', answer_length: 4, options: undefined }))).toEqual({ id: 'e1', kind: 'spell', prompt: 'груша', answer_length: 4 })
    expect(cupTaskElement(cupTask({ kind: 'gap', prompt: undefined, masked: 'I ____ tea', translation: 'Я пью чай', options: undefined }))).toEqual({ id: 'e1', kind: 'gap', masked: 'I ____ tea', translation: 'Я пью чай' })
  })

  it('reveal text', () => {
    expect(revealText({ correct_text: 'apple' })).toBe('apple')
    expect(revealText(null)).toBe('')
  })
})

describe('the state and the dates of a cup', () => {
  it('ru and uz', () => {
    expect(cupStatusLine(cupFields(), 'ru')).toBe('День 3 из 7')
    expect(cupPeriod(cupFields(), 'ru')).toMatch(/^1 дек\.? – 7 дек\.?$/)
    setLocale('uz')
    expect(cupStatusLine(cupFields(), 'uz')).toBe('7 kundan 3-kun')
    expect(cupStatusLine(cupFields({ status: 'canceled', day: null }), 'uz')).toBe('Kubok bekor qilindi')
  })
})

describe('the texts of the cup', () => {
  it('ru plural of points: 1, 2, 5, 21', () => {
    expect([1, 2, 5, 21].map((n) => tn('cup.points', n))).toEqual(['1 очко', '2 очка', '5 очков', '21 очко'])
  })

  it('uz: the same keys, Latin with the ASCII apostrophe, no Cyrillic, one/other plurals', () => {
    const cupKeys = Object.keys(ru).filter((key) => key.startsWith('cup.') || key.startsWith('today.cup.') || key === 'profile.cups')
    expect(cupKeys.length).toBeGreaterThan(100)
    for (const key of cupKeys) {
      const value = (uz as Record<string, unknown>)[key]
      const texts = typeof value === 'string' ? [value] : Object.values(value as Record<string, string>)
      for (const text of texts) {
        expect(text, key).not.toMatch(/\p{Script=Cyrillic}/u)
        expect(text, key).not.toMatch(/[ʻʼ’‘]/)
      }
    }
    setLocale('uz')
    expect([1, 5].map((n) => tn('cup.points', n))).toEqual(['1 ball', '5 ball'])
  })
})
