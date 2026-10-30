import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { ANSWER_REPEATS, RESULT_CHECKS_MAX, useBattleStore } from '../battle'
import {
  SERVER_NOW,
  answeringRound,
  at,
  battle,
  closedBattle,
  countdownBattle,
  finishedBattle,
  incoming,
  battleList,
  invitedBattle,
  poll,
  pollOf,
  revealRound,
  roomBattle,
  roundState,
} from '@/api/__tests__/battleFixtures'
import { sentenceElement } from '@/api/__tests__/practiceFixtures'
import type { Battle, BattlePoll, BattleRound } from '@/api/types'

vi.mock('@/api', () => ({
  api: {
    battles: {
      list: vi.fn(),
      get: vi.fn(),
      poll: vi.fn(),
      round: vi.fn(),
      accept: vi.fn(),
      decline: vi.fn(),
      cancel: vi.fn(),
      ready: vi.fn(),
      surrender: vi.fn(),
      answer: vi.fn(),
      audioFailed: vi.fn(),
      invite: vi.fn(),
    },
  },
}))

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

const offline = () => new ApiClientError('network', 0, 'network', 'fetch failed')
const apiError = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)

function pending<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}

/** A store with `b` open (its round read when it is one the screen needs). */
async function opened(b: Battle = battle(), round: BattleRound | null = answeringRound()) {
  vi.mocked(api.battles.get).mockResolvedValue(b)
  if (round !== null) vi.mocked(api.battles.round).mockResolvedValue(round)
  const store = useBattleStore()
  await store.open(b.id)
  return store
}

const answerReply = (overrides: Partial<{ round_resolved: boolean; poll: BattlePoll }> = {}) => ({
  verdict: 'correct' as const,
  points: 100,
  accepted_at: SERVER_NOW,
  round_resolved: false,
  poll: poll({ version: 24, round: roundState({ my_answered: true }) }),
  ...overrides,
})

const ORDER = { order: ['fW2', 'fT5', 'fR4', 'fE3', 'fQ1'] }

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date'] })
  vi.setSystemTime(new Date(SERVER_NOW))
})
afterEach(() => {
  vi.useRealTimers()
})

describe('battle store — opening a battle', () => {
  it('reads the battle, mirrors its status and takes the clock of the server', async () => {
    const store = await opened(roomBattle())

    expect(api.battles.get).toHaveBeenCalledWith(15)
    expect(store.loadStatus).toBe('ready')
    expect(store.status).toBe('room')
    expect(store.battle?.id).toBe(15)
    expect(store.serverOffsetMs).toBe(0)
  })

  it.each(['invited', 'room', 'countdown', 'playing'] as const)('polls a battle that is alive: %s', async (status) => {
    const store = await opened(battle({ status, round: status === 'playing' ? roundState() : null }))

    expect(store.polling).toBe(true)
  })

  it.each([
    ['finished', finishedBattle()],
    ['closed', closedBattle()],
  ] as const)('does not poll a battle that is over: %s', async (status, over) => {
    const store = await opened(over, null)

    expect(store.status).toBe(status)
    expect(store.polling).toBe(false)
    expect(store.isOver).toBe(true)
  })

  it('reads the round of the match (the element to answer) when it is the learner’s turn', async () => {
    const store = await opened()

    expect(api.battles.round).toHaveBeenCalledWith(15, 2)
    expect(store.currentRound?.no).toBe(2)
    expect(store.currentRound?.element.id).toBe('eSent00002')
    expect(store.elementSeq).toBe(1)
  })

  it('does not read the round of a learner who has answered it already: there is nothing to show but "waiting"', async () => {
    await opened(battle({ round: roundState({ my_answered: true }) }), null)

    expect(api.battles.round).not.toHaveBeenCalled()
  })

  it('reads a round in its reveal phase with the reveal', async () => {
    const store = await opened(battle({ round: roundState({ phase: 'reveal', phase_ends_at: at(3) }) }), revealRound())

    expect(store.currentRound?.reveal?.me.verdict).toBe('correct')
  })

  it('reads no round in the countdown: it has no phase yet', async () => {
    await opened(countdownBattle(), null)

    expect(api.battles.round).not.toHaveBeenCalled()
  })

  it('is “loading” while the first reading is on its way', async () => {
    const reply = pending<Battle>()
    vi.mocked(api.battles.get).mockReturnValue(reply.promise)
    const store = useBattleStore()

    const opening = store.open(15)
    expect(store.loadStatus).toBe('loading')
    expect(store.battleId).toBe(15)
    reply.resolve(invitedBattle())
    await opening

    expect(store.loadStatus).toBe('ready')
  })

  it('a refusal that no repeat can change is final: a battle that is not there, or not the learner’s', async () => {
    vi.mocked(api.battles.get).mockRejectedValue(apiError('not_found', 404, 'battle_not_found'))
    const store = useBattleStore()

    await store.open(99)

    expect(store.loadStatus).toBe('error')
    expect(store.gone).toBe(true)
    expect(store.errorMessage).toBe('Баттл не найден.')
    expect(store.polling).toBe(false)
  })

  it('a lost connection is not final: the screen offers “Retry”', async () => {
    vi.mocked(api.battles.get).mockRejectedValue(offline())
    const store = useBattleStore()

    await store.open(15)

    expect(store.loadStatus).toBe('error')
    expect(store.gone).toBe(false)
    expect(store.errorMessage).toBe('Нет связи с сервером. Попробуйте ещё раз.')
  })

  it('a battle the learner may not see because practice was switched off is final too', async () => {
    vi.mocked(api.battles.get).mockRejectedValue(apiError('forbidden', 403, 'practice_unavailable'))
    const store = useBattleStore()

    await store.open(15)

    expect(store.gone).toBe(true)
    expect(store.errorMessage).toBe('Практика сейчас недоступна.')
  })
})

