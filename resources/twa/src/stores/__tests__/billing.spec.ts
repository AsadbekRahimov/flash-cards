import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { ME_FRESH_MS, useBillingStore } from '../billing'
import type { MeResponse, PracticeAccess } from '@/api/types'
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

    it('does not send a second request while one is in flight, forced or not', async () => {
      let finish: (value: MeResponse) => void = () => {}
      vi.mocked(api.me.get).mockReturnValue(new Promise((resolve) => (finish = resolve)))
      const billing = useBillingStore()

      const first = billing.load()
      await billing.load()
      await billing.load({ force: true })
      finish(me())
      await first

      expect(api.me.get).toHaveBeenCalledTimes(1)
      expect(billing.loading).toBe(false)
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
