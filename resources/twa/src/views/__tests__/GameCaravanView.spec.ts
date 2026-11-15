import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import GameCaravanView from '../GameCaravanView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { showErrorToast } from '@/composables/errorToast'
import { booster, profile, shop, shopItem, wallet } from '@/api/__tests__/gameShopFixtures'
import type { GameShop } from '@/api/gameShopTypes'

vi.mock('@/api', () => ({
  api: { gameShop: { wallet: vi.fn(), shop: vi.fn(), buy: vi.fn(), profile: vi.fn(), equip: vi.fn() } },
}))

const authEnsure = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: authEnsure }) }))

const back = vi.hoisted(() => ({ target: null as unknown }))
vi.mock('@/composables/useBackButton', () => ({ useBackButton: (target: unknown) => (back.target = target) }))
vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => ({ haptic: vi.fn(), notificationHaptic: vi.fn() }) }))
vi.mock('@/composables/errorToast', () => ({ showErrorToast: vi.fn() }))
vi.mock('@/composables/useReviewFromPaywall', () => ({ useReviewFromPaywall: () => ({ reviewFromPaywall: vi.fn() }) }))

vi.mock('@/components/PaywallSheet.vue', async () => {
  const { defineComponent, h } = await import('vue')
  return {
    default: defineComponent({
      props: { reason: { type: String, default: 'info' } },
      emits: ['close', 'review'],
      setup: (props, { emit }) => () => h('div', { 'data-testid': 'paywall', 'data-reason': props.reason }, [h('button', { 'data-testid': 'paywall-close', onClick: () => emit('close') })]),
    }),
  }
})

const apiError = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)

async function render(catalogue: GameShop = shop(), coins = 200, path = '/game/caravan') {
  vi.mocked(api.gameShop.wallet).mockResolvedValue(wallet({ balance: coins }))
  vi.mocked(api.gameShop.shop).mockResolvedValue(catalogue)
  vi.mocked(api.gameShop.profile).mockResolvedValue(profile())
  return mountView(path)
}

async function mountView(path = '/game/caravan') {
  const stub = { template: '<div />' }
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/game', name: 'game', component: stub }, { path: '/profile', name: 'profile', component: stub }, { path: '/game/caravan', name: 'game-caravan', component: stub }] })
  await router.push(path)
  const wrapper = mount(GameCaravanView, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountView>>
const tid = (w: Wrapper, id: string) => w.get(`[data-testid="${id}"]`)
const has = (w: Wrapper, id: string) => w.find(`[data-testid="${id}"]`).exists()
const click = async (w: Wrapper, id: string) => {
  await tid(w, id).trigger('click')
  await flushPromises()
}
const shopTab = (w: Wrapper) => click(w, 'tab-shop')

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  authEnsure.mockResolvedValue(undefined)
})
afterEach(() => {
  setLocale('ru')
  document.body.innerHTML = ''
})

describe('GameCaravanView - states', () => {
  it('is a skeleton while it is read, then the wallet tab', async () => {
    let resolve!: (value: ReturnType<typeof wallet>) => void
    vi.mocked(api.gameShop.wallet).mockReturnValue(new Promise((yes) => (resolve = yes)))
    vi.mocked(api.gameShop.shop).mockResolvedValue(shop())
    vi.mocked(api.gameShop.profile).mockResolvedValue(profile())
    const w = await mountView()

    expect(has(w, 'wallet')).toBe(false)
    resolve(wallet())
    await flushPromises()
    expect(has(w, 'wallet')).toBe(true)
    w.unmount()
  })

  it('says "not available yet" on game_unavailable', async () => {
    vi.mocked(api.gameShop.wallet).mockRejectedValue(apiError('forbidden', 403, 'game_unavailable'))
    vi.mocked(api.gameShop.shop).mockResolvedValue(shop())
    vi.mocked(api.gameShop.profile).mockResolvedValue(profile())
    const w = await mountView()

    expect(has(w, 'unavailable')).toBe(true)
    expect(has(w, 'wallet')).toBe(false)
    expect(tid(w, 'to-map').text()).toBe('На карту')
    await click(w, 'to-map')
    expect(w.vm.$router.currentRoute.value.name).toBe('game')
    w.unmount()
  })

  it('shows an error with "Retry" that reads again', async () => {
    vi.mocked(api.gameShop.wallet).mockRejectedValueOnce(apiError('server', 500, 'x')).mockResolvedValue(wallet())
    vi.mocked(api.gameShop.shop).mockResolvedValue(shop())
    vi.mocked(api.gameShop.profile).mockResolvedValue(profile())
    const w = await mountView()

    expect(w.text()).toContain('Повторить')
    await w.get('button.btn').trigger('click')
    await flushPromises()
    expect(has(w, 'wallet')).toBe(true)
    w.unmount()
  })

  it('sends Telegram Back to the map, and to the course the map put in the address (the store of the map is reset when it is left)', async () => {
    const plain = await render()
    expect(back.target).toEqual({ name: 'game' })
    plain.unmount()

    const withCourse = await render(shop(), 200, '/game/caravan?course=english-a1')
    expect(back.target).toEqual({ name: 'game', query: { course: 'english-a1' } })
    withCourse.unmount()
  })

  it('sends Telegram Back to the profile when the learner came from it', async () => {
    const w = await render(shop(), 200, '/game/caravan?from=profile')
    expect(back.target).toEqual({ name: 'profile' })
    w.unmount()
  })
})

