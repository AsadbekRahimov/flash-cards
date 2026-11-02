import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { usePracticeStore } from '../practice'
import { useBillingStore } from '../billing'
import { finishedSession, freeLimits, hub, inHours, limitDetails, session } from '@/api/__tests__/practiceFixtures'

vi.mock('@/api', () => ({
  api: { practice: { hub: vi.fn(), open: vi.fn(), get: vi.fn(), answer: vi.fn(), replace: vi.fn() } },
}))

/**
 * The Free limits of practice (docs/18 §3, G1) in the store of the solo practice: the counters it brings to the billing store, the wall
 * it opens without a request when the counter says the free sessions of today are spent, and the 403 `practice_limit_reached`.
 */

const apiError = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)
const offline = () => apiError('network', 0, 'network')

const request = { mode: 'pairs', course: 'english-a1', lesson_id: 41, source: 'hub' } as const

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
})
afterEach(() => {
  vi.useRealTimers()
  setLocale('ru')
})

describe('practice store — the counters it brings to the billing store', () => {
  it('the hub says them, and a hub that does not mention them leaves what the learner was told', async () => {
    const billing = useBillingStore()
    const store = usePracticeStore()

    vi.mocked(api.practice.hub).mockResolvedValue(hub({ limits: freeLimits(1, 0) }))
    await store.loadHub('english-a1', 41)
    expect(billing.sessionLimit).toMatchObject({ limit: 2, used: 1, remaining: 1 })

    vi.mocked(api.practice.hub).mockResolvedValue(hub())
    await store.loadHub('english-a1', 41)
    expect(billing.sessionLimit).toMatchObject({ remaining: 1 })
  })

  it('Pro has none: a hub with `limits: null` clears them', async () => {
    const billing = useBillingStore()
    billing.setLimits(freeLimits(2, 1))
    vi.mocked(api.practice.hub).mockResolvedValue(hub({ limits: null }))

    await usePracticeStore().loadHub('english-a1', 41)

    expect(billing.limits).toBeNull()
  })

  it('an older hub that comes late says nothing: the counters of the newer one stay', async () => {
    const billing = useBillingStore()
    const store = usePracticeStore()
    let late!: (value: ReturnType<typeof hub>) => void
    vi.mocked(api.practice.hub).mockReturnValueOnce(new Promise((resolve) => (late = resolve)))
    const first = store.loadHub('english-a1', 41)
    vi.mocked(api.practice.hub).mockResolvedValueOnce(hub({ limits: freeLimits(1, 0) }))
    await store.loadHub('english-a1', 41)

    late(hub({ limits: freeLimits(0, 0) }))
    await first

    expect(billing.sessionLimit).toMatchObject({ used: 1, remaining: 1 })
  })

  it('the 201 of a session says them as they are after it', async () => {
    const billing = useBillingStore()
    billing.setLimits(freeLimits(0, 0))
    vi.mocked(api.practice.open).mockResolvedValue(session({ id: 78, limits: freeLimits(1, 0) }))

    expect(await usePracticeStore().open(request)).toBe(78)

    expect(billing.sessionLimit).toMatchObject({ used: 1, remaining: 1 })
  })

  it('a 201 of an older server, which says none, changes nothing; one with `null` (Pro) clears them', async () => {
    const billing = useBillingStore()
    const store = usePracticeStore()
    billing.setLimits(freeLimits(1, 0))

    vi.mocked(api.practice.open).mockResolvedValue(session({ id: 78 }))
    await store.open(request)
    expect(billing.sessionLimit).toMatchObject({ remaining: 1 })

    vi.mocked(api.practice.open).mockResolvedValue(session({ id: 79, limits: null }))
    await store.open(request)
    expect(billing.limits).toBeNull()
  })

  it('the counters of a session that was opened by a screen that is gone count as well: the session exists', async () => {
    const billing = useBillingStore()
    const store = usePracticeStore()
    let finish!: (value: ReturnType<typeof session>) => void
    vi.mocked(api.practice.open).mockReturnValue(new Promise((resolve) => (finish = resolve)))
    const opening = store.open(request)

    store.leaveOpen()
    finish(session({ id: 80, limits: freeLimits(2, 0) }))
    await opening

    expect(billing.sessionLimit).toMatchObject({ used: 2, remaining: 0 })
  })
})

