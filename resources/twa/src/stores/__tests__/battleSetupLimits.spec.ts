import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useBattleSetupStore } from '../battleSetup'
import { useBillingStore } from '../billing'
import { courseDetail, courseRow, invitedBattle, options } from '@/api/__tests__/battleFixtures'
import { freeLimits, inHours, limitDetails } from '@/api/__tests__/practiceFixtures'
import type { MeResponse, PracticeLimits } from '@/api/types'

vi.mock('@/api', () => ({
  api: {
    battles: { options: vi.fn(), invite: vi.fn(), get: vi.fn() },
    courses: { list: vi.fn(), get: vi.fn() },
    decks: { list: vi.fn() },
    me: { get: vi.fn() },
  },
}))

/**
 * The Free limits (docs/18 §3, G1) in the setup of a battle: "Invite" starts a battle and meets the counter of battles. The counters come
 * from the billing store (`/me`, the practice hub, the 201 of the last session or battle).
 */

const apiError = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)

function serve() {
  vi.mocked(api.courses.list).mockResolvedValue({ courses: [courseRow({ slug: 'english-a1', title: 'Английский A1', is_active: true })] })
  vi.mocked(api.courses.get).mockImplementation((slug) => Promise.resolve(courseDetail({ slug, title: slug })))
  vi.mocked(api.decks.list).mockResolvedValue({ decks: [], limits: { decks: 3, deck_words: 50, used_decks: 0 } } as never)
  vi.mocked(api.battles.options).mockImplementation(() => Promise.resolve(options()))
}

/** `/me` as the server answers it: the plan and the given counters (`null`: no limits at all). */
const meWith = (limits: PracticeLimits | null): MeResponse =>
  ({
    entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 20 },
    paywall: { mode: 'coming_soon', contact: null },
    practice: { available: true, pro_required: false, modes: ['pairs'], battle: true, limits },
  }) as MeResponse

/** The server has not changed its mind: `/me` tells the counters the learner already has. */
function serveMe(limits: PracticeLimits | null = useBillingStore().limits): void {
  vi.mocked(api.me.get).mockResolvedValue(meWith(limits))
}

/** A setup with a friend, a course and a format that can be played: "Invite" is ready. */
async function ready() {
  serve()
  serveMe()
  const setup = useBattleSetupStore()
  await setup.begin(11, null, null)
  return setup
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
})
afterEach(() => {
  vi.useRealTimers()
  setLocale('ru')
})