describe('battle store — the poll (tick)', () => {
  it('a poll that moves only the score, the flags or the presence reads nothing else', async () => {
    const store = await opened()
    vi.mocked(api.battles.poll).mockResolvedValue(poll({ version: 24, scores: { me: 100, opponent: 225 }, round: roundState({ opponent_answered: true }) }))

    await store.tick()

    expect(api.battles.get).toHaveBeenCalledTimes(1)
    expect(api.battles.round).toHaveBeenCalledTimes(1)
    expect(store.scores).toEqual({ me: 100, opponent: 225 })
    expect(store.roundState?.opponent_answered).toBe(true)
  })

  it('a new status reads the battle in full', async () => {
    const store = await opened(roomBattle())
    vi.mocked(api.battles.poll).mockResolvedValue(pollOf(countdownBattle()))
    vi.mocked(api.battles.get).mockResolvedValue(countdownBattle())

    await store.tick()

    expect(api.battles.get).toHaveBeenCalledTimes(2)
    expect(store.status).toBe('countdown')
    expect(store.battle?.starts_at).toBe(at(3))
    expect(store.polling).toBe(true)
  })

  it('a new round number reads the round', async () => {
    const store = await opened()
    vi.mocked(api.battles.poll).mockResolvedValue(poll({ version: 30, round: roundState({ no: 3, deadline_at: at(45) }) }))
    vi.mocked(api.battles.round).mockResolvedValue(answeringRound({ no: 3, element: sentenceElement({ id: 'eSent00003' }) }))

    await store.tick()

    expect(api.battles.round).toHaveBeenLastCalledWith(15, 3)
    expect(store.currentRound?.element.id).toBe('eSent00003')
    expect(store.elementSeq).toBe(2)
  })

  it('a new phase of the same round reads the round again, and the reveal comes with it', async () => {
    const store = await opened()
    vi.mocked(api.battles.poll).mockResolvedValue(poll({ version: 26, round: roundState({ phase: 'reveal', phase_ends_at: at(3), my_answered: true, opponent_answered: true }) }))
    vi.mocked(api.battles.round).mockResolvedValue(revealRound())

    await store.tick()

    expect(api.battles.round).toHaveBeenCalledTimes(2)
    expect(store.currentRound?.phase).toBe('reveal')
    expect(store.currentRound?.reveal?.correct.correct_text).toBe('My mother is a teacher.')
    // the same task is not a new element: the mode component keeps its place
    expect(store.elementSeq).toBe(1)
  })

  it('reads the round again if the one on screen has no reveal although the server is in the reveal', async () => {
    const store = await opened()
    vi.mocked(api.battles.poll).mockResolvedValue(poll({ version: 26, round: roundState({ phase: 'reveal', phase_ends_at: at(3) }) }))
    vi.mocked(api.battles.round).mockResolvedValueOnce(revealRound({ reveal: null })).mockResolvedValueOnce(revealRound())

    await store.tick()
    expect(store.currentRound?.reveal).toBeNull()
    await store.tick()

    expect(store.currentRound?.reveal).not.toBeNull()
  })

  it('stops the poll when the battle is over, and polls no more', async () => {
    const store = await opened()
    vi.mocked(api.battles.poll).mockResolvedValue(pollOf(finishedBattle()))
    vi.mocked(api.battles.get).mockResolvedValue(finishedBattle())

    await store.tick()

    expect(store.status).toBe('finished')
    expect(store.battle?.result?.outcome).toBe('won')
    expect(store.polling).toBe(false)

    vi.mocked(api.battles.poll).mockClear()
    await store.tick()
    expect(api.battles.poll).not.toHaveBeenCalled()
  })

  it('keeps polling until the full reading of a finished battle has come', async () => {
    const store = await opened()
    vi.mocked(api.battles.poll).mockResolvedValue(pollOf(finishedBattle()))
    vi.mocked(api.battles.get).mockRejectedValueOnce(offline()).mockResolvedValueOnce(finishedBattle())

    await expect(store.tick()).rejects.toBeInstanceOf(ApiClientError)
    expect(store.polling).toBe(true)
    expect(store.status).toBe('playing')

    await store.tick()
    expect(store.status).toBe('finished')
    expect(store.polling).toBe(false)
  })

  it('throws when the beat does not come (so that the poll slows down), and says “no connection” until one does', async () => {
    const store = await opened()
    vi.mocked(api.battles.poll).mockRejectedValueOnce(offline()).mockResolvedValueOnce(poll({ version: 24 }))

    await expect(store.tick()).rejects.toBeInstanceOf(ApiClientError)
    expect(store.offline).toBe(true)
    expect(store.status).toBe('playing')
    expect(store.battle).not.toBeNull()

    await store.tick()
    expect(store.offline).toBe(false)
  })

  it('a server error or a throttle is the same: the screen stays', async () => {
    const store = await opened()
    vi.mocked(api.battles.poll).mockRejectedValueOnce(apiError('server', 503, 'x')).mockRejectedValueOnce(apiError('rate_limited', 429, 'x'))

    await expect(store.tick()).rejects.toBeDefined()
    await expect(store.tick()).rejects.toBeDefined()

    expect(store.offline).toBe(true)
    expect(store.loadStatus).toBe('ready')
  })

  it('ends the polling for good on a refusal that no repeat cures', async () => {
    const store = await opened()
    vi.mocked(api.battles.poll).mockRejectedValue(apiError('not_found', 404, 'battle_not_found'))

    await store.tick()

    expect(store.polling).toBe(false)
    expect(store.gone).toBe(true)
    expect(store.loadStatus).toBe('error')
  })

  it('ignores a poll that is older than what it has', async () => {
    const store = await opened()
    await vi.advanceTimersByTimeAsync(1)
    vi.mocked(api.battles.poll).mockResolvedValueOnce(poll({ version: 24, scores: { me: 100, opponent: 225 } })).mockResolvedValueOnce(poll({ version: 22, scores: { me: 0, opponent: 0 } }))

    await store.tick()
    await store.tick()

    expect(store.scores).toEqual({ me: 100, opponent: 225 })
  })

  it('does not start a poll beside one that is on its way', async () => {
    const store = await opened()
    const reply = pending<BattlePoll>()
    vi.mocked(api.battles.poll).mockReturnValue(reply.promise)

    const first = store.tick()
    await store.tick()
    reply.resolve(poll({ version: 24 }))
    await first

    expect(api.battles.poll).toHaveBeenCalledTimes(1)
  })

  it('does nothing when the battle is not the one asked about, or nothing is open', async () => {
    const store = useBattleStore()

    await store.tick()

    expect(api.battles.poll).not.toHaveBeenCalled()
  })

  it('stopPolling turns the poll off, startPolling turns it on while the battle is alive', async () => {
    const store = await opened()

    store.stopPolling()
    expect(store.polling).toBe(false)
    await store.tick()
    expect(api.battles.poll).not.toHaveBeenCalled()

    store.startPolling()
    expect(store.polling).toBe(true)
  })

  it('a full reading does not turn a stopped poll on again', async () => {
    const store = await opened(roomBattle())
    store.stopPolling()
    vi.mocked(api.battles.ready).mockResolvedValue(roomBattle({ version: 3, ready: { me: true, opponent: false } }))

    await store.ready()

    expect(store.polling).toBe(false)
  })
})

describe('battle store — the clock of the server and the presence of the opponent', () => {
  it('takes the offset from the median of the last three readings', async () => {
    // the learner has answered: no round is read, so the open is one reading of the clock (0), not two
    const store = await opened(battle({ round: roundState({ my_answered: true }) }), null)
    const reading = (offsetMs: number, version: number) =>
      poll({ version, server_now: new Date(Date.now() + offsetMs).toISOString(), round: roundState({ my_answered: true }) })
    vi.mocked(api.battles.poll)
      .mockResolvedValueOnce(reading(5000, 24))
      .mockResolvedValueOnce(reading(9000, 25))
      .mockResolvedValueOnce(reading(1000, 26))

    await store.tick()
    expect(store.serverOffsetMs).toBe(2500) // the open (0) and the first poll (5000)
    await store.tick()
    expect(store.serverOffsetMs).toBe(5000) // 0, 5000, 9000
    await store.tick()
    expect(store.serverOffsetMs).toBe(5000) // 5000, 9000, 1000: a slow answer cannot drag it far
  })

  it('remembers since when the opponent has been gone, and forgets it when they are back', async () => {
    const store = await opened()
    vi.mocked(api.battles.poll)
      .mockResolvedValueOnce(poll({ version: 24, opponent_present: false, server_now: at(10) }))
      .mockResolvedValueOnce(poll({ version: 24, opponent_present: false, server_now: at(14) }))
      .mockResolvedValueOnce(poll({ version: 25, opponent_present: true, server_now: at(16) }))

    await store.tick()
    expect(store.opponentPresent).toBe(false)
    expect(store.absentSince).toBe(Date.parse(at(10)))
    await store.tick()
    expect(store.absentSince).toBe(Date.parse(at(10)))
    await store.tick()
    expect(store.opponentPresent).toBe(true)
    expect(store.absentSince).toBeNull()
  })
})

describe('battle store — generation: a reply that comes late changes nothing', () => {
  it('drops the reading of a battle the learner has left meanwhile', async () => {
    const reply = pending<Battle>()
    vi.mocked(api.battles.get).mockReturnValue(reply.promise)
    const store = useBattleStore()

    const opening = store.open(15)
    store.leave()
    reply.resolve(battle())
    await opening

    expect(store.battle).toBeNull()
    expect(store.status).toBe('idle')
    expect(store.polling).toBe(false)
    expect(api.battles.round).not.toHaveBeenCalled()
  })

  it('drops the reading of the first battle when the learner has opened another', async () => {
    const first = pending<Battle>()
    vi.mocked(api.battles.get).mockReturnValueOnce(first.promise).mockResolvedValueOnce(roomBattle({ id: 16 }))
    const store = useBattleStore()

    const opening = store.open(15)
    await store.open(16)
    first.resolve(roomBattle({ id: 15 }))
    await opening

    expect(store.battleId).toBe(16)
    expect(store.battle?.id).toBe(16)
  })

  it('drops a poll that comes back after the screen was left, and a failure of it', async () => {
    const store = await opened()
    const reply = pending<BattlePoll>()
    vi.mocked(api.battles.poll).mockReturnValue(reply.promise)

    const ticking = store.tick()
    store.leave()
    reply.reject(offline())
    await ticking

    expect(store.offline).toBe(false)
    expect(store.battle).toBeNull()
  })

  it('drops a poll that comes back after the screen was left: it is not taken, nothing is read for it', async () => {
    const store = await opened()
    vi.mocked(api.battles.get).mockClear()
    const reply = pending<BattlePoll>()
    vi.mocked(api.battles.poll).mockReturnValue(reply.promise)

    const ticking = store.tick()
    store.leave()
    reply.resolve(poll({ status: 'finished', version: 40, round: null, scores: { me: 300, opponent: 200 }, end_reason: 'completed' }))
    await ticking

    expect(store.poll).toBeNull()
    expect(store.status).toBe('idle')
    expect(store.scores).toEqual({ me: 0, opponent: 0 })
    expect(api.battles.get).not.toHaveBeenCalled()
  })

  it('drops a poll of the first battle that comes back when the learner has opened another', async () => {
    const store = await opened()
    const reply = pending<BattlePoll>()
    vi.mocked(api.battles.poll).mockReturnValue(reply.promise)
    vi.mocked(api.battles.get).mockResolvedValue(roomBattle({ id: 16 }))

    const ticking = store.tick()
    await store.open(16)
    reply.resolve(poll({ id: 15, status: 'finished', version: 40, round: null, end_reason: 'completed' }))
    await ticking

    expect(store.battleId).toBe(16)
    expect(store.status).toBe('room')
    expect(store.poll?.id).toBe(16)
  })

  it('leaving forgets everything of the battle on screen and stops the poll', async () => {
    const store = await opened()

    store.leave()

    expect(store.battleId).toBeNull()
    expect(store.battle).toBeNull()
    expect(store.poll).toBeNull()
    expect(store.round).toBeNull()
    expect(store.status).toBe('idle')
    expect(store.polling).toBe(false)
    expect(store.generation).toBeGreaterThan(0)
  })
})

