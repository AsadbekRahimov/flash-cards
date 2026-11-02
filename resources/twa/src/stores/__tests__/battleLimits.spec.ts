import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useBattleStore } from '../battle'
import { useBillingStore } from '../billing'
import { SERVER_NOW, battleList, closedBattle, finishedBattle, incoming, invitedBattle, roomBattle } from '@/api/__tests__/battleFixtures'
import { freeLimits, inHours, limitDetails } from '@/api/__tests__/practiceFixtures'
import type { Battle } from '@/api/types'

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
    me: { get: vi.fn() },
  },
}))

/**
 * The Free limits (docs/18 §3, G1) in the store of the battle: a rematch is a new battle like any other, and meets the counter of battles;
 * accepting a battle - an invitation, a rematch of the friend's - is never limited.
 */

const apiError = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)

/** A store with `b` open, nothing else read. */
async function opened(b: Battle) {
  vi.mocked(api.battles.get).mockResolvedValue(b)
  const store = useBattleStore()
  await store.open(b.id)
  return store
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date'] })
  vi.setSystemTime(new Date(SERVER_NOW))
})
afterEach(() => {
  vi.useRealTimers()
  setLocale('ru')
})

describe('battle store — the rematch meets the counter of battles', () => {
  it('opens the wall at once, without a request, when the counter says today\'s battles are spent', async () => {
    const resetsAt = inHours(4)
    useBillingStore().setLimits(freeLimits(0, 1, resetsAt))
    const store = await opened(finishedBattle())

    expect(await store.rematch()).toBeNull()

    expect(api.battles.invite).not.toHaveBeenCalled()
    expect(store.limitHit).toEqual({ kind: 'battles', limit: 1, used: 1, resets_at: resetsAt })
    expect(store.acting).toBeNull()
    expect(store.actionError).toBe('')
    expect(store.paywall).toBe(false)
  })

  // A battle that did not take place (declined, not answered in time, withdrawn) is not counted among the battles of the day: the counter the
  // screen holds may be from before it, so "Invite again" after such a battle asks the server, which knows.
  describe('after a battle that did not take place', () => {
    it.each(['invite_expired', 'declined', 'canceled', 'room_expired'] as const)('"Invite again" (%s) asks the server, whatever the counter remembers', async (reason) => {
      useBillingStore().setLimits(freeLimits(0, 1))
      vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 16 }))
      const store = await opened(closedBattle(reason))

      expect(await store.rematch()).toBe(16)

      expect(api.battles.invite).toHaveBeenCalledTimes(1)
      expect(store.limitHit).toBeNull()
    })

    it('and the server\'s word is the wall: if it still counts that battle, 403 is the paywall with its numbers', async () => {
      const billing = useBillingStore()
      billing.setLimits(freeLimits(0, 1))
      vi.mocked(api.battles.invite).mockRejectedValue(apiError('forbidden', 403, 'practice_limit_reached', limitDetails('battles')))
      const store = await opened(closedBattle('room_expired'))

      expect(await store.rematch()).toBeNull()

      expect(store.limitHit).toMatchObject({ kind: 'battles', limit: 1 })
      expect(store.actionError).toBe('')
      expect(billing.battleLimit).toMatchObject({ remaining: 0 })
    })

    it('a battle that was played is counted: its rematch meets the counter', async () => {
      useBillingStore().setLimits(freeLimits(0, 1))
      const store = await opened(finishedBattle('draw'))

      expect(await store.rematch()).toBeNull()

      expect(api.battles.invite).not.toHaveBeenCalled()
      expect(store.limitHit).toMatchObject({ kind: 'battles' })
    })
  })

  describe('an invitation of the learner that closed without a battle', () => {
    const plan = () => ({
      entitlement: { plan: 'free' as const, source: 'free' as const, until: null, new_words_per_day: 20 },
      paywall: { mode: 'coming_soon' as const, contact: null },
    })

    it('makes the screens forget the battles counter (no line, no stop) and the next reading of /me ask the server: the counter is from before it', async () => {
      const billing = useBillingStore()
      billing.apply(plan())
      billing.setLimits(freeLimits(1, 1))
      expect(billing.loadedAt).not.toBeNull()

      await opened(closedBattle('invite_expired'))

      expect(billing.loadedAt).toBeNull()
      expect(billing.battleLimit).toBeNull()
      expect(billing.limitReached('battles')).toBeNull()
      expect(billing.sessionLimit).toMatchObject({ used: 1, remaining: 1 }) // the sessions of practice have nothing to do with it
    })

    it.each(['declined', 'canceled'] as const)('also when it was %s', async (reason) => {
      const billing = useBillingStore()
      billing.apply(plan())
      billing.setLimits(freeLimits(0, 1))

      await opened(closedBattle(reason))

      expect(billing.loadedAt).toBeNull()
      expect(billing.battleLimit).toBeNull()
    })

    it('the counter comes back with the next reading of /me, with the number the server has', async () => {
      const billing = useBillingStore()
      billing.apply(plan())
      billing.setLimits(freeLimits(0, 1))
      await opened(closedBattle('declined'))
      vi.mocked(api.me.get).mockResolvedValue({ ...plan(), practice: { available: true, pro_required: false, modes: [], battle: true, limits: freeLimits(0, 0) } } as never)

      await billing.load()

      expect(api.me.get).toHaveBeenCalledTimes(1)
      expect(billing.battleLimit).toMatchObject({ limit: 1, remaining: 1 })
    })

    it('not when the invitation was the learner\'s to answer (the friend\'s battle is not counted against them), nor for a battle that is on or was played', async () => {
      const billing = useBillingStore()
      for (const b of [closedBattle('declined', { role: 'opponent' }), invitedBattle({ role: 'challenger' }), finishedBattle('won'), roomBattle()]) {
        billing.apply(plan())
        billing.setLimits(freeLimits(0, 1))
        const at = billing.loadedAt

        await opened(b)

        expect(billing.loadedAt, `${b.status}/${b.role}`).toBe(at)
        expect(billing.battleLimit, `${b.status}/${b.role}`).toMatchObject({ remaining: 0 })
        useBattleStore().leave()
      }
    })
  })

  it('asks as usual while a battle is left (the sessions of practice are another counter), for Pro and for an unknown counter', async () => {
    vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 16 }))
    const billing = useBillingStore()
    const store = await opened(finishedBattle())

    billing.setLimits(freeLimits(2, 0))
    expect(await store.rematch()).toBe(16)

    billing.setLimits(null)
    expect(await store.rematch()).toBe(16)

    billing.limits = null
    expect(await store.rematch()).toBe(16)
    expect(api.battles.invite).toHaveBeenCalledTimes(3)
    expect(store.limitHit).toBeNull()
  })

  it('asks once the day of the counter is over: the app stayed open overnight, and the server decides', async () => {
    useBillingStore().setLimits(freeLimits(0, 1, new Date(Date.parse(SERVER_NOW) + 3_600_000).toISOString()))
    vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 16, limits: freeLimits(0, 1) }))
    const store = await opened(finishedBattle())
    expect(await store.rematch()).toBeNull()

    vi.setSystemTime(new Date(Date.parse(SERVER_NOW) + 3_600_001))

    expect(await store.rematch()).toBe(16)
  })

  it('the 201 says the counters as they are after the battle, and the learner is led to it', async () => {
    const billing = useBillingStore()
    billing.setLimits(freeLimits(1, 0))
    vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 16, limits: freeLimits(1, 1) }))
    const store = await opened(finishedBattle())

    expect(await store.rematch()).toBe(16)

    expect(billing.battleLimit).toMatchObject({ used: 1, remaining: 0 })
    expect(billing.sessionLimit).toMatchObject({ used: 1, remaining: 1 })
  })

  it('a 201 of an older server, which says none, changes nothing; one with `null` (Pro) clears them', async () => {
    const billing = useBillingStore()
    billing.setLimits(freeLimits(0, 0))
    vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 16 }))
    const store = await opened(finishedBattle())

    await store.rematch()
    expect(billing.battleLimit).toMatchObject({ remaining: 1 })

    vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 17, limits: null }))
    await store.rematch()
    expect(billing.limits).toBeNull()
  })

  it('403 practice_limit_reached: the wall with the numbers of the refusal, no toast, and the counter is spent from now on', async () => {
    const resetsAt = inHours(5)
    const billing = useBillingStore()
    billing.setLimits(freeLimits(0, 0)) // the screen believed a battle was left
    vi.mocked(api.battles.invite).mockRejectedValue(apiError('forbidden', 403, 'practice_limit_reached', limitDetails('battles', resetsAt)))
    const store = await opened(finishedBattle())

    expect(await store.rematch()).toBeNull()

    expect(store.limitHit).toEqual({ kind: 'battles', limit: 1, used: 1, resets_at: resetsAt })
    expect(store.actionError).toBe('')
    expect(store.busyBattleId).toBeNull()
    expect(store.acting).toBeNull()
    expect(billing.battleLimit).toMatchObject({ used: 1, remaining: 0 })
  })

  it('403 practice_limit_reached with details that cannot be read is told in words, never a wall without numbers', async () => {
    vi.mocked(api.battles.invite).mockRejectedValue(apiError('forbidden', 403, 'practice_limit_reached'))
    const store = await opened(finishedBattle())

    expect(await store.rematch()).toBeNull()

    expect(store.limitHit).toBeNull()
    expect(store.actionError).toBe('Бесплатный лимит на сегодня закончился. Он обновится завтра.')
  })

  it('the other refusals of a rematch are as they were: a battle in the way, the Pro wall, a lost connection', async () => {
    const store = await opened(finishedBattle())

    vi.mocked(api.battles.invite).mockRejectedValue(apiError('conflict', 409, 'battle_busy', { battle_id: 9 }))
    vi.mocked(api.battles.get).mockImplementation((id) => Promise.resolve(id === 15 ? finishedBattle() : invitedBattle({ id, role: 'challenger' })))
    await store.rematch()
    expect(store.busyBattleId).toBe(9)
    expect(store.limitHit).toBeNull()

    vi.mocked(api.battles.invite).mockRejectedValue(apiError('forbidden', 403, 'practice_pro_required'))
    await store.rematch()
    expect(store.paywall).toBe(true)
    expect(store.limitHit).toBeNull()

    vi.mocked(api.battles.invite).mockRejectedValue(new ApiClientError('network', 0, 'network', 'fetch failed'))
    await store.rematch()
    expect(store.actionError).toBe('Нет связи с сервером. Попробуйте ещё раз.')
    expect(store.limitHit).toBeNull()
  })

  it('a wall that was shown is forgotten by the next try, by closing the sheet and by leaving the battle', async () => {
    const billing = useBillingStore()
    billing.setLimits(freeLimits(0, 1))
    const store = await opened(finishedBattle())
    await store.rematch()
    expect(store.limitHit).not.toBeNull()

    store.dismissPaywall()
    expect(store.limitHit).toBeNull()

    await store.rematch()
    store.leave()
    expect(store.limitHit).toBeNull()

    billing.setLimits(freeLimits(0, 0))
    vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 16 }))
    const again = await opened(finishedBattle())
    again.showLimit({ kind: 'battles', limit: 1, used: 1, resets_at: inHours(1) })
    expect(await again.rematch()).toBe(16)
    expect(again.limitHit).toBeNull()
  })

  it('opening another battle forgets a wall that a screen showed before (the Friends screen shows its own)', async () => {
    const store = useBattleStore()
    store.showLimit({ kind: 'battles', limit: 1, used: 1, resets_at: inHours(1) })

    await opened(finishedBattle())

    expect(store.limitHit).toBeNull()
  })

  it('a rematch that is answered after the learner left the screen still counts: the battle exists', async () => {
    const billing = useBillingStore()
    let finish!: (value: Battle) => void
    vi.mocked(api.battles.invite).mockReturnValue(new Promise((resolve) => (finish = resolve)))
    const store = await opened(finishedBattle())
    const asking = store.rematch()

    store.leave()
    finish(invitedBattle({ id: 16, limits: freeLimits(0, 1) }))
    await asking

    expect(billing.battleLimit).toMatchObject({ used: 1, remaining: 0 })
  })

  it('a refusal that comes after the learner left is not told to the next screen, but the counter learns it', async () => {
    const billing = useBillingStore()
    billing.setLimits(freeLimits(0, 0))
    let refuse!: (reason: unknown) => void
    vi.mocked(api.battles.invite).mockReturnValue(new Promise((_, reject) => (refuse = reject)))
    const store = await opened(finishedBattle())
    const asking = store.rematch()

    store.leave()
    refuse(apiError('forbidden', 403, 'practice_limit_reached', limitDetails('battles')))
    await asking

    expect(store.limitHit).toBeNull()
    expect(billing.battleLimit).toMatchObject({ remaining: 0 })
  })
})