describe('practice store — when the counter says the free sessions of today are spent', () => {
  it('opens the wall at once, without a request, with the numbers the paywall quotes', async () => {
    const resetsAt = inHours(4)
    useBillingStore().setLimits(freeLimits(2, 0, resetsAt))
    const store = usePracticeStore()

    expect(await store.open(request)).toBeNull()

    expect(api.practice.open).not.toHaveBeenCalled()
    expect(store.limitHit).toEqual({ kind: 'sessions', limit: 2, used: 2, resets_at: resetsAt })
    expect(store.opening).toBeNull()
    expect(store.openError).toBe('')
    expect(store.paywall).toBe(false)
    expect(store.conflict).toBeNull()
  })

  it('asks as usual while something is left (spent battles are another counter), for Pro and for a learner whose limits are not known', async () => {
    vi.mocked(api.practice.open).mockResolvedValue(session({ id: 81 }))
    const billing = useBillingStore()
    const store = usePracticeStore()

    billing.setLimits(freeLimits(1, 1))
    expect(await store.open(request)).toBe(81)

    billing.setLimits(null)
    expect(await store.open(request)).toBe(81)

    billing.limits = null
    expect(await store.open(request)).toBe(81)
    expect(api.practice.open).toHaveBeenCalledTimes(3)
    expect(store.limitHit).toBeNull()
  })

  it('asks once the day of the counter is over: the app stayed open overnight, and the server decides', async () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-11-02T10:00:00Z'))
    useBillingStore().setLimits(freeLimits(2, 0, '2026-11-02T19:00:00Z'))
    vi.mocked(api.practice.open).mockResolvedValue(session({ id: 82, limits: freeLimits(1, 0, '2026-11-03T19:00:00Z') }))
    const store = usePracticeStore()

    expect(await store.open(request)).toBeNull()
    expect(store.limitHit).not.toBeNull()

    vi.setSystemTime(new Date('2026-11-02T19:00:01Z'))
    expect(await store.open(request)).toBe(82)
    expect(store.limitHit).toBeNull()
    expect(useBillingStore().sessionLimit).toMatchObject({ remaining: 1 })
  })

  it('stops "Again" of the summary as well: a new session of the same mode is one more session', async () => {
    vi.mocked(api.practice.get).mockResolvedValue(finishedSession())
    const store = usePracticeStore()
    await store.load(77)
    useBillingStore().setLimits(freeLimits(2, 0))

    expect(await store.again()).toBeNull()

    expect(api.practice.open).not.toHaveBeenCalled()
    expect(store.limitHit).toMatchObject({ kind: 'sessions' })
  })

  it('does not stop "Repeat mistakes": it spends nothing, and the server knows it', async () => {
    vi.mocked(api.practice.get).mockResolvedValue(finishedSession())
    const store = usePracticeStore()
    await store.load(77)
    useBillingStore().setLimits(freeLimits(2, 0))
    vi.mocked(api.practice.open).mockResolvedValue(session({ id: 83, source: 'retry', retry_of: 77, limits: freeLimits(2, 0) }))

    expect(await store.retry()).toBe(83)

    expect(vi.mocked(api.practice.open).mock.calls[0]![0]).toMatchObject({ source: 'retry', retry_of: 77 })
    expect(store.limitHit).toBeNull()
  })

  it('a repeat is known by its `retry_of` too, whatever its source says', async () => {
    useBillingStore().setLimits(freeLimits(2, 0))
    vi.mocked(api.practice.open).mockResolvedValue(session({ id: 84 }))

    expect(await usePracticeStore().open({ ...request, retry_of: 77 })).toBe(84)
  })

  it('a new try forgets the wall of the one before; closing the sheet, leaving the screen and entering a session do the same', async () => {
    const billing = useBillingStore()
    const store = usePracticeStore()
    billing.setLimits(freeLimits(2, 0))
    await store.open(request)
    expect(store.limitHit).not.toBeNull()

    store.dismissPaywall()
    expect(store.limitHit).toBeNull()

    await store.open(request)
    store.leaveOpen()
    expect(store.limitHit).toBeNull()

    await store.open(request)
    vi.mocked(api.practice.get).mockResolvedValue(session())
    await store.load(77)
    expect(store.limitHit).toBeNull()

    billing.setLimits(freeLimits(0, 0))
    vi.mocked(api.practice.open).mockResolvedValue(session({ id: 85 }))
    store.showLimit({ kind: 'battles', limit: 1, used: 1, resets_at: inHours(1) })
    expect(await store.open(request)).toBe(85)
    expect(store.limitHit).toBeNull()
  })

  it('shows the wall of another kind when a screen asks for it (the hub: "Play with a friend")', () => {
    const store = usePracticeStore()
    const hit = { kind: 'battles', limit: 1, used: 1, resets_at: inHours(2) } as const

    store.showLimit(hit)

    expect(store.limitHit).toEqual(hit)
  })
})