describe('battle store — the actions of the room', () => {
  it('accepting: the answer is the new state, and the poll goes on', async () => {
    const store = await opened(invitedBattle({ role: 'opponent' }), null)
    vi.mocked(api.battles.accept).mockResolvedValue(roomBattle({ role: 'opponent' }))

    expect(await store.accept()).toBe(true)

    expect(api.battles.accept).toHaveBeenCalledWith(15)
    expect(store.status).toBe('room')
    expect(store.polling).toBe(true)
    expect(store.acting).toBeNull()
  })

  it('a double tap is one request: the button waits for the answer', async () => {
    const store = await opened(invitedBattle({ role: 'opponent' }), null)
    const reply = pending<Battle>()
    vi.mocked(api.battles.accept).mockReturnValue(reply.promise)

    const first = store.accept()
    expect(store.acting).toBe('accept')
    expect(await store.accept()).toBe(false)
    reply.resolve(roomBattle())
    await first

    expect(api.battles.accept).toHaveBeenCalledTimes(1)
  })

  it('declining closes the battle and stops the poll', async () => {
    const store = await opened(invitedBattle({ role: 'opponent' }), null)
    vi.mocked(api.battles.decline).mockResolvedValue(closedBattle('declined', { role: 'opponent' }))

    expect(await store.decline()).toBe(true)

    expect(api.battles.decline).toHaveBeenCalledWith(15)
    expect(store.status).toBe('closed')
    expect(store.polling).toBe(false)
    expect(store.battle?.result?.end_reason).toBe('declined')
  })

  it('leaving the room closes the battle and stops the poll', async () => {
    const store = await opened(roomBattle(), null)
    vi.mocked(api.battles.cancel).mockResolvedValue(closedBattle('canceled'))

    expect(await store.cancel()).toBe(true)

    expect(api.battles.cancel).toHaveBeenCalledWith(15)
    expect(store.status).toBe('closed')
    expect(store.polling).toBe(false)
  })

  it('“Ready” can bring the countdown, and the poll goes on', async () => {
    const store = await opened(roomBattle({ ready: { me: false, opponent: true } }), null)
    vi.mocked(api.battles.ready).mockResolvedValue(countdownBattle())

    await store.ready()

    expect(api.battles.ready).toHaveBeenCalledWith(15)
    expect(store.status).toBe('countdown')
    expect(store.readiness).toEqual({ me: true, opponent: true })
  })

  it('surrender ends the match with the opponent as the winner', async () => {
    const store = await opened()
    vi.mocked(api.battles.surrender).mockResolvedValue(finishedBattle('lost', 'surrender'))

    expect(await store.surrender()).toBe(true)

    expect(store.status).toBe('finished')
    expect(store.battle?.result).toMatchObject({ outcome: 'lost', end_reason: 'surrender' })
    expect(store.polling).toBe(false)
  })

  it('a conflict means the state has moved: the battle is read again and the refusal is told', async () => {
    const store = await opened(invitedBattle({ role: 'opponent' }), null)
    vi.mocked(api.battles.accept).mockRejectedValue(apiError('conflict', 409, 'battle_closed', { end_reason: 'invite_expired' }))
    vi.mocked(api.battles.get).mockResolvedValue(closedBattle('invite_expired', { role: 'opponent' }))

    expect(await store.accept()).toBe(false)

    expect(store.actionError).toBe('Этот баттл уже закрыт.')
    expect(store.status).toBe('closed')
    expect(store.acting).toBeNull()
  })

  it('remembers the battle that is in the way of an accept', async () => {
    const store = await opened(invitedBattle({ role: 'opponent' }), null)
    vi.mocked(api.battles.accept).mockRejectedValue(apiError('conflict', 409, 'battle_busy', { battle_id: 9 }))

    await store.accept()

    expect(store.busyBattleId).toBe(9)
    expect(store.actionError).toBe('У вас уже есть активный баттл. Завершите его, чтобы начать новый.')
    expect(store.status).toBe('invited')
    store.clearBusyBattle()
    expect(store.busyBattleId).toBeNull()
  })

  it('a lost connection leaves the state as it is and tells so', async () => {
    const store = await opened(roomBattle(), null)
    vi.mocked(api.battles.ready).mockRejectedValue(offline())

    expect(await store.ready()).toBe(false)

    expect(store.actionError).toBe('Нет связи с сервером. Попробуйте ещё раз.')
    expect(store.status).toBe('room')
  })

  it('ignores the answer of an action to a battle that has been left', async () => {
    const store = await opened(roomBattle(), null)
    const reply = pending<Battle>()
    vi.mocked(api.battles.ready).mockReturnValue(reply.promise)

    const doing = store.ready()
    store.leave()
    reply.resolve(countdownBattle())
    await doing

    expect(store.battle).toBeNull()
    expect(store.status).toBe('idle')
  })
})

