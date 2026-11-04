/**
 * The wallet, the shop and the profile of "Karvon" (docs/18 §12.8, docs/04 A.18 "Кошелёк, лавка, профиль"), and the boosters of a run.
 */

export type GameItemSlot = 'camel' | 'carpet' | 'tent' | 'frame'
export type GameBoosterEffect = 'hint' | 'extra_flask' | 'second_chance'
export type GameItemKind = 'cosmetic' | 'booster'

/** One entry of the ledger of coins: `amount` is signed, `title` is the server's words for the kind of entry. */
export interface GameLedgerEntry {
  amount: number
  kind: string
  created_at: string
  title: string
}

/** `GET /game/wallet`, and the reply of a purchase. */
export interface GameWallet {
  balance: number
  earned_total: number
  spent_total: number
  tickets: { balance: number; max: number }
  ledger: GameLedgerEntry[]
}

/** `pro`: for Pro only and not bought yet; `achievement`: a prize, not for sale. */
export type GameItemLock = 'pro' | 'achievement'

export interface GameShopItem {
  key: string
  kind: GameItemKind
  /** null for a booster. */
  slot: GameItemSlot | null
  /** null for a cosmetic. */
  effect: GameBoosterEffect | null
  price: number
  availability: string
  owned: boolean
  /** Boosters in stock (a cosmetic: 1 or 0). */
  qty: number
  equipped: boolean
  locked_reason: GameItemLock | null
  title: string
  /** `null` for an item with no text of its own (the server's column is nullable). */
  description: string | null
  /** The name of the picture (`assets/game`), not a URL; `null` for an item with no picture. */
  asset: string | null
}

export interface GameShop {
  items: GameShopItem[]
  balance: number
}

export interface GameEquippedItem {
  key: string
  asset: string | null
}

/** `equipped` is `{}` until something is worn. */
export interface GameProfile {
  equipped: Partial<Record<GameItemSlot, GameEquippedItem>>
  stops_cleared: number
  cities_cleared: number
  coins: number
  tickets: number
}

export interface GameBuyRequest {
  /** uuid: the key of the purchase, the same one for a repeat of it. */
  request_id: string
}

export interface GameEquipRequest {
  slot: GameItemSlot
  /** null takes the item of the slot off. */
  item_key: string | null
}

/** `POST /game/runs/{id}/booster`. */
export interface GameBoosterRequest {
  request_id: string
  item_key: string
  /** `hint` only: the element the hint is for (default: the current one). */
  element_id?: string
}

/** `boosters` of a run: what the learner has in stock (qty > 0) and what was used in this run (`element_id` only for a hint). */
export interface GameRunBoosters {
  available: { key: string; effect: GameBoosterEffect; qty: number }[]
  used: { key: string; effect: GameBoosterEffect; element_id: string | null }[]
}

/** `details` of 409 `booster_limit_reached`. */
export interface GameBoosterLimitHit {
  limit: number
  used: number
  resets_at: string
  /** `false` for a Pro learner (G2.13): the paywall "buy Pro" is not shown, a toast with the date is; absent on an older server = sell. */
  upsell?: boolean
}
