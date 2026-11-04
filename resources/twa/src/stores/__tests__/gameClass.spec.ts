import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useGameClassStore } from '../gameClass'
import { gameClass } from '@/api/__tests__/gameClassFixtures'

vi.mock('@/api', () => ({ api: { gameClass: { show: vi.fn(), list: vi.fn() }, game: { startRun: vi.fn() } } }))

const error = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  setLocale('ru')
})

describe('gameClass store', () => {
  it('reads the caravan: loading → ready', async () => {
    vi.mocked(api.gameClass.show).mockResolvedValue(gameClass())
    const store = useGameClassStore()

    const pending = store.load(5)
    expect(store.status).toBe('loading')
    await pending

    expect(api.gameClass.show).toHaveBeenCalledWith(5)
    expect(store.status).toBe('ready')
    expect(store.caravan?.id).toBe(5)
  })

  it.each([
    ['class_caravan_not_found', 'not_found', 'not_found', 404],
    ['game_unavailable', 'forbidden', 'unavailable', 403],
  ] as const)('%s ends it as %s without a text', async (code, kind, expected, httpStatus) => {
    vi.mocked(api.gameClass.show).mockRejectedValue(error(kind, httpStatus, code))
    const store = useGameClassStore()

    await store.load(5)
    expect(store.status).toBe(expected)
    expect(store.caravan).toBeNull()
  })

  it('another failure is an error with a localized text', async () => {
    vi.mocked(api.gameClass.show).mockRejectedValue(error('server', 500, 'server_error'))
    const store = useGameClassStore()

    await store.load(5)
    expect(store.status).toBe('error')
    expect(store.error).not.toBe('')
  })

  it('drops an answer that comes after the screen is left', async () => {
    let resolve: (value: ReturnType<typeof gameClass>) => void = () => {}
    vi.mocked(api.gameClass.show).mockReturnValue(new Promise((r) => (resolve = r)))
    const store = useGameClassStore()

    const pending = store.load(5)
    store.reset()
    resolve(gameClass())
    await pending

    expect(store.status).toBe('idle')
    expect(store.caravan).toBeNull()
  })

  it('startBoss sends class_boss with the id; replace_active only when asked; a refusal comes back typed', async () => {
    vi.mocked(api.game.startRun).mockResolvedValueOnce({ id: 9 }).mockRejectedValueOnce(error('conflict', 409, 'class_boss_locked'))
    const store = useGameClassStore()

    expect(await store.startBoss(5)).toEqual({ ok: true, id: 9 })
    expect(api.game.startRun).toHaveBeenLastCalledWith({ kind: 'class_boss', class_caravan_id: 5 })

    const refused = await store.startBoss(5, true)
    expect(api.game.startRun).toHaveBeenLastCalledWith({ kind: 'class_boss', class_caravan_id: 5, replace_active: true })
    expect(refused.ok).toBe(false)
    if (!refused.ok) expect(refused.error.code).toBe('class_boss_locked')
  })
})
