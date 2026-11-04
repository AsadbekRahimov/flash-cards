import type { GameProfile, GameShop, GameShopItem, GameWallet } from '../gameShopTypes'

export const shopItem = (overrides: Partial<GameShopItem> = {}): GameShopItem => ({
  key: 'camel_bactrian',
  kind: 'cosmetic',
  slot: 'camel',
  effect: null,
  price: 150,
  availability: 'base',
  owned: false,
  qty: 0,
  equipped: false,
  locked_reason: null,
  title: 'Бактриан',
  description: 'Двугорбый.',
  asset: 'camel_bactrian',
  ...overrides,
})

export const booster = (overrides: Partial<GameShopItem> = {}): GameShopItem =>
  shopItem({ key: 'booster_hint', kind: 'booster', slot: null, effect: 'hint', price: 15, title: 'Подсказка', description: 'Убирает два варианта.', asset: 'booster_hint', ...overrides })

/** The base catalogue: the free camel is worn, the second camel is for sale, the golden one is for Pro, a carpet, a hint. */
export function shop(items: GameShopItem[] | null = null, balance = 200): GameShop {
  return {
    balance,
    items: items ?? [
      shopItem({ key: 'camel_dromedary', price: 0, owned: true, qty: 1, equipped: true, title: 'Дромедар', asset: 'camel_dromedary' }),
      shopItem(),
      shopItem({ key: 'camel_golden', price: 400, availability: 'exclusive', locked_reason: 'pro', title: 'Золотой верблюд', asset: 'camel_golden' }),
      shopItem({ key: 'carpet_bukhara', slot: 'carpet', price: 80, title: 'Бухарский ковёр', asset: 'carpet_bukhara' }),
      booster(),
    ],
  }
}

export function wallet(overrides: Partial<GameWallet> = {}): GameWallet {
  return {
    balance: 200,
    earned_total: 260,
    spent_total: 60,
    tickets: { balance: 3, max: 10 },
    ledger: [
      { amount: 10, kind: 'stop_clear', created_at: '2026-11-04T10:00:00.000Z', title: 'Стоянка пройдена' },
      { amount: -15, kind: 'item_purchase', created_at: '2026-11-04T09:00:00.000Z', title: 'Покупка в лавке' },
    ],
    ...overrides,
  }
}

export function profile(overrides: Partial<GameProfile> = {}): GameProfile {
  return {
    equipped: { camel: { key: 'camel_dromedary', asset: 'camel_dromedary' } },
    stops_cleared: 4,
    cities_cleared: 1,
    coins: 200,
    tickets: 3,
    ...overrides,
  }
}