describe('battle setup — "Invite" meets the counter of battles', () => {
  it('opens the wall instead of sending the invitation, when the counter says today\'s battles are spent and /me, read again, says so too', async () => {
    const resetsAt = inHours(4)
    useBillingStore().setLimits(freeLimits(0, 1, resetsAt))
    const setup = await ready()

    expect(await setup.invite()).toBeNull()

    expect(api.me.get).toHaveBeenCalledTimes(1)
    expect(api.battles.invite).not.toHaveBeenCalled()
    expect(setup.limitHit).toEqual({ kind: 'battles', limit: 1, used: 1, resets_at: resetsAt })
    expect(setup.inviting).toBe(false)
    expect(setup.inviteError).toBe('')
    expect(setup.paywall).toBe(false)
  })

  // A battle that did not take place (declined, not answered in time, withdrawn) is not counted: its place comes back on the server,
  // and the counter the screen holds is from before. So a zero is confirmed by `/me` read again, not believed.
  describe('a zero that the server may have forgiven', () => {
    it('opens the way when /me, read again, gives the place back: the invitation is sent', async () => {
      const billing = useBillingStore()
      billing.setLimits(freeLimits(0, 1)) // the 201 of the invitation that was declined meanwhile said so
      const setup = await ready()
      serveMe(freeLimits(0, 0)) // ... and the server has given the place back
      vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 21 }))

      expect(await setup.invite()).toBe(21)

      expect(api.me.get).toHaveBeenCalledTimes(1)
      expect(setup.limitHit).toBeNull()
      expect(billing.battleLimit).toMatchObject({ limit: 1, remaining: 1 })
    })

    it('does not ask /me while some place is left, nor for a kind without a counter', async () => {
      vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 21 }))
      const billing = useBillingStore()
      const setup = await ready()

      billing.setLimits(freeLimits(2, 0))
      await setup.invite()
      billing.setLimits({ sessions: freeLimits(0, 0).sessions, battles: null })
      await setup.invite()
      billing.setLimits(null)
      await setup.invite()

      expect(api.me.get).not.toHaveBeenCalled()
      expect(api.battles.invite).toHaveBeenCalledTimes(3)
    })

    it('does not sell Pro on a number that cannot be checked: with /me out of reach the invitation goes to the server, which decides', async () => {
      vi.spyOn(console, 'warn').mockImplementation(() => {})
      useBillingStore().setLimits(freeLimits(0, 1))
      const setup = await ready()
      vi.mocked(api.me.get).mockRejectedValue(apiError('network', 0, 'network'))
      vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 21 }))

      expect(await setup.invite()).toBe(21)

      expect(setup.limitHit).toBeNull()
    })

    it('... and what the server then says is the wall, as ever (403 with its numbers)', async () => {
      vi.spyOn(console, 'warn').mockImplementation(() => {})
      useBillingStore().setLimits(freeLimits(0, 1))
      const setup = await ready()
      vi.mocked(api.me.get).mockRejectedValue(apiError('server', 500, 'server_error'))
      vi.mocked(api.battles.invite).mockRejectedValue(apiError('forbidden', 403, 'practice_limit_reached', limitDetails('battles')))

      expect(await setup.invite()).toBeNull()

      expect(setup.limitHit).toMatchObject({ kind: 'battles', limit: 1 })
    })

    it('waits for the answer with the button switched off: one press is one question and one invitation', async () => {
      useBillingStore().setLimits(freeLimits(0, 1))
      const setup = await ready()
      let answer!: (value: MeResponse) => void
      vi.mocked(api.me.get).mockReturnValue(new Promise((resolve) => (answer = resolve)))
      vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 21 }))

      const first = setup.invite()
      expect(setup.inviting).toBe(true)
      expect(setup.canInvite).toBe(false)
      expect(await setup.invite()).toBeNull() // a second press does nothing
      answer(meWith(freeLimits(0, 0)))

      expect(await first).toBe(21)
      expect(api.me.get).toHaveBeenCalledTimes(1)
      expect(api.battles.invite).toHaveBeenCalledTimes(1)
      expect(setup.inviting).toBe(false)
    })

    it('a /me that is already on its way (the reading of the screen itself) is waited for, and its counter decides: no second question', async () => {
      useBillingStore().setLimits(freeLimits(0, 1))
      const setup = await ready()
      let answer!: (value: MeResponse) => void
      vi.mocked(api.me.get).mockReturnValue(new Promise((resolve) => (answer = resolve)))
      vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 21 }))
      vi.mocked(api.me.get).mockClear()
      const reading = useBillingStore().load({ force: true }) // began with the screen, the learner pressed before the answer

      const inviting = setup.invite()
      expect(setup.inviting).toBe(true)
      await flushPromises()
      expect(api.battles.invite, 'the old zero does not decide, and nothing is sent before the answer').not.toHaveBeenCalled()
      answer(meWith(freeLimits(0, 0))) // the server had given the place back

      expect(await inviting).toBe(21)
      await reading
      expect(api.me.get).toHaveBeenCalledTimes(1) // the one that was on its way: the invitation did not ask a second time
      expect(setup.limitHit).toBeNull()
    })

    it('... and when that answer still says none, the wall opens without the invitation', async () => {
      const resetsAt = inHours(4)
      useBillingStore().setLimits(freeLimits(0, 1, resetsAt))
      const setup = await ready()
      let answer!: (value: MeResponse) => void
      vi.mocked(api.me.get).mockReturnValue(new Promise((resolve) => (answer = resolve)))
      vi.mocked(api.me.get).mockClear()
      void useBillingStore().load({ force: true })

      const inviting = setup.invite()
      await flushPromises()
      expect(api.battles.invite, 'nothing is sent before the answer').not.toHaveBeenCalled()
      answer(meWith(freeLimits(0, 1, resetsAt)))

      expect(await inviting).toBeNull()
      expect(api.me.get).toHaveBeenCalledTimes(1)
      expect(api.battles.invite).not.toHaveBeenCalled()
      expect(setup.limitHit).toEqual({ kind: 'battles', limit: 1, used: 1, resets_at: resetsAt })
    })

    it('an answer that comes after a new setup was started moves nobody', async () => {
      useBillingStore().setLimits(freeLimits(0, 1))
      const setup = await ready()
      let answer!: (value: MeResponse) => void
      vi.mocked(api.me.get).mockReturnValue(new Promise((resolve) => (answer = resolve)))

      const first = setup.invite()
      await setup.begin(12, null, null)
      answer(meWith(freeLimits(0, 0)))

      expect(await first).toBeNull()
      expect(api.battles.invite).not.toHaveBeenCalled()
      expect(setup.limitHit).toBeNull()
    })
  })

  it('"Invite" is still on when the battles are spent: it is what opens the wall (only a setup that cannot go on switches it off)', async () => {
    useBillingStore().setLimits(freeLimits(0, 1))
    const setup = await ready()

    expect(setup.canInvite).toBe(true)
  })

  it('asks as usual while a battle is left (the sessions of practice are another counter), for Pro and for an unknown counter', async () => {
    vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 21 }))
    const billing = useBillingStore()
    const setup = await ready()

    billing.setLimits(freeLimits(2, 0))
    expect(await setup.invite()).toBe(21)

    billing.setLimits(null)
    expect(await setup.invite()).toBe(21)

    billing.limits = null
    expect(await setup.invite()).toBe(21)
    expect(api.battles.invite).toHaveBeenCalledTimes(3)
    expect(api.me.get).not.toHaveBeenCalled()
    expect(setup.limitHit).toBeNull()
  })

  it('asks once the day of the counter is over: the app stayed open overnight, and the server decides', async () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-11-02T10:00:00Z'))
    useBillingStore().setLimits(freeLimits(0, 1, '2026-11-02T19:00:00Z'))
    vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 21 }))
    const setup = await ready()
    expect(await setup.invite()).toBeNull()

    vi.setSystemTime(new Date('2026-11-02T19:00:01Z'))

    expect(await setup.invite()).toBe(21)
    expect(setup.limitHit).toBeNull()
  })

  it('the 201 says the counters as they are after the battle', async () => {
    const billing = useBillingStore()
    billing.setLimits(freeLimits(1, 0))
    vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 21, limits: freeLimits(1, 1) }))
    const setup = await ready()

    expect(await setup.invite()).toBe(21)

    expect(billing.battleLimit).toMatchObject({ used: 1, remaining: 0 })
    expect(billing.sessionLimit).toMatchObject({ used: 1, remaining: 1 })
  })

  it('a 201 of an older server, which says none, changes nothing; one with `null` (Pro) clears them', async () => {
    const billing = useBillingStore()
    billing.setLimits(freeLimits(0, 0))
    const setup = await ready()

    vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 21 }))
    await setup.invite()
    expect(billing.battleLimit).toMatchObject({ remaining: 1 })

    vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 22, limits: null }))
    await setup.invite()
    expect(billing.limits).toBeNull()
  })

  it('the counters of an invitation that is answered after a new setup was started still count: the battle exists', async () => {
    const billing = useBillingStore()
    let finish!: (value: ReturnType<typeof invitedBattle>) => void
    vi.mocked(api.battles.invite).mockReturnValue(new Promise((resolve) => (finish = resolve)))
    const setup = await ready()
    const sending = setup.invite()
    await vi.waitFor(() => expect(api.battles.invite).toHaveBeenCalled()) // the invitation is on its way

    await setup.begin(12, null, null)
    finish(invitedBattle({ id: 21, limits: freeLimits(0, 1) }))

    expect(await sending).toBeNull()
    expect(billing.battleLimit).toMatchObject({ used: 1, remaining: 0 })
  })
})