// An empty env of the server means that kind has no limit: `limits` carries `null` for it and the other kind keeps its counter.
describe('battle store — battles without a limit (null inside the limits)', () => {
  it('a rematch is never stopped, whatever the counter of the sessions of practice says', async () => {
    useBillingStore().setLimits({ sessions: freeLimits(2, 0).sessions, battles: null })
    vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 16 }))
    const store = await opened(finishedBattle())

    expect(await store.rematch()).toBe(16)

    expect(api.battles.invite).toHaveBeenCalledTimes(1)
    expect(store.limitHit).toBeNull()
  })

  it('the 201 says it: the battles go on without a counter, the sessions keep theirs', async () => {
    const billing = useBillingStore()
    billing.setLimits(freeLimits(1, 0))
    vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 16, limits: { sessions: freeLimits(1, 0).sessions, battles: null } }))
    const store = await opened(finishedBattle())

    await store.rematch()

    expect(billing.battleLimit).toBeNull()
    expect(billing.sessionLimit).toMatchObject({ used: 1, remaining: 1 })
  })

  it('a 403 for the battles that the screen took for unlimited is the wall, and the counter appears spent', async () => {
    const billing = useBillingStore()
    billing.setLimits({ sessions: freeLimits(0, 0).sessions, battles: null })
    vi.mocked(api.battles.invite).mockRejectedValue(apiError('forbidden', 403, 'practice_limit_reached', limitDetails('battles')))
    const store = await opened(finishedBattle())

    expect(await store.rematch()).toBeNull()

    expect(store.limitHit).toMatchObject({ kind: 'battles', limit: 1 })
    expect(billing.battleLimit).toMatchObject({ used: 1, remaining: 0 })
  })
})