describe('practice store — 403 practice_limit_reached from the server', () => {
  it('is the wall with the numbers of the refusal - no sentence beside it - and the counter is spent from now on', async () => {
    const resetsAt = inHours(5)
    const billing = useBillingStore()
    billing.setLimits(freeLimits(1, 0)) // the screen believed there was one session left
    vi.mocked(api.practice.open).mockRejectedValue(apiError('forbidden', 403, 'practice_limit_reached', limitDetails('sessions', resetsAt)))
    const store = usePracticeStore()

    expect(await store.open(request)).toBeNull()

    expect(store.limitHit).toEqual({ kind: 'sessions', limit: 2, used: 2, resets_at: resetsAt })
    expect(store.openError).toBe('')
    expect(store.paywall).toBe(false)
    expect(store.opening).toBeNull()
    expect(billing.sessionLimit).toMatchObject({ used: 2, remaining: 0 })
    expect(billing.limitReached('sessions')).not.toBeNull()
  })

  it('is told in words when its details cannot be read: the message of the code, never a wall without numbers', async () => {
    const store = usePracticeStore()

    for (const details of [null, { kind: 'sessions' }, { kind: 'sessions', limit: 2, used: 2, resets_at: 'soon' }]) {
      vi.mocked(api.practice.open).mockRejectedValue(apiError('forbidden', 403, 'practice_limit_reached', details))
      await store.open(request)

      expect(store.limitHit).toBeNull()
      expect(store.openError).toBe('Бесплатный лимит на сегодня закончился. Он обновится завтра.')
    }
  })

  it('says it in Uzbek', async () => {
    setLocale('uz')
    vi.mocked(api.practice.open).mockRejectedValue(apiError('forbidden', 403, 'practice_limit_reached'))
    const store = usePracticeStore()

    await store.open(request)

    expect(store.openError).toBe('Bugungi bepul limit tugadi. U ertaga yangilanadi.')
  })

  it('leaves the store a session and the rest as they were: nothing was opened, nothing is kept', async () => {
    vi.mocked(api.practice.open).mockRejectedValue(apiError('forbidden', 403, 'practice_limit_reached', limitDetails()))
    const store = usePracticeStore()

    await store.open(request)

    expect(store.preloaded).toBeNull()
    expect(store.sessionId).toBeNull()
    expect(store.status).toBe('idle')
  })

  it('is not told to a screen that was left meanwhile', async () => {
    let refuse!: (reason: unknown) => void
    vi.mocked(api.practice.open).mockReturnValue(new Promise((_, reject) => (refuse = reject)))
    const store = usePracticeStore()
    const opening = store.open(request)

    store.leaveOpen()
    refuse(apiError('forbidden', 403, 'practice_limit_reached', limitDetails()))
    await opening

    expect(store.limitHit).toBeNull()
    expect(store.openError).toBe('')
  })

  it('does not touch the other refusals: a lost connection, the Pro wall and a lack of material are told as they were', async () => {
    const store = usePracticeStore()

    vi.mocked(api.practice.open).mockRejectedValue(offline())
    await store.open(request)
    expect(store.openError).toBe('Нет связи с сервером. Попробуйте ещё раз.')
    expect(store.limitHit).toBeNull()

    vi.mocked(api.practice.open).mockRejectedValue(apiError('forbidden', 403, 'practice_pro_required'))
    await store.open(request)
    expect(store.paywall).toBe(true)
    expect(store.limitHit).toBeNull()

    vi.mocked(api.practice.open).mockRejectedValue(apiError('validation', 422, 'practice_not_enough_material'))
    await store.open(request)
    expect(store.paywall).toBe(false)
    expect(store.limitHit).toBeNull()
    expect(store.openError).toBe('Для этого режима пока мало материала. Выучите ещё несколько слов.')
  })
})
