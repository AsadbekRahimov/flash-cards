import { beforeEach, describe, expect, it } from 'vitest'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { duelBlockReason, duelFailure, duelOutcomeText, duelRated } from '../cupDuelInfo'
import { cupBoard, cupDuel, duelAvailability } from '@/api/__tests__/cupFixtures'

/** The refusals and the words of the duels of a cup (docs/04 A.23d). */

const err = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)

beforeEach(() => setLocale('ru'))

describe('duelFailure', () => {
  it('reads the codes of the duels and their details', () => {
    expect(duelFailure(err('conflict', 409, 'cup_duel_disabled')).kind).toBe('disabled')
    expect(duelFailure(err('not_found', 404, 'cup_duel_not_found')).kind).toBe('not_found')
    expect(duelFailure(err('conflict', 409, 'cup_duel_already_open', { duel_id: 9 })).duelId).toBe(9)
    expect(duelFailure(err('conflict', 409, 'battle_busy', { battle_id: 12 })).battleId).toBe(12)
    expect(duelFailure(err('conflict', 409, 'cup_duel_rating_changed', { rated: true }))).toMatchObject({ rated: true, message: expect.stringContaining('зачётная') })
    expect(duelFailure(err('conflict', 409, 'cup_duel_limit', { scope: 'daily', limit: 20 })).message).toBe('Сегодня можно отправить не больше 20 вызовов.')
    expect(duelFailure(err('conflict', 409, 'cup_duel_limit', { scope: 'public_participant', limit: 3 })).message).toContain('Вызовите участника лично')
    expect(duelFailure(err('conflict', 409, 'cup_duel_limit', { scope: 'odd' })).message).toBe('Лимит вызовов на сегодня исчерпан.')
    const limit = duelFailure(err('forbidden', 403, 'practice_limit_reached', { kind: 'battles', limit: 1, used: 1, resets_at: '2026-12-02T19:00:00.000Z' }))
    expect(limit.kind).toBe('limit')
    expect(limit.limitHit?.limit).toBe(1)
  })

  it('falls back to the refusals of the cup; a lost reply is uncertain, a refusal is not', () => {
    expect(duelFailure(err('forbidden', 403, 'cup_not_member'))).toMatchObject({ kind: 'unavailable', uncertain: false })
    expect(duelFailure(err('forbidden', 403, 'cup_unavailable')).kind).toBe('off')
    expect(duelFailure(err('network', 0, 'network')).uncertain).toBe(true)
    expect(duelFailure(err('rate_limited', 429, 'too_many_requests')).uncertain).toBe(true)
    expect(duelFailure(err('server', 503, 'cup_unverifiable')).uncertain).toBe(true)
    expect(duelFailure(err('validation', 422, 'cup_duel_self')).uncertain).toBe(false)
  })
})

describe('the words of a duel', () => {
  it('the kind: what the acceptance made of it, else what the challenge offers', () => {
    expect(duelRated(cupDuel({ rated: null, rated_offered: true }))).toBe(true)
    expect(duelRated(cupDuel({ rated: false, rated_offered: true }))).toBe(false)
  })

  it('how it ended', () => {
    expect(duelOutcomeText(cupDuel({ status: 'void', end_reason: 'material' }))).toContain('не хватило слов')
    expect(duelOutcomeText(cupDuel({ status: 'canceled', end_reason: 'challenger_limit' }))).toContain('бесплатные игры')
    expect(duelOutcomeText(cupDuel({ status: 'declined', role: 'opponent' }))).toBe('Вы отклонили вызов.')
  })

  it('why not today: the state of the cup when it is known, else the hint alone', () => {
    const board = cupBoard()
    expect(duelBlockReason(duelAvailability(), board)).toBeNull()
    expect(duelBlockReason(duelAvailability({ can_challenge: false, open_duel_id: 3 }), board)).toBeNull()
    expect(duelBlockReason(duelAvailability({ can_challenge: false }), cupBoard({ me: { ...board.me!, status: 'left' } }))).toContain('вышли из группы')
    expect(duelBlockReason(duelAvailability({ can_challenge: false, day: null }), cupBoard({ cup: { ...board.cup, status: 'scheduled' } }))).toBe('Дуэли начнутся вместе с кубком.')
    expect(duelBlockReason(duelAvailability({ can_challenge: false, challenges_left: 0 }), null)).toBe('Вызовы на сегодня закончились.')
  })
})
