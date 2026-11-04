import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useGameShopStore } from '../gameShop'
import { booster, profile, shop, shopItem, wallet } from '@/api/__tests__/gameShopFixtures'

vi.mock('@/api', () => ({
  api: { gameShop: { wallet: vi.fn(), shop: vi.fn(), buy: vi.fn(), profile: vi.fn(), equip: vi.fn() } },
}))

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

const apiError = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)

const sentId = (n = 0) => vi.mocked(api.gameShop.buy).mock.calls[n]![1].request_id

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
})
afterEach(() => setLocale('ru'))

async function loaded() {
  vi.mocked(api.gameShop.wallet).mockResolvedValue(wallet())
  vi.mocked(api.gameShop.shop).mockResolvedValue(shop())
  vi.mocked(api.gameShop.profile).mockResolvedValue(profile())
  const store = useGameShopStore()
  await store.load()
  return store
}

describe('gameShop store - reading', () => {
  it('reads the wallet, the shop and the profile together', async () => {
    const store = await loaded()

    expect(store.status).toBe('ready')
    expect(store.balance).toBe(200)
    expect(store.items).toHaveLength(5)
    expect(store.profile?.equipped.camel?.key).toBe('camel_dromedary')
  })

  it('is unavailable on 403 game_unavailable', async () => {
    vi.mocked(api.gameShop.wallet).mockRejectedValue(apiError('forbidden', 403, 'game_unavailable'))
    vi.mocked(api.gameShop.shop).mockResolvedValue(shop())
    vi.mocked(api.gameShop.profile).mockResolvedValue(profile())
    const store = useGameShopStore()
    await store.load()

    expect(store.status).toBe('unavailable')
  })

  it('shows an error with a text on another failure, and can read again', async () => {
    vi.mocked(api.gameShop.wallet).mockRejectedValue(apiError('server', 500, 'server_error'))
    vi.mocked(api.gameShop.shop).mockResolvedValue(shop())
    vi.mocked(api.gameShop.profile).mockResolvedValue(profile())
    const store = useGameShopStore()
    await store.load()

    expect(store.status).toBe('error')
    expect(store.error).not.toBe('')

    vi.mocked(api.gameShop.wallet).mockResolvedValue(wallet())
    await store.load()
    expect(store.status).toBe('ready')
  })
})