describe('GameCaravanView - the profile and the wallet', () => {
  it('shows what is worn, in its slots', async () => {
    const w = await render()

    expect(tid(w, 'worn-camel').find('img').attributes('data-asset')).toBe('camel_dromedary')
    expect(tid(w, 'worn-carpet').find('img').exists()).toBe(false)
    expect(tid(w, 'profile-stats').text()).toContain('4')
    w.unmount()
  })

  it('shows the balance, the tickets as balance of max and the totals', async () => {
    const w = await render()

    expect(tid(w, 'wallet-balance').text()).toBe('200')
    expect(tid(w, 'wallet-tickets').text()).toBe('3 из 10')
    expect(tid(w, 'wallet-tickets-hint').text()).toBe('Билет — один дополнительный забег сверх бесплатного. Билеты дают за каждую неделю серии и за приглашённых друзей.')
    expect(tid(w, 'wallet-totals').text()).toBe('260 / 60')
    w.unmount()
  })

  it('lists the entries with the titles of the server and a sign on the amount', async () => {
    const w = await render()

    expect(w.findAll('[data-testid=ledger-title]').map((n) => n.text())).toEqual(['Стоянка пройдена', 'Покупка в лавке'])
    expect(w.findAll('[data-testid=ledger-amount]').map((n) => n.text())).toEqual(['+10', '−15'])
    w.unmount()
  })

  it('says so when there are no entries', async () => {
    vi.mocked(api.gameShop.wallet).mockResolvedValue(wallet({ ledger: [] }))
    vi.mocked(api.gameShop.shop).mockResolvedValue(shop())
    vi.mocked(api.gameShop.profile).mockResolvedValue(profile())
    const w = await mountView()

    expect(has(w, 'ledger-empty')).toBe(true)
    expect(has(w, 'ledger')).toBe(false)
    w.unmount()
  })
})

describe('GameCaravanView - the shop', () => {
  it('has the five categories and starts with the camels', async () => {
    const w = await render()
    await shopTab(w)

    expect(w.findAll('[role=tab]').map((n) => n.text())).toEqual(['Верблюды', 'Ковры', 'Шатры', 'Рамки', 'Бустеры'])
    expect(w.findAll('[data-testid=item-title]').map((n) => n.text())).toEqual(['Дромедар', 'Бактриан', 'Золотой верблюд'])
    w.unmount()
  })

  it('draws the state of every card: worn, for sale with a price, Pro', async () => {
    const w = await render()
    await shopTab(w)

    expect(tid(w, 'item-camel_dromedary').get('[data-testid=item-equipped]').text()).toBe('Надето')
    expect(tid(w, 'item-camel_dromedary').get('[data-testid=item-unequip]').text()).toBe('Снять')
    expect(tid(w, 'item-camel_bactrian').get('[data-testid=item-buy]').text()).toContain('150')
    expect(tid(w, 'item-camel_golden').find('[data-testid=item-pro]').exists()).toBe(true)
    expect(tid(w, 'item-camel_golden').find('[data-testid=item-buy]').exists()).toBe(false)
    expect(tid(w, 'item-camel_bactrian').find('img').attributes('data-asset')).toBe('camel_bactrian')
    w.unmount()
  })

  it('shows an owned cosmetic as "Wear", a prize of an achievement without a button, a booster with its stock', async () => {
    const w = await render(
      shop([
        shopItem({ owned: true, qty: 1 }),
        shopItem({ key: 'camel_golden', locked_reason: 'achievement', asset: 'camel_golden', title: 'Приз' }),
        booster({ owned: true, qty: 2 }),
      ]),
    )
    await shopTab(w)
    expect(has(w, 'item-equip')).toBe(true)
    expect(has(w, 'item-achievement')).toBe(true)
    expect(tid(w, 'item-camel_golden').find('button').exists()).toBe(false)

    await click(w, 'cat-booster')
    expect(tid(w, 'item-stock').text()).toBe('В запасе: 2')
    expect(has(w, 'item-buy')).toBe(true)
    w.unmount()
  })

  it('says so for an empty category', async () => {
    const w = await render()
    await shopTab(w)
    await click(w, 'cat-tent')

    expect(has(w, 'cat-empty')).toBe(true)
    w.unmount()
  })
})

