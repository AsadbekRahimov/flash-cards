import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '@/api'
import { useBattleStore } from '../battle'
import type { BattleListResponse } from '@/api/types'

vi.mock('@/api', () => ({ api: { battles: { list: vi.fn() } } }))

function pending<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}

const list = (overrides: Partial<BattleListResponse> = {}): BattleListResponse => ({
  active: null,
  incoming: [
    {
      id: 15,
      mode: 'pairs',
      from: { first_name: 'Bek' },
      course: { slug: 'english-a1', title: 'Английский A1' },
      lesson: { id: 41, stage: 2, number: 4, title: 'Еда' },
      invite_expires_at: '2026-10-30T10:05:00.000Z',
      rematch: false,
    },
  ],
  finished: [],
  ...overrides,
})

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
})

describe('battle store — the list the hub reads', () => {
  it('starts empty and idle: no battle, no invitations', () => {
    const store = useBattleStore()

    expect(store.status).toBe('idle')
    expect(store.battle).toBeNull()
    expect(store.polling).toBe(false)
    expect(store.incoming).toEqual([])
    expect(store.active).toBeNull()
  })

  it('loads the invitations and the battle that is on', async () => {
    vi.mocked(api.battles.list).mockResolvedValue(
      list({ active: { id: 9, status: 'room', mode: 'sentence', role: 'challenger', course: { slug: 'x', title: 'X' }, lesson: null, opponent: { first_name: 'Dilnoza' } } }),
    )
    const store = useBattleStore()

    await store.loadList()

    expect(store.listStatus).toBe('ready')
    expect(store.incoming.map((i) => i.id)).toEqual([15])
    expect(store.active?.id).toBe(9)
  })

  it('is loading the first time, and an error when that fails', async () => {
    const reply = pending<BattleListResponse>()
    vi.mocked(api.battles.list).mockReturnValue(reply.promise)
    const store = useBattleStore()

    const loading = store.loadList()
    expect(store.listStatus).toBe('loading')
    reply.reject(new Error('offline'))
    await loading

    expect(store.listStatus).toBe('error')
    expect(store.incoming).toEqual([])
  })

  it('keeps the list it has when a refresh fails (the screen does not break for it)', async () => {
    vi.mocked(api.battles.list).mockResolvedValueOnce(list()).mockRejectedValueOnce(new Error('offline'))
    const store = useBattleStore()
    await store.loadList()

    await store.loadList()

    expect(store.listStatus).toBe('ready')
    expect(store.incoming).toHaveLength(1)
  })

  it('ignores the late answer of an earlier request', async () => {
    const old = pending<BattleListResponse>()
    vi.mocked(api.battles.list).mockReturnValueOnce(old.promise).mockResolvedValueOnce(list({ incoming: [] }))
    const store = useBattleStore()

    const first = store.loadList()
    await store.loadList()
    old.resolve(list())
    await first

    expect(store.incoming).toEqual([])
  })
})