describe('battle setup — 403 practice_limit_reached from the server', () => {
  it('is the wall with the numbers of the refusal, no sentence beside it, and the counter is spent from now on', async () => {
    const resetsAt = inHours(5)
    const billing = useBillingStore()
    billing.setLimits(freeLimits(0, 0)) // the screen believed a battle was left
    vi.mocked(api.battles.invite).mockRejectedValue(apiError('forbidden', 403, 'practice_limit_reached', limitDetails('battles', resetsAt)))
    const setup = await ready()

    expect(await setup.invite()).toBeNull()

    expect(setup.limitHit).toEqual({ kind: 'battles', limit: 1, used: 1, resets_at: resetsAt })
    expect(setup.inviteError).toBe('')
    expect(setup.busyBattleId).toBeNull()
    expect(setup.paywall).toBe(false)
    expect(setup.blocked).toBeNull()
    expect(setup.inviting).toBe(false)
    expect(billing.battleLimit).toMatchObject({ used: 1, remaining: 0 })
  })

  it('leaves the setup as it was, so that the learner can go on after closing the sheet', async () => {
    vi.mocked(api.battles.invite).mockRejectedValue(apiError('forbidden', 403, 'practice_limit_reached', limitDetails('battles')))
    const setup = await ready()
    await setup.invite()

    setup.dismissPaywall()

    expect(setup.limitHit).toBeNull()
    expect(setup.canInvite).toBe(true)
    expect(setup.course).toBe('english-a1')
    expect(setup.mode).not.toBeNull()
  })

  it('is told in words when its details cannot be read: the message of the code, never a wall without numbers', async () => {
    vi.mocked(api.battles.invite).mockRejectedValue(apiError('forbidden', 403, 'practice_limit_reached', { kind: 'battles' }))
    const setup = await ready()

    expect(await setup.invite()).toBeNull()

    expect(setup.limitHit).toBeNull()
    expect(setup.inviteError).toBe('Бесплатный лимит на сегодня закончился. Он обновится завтра.')
  })

  it('says it in Uzbek', async () => {
    setLocale('uz')
    vi.mocked(api.battles.invite).mockRejectedValue(apiError('forbidden', 403, 'practice_limit_reached'))
    const setup = await ready()

    await setup.invite()

    expect(setup.inviteError).toBe('Bugungi bepul limit tugadi. U ertaga yangilanadi.')
  })

  it('a refusal that comes after a new setup was started is not told to it, but the counter learns it', async () => {
    const billing = useBillingStore()
    billing.setLimits(freeLimits(0, 0))
    let refuse!: (reason: unknown) => void
    vi.mocked(api.battles.invite).mockReturnValue(new Promise((_, reject) => (refuse = reject)))
    const setup = await ready()
    const sending = setup.invite()
    await vi.waitFor(() => expect(api.battles.invite).toHaveBeenCalled()) // the invitation is on its way

    await setup.begin(12, null, null)
    refuse(apiError('forbidden', 403, 'practice_limit_reached', limitDetails('battles')))
    await sending

    expect(setup.limitHit).toBeNull()
    expect(billing.battleLimit).toMatchObject({ remaining: 0 })
  })

  it('does not touch the other refusals: the Pro wall, a battle in the way and a lost connection are told as they were', async () => {
    const setup = await ready()

    vi.mocked(api.battles.invite).mockRejectedValue(apiError('forbidden', 403, 'practice_pro_required'))
    await setup.invite()
    expect(setup.paywall).toBe(true)
    expect(setup.limitHit).toBeNull()

    vi.mocked(api.battles.invite).mockRejectedValue(apiError('conflict', 409, 'battle_busy', { battle_id: 9 }))
    vi.mocked(api.battles.get).mockResolvedValue(invitedBattle({ id: 9, role: 'challenger' }))
    await setup.invite()
    expect(setup.busyBattleId).toBe(9)
    expect(setup.limitHit).toBeNull()

    vi.mocked(api.battles.invite).mockRejectedValue(apiError('network', 0, 'network'))
    await setup.invite()
    expect(setup.inviteError).toBe('Нет связи с сервером. Попробуйте ещё раз.')
    expect(setup.limitHit).toBeNull()
  })
})