describe('gameShop store - buying', () => {
  it('sends a uuid request_id, takes the wallet of the reply and marks a cosmetic as owned', async () => {
    const store = await loaded()
    vi.mocked(api.gameShop.buy).mockResolvedValue(wallet({ balance: 50 }))

    const result = await store.buy('camel_bactrian')

    expect(result).toEqual({ ok: true })
    expect(vi.mocked(api.gameShop.buy).mock.calls[0]![0]).toBe('camel_bactrian')
    expect(sentId()).toMatch(UUID)
    expect(store.balance).toBe(50)
    expect(store.shop?.balance).toBe(50)
    expect(store.items.find((i) => i.key === 'camel_bactrian')).toMatchObject({ owned: true, qty: 1 })
    expect(store.buying).toBeNull()
  })

  it('adds one to the stock of a booster', async () => {
    const store = await loaded()
    vi.mocked(api.gameShop.buy).mockResolvedValue(wallet({ balance: 185 }))

    await store.buy('booster_hint')

    expect(store.items.find((i) => i.key === 'booster_hint')).toMatchObject({ owned: true, qty: 1 })
  })

  it('repeats a purchase that got no reply under the same request_id, and a new purchase gets a new one', async () => {
    const store = await loaded()
    vi.mocked(api.gameShop.buy).mockRejectedValueOnce(apiError('network', 0, 'network')).mockRejectedValueOnce(apiError('server', 500, 'x')).mockResolvedValue(wallet({ balance: 120 }))

    const first = await store.buy('carpet_bukhara')
    expect(first.ok).toBe(false)
    await store.buy('carpet_bukhara')
    const third = await store.buy('carpet_bukhara')

    expect(third).toEqual({ ok: true })
    expect(sentId(1)).toBe(sentId(0))
    expect(sentId(2)).toBe(sentId(0))

    vi.mocked(api.gameShop.buy).mockResolvedValue(wallet({ balance: 105 }))
    await store.buy('booster_hint')
    expect(sentId(3)).not.toBe(sentId(0))
    expect(sentId(3)).toMatch(UUID)
  })

  it('does not take the id of an earlier try for another item', async () => {
    const store = await loaded()
    vi.mocked(api.gameShop.buy).mockRejectedValueOnce(apiError('network', 0, 'network')).mockResolvedValue(wallet())

    await store.buy('carpet_bukhara')
    await store.buy('booster_hint')

    expect(sentId(1)).not.toBe(sentId(0))
  })

  it('forgets the id after a refusal of the server (a new try is a new purchase)', async () => {
    const store = await loaded()
    vi.mocked(api.gameShop.buy).mockRejectedValueOnce(apiError('conflict', 409, 'coins_insufficient', { balance: 5, price: 80 })).mockResolvedValue(wallet())

    await store.buy('carpet_bukhara')
    await store.buy('carpet_bukhara')

    expect(sentId(1)).not.toBe(sentId(0))
  })

  it('ignores a second tap while a purchase is on its way', async () => {
    const store = await loaded()
    let resolve!: (value: ReturnType<typeof wallet>) => void
    vi.mocked(api.gameShop.buy).mockReturnValue(new Promise((yes) => (resolve = yes)))

    const first = store.buy('carpet_bukhara')
    const second = await store.buy('carpet_bukhara')
    resolve(wallet())
    await first

    expect(second.ok).toBe(false)
    expect(api.gameShop.buy).toHaveBeenCalledTimes(1)
  })

  it('409 coins_insufficient: the balance of the server, the refusal typed', async () => {
    const store = await loaded()
    vi.mocked(api.gameShop.buy).mockRejectedValue(apiError('conflict', 409, 'coins_insufficient', { balance: 5, price: 80 }))

    const result = await store.buy('carpet_bukhara')

    expect(result).toMatchObject({ ok: false, error: { code: 'coins_insufficient', details: { balance: 5, price: 80 } } })
    expect(store.balance).toBe(5)
    expect(store.items.find((i) => i.key === 'carpet_bukhara')?.owned).toBe(false)
  })

  it('409 item_already_owned: the item is shown as owned', async () => {
    const store = await loaded()
    vi.mocked(api.gameShop.buy).mockRejectedValue(apiError('conflict', 409, 'item_already_owned'))

    const result = await store.buy('carpet_bukhara')

    expect(result).toMatchObject({ ok: false, error: { code: 'item_already_owned' } })
    expect(store.items.find((i) => i.key === 'carpet_bukhara')?.owned).toBe(true)
  })

  it('409 item_not_purchasable carries its reason', async () => {
    const store = await loaded()
    vi.mocked(api.gameShop.buy).mockRejectedValue(apiError('conflict', 409, 'item_not_purchasable', { reason: 'pro' }))

    const result = await store.buy('camel_golden')

    expect(result).toMatchObject({ ok: false, error: { code: 'item_not_purchasable', details: { reason: 'pro' } } })
    expect(store.balance).toBe(200)
  })

  it('409 request_id_reused and 404 item_not_found are refusals with a text; the shop is read again on the 404', async () => {
    const store = await loaded()
    vi.mocked(api.gameShop.buy).mockRejectedValueOnce(apiError('conflict', 409, 'request_id_reused'))
    const reused = await store.buy('booster_hint')
    expect(reused).toMatchObject({ ok: false, error: { code: 'request_id_reused' } })
    expect(reused.ok === false && reused.error.message).not.toBe('')

    vi.mocked(api.gameShop.shop).mockClear()
    vi.mocked(api.gameShop.buy).mockRejectedValueOnce(apiError('not_found', 404, 'item_not_found'))
    const gone = await store.buy('booster_hint')
    expect(gone).toMatchObject({ ok: false, error: { code: 'item_not_found' } })
    expect(api.gameShop.shop).toHaveBeenCalledTimes(1)
  })
})