describe('battle store — the answer of a round', () => {
  it('sends one final answer with a request_id, and takes the poll that comes back', async () => {
    const store = await opened()
    vi.mocked(api.battles.answer).mockResolvedValue(answerReply())

    await store.answer(ORDER)

    expect(api.battles.answer).toHaveBeenCalledTimes(1)
    expect(api.battles.answer).toHaveBeenCalledWith(15, { request_id: expect.stringMatching(UUID), round: 2, answer: ORDER })
    expect(store.roundState?.my_answered).toBe(true)
    expect(store.pendingAnswer).toBeNull()
    expect(store.answerState).toBe('idle')
    // having answered, the learner waits: the round is not read again
    expect(api.battles.round).toHaveBeenCalledTimes(1)
  })

  it('reads the reveal at once when this answer was the last one of the round', async () => {
    const store = await opened()
    vi.mocked(api.battles.answer).mockResolvedValue(
      answerReply({ round_resolved: true, poll: poll({ version: 25, round: roundState({ phase: 'reveal', phase_ends_at: at(4), my_answered: true, opponent_answered: true }) }) }),
    )
    vi.mocked(api.battles.round).mockResolvedValue(revealRound())

    await store.answer(ORDER)

    expect(store.currentRound?.reveal).not.toBeNull()
  })

  it('sends the same request_id again after a lost connection, up to three times, and then asks for “Retry”', async () => {
    const store = await opened()
    vi.mocked(api.battles.answer).mockRejectedValue(offline())

    const sending = store.answer(ORDER)
    expect(store.answerState).toBe('sending')
    await vi.advanceTimersByTimeAsync(500)
    await vi.advanceTimersByTimeAsync(1000)
    await vi.advanceTimersByTimeAsync(2000)
    await sending

    const ids = vi.mocked(api.battles.answer).mock.calls.map((call) => call[1].request_id)
    expect(ids).toHaveLength(1 + ANSWER_REPEATS)
    expect(new Set(ids).size).toBe(1)
    expect(store.answerState).toBe('failed')
    expect(store.pendingAnswer).toMatchObject({ request_id: ids[0], round: 2, payload: ORDER })
  })

  it('“Retry” sends the answer that was not delivered with its own request_id', async () => {
    const store = await opened()
    vi.mocked(api.battles.answer).mockRejectedValue(offline())
    const sending = store.answer(ORDER)
    await vi.advanceTimersByTimeAsync(3500)
    await sending
    const first = store.pendingAnswer?.request_id
    vi.mocked(api.battles.answer).mockReset()
    vi.mocked(api.battles.answer).mockResolvedValue(answerReply())

    await store.retryAnswer()

    expect(api.battles.answer).toHaveBeenCalledTimes(1)
    expect(api.battles.answer).toHaveBeenCalledWith(15, { request_id: first, round: 2, answer: ORDER })
    expect(store.answerState).toBe('idle')
    expect(store.pendingAnswer).toBeNull()
    expect(store.roundState?.my_answered).toBe(true)
  })

  it('a repeat that gets through after the first was counted is counted once: the server answers the same', async () => {
    const store = await opened()
    vi.mocked(api.battles.answer).mockRejectedValueOnce(offline()).mockResolvedValueOnce(answerReply())

    const sending = store.answer(ORDER)
    await vi.advanceTimersByTimeAsync(500)
    await sending

    const [one, two] = vi.mocked(api.battles.answer).mock.calls
    expect(one?.[1].request_id).toBe(two?.[1].request_id)
    expect(store.answerState).toBe('idle')
  })

  it('round_closed: the answer came too late, no verdict is shown, the state of the server is read instead', async () => {
    const store = await opened()
    vi.mocked(api.battles.answer).mockRejectedValue(apiError('conflict', 409, 'round_closed', { round: 2, phase: 'reveal' }))
    vi.mocked(api.battles.poll).mockResolvedValue(poll({ version: 26, round: roundState({ phase: 'reveal', phase_ends_at: at(3) }) }))
    vi.mocked(api.battles.round).mockResolvedValue(revealRound())

    await store.answer(ORDER)

    expect(store.pendingAnswer).toBeNull()
    expect(store.answerState).toBe('idle')
    expect(store.actionError).toBe('Время раунда вышло: ответ не принят.')
    expect(store.roundState?.my_answered).toBe(false)
    expect(api.battles.poll).toHaveBeenCalledWith(15)
    expect(store.currentRound?.phase).toBe('reveal')
    // it was not a lost connection: the answer was not sent again
    expect(api.battles.answer).toHaveBeenCalledTimes(1)
  })

  it('answer_already_accepted: nothing to tell, only to follow the server', async () => {
    const store = await opened()
    vi.mocked(api.battles.answer).mockRejectedValue(apiError('conflict', 409, 'answer_already_accepted', { verdict: 'correct', points: 100 }))
    vi.mocked(api.battles.poll).mockResolvedValue(poll({ version: 24, round: roundState({ my_answered: true }) }))

    await store.answer(ORDER)

    expect(store.actionError).toBe('')
    expect(store.pendingAnswer).toBeNull()
    expect(store.roundState?.my_answered).toBe(true)
  })

  it('a refusal of the shape reads the round again: the task on screen is not the one of the server', async () => {
    const store = await opened()
    vi.mocked(api.battles.answer).mockRejectedValue(apiError('validation', 422, 'unknown_element_id'))
    vi.mocked(api.battles.poll).mockResolvedValue(poll({ version: 24 }))

    await store.answer(ORDER)

    expect(store.actionError).toBe('Ответ не относится к этому заданию. Обновите экран.')
    expect(api.battles.round).toHaveBeenCalledTimes(2)
  })

  it('takes no second answer while one is on its way, and none after one is given', async () => {
    const store = await opened()
    const reply = pending<ReturnType<typeof answerReply>>()
    vi.mocked(api.battles.answer).mockReturnValue(reply.promise)

    const first = store.answer(ORDER)
    await store.answer({ order: ['x'] })
    expect(api.battles.answer).toHaveBeenCalledTimes(1)
    reply.resolve(answerReply())
    await first

    await store.answer(ORDER)
    expect(api.battles.answer).toHaveBeenCalledTimes(1) // the poll of the first one says my_answered
  })

  it('takes no answer in the pause that shows the answers of the round', async () => {
    const store = await opened(battle({ round: roundState({ phase: 'reveal', phase_ends_at: at(3) }) }), revealRound())

    await store.answer(ORDER)

    expect(api.battles.answer).not.toHaveBeenCalled()
  })

  it('takes no answer before the task of the round has been read', async () => {
    vi.mocked(api.battles.get).mockResolvedValue(battle())
    vi.mocked(api.battles.round).mockReturnValue(new Promise(() => undefined))
    const store = useBattleStore()
    void store.open(15)
    await vi.advanceTimersByTimeAsync(1)

    await store.answer(ORDER)

    expect(api.battles.answer).not.toHaveBeenCalled()
  })

  it('an answer that comes back after the screen was left changes nothing', async () => {
    const store = await opened()
    const reply = pending<ReturnType<typeof answerReply>>()
    vi.mocked(api.battles.answer).mockReturnValue(reply.promise)

    const sending = store.answer(ORDER)
    store.leave()
    reply.resolve(answerReply())
    await sending

    expect(store.battle).toBeNull()
    expect(store.pendingAnswer).toBeNull()
  })

  it('stops repeating when the screen is left', async () => {
    const store = await opened()
    vi.mocked(api.battles.answer).mockRejectedValue(offline())

    const sending = store.answer(ORDER)
    await vi.advanceTimersByTimeAsync(0)
    store.leave()
    await vi.advanceTimersByTimeAsync(5000)
    await sending

    expect(api.battles.answer).toHaveBeenCalledTimes(1)
  })
})

describe('battle store — a clip that does not play', () => {
  it('tells the server once for an element, and takes the battle it answers with', async () => {
    const store = await opened()
    vi.mocked(api.battles.audioFailed).mockResolvedValue({ verified: true, battle: closedBattle('voided', { version: 30 }) })

    await store.audioFailed('eDict00001')
    await store.audioFailed('eDict00001')

    expect(api.battles.audioFailed).toHaveBeenCalledTimes(1)
    expect(api.battles.audioFailed).toHaveBeenCalledWith(15, { request_id: expect.stringMatching(UUID), element_id: 'eDict00001' })
    expect(store.status).toBe('closed')
    expect(store.battle?.result?.end_reason).toBe('voided')
    expect(store.polling).toBe(false)
  })

  it('when the file is there the match goes on, and a report that never gets through is not a failure of the match', async () => {
    const store = await opened()
    vi.mocked(api.battles.audioFailed).mockResolvedValueOnce({ verified: false, battle: battle({ version: 24 }) }).mockRejectedValue(offline())

    expect(await store.audioFailed('eDict00001')).toBe(false)
    const second = store.audioFailed('eDict00002')
    await vi.advanceTimersByTimeAsync(2000)

    expect(await second).toBeNull()
    expect(store.status).toBe('playing')
    expect(store.polling).toBe(true)
    expect(store.actionError).toBe('')
  })

  it('answers what the server found: true when the match is voided, false when the file is there, null when nothing was found out', async () => {
    const store = await opened()
    vi.mocked(api.battles.audioFailed)
      .mockResolvedValueOnce({ verified: false, battle: battle({ version: 24 }) })
      .mockResolvedValueOnce({ verified: true, battle: closedBattle('voided', { version: 30 }) })

    expect(await store.audioFailed('eDict00001')).toBe(false)
    expect(await store.audioFailed('eDict00002')).toBe(true)
    expect(await store.audioFailed('eDict00002')).toBeNull() // once per element
  })

  it('sends a report that got no reply once more - the same request_id, so the server counts it once', async () => {
    const store = await opened()
    vi.mocked(api.battles.audioFailed).mockRejectedValueOnce(offline()).mockResolvedValueOnce({ verified: true, battle: closedBattle('voided', { version: 30 }) })

    const reporting = store.audioFailed('eDict00001')
    await vi.advanceTimersByTimeAsync(2000)

    expect(await reporting).toBe(true)
    const [first, second] = vi.mocked(api.battles.audioFailed).mock.calls
    expect(first?.[1].request_id).toMatch(UUID)
    expect(second?.[1]).toEqual(first?.[1])
    expect(store.status).toBe('closed')
  })

  it('repeats a report ONCE: a second failure is let be', async () => {
    const store = await opened()
    vi.mocked(api.battles.audioFailed).mockRejectedValue(apiError('server', 503, 'x'))

    const reporting = store.audioFailed('eDict00005')
    await vi.advanceTimersByTimeAsync(10_000)

    expect(await reporting).toBeNull()
    expect(api.battles.audioFailed).toHaveBeenCalledTimes(2)
  })

  it('stops repeating a report when the screen is left', async () => {
    const store = await opened()
    vi.mocked(api.battles.audioFailed).mockRejectedValue(offline())

    const reporting = store.audioFailed('eDict00005')
    await vi.advanceTimersByTimeAsync(0)
    store.leave()
    await vi.advanceTimersByTimeAsync(5000)

    expect(await reporting).toBeNull()
    expect(api.battles.audioFailed).toHaveBeenCalledTimes(1)
  })

  it('does not send a report again after a refusal: it would be the same refusal', async () => {
    const store = await opened()
    vi.mocked(api.battles.audioFailed).mockRejectedValue(apiError('conflict', 409, 'battle_closed', { end_reason: 'voided' }))

    expect(await store.audioFailed('eDict00001')).toBeNull()

    expect(api.battles.audioFailed).toHaveBeenCalledTimes(1)
  })
})