// An empty env of the server means that kind has no limit: `limits` carries `null` for it and the other kind keeps its counter.
describe('battle setup — battles without a limit (null inside the limits)', () => {
  it('"Invite" is never stopped, whatever the counter of the sessions of practice says', async () => {
    useBillingStore().setLimits({ sessions: freeLimits(2, 0).sessions, battles: null })
    vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 21 }))
    const setup = await ready()

    expect(await setup.invite()).toBe(21)

    expect(api.battles.invite).toHaveBeenCalledTimes(1)
    expect(setup.limitHit).toBeNull()
  })

  it('both kinds without a limit stop nothing', async () => {
    useBillingStore().setLimits({ sessions: null, battles: null })
    vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 21 }))
    const setup = await ready()

    expect(await setup.invite()).toBe(21)
  })

  it('the 201 says it: the battles go on without a counter, the sessions keep theirs', async () => {
    const billing = useBillingStore()
    billing.setLimits(freeLimits(1, 0))
    vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 21, limits: { sessions: freeLimits(1, 0).sessions, battles: null } }))
    const setup = await ready()

    await setup.invite()

    expect(billing.battleLimit).toBeNull()
    expect(billing.sessionLimit).toMatchObject({ used: 1, remaining: 1 })
  })

  it('a 403 for the battles that the screen took for unlimited is the wall, and the counter appears spent', async () => {
    const billing = useBillingStore()
    billing.setLimits({ sessions: freeLimits(0, 0).sessions, battles: null })
    vi.mocked(api.battles.invite).mockRejectedValue(apiError('forbidden', 403, 'practice_limit_reached', limitDetails('battles')))
    const setup = await ready()

    expect(await setup.invite()).toBeNull()

    expect(setup.limitHit).toMatchObject({ kind: 'battles', limit: 1 })
    expect(billing.battleLimit).toMatchObject({ used: 1, remaining: 0 })
    expect(billing.sessionLimit).toMatchObject({ remaining: 2 })
  })
})

describe('battle setup — the wall belongs to one setup', () => {
  it('is forgotten when a setup begins again (another friend, the address changed)', async () => {
    useBillingStore().setLimits(freeLimits(0, 1))
    const setup = await ready()
    await setup.invite()
    expect(setup.limitHit).not.toBeNull()

    await setup.begin(12, null, null)

    expect(setup.limitHit).toBeNull()
  })

  it('is forgotten by the next press of "Invite", which asks the counter again', async () => {
    const billing = useBillingStore()
    billing.setLimits(freeLimits(0, 1))
    vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 21 }))
    const setup = await ready()
    await setup.invite()

    billing.setLimits(freeLimits(0, 0)) // the counters were told again meanwhile (the hub, a new /me): the press asks them again
    expect(await setup.invite()).toBe(21)

    expect(setup.limitHit).toBeNull()
  })
})