describe('battle store — accepting is never limited', () => {
  beforeEach(() => useBillingStore().setLimits(freeLimits(2, 1)))

  it('"Accept" of the invitation on screen asks the server, whatever the counter says', async () => {
    vi.mocked(api.battles.accept).mockResolvedValue(roomBattle({ role: 'opponent' }))
    const store = await opened(invitedBattle({ role: 'opponent' }))

    expect(await store.accept()).toBe(true)

    expect(api.battles.accept).toHaveBeenCalledWith(15)
    expect(store.limitHit).toBeNull()
    expect(store.status).toBe('room')
  })

  it('so does an invitation of the list', async () => {
    vi.mocked(api.battles.list).mockResolvedValue(battleList({ incoming: [incoming({ id: 15 })] }))
    vi.mocked(api.battles.accept).mockResolvedValue(roomBattle({ role: 'opponent' }))
    const store = useBattleStore()
    await store.loadList()

    expect(await store.acceptInvitation(15)).toBe(true)

    expect(api.battles.accept).toHaveBeenCalledWith(15)
    expect(store.limitHit).toBeNull()
  })

  it('and the rematch that the friend offered', async () => {
    vi.mocked(api.battles.accept).mockResolvedValue(roomBattle({ id: 16, role: 'opponent' }))
    const store = await opened(finishedBattle('lost', 'completed', { rematch: { battle_id: 16, status: 'invited', by_me: false } }))

    expect(await store.acceptRematch()).toBe(16)

    expect(api.battles.accept).toHaveBeenCalledWith(16)
    expect(store.limitHit).toBeNull()
  })

  it('and the room: "Ready" and the rest never meet the counter', async () => {
    vi.mocked(api.battles.ready).mockResolvedValue(roomBattle({ ready: { me: true, opponent: false } }))
    const store = await opened(roomBattle())

    expect(await store.ready()).toBe(true)

    expect(store.limitHit).toBeNull()
  })

  it('the wall of a refusal that no accept can get is not invented: an unexpected 403 of an accept is told as any other refusal', async () => {
    vi.mocked(api.battles.accept).mockRejectedValue(apiError('forbidden', 403, 'practice_limit_reached', limitDetails('battles')))
    const store = await opened(invitedBattle({ role: 'opponent' }))

    expect(await store.accept()).toBe(false)

    expect(store.limitHit).toBeNull()
    expect(store.actionError).toBe('Бесплатный лимит на сегодня закончился. Он обновится завтра.')
  })
})

describe('battle store — the wall that the Friends screen shows', () => {
  it('is the counter of battles, kept until the sheet is closed', () => {
    const store = useBattleStore()
    const hit = { kind: 'battles', limit: 1, used: 1, resets_at: inHours(2) } as const

    store.showLimit(hit)
    expect(store.limitHit).toEqual(hit)

    store.dismissPaywall()
    expect(store.limitHit).toBeNull()
  })
})