const OFFERED = { battle_id: 16, status: 'invited', by_me: false } as const
const MINE = { battle_id: 16, status: 'invited', by_me: true } as const

describe('battle store — the rematch', () => {
  it('invites to the same format and place with rematch_of', async () => {
    const store = await opened(finishedBattle(), null)
    vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 16 }))

    expect(await store.rematch()).toBe(16)

    expect(api.battles.invite).toHaveBeenCalledWith({ rematch_of: 15, mode: 'sentence', course: 'english-a1', lesson_id: 41, friendship_id: 11 })
    expect(store.acting).toBeNull()
  })

  it('leaves the friendship out when the server has none to tell (it is gone): a rematch needs none', async () => {
    const store = await opened(finishedBattle('won', 'completed', { friendship_id: null }), null)
    vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 16 }))

    await store.rematch()

    expect(api.battles.invite).toHaveBeenCalledWith({ rematch_of: 15, mode: 'sentence', course: 'english-a1', lesson_id: 41 })
  })

  it('also after a battle that did not take place; a whole course is a null lesson', async () => {
    const store = await opened(closedBattle('room_expired', { lesson: null, friendship_id: null }), null)
    vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 16 }))

    await store.rematch()

    expect(api.battles.invite).toHaveBeenCalledWith({ rematch_of: 15, mode: 'sentence', course: 'english-a1', lesson_id: null })
  })

  it('does not invite from a battle that is still on', async () => {
    const store = await opened()

    expect(await store.rematch()).toBeNull()
    expect(api.battles.invite).not.toHaveBeenCalled()
  })

  describe('when a battle is in the way (409 battle_busy)', () => {
    const refusedBy = (id: number | null) => apiError('conflict', 409, 'battle_busy', id === null ? null : { battle_id: id })
    const BUSY = 'У вас уже есть активный баттл. Завершите его, чтобы начать новый.'
    const reading = (other: Battle) => vi.mocked(api.battles.get).mockImplementation((id) => Promise.resolve(id === 15 ? finishedBattle() : other))

    it('the friend offered a rematch at the same moment: their invitation to the learner is the one to go to, and nothing is refused', async () => {
      const store = await opened(finishedBattle(), null)
      vi.mocked(api.battles.invite).mockRejectedValue(refusedBy(16))
      reading(invitedBattle({ id: 16, role: 'opponent' }))

      expect(await store.rematch()).toBe(16)

      expect(api.battles.get).toHaveBeenCalledWith(16)
      expect(store.actionError).toBe('')
      expect(store.busyBattleId).toBeNull()
      expect(store.acting).toBeNull()
    })

    it('the battle in the way is the learner’s own invitation (they are its challenger): the message stands, with the way to it', async () => {
      const store = await opened(finishedBattle(), null)
      vi.mocked(api.battles.invite).mockRejectedValue(refusedBy(9))
      reading(invitedBattle({ id: 9, role: 'challenger' }))

      expect(await store.rematch()).toBeNull()

      expect(store.actionError).toBe(BUSY)
      expect(store.busyBattleId).toBe(9)
      expect(store.acting).toBeNull()
    })

    it('a battle that is on (the invitation was accepted) is not an invitation to accept, whoever started it', async () => {
      const store = await opened(finishedBattle(), null)
      vi.mocked(api.battles.invite).mockRejectedValue(refusedBy(9))
      reading(roomBattle({ id: 9, role: 'opponent' }))

      expect(await store.rematch()).toBeNull()

      expect(store.busyBattleId).toBe(9)
    })

    it('the battle in the way cannot be read: the message stands', async () => {
      const store = await opened(finishedBattle(), null)
      vi.mocked(api.battles.invite).mockRejectedValue(refusedBy(9))
      vi.mocked(api.battles.get).mockImplementation((id) => (id === 15 ? Promise.resolve(finishedBattle()) : Promise.reject(offline())))

      expect(await store.rematch()).toBeNull()

      expect(store.actionError).toBe(BUSY)
      expect(store.busyBattleId).toBe(9)
    })

    it('a refusal that names no battle is not looked up', async () => {
      const store = await opened(finishedBattle(), null)
      vi.mocked(api.battles.invite).mockRejectedValue(refusedBy(null))
      vi.mocked(api.battles.get).mockClear()

      expect(await store.rematch()).toBeNull()

      expect(api.battles.get).not.toHaveBeenCalled()
      expect(store.busyBattleId).toBeNull()
      expect(store.actionError).toBe(BUSY)
    })

    it('another refusal is not looked up either', async () => {
      const store = await opened(finishedBattle(), null)
      vi.mocked(api.battles.invite).mockRejectedValue(apiError('forbidden', 403, 'not_friends'))
      vi.mocked(api.battles.get).mockClear()

      expect(await store.rematch()).toBeNull()

      expect(api.battles.get).not.toHaveBeenCalled()
      expect(store.actionError).toBe('Это можно сделать только с другом.')
    })

    it('a lookup that comes back after the screen was left changes nothing', async () => {
      const store = await opened(finishedBattle(), null)
      vi.mocked(api.battles.invite).mockRejectedValue(refusedBy(16))
      const looked = pending<Battle>()
      vi.mocked(api.battles.get).mockImplementation((id) => (id === 15 ? Promise.resolve(finishedBattle()) : looked.promise))

      const asking = store.rematch()
      await vi.advanceTimersByTimeAsync(0)
      store.leave()
      looked.resolve(invitedBattle({ id: 16, role: 'opponent' }))

      expect(await asking).toBeNull()
      expect(store.actionError).toBe('')
    })
  })

  it('takes who offered it from the reading itself (by_me): there is no second request to find out', async () => {
    vi.mocked(api.battles.get).mockResolvedValue(finishedBattle('won', 'completed', { rematch: OFFERED }))
    const store = useBattleStore()

    await store.open(15)

    expect(api.battles.get).toHaveBeenCalledTimes(1)
    expect(api.battles.get).not.toHaveBeenCalledWith(16)
    expect(store.rematchOffer).toEqual(OFFERED)
  })

  it('has no offer when there is none', async () => {
    const store = await opened(finishedBattle(), null)

    expect(store.rematchOffer).toBeNull()
    expect(api.battles.get).toHaveBeenCalledTimes(1)
  })

  it('the result screen asks with the POLL (it carries the offer and costs the server little), not with GET /battles/{id}; the reading is bounded', async () => {
    const store = await opened(finishedBattle(), null)
    vi.mocked(api.battles.get).mockClear()
    vi.mocked(api.battles.poll).mockResolvedValue(pollOf(finishedBattle(), { rematch: OFFERED }))
    expect(store.watchingResult).toBe(true)

    await store.refreshResult()

    expect(api.battles.poll).toHaveBeenCalledWith(15)
    expect(api.battles.get).not.toHaveBeenCalled()
    expect(store.rematchOffer).toEqual(OFFERED)
    expect(store.resultChecks).toBe(1)

    for (let i = 1; i < RESULT_CHECKS_MAX; i++) await store.refreshResult()
    expect(store.resultChecks).toBe(RESULT_CHECKS_MAX)
    expect(store.watchingResult).toBe(false)
    const reads = vi.mocked(api.battles.poll).mock.calls.length
    await store.refreshResult()
    expect(api.battles.poll).toHaveBeenCalledTimes(reads)
    expect(api.battles.get).not.toHaveBeenCalled()
  })

  it('an offer that is taken back disappears, a reading that fails is let be', async () => {
    const store = await opened(finishedBattle('won', 'completed', { rematch: OFFERED }), null)
    vi.mocked(api.battles.poll).mockRejectedValueOnce(offline()).mockResolvedValueOnce(pollOf(finishedBattle(), { rematch: null }))

    await store.refreshResult()
    expect(store.rematchOffer).toEqual(OFFERED)
    await store.refreshResult()

    expect(store.rematchOffer).toBeNull()
  })

  it('does not read the result of a battle that is on', async () => {
    const store = await opened()

    expect(store.watchingResult).toBe(false)
    await store.refreshResult()
    expect(api.battles.poll).not.toHaveBeenCalled()
    expect(api.battles.get).toHaveBeenCalledTimes(1)
  })

  it('accepts the rematch of the friend and gives the id of its room', async () => {
    const store = await opened(finishedBattle('won', 'completed', { rematch: OFFERED }), null)
    vi.mocked(api.battles.accept).mockResolvedValue(roomBattle({ id: 16, role: 'opponent' }))

    expect(await store.acceptRematch()).toBe(16)

    expect(api.battles.accept).toHaveBeenCalledWith(16)
  })

  it('does not answer an offer that is the learner’s own: it is the friend’s to accept', async () => {
    const store = await opened(finishedBattle('won', 'completed', { rematch: MINE }), null)

    expect(await store.acceptRematch()).toBeNull()
    expect(await store.declineRematch()).toBe(false)

    expect(api.battles.accept).not.toHaveBeenCalled()
    expect(api.battles.decline).not.toHaveBeenCalled()
  })

  it('a rematch that cannot be accepted any more is told, and the offer is read again with the poll', async () => {
    const store = await opened(finishedBattle('won', 'completed', { rematch: OFFERED }), null)
    vi.mocked(api.battles.accept).mockRejectedValue(apiError('conflict', 409, 'battle_closed', { end_reason: 'canceled' }))
    vi.mocked(api.battles.poll).mockResolvedValue(pollOf(finishedBattle(), { rematch: { ...OFFERED, status: 'closed' } }))

    expect(await store.acceptRematch()).toBeNull()

    expect(store.actionError).toBe('Этот баттл уже закрыт.')
    expect(store.acting).toBeNull()
    expect(store.rematchOffer?.status).toBe('closed')
  })

  it('declines the rematch of the friend', async () => {
    const store = await opened(finishedBattle('won', 'completed', { rematch: OFFERED }), null)
    vi.mocked(api.battles.decline).mockResolvedValue(closedBattle('declined', { id: 16, role: 'opponent' }))

    expect(await store.declineRematch()).toBe(true)

    expect(api.battles.decline).toHaveBeenCalledWith(16)
    expect(store.rematchOffer).toEqual({ battle_id: 16, status: 'closed', by_me: false })
  })

  it('a reading that was on its way while the offer was declined cannot bring it back: the statuses only go forward', async () => {
    const store = await opened(finishedBattle('won', 'completed', { rematch: OFFERED }), null)
    vi.mocked(api.battles.decline).mockResolvedValue(closedBattle('declined', { id: 16, role: 'opponent' }))
    await store.declineRematch()
    vi.mocked(api.battles.poll).mockResolvedValue(pollOf(finishedBattle(), { rematch: OFFERED }))

    await store.refreshResult()

    expect(store.rematchOffer?.status).toBe('closed')
  })

  it('an offer of another battle is taken as it is (a new rematch after the declined one)', async () => {
    const store = await opened(finishedBattle('won', 'completed', { rematch: { ...OFFERED, status: 'closed' } }), null)
    vi.mocked(api.battles.poll).mockResolvedValue(pollOf(finishedBattle(), { rematch: { battle_id: 17, status: 'invited', by_me: false } }))

    await store.refreshResult()

    expect(store.rematchOffer).toEqual({ battle_id: 17, status: 'invited', by_me: false })
  })

  it('a poll of a battle that is on takes the offer away (it has none)', async () => {
    const store = await opened()
    store.applyRematch(OFFERED)

    await store.applyPoll(poll({ version: 24, rematch: null }))

    expect(store.rematchOffer).toBeNull()
  })
})

