import { defineStore } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { newRequestId } from '@/composables/requestId'
import { errorMessage } from '@/i18n/errors'
import type { GameItemSlot, GameProfile, GameShop, GameShopItem, GameWallet } from '@/api/gameShopTypes'

/** idle → loading (the wallet, the shop and the profile are being read) → ready; unavailable (403 `game_unavailable`) and error end it. */
export type GameShopStatus = 'idle' | 'loading' | 'ready' | 'unavailable' | 'error'

/** Why an action was refused: what the screen decides by (`code`, `details`) and a localized text for a toast. */
export interface GameShopError {
  code: string
  kind: ApiClientError['kind'] | 'unexpected'
  details: Record<string, unknown> | null
  message: string
}

export type GameShopResult = { ok: true } | { ok: false; error: GameShopError }

interface GameShopState {
  wallet: GameWallet | null
  shop: GameShop | null
  profile: GameProfile | null
  status: GameShopStatus
  error: string
  /** Number of the newest read: an older answer that comes late is dropped. */
  seq: number
  /** The key of the item being bought. */
  buying: string | null
  /** The purchase that went out without a reply (network, 5xx, 429): a repeat of the same item carries the same id. */
  pendingBuy: { key: string; requestId: string } | null
  equipping: boolean
}

function refusal(err: unknown): GameShopResult {
  const message = errorMessage(err, 'game')
  if (err instanceof ApiClientError) return { ok: false, error: { code: err.code, kind: err.kind, details: err.details, message } }
  return { ok: false, error: { code: 'unexpected', kind: 'unexpected', details: null, message } }
}

/** A failure after which the server may or may not have counted the request. */
const isUncertain = (err: unknown): boolean =>
  err instanceof ApiClientError && (err.kind === 'network' || err.kind === 'server' || err.kind === 'rate_limited')

/**
 * "My caravan" (docs/05 §5b.5, docs/18 §12.8): the wallet, the shop and what the learner wears.
 *
 * - A purchase has a `request_id` that lives until the server has answered for good: after a lost reply the repeat carries the same id,
 *   so the coins go once. A reply is the wallet after the purchase.
 * - Wearing an item is optimistic: the screen changes at once and goes back to the snapshot if the server refuses.
 */
export const useGameShopStore = defineStore('gameShop', {
  state: (): GameShopState => ({
    wallet: null,
    shop: null,
    profile: null,
    status: 'idle',
    error: '',
    seq: 0,
    buying: null,
    pendingBuy: null,
    equipping: false,
  }),

  getters: {
    balance: (s): number => s.wallet?.balance ?? s.shop?.balance ?? 0,
    items: (s): GameShopItem[] => s.shop?.items ?? [],
  },

  actions: {
    async load(): Promise<void> {
      const seq = ++this.seq
      this.status = 'loading'
      this.error = ''
      try {
        const [wallet, shop, profile] = await Promise.all([api.gameShop.wallet(), api.gameShop.shop(), api.gameShop.profile()])
        if (seq !== this.seq) return
        this.wallet = wallet
        this.shop = shop
        this.profile = profile
        this.status = 'ready'
      } catch (err) {
        if (seq !== this.seq) return
        if (err instanceof ApiClientError && err.code === 'game_unavailable') {
          this.status = 'unavailable'
          return
        }
        this.status = 'error'
        this.error = errorMessage(err, 'game')
      }
    },

    /** The catalogue again (after `item_not_found`, a purchase that the server says is not needed): quietly, the screen stays. */
    async reloadShop(): Promise<void> {
      try {
        const shop = await api.gameShop.shop()
        this.shop = shop
      } catch {
        // the screen keeps what it has
      }
    },

    /** Buys the item: `{ok: true}`, or the refusal typed (never thrown). A second call while one is on its way does nothing. */
    async buy(key: string): Promise<GameShopResult> {
      if (this.buying !== null) return { ok: false, error: { code: 'busy', kind: 'unexpected', details: null, message: '' } }
      const pending = this.pendingBuy?.key === key ? this.pendingBuy : { key, requestId: newRequestId() }
      this.pendingBuy = pending
      this.buying = key
      try {
        const wallet = await api.gameShop.buy(key, { request_id: pending.requestId })
        this.pendingBuy = null
        this.applyPurchase(key, wallet)
        return { ok: true }
      } catch (err) {
        if (!isUncertain(err)) this.pendingBuy = null
        if (err instanceof ApiClientError) {
          if (err.code === 'coins_insufficient') {
            const balance = err.details?.balance
            if (typeof balance === 'number') this.setBalance(balance)
          } else if (err.code === 'item_already_owned') {
            this.patchItem(key, (item) => ({ ...item, owned: true, qty: Math.max(item.qty, 1) }))
          } else if (err.code === 'item_not_found') {
            void this.reloadShop()
          }
        }
        return refusal(err)
      } finally {
        this.buying = null
      }
    },

    applyPurchase(key: string, wallet: GameWallet): void {
      this.wallet = wallet
      this.setBalance(wallet.balance)
      this.patchItem(key, (item) => (item.kind === 'booster' ? { ...item, owned: true, qty: item.qty + 1 } : { ...item, owned: true, qty: 1 }))
    },

    setBalance(balance: number): void {
      if (this.shop !== null) this.shop = { ...this.shop, balance }
      if (this.wallet !== null) this.wallet = { ...this.wallet, balance }
    },

    patchItem(key: string, change: (item: GameShopItem) => GameShopItem): void {
      if (this.shop === null) return
      this.shop = { ...this.shop, items: this.shop.items.map((item) => (item.key === key ? change(item) : item)) }
    },

    /**
     * Puts the item on (`key`) or takes the slot's item off (`null`). The screen changes first; when the server refuses, the snapshot comes
     * back, and 404 `item_not_found` (the item is not in the inventory) reads the shop again.
     */
    async equip(slot: GameItemSlot, key: string | null): Promise<GameShopResult> {
      if (this.equipping || this.profile === null || this.shop === null) return { ok: false, error: { code: 'busy', kind: 'unexpected', details: null, message: '' } }
      const before = { profile: this.profile, shop: this.shop }
      const item = key === null ? null : (this.shop.items.find((i) => i.key === key) ?? null)
      const equipped = { ...this.profile.equipped }
      if (item === null) delete equipped[slot]
      else equipped[slot] = { key: item.key, asset: item.asset }
      this.profile = { ...this.profile, equipped }
      this.shop = { ...this.shop, items: this.shop.items.map((i) => (i.slot === slot ? { ...i, equipped: i.key === key } : i)) }
      this.equipping = true
      try {
        const profile = await api.gameShop.equip({ slot, item_key: key })
        this.profile = profile
        return { ok: true }
      } catch (err) {
        this.profile = before.profile
        this.shop = before.shop
        if (err instanceof ApiClientError && err.code === 'item_not_found') void this.reloadShop()
        return refusal(err)
      } finally {
        this.equipping = false
      }
    },

    reset(): void {
      this.seq++
      this.wallet = null
      this.shop = null
      this.profile = null
      this.status = 'idle'
      this.error = ''
      this.buying = null
      this.pendingBuy = null
      this.equipping = false
    },
  },
})
