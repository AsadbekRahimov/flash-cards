import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useCupDuelsStore } from '../cupDuels'
import { cupDuel, duelList } from '@/api/__tests__/cupFixtures'
import type { CupDuel, CupDuelListResponse } from '@/api/types'

/** The store of the duels of a cup: an action on the list of one cup never replaces the list of another opened meanwhile. */

vi.mock('@/api', () => ({
  api: {
    cups: {
      show: vi.fn(),
      duels: { list: vi.fn(), show: vi.fn(), challenge: vi.fn(), accept: vi.fn(), decline: vi.fn(), cancel: vi.fn() },
    },
  },
}))

function pending<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}

const apiError = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)

const listOf = (id: number): CupDuelListResponse => duelList({ incoming: [cupDuel({ id, role: 'opponent' })] })

/** The list of cup 5 is on the screen with the duel 51; the action on it waits for `settle`; meanwhile cup 6 is opened. */
async function actThenSwitch(action: (store: ReturnType<typeof useCupDuelsStore>, duel: CupDuel) => Promise<unknown>, settle: () => void) {
  const store = useCupDuelsStore()
  vi.mocked(api.cups.duels.list).mockImplementation(async (cupId: number) => listOf(cupId === 5 ? 51 : 61))
  await store.load(5)
  const duel = store.list!.incoming[0]!
  const acting = action(store, duel)
  await store.load(6)
  settle()
  await acting
  return store
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  setLocale('ru')
})

describe('cupDuels: an action ends after another cup was opened', () => {
  it('"Decline": the list of the other cup stays, the old one is not read again', async () => {
    const answer = pending<CupDuel>()
    vi.mocked(api.cups.duels.decline).mockReturnValue(answer.promise)

    const store = await actThenSwitch((s, d) => s.decline(d), () => answer.resolve(cupDuel({ id: 51, status: 'declined' })))

    expect(api.cups.duels.list).toHaveBeenCalledTimes(2)
    expect(store.cupId).toBe(6)
    expect(store.status).toBe('ready')
    expect(store.list?.incoming[0]?.id).toBe(61)
  })

  it('"Accept" without a match to go to: the list of the other cup stays', async () => {
    const answer = pending<CupDuel>()
    vi.mocked(api.cups.duels.accept).mockReturnValue(answer.promise)

    const store = await actThenSwitch((s, d) => s.accept(d), () => answer.resolve(cupDuel({ id: 51, status: 'accepted', rated: true, battle_id: null })))

    expect(api.cups.duels.list).toHaveBeenCalledTimes(2)
    expect(store.cupId).toBe(6)
    expect(store.list?.incoming[0]?.id).toBe(61)
  })

  it('a refusal (the challenge closed): no message on the list of the other cup, nothing read again', async () => {
    const answer = pending<CupDuel>()
    vi.mocked(api.cups.duels.accept).mockReturnValue(answer.promise)

    const store = await actThenSwitch((s, d) => s.accept(d), () => answer.reject(apiError('not_found', 404, 'cup_duel_not_found')))

    expect(api.cups.duels.list).toHaveBeenCalledTimes(2)
    expect(store.cupId).toBe(6)
    expect(store.status).toBe('ready')
    expect(store.list?.incoming[0]?.id).toBe(61)
    expect(store.actionError).toBe('')
  })

  it('the same cup: the list is read again after the action, as before', async () => {
    const store = useCupDuelsStore()
    vi.mocked(api.cups.duels.list).mockResolvedValue(listOf(51))
    vi.mocked(api.cups.duels.decline).mockResolvedValue(cupDuel({ id: 51, status: 'declined' }))
    await store.load(5)

    await store.decline(store.list!.incoming[0]!)

    expect(api.cups.duels.list).toHaveBeenCalledTimes(2)
    expect(store.cupId).toBe(5)
  })
})