describe('battle store — an answer belongs to its round (review Б1)', () => {
  const round3 = () => poll({ version: 27, round: roundState({ no: 3, deadline_at: at(45) }) })
  const round3Task = () => answeringRound({ no: 3, deadline_at: at(45), element: sentenceElement({ id: 'eSent00003' }) })

  /** Round 2: the answer never gets through (four tries) and the screen waits for "Retry". */
  async function failedInRound2() {
    const store = await opened()
    vi.mocked(api.battles.answer).mockRejectedValue(offline())
    const sending = store.answer(ORDER)
    await vi.advanceTimersByTimeAsync(3500)
    await sending
    expect(store.answerState).toBe('failed')
    expect(store.pendingAnswer?.round).toBe(2)
    return store
  }

  it('an answer of round 2 that was not delivered does not stand in the way of round 3: the poll lets it go, without a word', async () => {
    const store = await failedInRound2()
    vi.mocked(api.battles.poll).mockResolvedValue(round3())
    vi.mocked(api.battles.round).mockResolvedValue(round3Task())

    await store.tick()

    expect(store.currentRound?.no).toBe(3)
    expect(store.answerState).toBe('idle')
    expect(store.pendingAnswer).toBeNull()
    expect(store.actionError).toBe('')

    // and round 3 takes its answer
    vi.mocked(api.battles.answer).mockReset()
    vi.mocked(api.battles.answer).mockResolvedValue(answerReply({ poll: poll({ version: 28, round: roundState({ no: 3, my_answered: true }) }) }))
    await store.answer({ order: ['a', 'b'] })
    expect(api.battles.answer).toHaveBeenCalledTimes(1)
    expect(api.battles.answer).toHaveBeenCalledWith(15, { request_id: expect.stringMatching(UUID), round: 3, answer: { order: ['a', 'b'] } })
    expect(store.actionError).toBe('')
  })

  it('the same when the lost answer WAS counted (only its reply was lost): the poll says so, then round 3 opens', async () => {
    const store = await failedInRound2()
    vi.mocked(api.battles.poll).mockResolvedValue(poll({ version: 24, round: roundState({ my_answered: true }) }))

    await store.tick()

    expect(store.roundState?.my_answered).toBe(true)
    expect(store.answerState).toBe('idle')
    expect(store.pendingAnswer).toBeNull()

    vi.mocked(api.battles.poll).mockResolvedValue(round3())
    vi.mocked(api.battles.round).mockResolvedValue(round3Task())
    await store.tick()
    expect(store.currentRound?.no).toBe(3)
    expect(store.answerState).toBe('idle')
  })

  it('the full reading of the battle lets it go too, not only the poll', async () => {
    const store = await failedInRound2()

    store.applyBattle(battle({ version: 30, round: roundState({ no: 3, deadline_at: at(45) }) }))

    expect(store.answerState).toBe('idle')
    expect(store.pendingAnswer).toBeNull()
  })

  it.each([
    ['the battle is over', () => poll({ version: 30, status: 'finished', round: null, end_reason: 'completed' })],
    ['the round is in its reveal', () => poll({ version: 30, round: roundState({ phase: 'reveal', phase_ends_at: at(3), opponent_answered: true }) })],
    ['the round is another one', () => poll({ version: 30, round: roundState({ no: 3 }) })],
  ])('lets it go when %s', async (_why, next) => {
    const store = await failedInRound2()
    vi.mocked(api.battles.get).mockResolvedValue(finishedBattle())
    vi.mocked(api.battles.round).mockResolvedValue(revealRound())
    vi.mocked(api.battles.poll).mockResolvedValue(next())

    await store.tick()

    expect(store.answerState).toBe('idle')
    expect(store.pendingAnswer).toBeNull()
    expect(store.actionError).toBe('')
  })

  it('keeps it while the server is still in the round, whatever else moves (the opponent answered, the score, the presence)', async () => {
    const store = await failedInRound2()
    vi.mocked(api.battles.poll).mockResolvedValue(poll({ version: 25, scores: { me: 100, opponent: 125 }, opponent_present: false, round: roundState({ opponent_answered: true }) }))

    await store.tick()

    expect(store.answerState).toBe('failed')
    expect(store.pendingAnswer?.round).toBe(2)
  })

  it('a repeat that is still on its way when the round moves on ends quietly: no toast, no new reading', async () => {
    const store = await opened()
    const reply = pending<ReturnType<typeof answerReply>>()
    vi.mocked(api.battles.answer).mockReturnValueOnce(reply.promise)
    const sending = store.answer(ORDER)
    expect(store.answerState).toBe('sending')
    vi.mocked(api.battles.poll).mockResolvedValue(round3())
    vi.mocked(api.battles.round).mockResolvedValue(round3Task())

    await store.tick()
    expect(store.answerState).toBe('idle')
    expect(store.currentRound?.no).toBe(3)
    vi.mocked(api.battles.poll).mockClear()
    reply.reject(apiError('conflict', 409, 'round_closed', { round: 2, phase: 'reveal' }))
    await sending

    expect(store.actionError).toBe('')
    expect(store.answerState).toBe('idle')
    expect(api.battles.poll).not.toHaveBeenCalled()
  })

  it('and the same when it is the NETWORK that fails it at that moment: no more repeats of an answer nobody waits for', async () => {
    const store = await opened()
    const reply = pending<ReturnType<typeof answerReply>>()
    vi.mocked(api.battles.answer).mockReturnValueOnce(reply.promise)
    const sending = store.answer(ORDER)
    vi.mocked(api.battles.poll).mockResolvedValue(round3())
    vi.mocked(api.battles.round).mockResolvedValue(round3Task())
    await store.tick()

    reply.reject(offline())
    await vi.advanceTimersByTimeAsync(5000)
    await sending

    expect(api.battles.answer).toHaveBeenCalledTimes(1)
    expect(store.answerState).toBe('idle')
  })

  it('what an old request brings back does not touch the answer of the next round', async () => {
    const store = await opened()
    const old = pending<ReturnType<typeof answerReply>>()
    vi.mocked(api.battles.answer).mockReturnValueOnce(old.promise)
    const first = store.answer(ORDER)
    vi.mocked(api.battles.poll).mockResolvedValue(round3())
    vi.mocked(api.battles.round).mockResolvedValue(round3Task())
    await store.tick()

    // the answer of round 3 is on its way, too
    const next = pending<ReturnType<typeof answerReply>>()
    vi.mocked(api.battles.answer).mockReturnValueOnce(next.promise)
    const second = store.answer({ order: ['a', 'b'] })
    expect(store.pendingAnswer?.round).toBe(3)

    // the reply of round 2 comes at last: its poll is from round 2
    old.resolve(answerReply())
    await first

    expect(store.pendingAnswer?.round).toBe(3)
    expect(store.answerState).toBe('sending')
    next.resolve(answerReply({ poll: poll({ version: 28, round: roundState({ no: 3, my_answered: true }) }) }))
    await second
    expect(store.pendingAnswer).toBeNull()
    expect(store.roundState).toMatchObject({ no: 3, my_answered: true })
  })
})

