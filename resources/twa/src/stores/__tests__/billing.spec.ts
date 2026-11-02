import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { ME_FRESH_MS, useBillingStore } from '../billing'
import type { MeResponse, PracticeAccess, PracticeLimitHit } from '@/api/types'
import { settingsFixture } from '@/api/__tests__/settingsFixture'
import { counter, freeLimits, inHours } from '@/api/__tests__/practiceFixtures'

vi.mock('@/api', () => ({ api: { me: { get: vi.fn() } } }))

const me = (): MeResponse => ({
  learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
  groups: [],
  stats: { words_learned: 0, words_due_today: 0 },
  entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 20 },
  paywall: { mode: 'coming_soon', contact: '@lexiflow_help' },
  settings: settingsFixture(),
})

describe('billing store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })
  afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
  })

  it('loads the plan and the paywall settings from /me', async () => {
    vi.mocked(api.me.get).mockResolvedValue(me())
    const billing = useBillingStore()

    await billing.load()

    expect(billing.entitlement?.new_words_per_day).toBe(20)
    expect(billing.paywall?.contact).toBe('@lexiflow_help')
    expect(billing.loading).toBe(false)
  })

  it('warns, keeps what it had and does not throw when /me fails', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const billing = useBillingStore()
    billing.apply(me())
    vi.mocked(api.me.get).mockRejectedValue(new ApiClientError('server', 500, 'server_error', 'x'))

    // `apply()` made the data fresh, so only a forced load goes to the server at all.
    await expect(billing.load({ force: true })).resolves.toBeUndefined()

    expect(warn).toHaveBeenCalledTimes(1)
    expect(warn.mock.calls[0]?.[0]).toContain('billing')
    expect(billing.entitlement?.new_words_per_day).toBe(20)
    expect(billing.loading).toBe(false)
  })

  describe('freshness of /me', () => {
    beforeEach(() => {
      vi.useFakeTimers()
      vi.setSystemTime(new Date('2026-10-28T12:00:00Z'))
      vi.mocked(api.me.get).mockResolvedValue(me())
    })

    it('does not ask /me again while the data is younger than 30 seconds', async () => {
      const billing = useBillingStore()

      await billing.load()
      await billing.load()
      vi.advanceTimersByTime(ME_FRESH_MS - 1)
      await billing.load()

      expect(api.me.get).toHaveBeenCalledTimes(1)
      expect(billing.entitlement?.new_words_per_day).toBe(20)
    })

    it('asks again once the data is 30 seconds old', async () => {
      const billing = useBillingStore()
      await billing.load()

      vi.advanceTimersByTime(ME_FRESH_MS)
      await billing.load()

      expect(api.me.get).toHaveBeenCalledTimes(2)
    })

    it('asks with force even when the data is fresh, and the answer starts a new 30 seconds', async () => {
      const billing = useBillingStore()
      await billing.load()
      vi.advanceTimersByTime(ME_FRESH_MS - 1)

      await billing.load({ force: true })
      expect(api.me.get).toHaveBeenCalledTimes(2)

      vi.advanceTimersByTime(ME_FRESH_MS - 1)
      await billing.load()
      expect(api.me.get).toHaveBeenCalledTimes(2)
    })

    it('does not send a second request while one is in flight, forced or not: whoever asks meanwhile waits for THAT answer', async () => {
      let finish: (value: MeResponse) => void = () => {}
      vi.mocked(api.me.get).mockReturnValue(new Promise((resolve) => (finish = resolve)))
      const billing = useBillingStore()

      const first = billing.load()
      const second = billing.load()
      const forced = billing.load({ force: true })
      const through: string[] = []
      void first.then(() => through.push('first'))
      void second.then(() => through.push('second'))
      void forced.then(() => through.push('forced'))
      await vi.advanceTimersByTimeAsync(0)
      expect(through, 'nobody goes on before the answer').toEqual([])
      expect(billing.entitlement).toBeNull()

      finish(me())
      await Promise.all([first, second, forced])

      expect(through).toHaveLength(3)
      expect(api.me.get).toHaveBeenCalledTimes(1)
      expect(billing.entitlement?.new_words_per_day).toBe(20) // all of them find the answer in the store
      expect(billing.loading).toBe(false)
    })

    it('a request in flight that fails lets everybody go on without a throw, and nothing is fresh', async () => {
      const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
      let fail: (reason: unknown) => void = () => {}
      vi.mocked(api.me.get).mockReturnValue(new Promise((_, reject) => (fail = reject)))
      const billing = useBillingStore()

      const first = billing.load()
      const forced = billing.load({ force: true })
      fail(new ApiClientError('network', 0, 'network', 'x'))

      await expect(Promise.all([first, forced])).resolves.toEqual([undefined, undefined])
      expect(warn).toHaveBeenCalledTimes(1)
      expect(api.me.get).toHaveBeenCalledTimes(1)
      expect(billing.loadedAt).toBeNull()
      expect(billing.loading).toBe(false)
    })

    it('forgets the request once it has come back: the next forced load asks again, and is waited for in its turn', async () => {
      const billing = useBillingStore()
      await billing.load()

      let finish: (value: MeResponse) => void = () => {}
      vi.mocked(api.me.get).mockReturnValue(new Promise((resolve) => (finish = resolve)))
      const again = billing.load({ force: true })
      const joined = billing.load()
      finish(me())
      await Promise.all([again, joined])

      expect(api.me.get).toHaveBeenCalledTimes(2)
    })

    it('never calls the data fresh after a failed request: a forced load that fails makes the next one ask', async () => {
      vi.spyOn(console, 'warn').mockImplementation(() => {})
      const billing = useBillingStore()
      await billing.load()
      vi.mocked(api.me.get).mockRejectedValue(new ApiClientError('network', 0, 'network', 'x'))

      await billing.load({ force: true })
      expect(billing.entitlement?.new_words_per_day).toBe(20) // still shown
      expect(billing.loadedAt).toBeNull()

      vi.mocked(api.me.get).mockResolvedValue(me())
      await billing.load()
      expect(api.me.get).toHaveBeenCalledTimes(3)
    })

    it('asks again after a failure on the very first load', async () => {
      vi.spyOn(console, 'warn').mockImplementation(() => {})
      vi.mocked(api.me.get).mockRejectedValueOnce(new ApiClientError('server', 500, 'server_error', 'x'))
      const billing = useBillingStore()

      await billing.load()
      await billing.load()

      expect(api.me.get).toHaveBeenCalledTimes(2)
      expect(billing.entitlement).not.toBeNull()
    })

    it('counts an answer given to apply() as fresh (promo, cancel / resume)', async () => {
      const billing = useBillingStore()

      billing.apply(me())
      await billing.load()
      expect(api.me.get).not.toHaveBeenCalled()

      vi.advanceTimersByTime(ME_FRESH_MS)
      await billing.load()
      expect(api.me.get).toHaveBeenCalledTimes(1)
    })

    it('invalidate() makes the next load ask, however young the data is', async () => {
      const billing = useBillingStore()
      await billing.load()

      billing.invalidate()
      await billing.load()

      expect(api.me.get).toHaveBeenCalledTimes(2)
    })
  })
})