describe('gameShop store - wearing', () => {
  it('changes the screen at once and keeps the profile of the reply', async () => {
    const store = await loaded()
    // the second camel is owned
    store.patchItem('camel_bactrian', (item) => ({ ...item, owned: true, qty: 1 }))
    let resolve!: (value: ReturnType<typeof profile>) => void
    vi.mocked(api.gameShop.equip).mockReturnValue(new Promise((yes) => (resolve = yes)))

    const done = store.equip('camel', 'camel_bactrian')

    expect(store.profile?.equipped.camel).toEqual({ key: 'camel_bactrian', asset: 'camel_bactrian' })
    expect(store.items.find((i) => i.key === 'camel_bactrian')?.equipped).toBe(true)
    expect(store.items.find((i) => i.key === 'camel_dromedary')?.equipped).toBe(false)
    expect(store.equipping).toBe(true)

    resolve(profile({ equipped: { camel: { key: 'camel_bactrian', asset: 'camel_bactrian' } } }))
    expect(await done).toEqual({ ok: true })
    expect(api.gameShop.equip).toHaveBeenCalledWith({ slot: 'camel', item_key: 'camel_bactrian' })
    expect(store.equipping).toBe(false)
  })

  it('goes back to the snapshot when the server refuses (404 item_not_found) and reads the shop again', async () => {
    const store = await loaded()
    const before = JSON.stringify({ p: store.profile, s: store.shop })
    vi.mocked(api.gameShop.equip).mockRejectedValue(apiError('not_found', 404, 'item_not_found'))
    vi.mocked(api.gameShop.shop).mockClear()

    const result = await store.equip('camel', 'camel_bactrian')

    expect(result).toMatchObject({ ok: false, error: { code: 'item_not_found' } })
    expect(JSON.stringify({ p: store.profile, s: store.shop })).toBe(before)
    expect(store.equipping).toBe(false)
    expect(api.gameShop.shop).toHaveBeenCalledTimes(1)
  })

  it('goes back on a network error too (nothing is read again)', async () => {
    const store = await loaded()
    const before = JSON.stringify({ p: store.profile, s: store.shop })
    vi.mocked(api.gameShop.equip).mockRejectedValue(apiError('network', 0, 'network'))
    vi.mocked(api.gameShop.shop).mockClear()

    await store.equip('camel', 'camel_bactrian')

    expect(JSON.stringify({ p: store.profile, s: store.shop })).toBe(before)
    expect(api.gameShop.shop).not.toHaveBeenCalled()
  })

  it('takes an item off with null', async () => {
    const store = await loaded()
    vi.mocked(api.gameShop.equip).mockResolvedValue(profile({ equipped: {} }))

    const done = store.equip('camel', null)
    expect(store.profile?.equipped.camel).toBeUndefined()
    await done

    expect(api.gameShop.equip).toHaveBeenCalledWith({ slot: 'camel', item_key: null })
  })

  it('takes a second wearing while one is on its way for nothing', async () => {
    const store = await loaded()
    vi.mocked(api.gameShop.equip).mockReturnValue(new Promise(() => undefined))

    void store.equip('camel', 'camel_bactrian')
    const second = await store.equip('camel', 'camel_dromedary')

    expect(second.ok).toBe(false)
    expect(api.gameShop.equip).toHaveBeenCalledTimes(1)
  })

  it('has the catalogue fixtures it needs', () => {
    expect(shopItem().kind).toBe('cosmetic')
    expect(booster().kind).toBe('booster')
  })
})

describe('gameShop store - only the profile (the card on /profile, docs/05 §5b.6)', () => {
  it('reads GET /game/profile alone and leaves the status of the caravan screen as it was', async () => {
    vi.mocked(api.gameShop.profile).mockResolvedValue(profile())
    const store = useGameShopStore()

    expect(await store.loadProfile()).toBe(true)

    expect(store.profile?.equipped.camel?.key).toBe('camel_dromedary')
    expect(store.status).toBe('idle')
    expect(api.gameShop.wallet).not.toHaveBeenCalled()
    expect(api.gameShop.shop).not.toHaveBeenCalled()
  })

  it('says false and keeps what it had when the read fails (403 included)', async () => {
    vi.mocked(api.gameShop.profile).mockRejectedValue(apiError('forbidden', 403, 'game_unavailable'))
    const store = useGameShopStore()

    expect(await store.loadProfile()).toBe(false)
    expect(store.profile).toBeNull()
  })
})