describe('battle store — a repeat that is late after a try with no reply is not a refusal (review Б2)', () => {
  const LATE = [
    ['round_closed', { round: 2, phase: 'reveal' }],
    ['round_not_open', { round: 2, phase: 'reveal' }],
    ['battle_closed', { end_reason: 'completed' }],
  ] as const

  it.each(LATE)('%s after a try that got no reply: nothing is told, the server is followed', async (code, details) => {
    const store = await opened()
    vi.mocked(api.battles.answer).mockRejectedValueOnce(offline()).mockRejectedValueOnce(apiError('conflict', 409, code, details))
    vi.mocked(api.battles.poll).mockResolvedValue(poll({ version: 26, round: roundState({ phase: 'reveal', phase_ends_at: at(3), my_answered: true, opponent_answered: true }) }))
    vi.mocked(api.battles.round).mockResolvedValue(revealRound())

    const sending = store.answer(ORDER)
    await vi.advanceTimersByTimeAsync(500)
    await sending

    expect(store.actionError).toBe('')
    expect(store.pendingAnswer).toBeNull()
    expect(store.answerState).toBe('idle')
    // the same request went twice, and the screen goes to where the server is: the review shows what became of the answer
    const ids = vi.mocked(api.battles.answer).mock.calls.map((call) => call[1].request_id)
    expect(ids).toHaveLength(2)
    expect(new Set(ids).size).toBe(1)
    expect(api.battles.poll).toHaveBeenCalledWith(15)
    expect(store.currentRound?.phase).toBe('reveal')
  })

  it('the match over after a lost reply: no “this battle is closed” above the result', async () => {
    const store = await opened(battle({ round: roundState({ no: 5 }) }), answeringRound({ no: 5 }))
    vi.mocked(api.battles.answer).mockRejectedValueOnce(offline()).mockRejectedValueOnce(offline()).mockRejectedValueOnce(apiError('conflict', 409, 'battle_closed', { end_reason: 'completed' }))
    vi.mocked(api.battles.poll).mockResolvedValue(pollOf(finishedBattle()))
    vi.mocked(api.battles.get).mockResolvedValue(finishedBattle())

    const sending = store.answer(ORDER)
    await vi.advanceTimersByTimeAsync(1500)
    await sending

    expect(store.actionError).toBe('')
    expect(store.status).toBe('finished')
  })

  it('also when the answer went again by hand: “Retry” meets “too late” after the automatic repeats had no reply', async () => {
    const store = await opened()
    vi.mocked(api.battles.answer).mockRejectedValue(offline())
    const sending = store.answer(ORDER)
    await vi.advanceTimersByTimeAsync(3500)
    await sending
    expect(store.pendingAnswer?.uncertain).toBe(true)
    vi.mocked(api.battles.answer).mockReset()
    vi.mocked(api.battles.answer).mockRejectedValue(apiError('conflict', 409, 'round_closed', { round: 2, phase: 'reveal' }))
    vi.mocked(api.battles.poll).mockResolvedValue(poll({ version: 26, round: roundState({ phase: 'reveal', phase_ends_at: at(3) }) }))
    vi.mocked(api.battles.round).mockResolvedValue(revealRound())

    await store.retryAnswer()

    expect(store.actionError).toBe('')
    expect(store.answerState).toBe('idle')
  })

  it('a FIRST try that is late is a plain “not taken”: the learner is told', async () => {
    const store = await opened()
    vi.mocked(api.battles.answer).mockRejectedValue(apiError('conflict', 409, 'battle_closed', { end_reason: 'completed' }))
    vi.mocked(api.battles.get).mockResolvedValue(finishedBattle())

    await store.answer(ORDER)

    expect(store.pendingAnswer).toBeNull()
    expect(store.actionError).toBe('Этот баттл уже закрыт.')
  })

  it('any other refusal after a try with no reply is still told (it is not about being late)', async () => {
    const store = await opened()
    vi.mocked(api.battles.answer).mockRejectedValueOnce(offline()).mockRejectedValueOnce(apiError('validation', 422, 'unknown_element_id'))
    vi.mocked(api.battles.poll).mockResolvedValue(poll({ version: 24 }))

    const sending = store.answer(ORDER)
    await vi.advanceTimersByTimeAsync(500)
    await sending

    expect(store.actionError).toBe('Ответ не относится к этому заданию. Обновите экран.')
  })

  it('says what the trouble was: the connection, or the server', async () => {
    const store = await opened()
    vi.mocked(api.battles.answer).mockRejectedValue(apiError('server', 503, 'x'))
    const sending = store.answer(ORDER)
    await vi.advanceTimersByTimeAsync(3500)
    await sending
    expect(store.answerState).toBe('failed')
    expect(store.answerCause).toBe('server')

    vi.mocked(api.battles.answer).mockReset()
    vi.mocked(api.battles.answer).mockRejectedValue(new ApiClientError('network', 0, 'timeout', 'The request timed out'))
    const again = store.retryAnswer()
    await vi.advanceTimersByTimeAsync(3500)
    await again
    expect(store.answerCause).toBe('network') // a request that hung up to its limit is the connection
  })
})