describe('GameCaravanView - buying', () => {
  async function openBuy(w: Wrapper, key = 'camel_bactrian') {
    await shopTab(w)
    await tid(w, `item-${key}`).get('[data-testid=item-buy]').trigger('click')
    await flushPromises()
  }

  it('asks "Buy for N?" first, and does not buy before the answer', async () => {
    const w = await render()
    await openBuy(w)

    expect(tid(w, 'buy-sheet').text()).toContain('Купить за 150?')
    expect(tid(w, 'buy-balance').text()).toBe('У вас 200')
    expect(api.gameShop.buy).not.toHaveBeenCalled()

    await click(w, 'buy-cancel')
    expect(has(w, 'buy-sheet')).toBe(false)
    w.unmount()
  })

  it('buys on the confirmation: the request carries a request_id, the card becomes "Wear", the balance falls', async () => {
    const w = await render()
    vi.mocked(api.gameShop.buy).mockResolvedValue(wallet({ balance: 50 }))
    await openBuy(w)
    await click(w, 'buy-confirm')

    expect(api.gameShop.buy).toHaveBeenCalledTimes(1)
    expect(vi.mocked(api.gameShop.buy).mock.calls[0]![0]).toBe('camel_bactrian')
    expect(vi.mocked(api.gameShop.buy).mock.calls[0]![1].request_id).toMatch(/^[0-9a-f-]{36}$/)
    expect(has(w, 'buy-sheet')).toBe(false)
    expect(tid(w, 'item-camel_bactrian').find('[data-testid=item-equip]').exists()).toBe(true)
    expect(tid(w, 'bought-live').text()).toContain('Бактриан')
    w.unmount()
  })

  it('tells the shortage of coins on the sheet and keeps it', async () => {
    const w = await render(shop(undefined, 20), 20)
    await openBuy(w)

    expect(has(w, 'buy-short')).toBe(true)
    vi.mocked(api.gameShop.buy).mockRejectedValue(apiError('conflict', 409, 'coins_insufficient', { balance: 20, price: 150 }))
    await click(w, 'buy-confirm')

    expect(has(w, 'buy-sheet')).toBe(true)
    expect(tid(w, 'buy-short').text()).toContain('Монеты — за забеги и хранителей')
    expect(showErrorToast).not.toHaveBeenCalled()
    w.unmount()
  })

  it('item_not_purchasable for Pro opens the paywall of the game', async () => {
    const w = await render()
    vi.mocked(api.gameShop.buy).mockRejectedValue(apiError('conflict', 409, 'item_not_purchasable', { reason: 'pro' }))
    await openBuy(w)
    await click(w, 'buy-confirm')

    expect(has(w, 'buy-sheet')).toBe(false)
    expect(tid(w, 'paywall').attributes('data-reason')).toBe('game')
    w.unmount()
  })

  it('item_not_purchasable for a prize is a toast', async () => {
    const w = await render()
    vi.mocked(api.gameShop.buy).mockRejectedValue(apiError('conflict', 409, 'item_not_purchasable', { reason: 'achievement' }))
    await openBuy(w)
    await click(w, 'buy-confirm')

    expect(showErrorToast).toHaveBeenCalledWith('Этот предмет — награда за достижение.')
    expect(has(w, 'paywall')).toBe(false)
    w.unmount()
  })

  it('opens the paywall at once for a Pro item, without a request', async () => {
    const w = await render()
    await shopTab(w)
    await click(w, 'item-pro-btn')

    expect(tid(w, 'paywall').attributes('data-reason')).toBe('game')
    expect(api.gameShop.buy).not.toHaveBeenCalled()
    await click(w, 'paywall-close')
    expect(has(w, 'paywall')).toBe(false)
    w.unmount()
  })

  it('item_already_owned closes the sheet with a toast and the card turns into "Wear"', async () => {
    const w = await render()
    vi.mocked(api.gameShop.buy).mockRejectedValue(apiError('conflict', 409, 'item_already_owned'))
    await openBuy(w)
    await click(w, 'buy-confirm')

    expect(has(w, 'buy-sheet')).toBe(false)
    expect(showErrorToast).toHaveBeenCalledTimes(1)
    expect(tid(w, 'item-camel_bactrian').find('[data-testid=item-equip]').exists()).toBe(true)
    w.unmount()
  })

  it('a network error keeps the sheet; the second try goes under the same request_id', async () => {
    const w = await render()
    vi.mocked(api.gameShop.buy).mockRejectedValueOnce(apiError('network', 0, 'network')).mockResolvedValue(wallet({ balance: 50 }))
    await openBuy(w)
    await click(w, 'buy-confirm')

    expect(has(w, 'buy-sheet')).toBe(true)
    expect(showErrorToast).toHaveBeenCalledTimes(1)
    await click(w, 'buy-confirm')

    const [first, second] = vi.mocked(api.gameShop.buy).mock.calls
    expect(second![1].request_id).toBe(first![1].request_id)
    expect(has(w, 'buy-sheet')).toBe(false)
    w.unmount()
  })
})