describe('billing store - the Free limits of practice and battles (docs/18 §3)', () => {
  const access = (limits?: PracticeAccess['limits']): PracticeAccess => ({
    available: true,
    pro_required: false,
    modes: ['pairs', 'collocation', 'sentence', 'dictation'],
    battle: true,
    ...(limits !== undefined ? { limits } : {}),
  })
  const meWith = (practice?: PracticeAccess): MeResponse => ({ ...me(), ...(practice ? { practice } : {}) })

  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })
  afterEach(() => vi.useRealTimers())

  it('knows nothing until somebody says: nothing is shown and nothing is stopped', () => {
    const billing = useBillingStore()

    expect(billing.limits).toBeNull()
    expect(billing.sessionLimit).toBeNull()
    expect(billing.battleLimit).toBeNull()
    expect(billing.limitReached('sessions')).toBeNull()
    expect(billing.limitReached('battles')).toBeNull()
  })

  it('takes the limits from /me, next to the access to practice, and does not keep a second copy of them there', async () => {
    vi.mocked(api.me.get).mockResolvedValue(meWith(access(freeLimits(1, 0))))
    const billing = useBillingStore()

    await billing.load()

    expect(billing.sessionLimit).toMatchObject({ limit: 2, used: 1, remaining: 1 })
    expect(billing.battleLimit).toMatchObject({ limit: 1, used: 0, remaining: 1 })
    expect(billing.practiceAccess).toEqual(access())
    expect('limits' in billing.practiceAccess).toBe(false)
  })

  it('Pro has none: /me says null, and the counters the learner had are gone', () => {
    const billing = useBillingStore()
    billing.apply(meWith(access(freeLimits(2, 1))))
    expect(billing.limitReached('sessions')).not.toBeNull()

    billing.apply(meWith(access(null)))

    expect(billing.limits).toBeNull()
    expect(billing.sessionLimit).toBeNull()
    expect(billing.limitReached('sessions')).toBeNull()
  })

  it('an answer that does not mention the limits (an older server, a /me without the block) leaves what the learner was told', () => {
    const billing = useBillingStore()
    billing.setLimits(freeLimits(1, 0))

    billing.apply(meWith(access()))
    billing.apply(meWith())

    expect(billing.sessionLimit).toMatchObject({ used: 1, remaining: 1 })
    expect(billing.practiceAccess.available).toBe(false) // a /me with no block still means "no practice", as before
  })

  describe('setLimits (the hub and the 201 of a session or a battle)', () => {
    it('replaces the counters with the newest word on them', () => {
      const billing = useBillingStore()
      billing.setLimits(freeLimits(0, 0))

      billing.setLimits(freeLimits(1, 1))

      expect(billing.sessionLimit?.remaining).toBe(1)
      expect(billing.battleLimit?.remaining).toBe(0)
    })

    it('null is a word too (Pro, no limit); undefined is no word', () => {
      const billing = useBillingStore()
      billing.setLimits(freeLimits(1, 0))

      billing.setLimits(undefined)
      expect(billing.sessionLimit?.remaining).toBe(1)

      billing.setLimits(null)
      expect(billing.sessionLimit).toBeNull()
    })
  })

  describe('limitReached', () => {
    it('is the wall of a kind that is spent, with the numbers the paywall quotes', () => {
      const billing = useBillingStore()
      const resetsAt = inHours(5)
      billing.setLimits(freeLimits(2, 0, resetsAt))

      expect(billing.limitReached('sessions')).toEqual({ kind: 'sessions', limit: 2, used: 2, resets_at: resetsAt })
      expect(billing.limitReached('battles')).toBeNull()
    })

    it('is the wall from the start for a kind that is for Pro only (limit 0): there is nothing to spend', () => {
      const billing = useBillingStore()
      const resetsAt = inHours(5)
      billing.setLimits({ sessions: counter(0, 0, resetsAt), battles: counter(0, 0, resetsAt) })

      expect(billing.limitReached('sessions')).toEqual({ kind: 'sessions', limit: 0, used: 0, resets_at: resetsAt })
      expect(billing.limitReached('battles')).toEqual({ kind: 'battles', limit: 0, used: 0, resets_at: resetsAt })
    })

    it('is nothing once the day of the counter is over: the app stayed open overnight, and the server decides', () => {
      vi.useFakeTimers()
      vi.setSystemTime(new Date('2026-11-02T10:00:00Z'))
      const billing = useBillingStore()
      billing.setLimits(freeLimits(2, 1, '2026-11-02T19:00:00Z'))
      expect(billing.limitReached('sessions')).not.toBeNull()

      vi.setSystemTime(new Date('2026-11-02T19:00:01Z'))

      expect(billing.limitReached('sessions')).toBeNull()
      expect(billing.limitReached('battles')).toBeNull()
    })
  })

  describe('the counters that are shown', () => {
    it('are the counters of today, and nothing for a day that is over', () => {
      vi.useFakeTimers()
      vi.setSystemTime(new Date('2026-11-02T10:00:00Z'))
      const billing = useBillingStore()

      billing.setLimits({ sessions: counter(1, 2, '2026-11-02T19:00:00Z'), battles: counter(0, 1, '2026-11-02T19:00:00Z') })
      expect(billing.sessionLimit).toMatchObject({ remaining: 1 })

      // a getter is computed when it is read after the counters changed: a fresh answer of the day after is read again
      billing.setLimits({ sessions: counter(1, 2, '2026-11-02T09:00:00Z'), battles: counter(0, 1, '2026-11-02T09:00:00Z') })
      expect(billing.sessionLimit).toBeNull()
      expect(billing.battleLimit).toBeNull()
    })
  })

  // The getters read `now`, which a timer moves at the end of the day of the nearest counter: a screen left open overnight redraws by itself.
  describe('the end of the day of the counters (an app left open overnight)', () => {
    const HOUR = 3_600_000
    const NOON = new Date('2026-11-02T10:00:00Z')
    const EVENING = '2026-11-02T19:00:00Z' // nine hours later

    beforeEach(() => {
      vi.useFakeTimers()
      vi.setSystemTime(NOON)
    })

    it('the counters go by themselves when their day ends: no answer comes, nothing is touched', () => {
      const billing = useBillingStore()
      billing.setLimits(freeLimits(1, 0, EVENING))
      expect(billing.sessionLimit).toMatchObject({ remaining: 1 })
      expect(billing.battleLimit).toMatchObject({ remaining: 1 })

      vi.advanceTimersByTime(9 * HOUR - 1)
      expect(billing.sessionLimit, 'a moment before the end of the day').not.toBeNull()

      vi.advanceTimersByTime(1 + 200)
      expect(billing.sessionLimit).toBeNull()
      expect(billing.battleLimit).toBeNull()
      expect(billing.limitReached('sessions')).toBeNull()
    })

    it('the nearest day ends first: the other counter stays until its own end', () => {
      const billing = useBillingStore()
      billing.setLimits({ sessions: counter(1, 2, '2026-11-02T12:00:00Z'), battles: counter(0, 1, '2026-11-02T15:00:00Z') })
      expect(billing.sessionLimit).not.toBeNull()
      expect(billing.battleLimit).not.toBeNull()

      vi.advanceTimersByTime(2 * HOUR + 500)
      expect(billing.sessionLimit).toBeNull()
      expect(billing.battleLimit).not.toBeNull()

      vi.advanceTimersByTime(3 * HOUR)
      expect(billing.battleLimit).toBeNull()
    })

    it('/me brings the counters and their end is waited for as well', async () => {
      vi.mocked(api.me.get).mockResolvedValue(meWith(access(freeLimits(2, 1, EVENING))))
      const billing = useBillingStore()
      await billing.load()
      expect(billing.sessionLimit).toMatchObject({ remaining: 0 })

      vi.advanceTimersByTime(9 * HOUR + 500)

      expect(billing.sessionLimit).toBeNull()
      expect(billing.battleLimit).toBeNull()
    })

    it('a refusal of the server counts too: the counter it makes goes at the end of its own day', () => {
      const billing = useBillingStore()
      billing.noteLimitHit({ kind: 'battles', limit: 1, used: 1, resets_at: '2026-11-02T12:00:00Z' })
      expect(billing.battleLimit).not.toBeNull()

      vi.advanceTimersByTime(2 * HOUR + 500)

      expect(billing.battleLimit).toBeNull()
    })

    it('the next day starts afresh: a new word after the end shows its counter, and that one is waited for in its turn', () => {
      const billing = useBillingStore()
      billing.setLimits(freeLimits(1, 0, EVENING))
      vi.advanceTimersByTime(9 * HOUR + 500)
      expect(billing.sessionLimit).toBeNull()

      billing.setLimits(freeLimits(0, 0, '2026-11-03T19:00:00Z'))
      expect(billing.sessionLimit).toMatchObject({ remaining: 2 })

      vi.advanceTimersByTime(24 * HOUR)
      expect(billing.sessionLimit).toBeNull()
    })

    it('keeps one timer: every new word on the counters replaces the one before', () => {
      const billing = useBillingStore()

      billing.setLimits(freeLimits(0, 0, EVENING))
      billing.setLimits(freeLimits(1, 0, EVENING))
      billing.noteLimitHit({ kind: 'sessions', limit: 2, used: 2, resets_at: EVENING })
      billing.forgetLimit('battles')

      expect(vi.getTimerCount()).toBe(1)
    })

    it.each([
      ['Pro (null)', null],
      ['no limit of either kind', { sessions: null, battles: null }],
      ['counters whose day is over', freeLimits(2, 1, '2026-11-02T09:00:00Z')],
    ] as const)('has nothing to wait for with %s: the timer is taken away', (_, limits) => {
      const billing = useBillingStore()
      billing.setLimits(freeLimits(0, 0, EVENING))
      expect(vi.getTimerCount()).toBe(1)

      billing.setLimits(limits)

      expect(vi.getTimerCount()).toBe(0)
    })

    it('dropping the last counter takes the wait away, and a store that never had counters waits for nothing', () => {
      const billing = useBillingStore()
      billing.apply(meWith(access()))
      expect(vi.getTimerCount()).toBe(0)

      billing.setLimits({ sessions: null, battles: counter(0, 1, EVENING) })
      expect(vi.getTimerCount()).toBe(1)
      billing.forgetLimit('battles')

      expect(vi.getTimerCount()).toBe(0)
    })

    it('a day that ends in more than a timer can wait for (about 24 days) is waited for in steps, never at once', () => {
      const spy = vi.spyOn(globalThis, 'setTimeout')
      const billing = useBillingStore()

      billing.setLimits(freeLimits(0, 0, new Date(NOON.getTime() + 40 * 24 * HOUR).toISOString()))

      expect(spy).toHaveBeenCalledTimes(1)
      expect(spy.mock.calls[0]?.[1]).toBeLessThanOrEqual(2 ** 31 - 1)
      vi.advanceTimersByTime(1000)
      expect(billing.sessionLimit, 'not at once').not.toBeNull()

      vi.advanceTimersByTime(25 * 24 * HOUR) // the first step is over: the store looked again and waits on
      expect(billing.sessionLimit).not.toBeNull()
      expect(vi.getTimerCount()).toBe(1)

      vi.advanceTimersByTime(16 * 24 * HOUR)
      expect(billing.sessionLimit).toBeNull()
      expect(vi.getTimerCount()).toBe(0)
      spy.mockRestore()
    })

    it('a limit of 0 has no counter to draw at any time, and its wall stands until the day ends', () => {
      const billing = useBillingStore()
      billing.setLimits({ sessions: null, battles: counter(0, 0, EVENING) })
      expect(billing.battleLimit).toBeNull()
      expect(billing.limitReached('battles')).not.toBeNull()

      vi.advanceTimersByTime(9 * HOUR + 500)

      expect(billing.limitReached('battles')).toBeNull()
    })
  })

  // A limit of 0 is "only Pro": "0 of 0 left" says nothing, so the screens get no counter for it - while the wall (`limitReached`) stands.
  describe('a kind that is for Pro only (limit 0)', () => {
    const proOnly = () => ({ sessions: counter(0, 0, inHours(5)), battles: counter(0, 0, inHours(5)) })

    it('draws no counter, and stops the learner all the same', () => {
      const billing = useBillingStore()

      billing.setLimits(proOnly())

      expect(billing.sessionLimit).toBeNull()
      expect(billing.battleLimit).toBeNull()
      expect(billing.limitReached('sessions')).toMatchObject({ kind: 'sessions', limit: 0 })
      expect(billing.limitReached('battles')).toMatchObject({ kind: 'battles', limit: 0 })
    })

    it('takes it from /me the same way', async () => {
      vi.mocked(api.me.get).mockResolvedValue(meWith(access(proOnly())))
      const billing = useBillingStore()

      await billing.load()

      expect(billing.sessionLimit).toBeNull()
      expect(billing.battleLimit).toBeNull()
      expect(billing.limitReached('battles')).not.toBeNull()
    })

    it('is told apart per kind: battles only for Pro, sessions with a counter', () => {
      const billing = useBillingStore()

      billing.setLimits({ sessions: counter(1, 2, inHours(5)), battles: counter(0, 0, inHours(5)) })

      expect(billing.sessionLimit).toMatchObject({ limit: 2, remaining: 1 })
      expect(billing.battleLimit).toBeNull()
      expect(billing.limitReached('sessions')).toBeNull()
      expect(billing.limitReached('battles')).toMatchObject({ limit: 0 })
    })

    it('a refusal for it draws no counter either, but is the wall the paywall quotes', () => {
      const billing = useBillingStore()
      const resetsAt = inHours(2)

      billing.noteLimitHit({ kind: 'battles', limit: 0, used: 0, resets_at: resetsAt })

      expect(billing.battleLimit).toBeNull()
      expect(billing.limitReached('battles')).toEqual({ kind: 'battles', limit: 0, used: 0, resets_at: resetsAt })
    })

    it('a limit that was raised on the server draws its counter again with the next answer', () => {
      const billing = useBillingStore()
      billing.setLimits(proOnly())

      billing.setLimits(freeLimits(0, 0))

      expect(billing.sessionLimit).toMatchObject({ limit: 2, remaining: 2 })
      expect(billing.battleLimit).toMatchObject({ limit: 1, remaining: 1 })
      expect(billing.limitReached('battles')).toBeNull()
    })
  })

  describe('noteLimitHit (the server refused for the limit)', () => {
    it('marks that counter spent whatever the screen believed, and leaves the other one alone', () => {
      const billing = useBillingStore()
      billing.setLimits(freeLimits(0, 0))
      const resetsAt = inHours(2)

      billing.noteLimitHit({ kind: 'sessions', limit: 2, used: 2, resets_at: resetsAt })

      expect(billing.sessionLimit).toEqual({ limit: 2, used: 2, remaining: 0, resets_at: resetsAt })
      expect(billing.battleLimit).toMatchObject({ remaining: 1 })
      expect(billing.limitReached('sessions')).toEqual({ kind: 'sessions', limit: 2, used: 2, resets_at: resetsAt })
    })

    it('takes the numbers of the refusal: the limit may have been changed since the counters were read', () => {
      const billing = useBillingStore()
      billing.setLimits(freeLimits(0, 0))
      const resetsAt = inHours(2)

      billing.noteLimitHit({ kind: 'battles', limit: 3, used: 3, resets_at: resetsAt })

      expect(billing.battleLimit).toEqual({ limit: 3, used: 3, remaining: 0, resets_at: resetsAt })
    })

    it('builds the counter of the kind that refused when none were told at all (the learner was taken for Pro): the other kind stays without one', () => {
      const billing = useBillingStore()
      const resetsAt = inHours(2)

      billing.noteLimitHit({ kind: 'sessions', limit: 2, used: 2, resets_at: resetsAt })

      expect(billing.limits).toEqual({ sessions: { limit: 2, used: 2, remaining: 0, resets_at: resetsAt }, battles: null })
      expect(billing.sessionLimit).toMatchObject({ remaining: 0 })
      expect(billing.battleLimit).toBeNull()
      expect(billing.limitReached('sessions')).not.toBeNull()
      expect(billing.limitReached('battles')).toBeNull()
    })

    it('turns a kind that had no counter into a spent one: the server has the last word on its own limit', () => {
      const billing = useBillingStore()
      billing.setLimits({ sessions: null, battles: counter(0, 1) })
      const resetsAt = inHours(2)

      billing.noteLimitHit({ kind: 'sessions', limit: 3, used: 3, resets_at: resetsAt })

      expect(billing.sessionLimit).toEqual({ limit: 3, used: 3, remaining: 0, resets_at: resetsAt })
      expect(billing.battleLimit).toMatchObject({ remaining: 1 })
    })
  })

  // An empty env of the server means that kind has no limit: `limits` carries `null` for it and the other kind keeps its counter.
  describe('a kind without a limit (null inside the limits)', () => {
    const onlyBattles = () => ({ sessions: null, battles: counter(0, 1, inHours(5)) })
    const onlySessions = () => ({ sessions: counter(1, 2, inHours(5)), battles: null })

    it('takes it from /me: nothing for that kind, the counter of the other one as it is', async () => {
      vi.mocked(api.me.get).mockResolvedValue(meWith(access(onlyBattles())))
      const billing = useBillingStore()

      await billing.load()

      expect(billing.sessionLimit).toBeNull()
      expect(billing.battleLimit).toMatchObject({ limit: 1, remaining: 1 })
      expect(billing.practiceAccess).toEqual(access())
    })

    it('stops nothing of that kind, and the other kind is stopped by its own counter only', () => {
      const billing = useBillingStore()
      billing.setLimits({ sessions: null, battles: counter(1, 1, inHours(5)) })

      expect(billing.limitReached('sessions')).toBeNull()
      expect(billing.limitReached('battles')).not.toBeNull()

      billing.setLimits({ sessions: counter(2, 2, inHours(5)), battles: null })

      expect(billing.limitReached('sessions')).not.toBeNull()
      expect(billing.limitReached('battles')).toBeNull()
    })

    it('shows the counter of the other kind alone', () => {
      const billing = useBillingStore()

      billing.setLimits(onlySessions())

      expect(billing.sessionLimit).toMatchObject({ limit: 2, used: 1, remaining: 1 })
      expect(billing.battleLimit).toBeNull()
    })

    it('both kinds without a limit are as good as no limits: nothing shown, nothing stopped', () => {
      const billing = useBillingStore()

      billing.setLimits({ sessions: null, battles: null })

      expect(billing.sessionLimit).toBeNull()
      expect(billing.battleLimit).toBeNull()
      expect(billing.limitReached('sessions')).toBeNull()
      expect(billing.limitReached('battles')).toBeNull()
    })

    it('a later answer replaces the whole picture: a limit that was switched on, or off, on the server', () => {
      const billing = useBillingStore()
      billing.setLimits(onlyBattles())

      billing.setLimits(onlySessions())

      expect(billing.sessionLimit).not.toBeNull()
      expect(billing.battleLimit).toBeNull()
    })
  })

  // A battle that did not take place is not counted: its place comes back on the server, while the screens hold the number from before.
  describe('forgetLimit', () => {
    it('drops the counter of that kind alone - nothing shown, nothing stopped - and makes the next /me ask the server', () => {
      const billing = useBillingStore()
      billing.apply(meWith(access(freeLimits(1, 1))))
      expect(billing.loadedAt).not.toBeNull()

      billing.forgetLimit('battles')

      expect(billing.battleLimit).toBeNull()
      expect(billing.limitReached('battles')).toBeNull()
      expect(billing.sessionLimit).toMatchObject({ used: 1, remaining: 1 })
      expect(billing.loadedAt).toBeNull()
    })

    it('the counter comes back with the next reading of /me', async () => {
      const billing = useBillingStore()
      billing.apply(meWith(access(freeLimits(0, 1))))
      billing.forgetLimit('battles')
      vi.mocked(api.me.get).mockResolvedValue(meWith(access(freeLimits(0, 0))))

      await billing.load()

      expect(api.me.get).toHaveBeenCalledTimes(1)
      expect(billing.battleLimit).toMatchObject({ limit: 1, remaining: 1 })
    })

    it('has nothing to drop for a learner without counters (Pro), but still makes /me ask', () => {
      const billing = useBillingStore()
      billing.apply(meWith(access(null)))

      billing.forgetLimit('battles')

      expect(billing.limits).toBeNull()
      expect(billing.loadedAt).toBeNull()
    })
  })

  // The wall that the SERVER stands behind: a zero on the screen may be old, so /me is read again before the paywall.
  describe('confirmLimit', () => {
    beforeEach(() => vi.spyOn(console, 'warn').mockImplementation(() => {}))

    it('is nothing, without a question, while some place is left, for a kind without a counter and for Pro', async () => {
      const billing = useBillingStore()

      billing.setLimits(freeLimits(1, 0))
      expect(await billing.confirmLimit('sessions')).toBeNull()
      expect(await billing.confirmLimit('battles')).toBeNull()

      billing.setLimits({ sessions: null, battles: null })
      expect(await billing.confirmLimit('battles')).toBeNull()

      billing.setLimits(null)
      expect(await billing.confirmLimit('battles')).toBeNull()
      expect(api.me.get).not.toHaveBeenCalled()
    })

    it('reads /me again (the answer to a question, not the cache) and is the wall the fresh counter describes', async () => {
      const billing = useBillingStore()
      const resetsAt = inHours(5)
      billing.apply(meWith(access(freeLimits(2, 1, resetsAt)))) // fresh in the cache: a plain load() would not ask
      vi.mocked(api.me.get).mockResolvedValue(meWith(access(freeLimits(2, 1, resetsAt))))

      expect(await billing.confirmLimit('battles')).toEqual({ kind: 'battles', limit: 1, used: 1, resets_at: resetsAt })

      expect(api.me.get).toHaveBeenCalledTimes(1)
    })

    it('is nothing when the fresh counter has a place: the server gave it back', async () => {
      const billing = useBillingStore()
      billing.apply(meWith(access(freeLimits(0, 1))))
      vi.mocked(api.me.get).mockResolvedValue(meWith(access(freeLimits(0, 0))))

      expect(await billing.confirmLimit('battles')).toBeNull()

      expect(billing.battleLimit).toMatchObject({ remaining: 1 })
    })

    it('is nothing when /me cannot be read: the number cannot be checked, so the action itself goes to the server', async () => {
      const billing = useBillingStore()
      billing.apply(meWith(access(freeLimits(0, 1))))
      vi.mocked(api.me.get).mockRejectedValue(new ApiClientError('network', 0, 'network', 'x'))

      expect(await billing.confirmLimit('battles')).toBeNull()

      expect(billing.battleLimit).toMatchObject({ remaining: 0 }) // what it knew is not thrown away
    })

    // The screen began its own reading of /me (it asks at its start), and the learner pressed before the answer came. The stale zero must not
    // decide: that reading is the newest word there is, so it is waited for - not skipped, and not asked again.
    describe('a /me that is already on its way', () => {
      const onItsWay = () => {
        let answer: (value: MeResponse) => void = () => {}
        vi.mocked(api.me.get).mockReturnValue(new Promise((resolve) => (answer = resolve)))
        return (value: MeResponse) => answer(value)
      }
      const decide = (billing: ReturnType<typeof useBillingStore>) => {
        const result: { hit: PracticeLimitHit | null | undefined } = { hit: undefined }
        const done = billing.confirmLimit('battles').then((hit) => {
          result.hit = hit
        })
        return { result, done }
      }

      it('is waited for, and its counter decides: the place it gives back is a place - no wall, no second request', async () => {
        const billing = useBillingStore()
        const resetsAt = inHours(5)
        billing.setLimits(freeLimits(0, 1, resetsAt)) // the zero the screen remembers
        const answer = onItsWay()
        const reading = billing.load() // the screen's own reading at its start

        const { result, done } = decide(billing)
        await flushPromises()
        expect(result.hit, 'the old zero does not decide before the answer').toBeUndefined()

        answer(meWith(access(freeLimits(0, 0, resetsAt)))) // the server has given the place back
        await Promise.all([done, reading])

        expect(result.hit).toBeNull()
        expect(api.me.get).toHaveBeenCalledTimes(1)
        expect(billing.battleLimit).toMatchObject({ remaining: 1 })
      })

      it('and the wall stands when that answer still says none: with the numbers of the fresh counter', async () => {
        const billing = useBillingStore()
        const resetsAt = inHours(5)
        billing.setLimits(freeLimits(0, 1, resetsAt))
        const answer = onItsWay()
        void billing.load()

        const { result, done } = decide(billing)
        answer(meWith(access(freeLimits(0, 1, resetsAt))))
        await done

        expect(result.hit).toEqual({ kind: 'battles', limit: 1, used: 1, resets_at: resetsAt })
        expect(api.me.get).toHaveBeenCalledTimes(1)
      })

      it('is no wall when it fails: nothing can be checked, so the action goes to the server', async () => {
        const billing = useBillingStore()
        billing.setLimits(freeLimits(0, 1))
        let fail: (reason: unknown) => void = () => {}
        vi.mocked(api.me.get).mockReturnValue(new Promise((_, reject) => (fail = reject)))
        void billing.load()

        const { result, done } = decide(billing)
        fail(new ApiClientError('network', 0, 'network', 'x'))
        await done

        expect(result.hit).toBeNull()
        expect(api.me.get).toHaveBeenCalledTimes(1)
      })

      it('is not waited for when no wall is remembered: the action goes to the server at once', async () => {
        const billing = useBillingStore()
        billing.setLimits(freeLimits(0, 0))
        onItsWay()
        void billing.load()

        expect(await billing.confirmLimit('battles')).toBeNull()
      })

      it('is waited for by a screen that asks while it is on its way, and a screen that asks after it has come back asks again', async () => {
        const billing = useBillingStore()
        billing.setLimits(freeLimits(0, 1))
        const answer = onItsWay()
        void billing.load()
        const { done } = decide(billing)
        answer(meWith(access(freeLimits(0, 1))))
        await done
        expect(api.me.get).toHaveBeenCalledTimes(1)

        vi.mocked(api.me.get).mockResolvedValue(meWith(access(freeLimits(0, 0))))
        expect(await billing.confirmLimit('battles')).toBeNull()

        expect(api.me.get).toHaveBeenCalledTimes(2)
      })
    })

    it('needs no question for a kind that is for Pro only (limit 0): nothing gives a place back there', async () => {
      const billing = useBillingStore()
      billing.setLimits({ sessions: counter(0, 0, inHours(5)), battles: counter(0, 0, inHours(5)) })

      expect(await billing.confirmLimit('battles')).toMatchObject({ kind: 'battles', limit: 0 })
      expect(await billing.confirmLimit('sessions')).toMatchObject({ kind: 'sessions', limit: 0 })

      expect(api.me.get).not.toHaveBeenCalled()
      expect(billing.battleLimit, 'no line for it: it is only for Pro').toBeNull()
      expect(billing.sessionLimit).toBeNull()
    })
  })
})