describe('battle store — the poll and the screen in trouble', () => {
  it('a reading of the battle that is older than the poll takes nothing back: the status does not flicker to the one before', async () => {
    const store = await opened()
    await store.applyPoll(poll({ version: 25, round: roundState({ phase: 'reveal', phase_ends_at: at(3), my_answered: true, opponent_answered: true }) }))
    vi.mocked(api.battles.round).mockResolvedValue(revealRound())

    // the full reading was asked before the poll and comes after it
    store.applyBattle(battle({ version: 24, status: 'countdown', starts_at: at(3), round: roundState({ no: 1, phase: null }) }))

    expect(store.status).toBe('playing')
    expect(store.battle?.version).toBe(23)
    expect(store.roundState?.phase).toBe('reveal')
  })

  it('a reading of the same version as the poll is taken (nothing is newer)', async () => {
    const store = await opened()
    await store.applyPoll(poll({ version: 24 }))

    store.applyBattle(battle({ version: 24, scores: { me: 100, opponent: 125 } }))

    expect(store.battle?.version).toBe(24)
  })

  it('the connection and the server are told apart in the trouble of the poll', async () => {
    const store = await opened()
    vi.mocked(api.battles.poll).mockRejectedValueOnce(apiError('server', 503, 'x')).mockRejectedValueOnce(apiError('rate_limited', 429, 'x')).mockRejectedValueOnce(offline())

    await expect(store.tick()).rejects.toBeDefined()
    expect([store.offline, store.offlineCause]).toEqual([true, 'server'])
    await expect(store.tick()).rejects.toBeDefined()
    expect(store.offlineCause).toBe('server')
    await expect(store.tick()).rejects.toBeDefined()
    expect(store.offlineCause).toBe('network')
  })

  it('a poll that hung up to its limit is the connection: it slows the poll down, and the next beat can start', async () => {
    const store = await opened()
    vi.mocked(api.battles.poll).mockRejectedValueOnce(new ApiClientError('network', 0, 'timeout', 'The request timed out')).mockResolvedValueOnce(poll({ version: 24 }))

    await expect(store.tick()).rejects.toBeInstanceOf(ApiClientError)
    expect(store.offline).toBe(true)
    expect(store.ticking).toBe(false) // nothing holds the next poll back

    await store.tick()
    expect(store.offline).toBe(false)
  })

  it('names the moment since which the opponent is gone - the server says it - and the same number comes after a reload', async () => {
    const store = await opened()

    await store.applyPoll(poll({ version: 24, opponent_present: false, opponent_absent_since: at(-12), server_now: at(0) }))
    expect(store.absentSince).toBe(Date.parse(at(-12)))

    // a reload: a new store opens the same battle and is told the same moment
    store.leave()
    vi.mocked(api.battles.get).mockResolvedValue(battle({ version: 25, opponent_present: false, opponent_absent_since: at(-12), server_now: at(2) }))
    await store.open(15)
    expect(store.absentSince).toBe(Date.parse(at(-12)))
  })

  it('a wake-up that comes while a poll is on its way is not lost: another poll follows it at once', async () => {
    const store = await opened()
    const first = pending<BattlePoll>()
    vi.mocked(api.battles.poll).mockReturnValueOnce(first.promise).mockResolvedValue(poll({ version: 24 }))

    const ticking = store.tick()
    await store.wake()
    expect(api.battles.poll).toHaveBeenCalledTimes(1)
    first.resolve(poll({ version: 24 }))
    await ticking
    await vi.advanceTimersByTimeAsync(0)

    expect(api.battles.poll).toHaveBeenCalledTimes(2)
    expect(store.wakeQueued).toBe(false)
  })

  it('several wake-ups during one poll make one more poll, not a crowd', async () => {
    const store = await opened()
    const first = pending<BattlePoll>()
    vi.mocked(api.battles.poll).mockReturnValueOnce(first.promise).mockResolvedValue(poll({ version: 24 }))

    const ticking = store.tick()
    await store.wake()
    await store.wake()
    await store.wake()
    first.resolve(poll({ version: 24 }))
    await ticking
    await vi.advanceTimersByTimeAsync(0)

    expect(api.battles.poll).toHaveBeenCalledTimes(2)
  })

  it('a wake-up with no poll on its way is a poll', async () => {
    const store = await opened()
    vi.mocked(api.battles.poll).mockResolvedValue(poll({ version: 24 }))

    await store.wake()

    expect(api.battles.poll).toHaveBeenCalledTimes(1)
  })

  it('a poll that failed does not forget the wake-up that waited for it', async () => {
    const store = await opened()
    const first = pending<BattlePoll>()
    vi.mocked(api.battles.poll).mockReturnValueOnce(first.promise).mockResolvedValue(poll({ version: 24 }))

    const ticking = store.tick().catch(() => undefined)
    await store.wake()
    first.reject(offline())
    await ticking
    await vi.advanceTimersByTimeAsync(0)

    expect(api.battles.poll).toHaveBeenCalledTimes(2)
  })

  it('a wake-up that waited for a poll of a battle that was left is dropped', async () => {
    const store = await opened()
    const first = pending<BattlePoll>()
    vi.mocked(api.battles.poll).mockReturnValue(first.promise)

    const ticking = store.tick()
    await store.wake()
    store.leave()
    first.resolve(poll({ version: 24 }))
    await ticking
    await vi.advanceTimersByTimeAsync(0)

    expect(api.battles.poll).toHaveBeenCalledTimes(1)
  })
})

describe('battle store — behind Pro (docs/16 §3.2)', () => {
  const PRO = () => apiError('forbidden', 403, 'practice_pro_required')

  it('“Accept” of a learner without Pro opens the paywall, with no message', async () => {
    const store = await opened(invitedBattle({ role: 'opponent' }), null)
    vi.mocked(api.battles.accept).mockRejectedValue(PRO())

    expect(await store.accept()).toBe(false)

    expect(store.paywall).toBe(true)
    expect(store.actionError).toBe('')
    expect(store.status).toBe('invited')
    store.dismissPaywall()
    expect(store.paywall).toBe(false)
  })

  it('a battle that cannot be opened for the same reason opens the paywall too (the screen keeps its closed state under it)', async () => {
    vi.mocked(api.battles.get).mockRejectedValue(PRO())
    const store = useBattleStore()

    await store.open(15)

    expect(store.paywall).toBe(true)
    expect(store.loadStatus).toBe('error')
    expect(store.gone).toBe(true)
    expect(store.errorMessage).toBe('Практика доступна в Pro.')
  })

  it('an invitation of the list is no different: the paywall, not a toast', async () => {
    vi.mocked(api.battles.list).mockResolvedValue(battleList({ incoming: [incoming({ id: 15 })] }))
    vi.mocked(api.battles.accept).mockRejectedValue(PRO())
    const store = useBattleStore()
    await store.loadList()

    expect(await store.acceptInvitation(15)).toBe(false)

    expect(store.paywall).toBe(true)
    expect(store.actionError).toBe('')
  })

  it('another refusal is not the paywall', async () => {
    const store = await opened(invitedBattle({ role: 'opponent' }), null)
    vi.mocked(api.battles.accept).mockRejectedValue(apiError('forbidden', 403, 'practice_unavailable'))

    await store.accept()

    expect(store.paywall).toBe(false)
    expect(store.actionError).toBe('Практика сейчас недоступна.')
  })

  it('leaving the battle takes the paywall with it', async () => {
    const store = await opened(invitedBattle({ role: 'opponent' }), null)
    vi.mocked(api.battles.accept).mockRejectedValue(PRO())
    await store.accept()

    store.leave()

    expect(store.paywall).toBe(false)
  })
})

describe('battle store — the invitations of the list', () => {
  it('accepts an invitation and takes it off the list', async () => {
    vi.mocked(api.battles.list).mockResolvedValue(battleList({ incoming: [incoming({ id: 15 }), incoming({ id: 16 })] }))
    vi.mocked(api.battles.accept).mockResolvedValue(roomBattle({ role: 'opponent' }))
    const store = useBattleStore()
    await store.loadList()

    expect(await store.acceptInvitation(15)).toBe(true)

    expect(api.battles.accept).toHaveBeenCalledWith(15)
    expect(store.incoming.map((item) => item.id)).toEqual([16])
    expect(store.invitationBusy).toEqual([])
  })

  it('declines one', async () => {
    vi.mocked(api.battles.list).mockResolvedValue(battleList({ incoming: [incoming({ id: 15 })] }))
    vi.mocked(api.battles.decline).mockResolvedValue(closedBattle('declined', { role: 'opponent' }))
    const store = useBattleStore()
    await store.loadList()

    expect(await store.declineInvitation(15)).toBe(true)

    expect(store.incoming).toEqual([])
  })

  it('an invitation that has run out is told, and the list is read again', async () => {
    vi.mocked(api.battles.list).mockResolvedValueOnce(battleList({ incoming: [incoming({ id: 15 })] })).mockResolvedValueOnce(battleList())
    vi.mocked(api.battles.accept).mockRejectedValue(apiError('conflict', 409, 'battle_closed', { end_reason: 'invite_expired' }))
    const store = useBattleStore()
    await store.loadList()

    expect(await store.acceptInvitation(15)).toBe(false)
    await vi.advanceTimersByTimeAsync(0)

    expect(store.actionError).toBe('Этот баттл уже закрыт.')
    expect(api.battles.list).toHaveBeenCalledTimes(2)
    expect(store.incoming).toEqual([])
  })

  it('a double tap on one invitation is one request', async () => {
    vi.mocked(api.battles.list).mockResolvedValue(battleList({ incoming: [incoming({ id: 15 })] }))
    const reply = pending<Battle>()
    vi.mocked(api.battles.accept).mockReturnValue(reply.promise)
    const store = useBattleStore()
    await store.loadList()

    const first = store.acceptInvitation(15)
    expect(store.invitationBusy).toEqual([15])
    expect(await store.acceptInvitation(15)).toBe(false)
    reply.resolve(roomBattle())
    await first

    expect(api.battles.accept).toHaveBeenCalledTimes(1)
  })
})