describe('GameCaravanView - wearing', () => {
  const owned = () => shop([shopItem({ key: 'camel_dromedary', price: 0, owned: true, qty: 1, equipped: true, asset: 'camel_dromedary', title: 'Дромедар' }), shopItem({ owned: true, qty: 1 })])

  it('wears at once, and the profile shows it', async () => {
    const w = await render(owned())
    vi.mocked(api.gameShop.equip).mockReturnValue(new Promise(() => undefined))
    await shopTab(w)
    await click(w, 'item-equip')

    expect(api.gameShop.equip).toHaveBeenCalledWith({ slot: 'camel', item_key: 'camel_bactrian' })
    expect(tid(w, 'worn-camel').find('img').attributes('data-asset')).toBe('camel_bactrian')
    expect(tid(w, 'item-camel_bactrian').find('[data-testid=item-equipped]').exists()).toBe(true)
    w.unmount()
  })

  it('takes the worn item off at once (item_key = null), and the slot goes empty', async () => {
    const w = await render(owned())
    vi.mocked(api.gameShop.equip).mockReturnValue(new Promise(() => undefined))
    await shopTab(w)
    await click(w, 'item-unequip')

    expect(api.gameShop.equip).toHaveBeenCalledWith({ slot: 'camel', item_key: null })
    expect(tid(w, 'worn-camel').find('img').exists()).toBe(false)
    expect(tid(w, 'item-camel_dromedary').find('[data-testid=item-equipped]').exists()).toBe(false)
    expect(tid(w, 'item-camel_dromedary').find('[data-testid=item-equip]').exists()).toBe(true)
    w.unmount()
  })

  it('puts the item back and says why when taking it off is refused', async () => {
    const w = await render(owned())
    vi.mocked(api.gameShop.equip).mockRejectedValue(apiError('server', 500, 'x'))
    await shopTab(w)
    await click(w, 'item-unequip')

    expect(tid(w, 'worn-camel').find('img').attributes('data-asset')).toBe('camel_dromedary')
    expect(tid(w, 'item-camel_dromedary').find('[data-testid=item-equipped]').exists()).toBe(true)
    expect(showErrorToast).toHaveBeenCalled()
    w.unmount()
  })

  it('goes back and says why when the server refuses (404 item_not_found)', async () => {
    const w = await render(owned())
    vi.mocked(api.gameShop.equip).mockRejectedValue(apiError('not_found', 404, 'item_not_found'))
    await shopTab(w)
    await click(w, 'item-equip')

    expect(tid(w, 'worn-camel').find('img').attributes('data-asset')).toBe('camel_dromedary')
    expect(tid(w, 'item-camel_dromedary').find('[data-testid=item-equipped]').exists()).toBe(true)
    expect(showErrorToast).toHaveBeenCalledWith('Такого предмета нет.')
    w.unmount()
  })
})
