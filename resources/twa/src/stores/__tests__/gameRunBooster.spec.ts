import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useGameRunStore } from '../gameRun'
import { runView, spell, translateFwd } from '@/api/__tests__/gameRunFixtures'
import type { GameRunView } from '@/api/gameRunTypes'

vi.mock('@/api', () => ({
  api: { gameRuns: { get: vi.fn(), answer: vi.fn(), hint: vi.fn(), abandon: vi.fn(), booster: vi.fn() } },
}))

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

const apiError = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)

const body = (n = 0) => vi.mocked(api.gameRuns.booster).mock.calls[n]![1]

const withStock = (overrides: Partial<GameRunView> = {}, element = translateFwd()): GameRunView =>
  runView({ boosters: { available: [{ key: 'booster_hint', effect: 'hint', qty: 2 }, { key: 'booster_extra_flask', effect: 'extra_flask', qty: 1 }], used: [] }, ...overrides }, element)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
})
afterEach(() => setLocale('ru'))

async function started(run: GameRunView = withStock()) {
  vi.mocked(api.gameRuns.get).mockResolvedValue(run)
  const store = useGameRunStore()
  await store.load(run.id)
  return store
}

describe('gameRun store - boosters', () => {
  it('sends a hint for the current element with a uuid request_id and takes the run of the reply', async () => {
    const store = await started()
    const hinted = translateFwd({ hint: { options_removed: ['o2', 'o3'] } })
    vi.mocked(api.gameRuns.booster).mockResolvedValue(withStock({ boosters: { available: [{ key: 'booster_hint', effect: 'hint', qty: 1 }], used: [{ key: 'booster_hint', effect: 'hint', element_id: hinted.id }] } }, hinted))
    const seq = store.elementSeq

    await store.useBooster('booster_hint', 'hint')

    expect(api.gameRuns.booster).toHaveBeenCalledWith(9, expect.objectContaining({ item_key: 'booster_hint', element_id: 'e1' }))
    expect(body().request_id).toMatch(UUID)
    expect(store.status).toBe('playing')
    expect(store.element).toMatchObject({ hint: { options_removed: ['o2', 'o3'] } })
    expect(store.elementSeq).toBe(seq)
    expect(store.pendingBooster).toBeNull()
  })

  it('sends no element for a booster of the whole run', async () => {
    const store = await started()
    vi.mocked(api.gameRuns.booster).mockResolvedValue(withStock({ flasks: 4, flasks_max: 4 }))

    await store.useBooster('booster_extra_flask', 'extra_flask')

    expect(body()).not.toHaveProperty('element_id')
    expect(store.run?.flasks).toBe(4)
  })

  it('repeats a booster that got no reply under the same request_id', async () => {
    const store = await started()
    vi.mocked(api.gameRuns.booster).mockRejectedValueOnce(apiError('network', 0, 'network')).mockResolvedValue(withStock())

    await store.useBooster('booster_hint', 'hint')
    expect(store.status).toBe('playing')
    expect(store.actionError).not.toBe('')
    await store.useBooster('booster_hint', 'hint')

    expect(body(1).request_id).toBe(body(0).request_id)
    expect(store.actionError).toBe('')
  })

  it('takes a new request_id for another booster', async () => {
    const store = await started()
    vi.mocked(api.gameRuns.booster).mockRejectedValueOnce(apiError('server', 500, 'x')).mockResolvedValue(withStock())

    await store.useBooster('booster_hint', 'hint')
    await store.useBooster('booster_extra_flask', 'extra_flask')

    expect(body(1).request_id).not.toBe(body(0).request_id)
  })

  it('409 booster_limit_reached leaves the numbers for the paywall and keeps the task', async () => {
    const store = await started()
    vi.mocked(api.gameRuns.booster).mockRejectedValue(apiError('conflict', 409, 'booster_limit_reached', { limit: 1, used: 1, resets_at: '2026-11-09T19:00:00.000Z' }))

    await store.useBooster('booster_hint', 'hint')

    expect(store.boosterLimit).toEqual({ limit: 1, used: 1, resets_at: '2026-11-09T19:00:00.000Z', upsell: true })
    expect(store.status).toBe('playing')
    expect(store.actionError).toBe('')

    store.clearBoosterLimit()
    expect(store.boosterLimit).toBeNull()
  })

  it('409 booster_limit_reached with no numbers is a plain message', async () => {
    const store = await started()
    vi.mocked(api.gameRuns.booster).mockRejectedValue(apiError('conflict', 409, 'booster_limit_reached'))

    await store.useBooster('booster_hint', 'hint')

    expect(store.boosterLimit).toBeNull()
    expect(store.actionError).not.toBe('')
  })

  it.each(['booster_not_owned', 'booster_already_used', 'booster_not_applicable', 'hint_not_available'])('%s: a message and the run is read again', async (code) => {
    const store = await started()
    vi.mocked(api.gameRuns.booster).mockRejectedValue(apiError('conflict', 409, code))
    vi.mocked(api.gameRuns.get).mockResolvedValue(withStock({ boosters: { available: [], used: [] } }))

    await store.useBooster('booster_hint', 'hint')

    expect(store.actionError).not.toBe('')
    expect(store.actionError).not.toMatch(/^error\./)
    expect(api.gameRuns.get).toHaveBeenCalledTimes(2)
    expect(store.run?.boosters.available).toEqual([])
    expect(store.status).toBe('playing')
  })

  it('a refusal that is not about the run (request_id_reused) is a message on the same task', async () => {
    const store = await started()
    vi.mocked(api.gameRuns.booster).mockRejectedValue(apiError('conflict', 409, 'request_id_reused'))

    await store.useBooster('booster_hint', 'hint')

    expect(store.status).toBe('playing')
    expect(store.actionError).not.toBe('')
  })

  it('410 closes the run', async () => {
    const store = await started()
    vi.mocked(api.gameRuns.booster).mockRejectedValue(apiError('gone', 410, 'game_run_closed', { finish_reason: 'expired' }))

    await store.useBooster('booster_hint', 'hint')

    expect(store.status).toBe('closed')
  })

  it('does nothing while an answer is on its way or the run is not playing', async () => {
    const store = await started()
    store.status = 'submitting'

    await store.useBooster('booster_hint', 'hint')

    expect(api.gameRuns.booster).not.toHaveBeenCalled()
  })

  it('a hint is sent for the element on screen: a typed element too', async () => {
    const store = await started(withStock({}, spell() as never))
    vi.mocked(api.gameRuns.booster).mockResolvedValue(withStock({}, spell({ hint: { prefix: 'ap' } } as never) as never))

    await store.useBooster('booster_hint', 'hint')

    expect(body().element_id).toBe('e4')
    expect(store.element).toMatchObject({ hint: { prefix: 'ap' } })
  })
})
