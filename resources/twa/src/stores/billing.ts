import { defineStore } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { useTelegram } from '@/composables/useTelegram'
import { t } from '@/i18n'
import { errorMessage } from '@/i18n/errors'
import { useTodayStore } from '@/stores/today'
import type {
  BillingCatalog,
  BillingPeriod,
  BillingProviderKey,
  Entitlement,
  MeResponse,
  Paywall,
} from '@/api/types'

/**
 * idle → starting (order being created) → awaiting (paying / waiting for the confirmation) → paid | failed | timeout;
 * `replaced`: the order was replaced by a newer one we do not know — informational, not an error.
 */
export type PurchaseStatus = 'idle' | 'starting' | 'awaiting' | 'paid' | 'failed' | 'timeout' | 'replaced'

interface PurchaseState {
  status: PurchaseStatus
  /** Token of the order being paid; polling stops when it changes. */
  orderToken: string | null
  /** Localized text for `failed`. */
  error: string
  /** Localized information shown next to `awaiting` / `replaced` (e.g. the order was replaced by a new one). */
  notice: string
}

interface BillingState {
  entitlement: Entitlement | null
  paywall: Paywall | null
  loading: boolean
  catalog: BillingCatalog | null
  catalogLoading: boolean
  purchase: PurchaseState
  /** Cancel / resume in flight. */
  acting: boolean
  actionError: string
  /**
   * When `entitlement` / `paywall` were last taken from the server (`Date.now()`); null = never, or the last
   * request failed / the plan may have changed since (`invalidate()`), so the next `load()` has to ask.
   */
  loadedAt: number | null
}

/** The payment is confirmed by a webhook, so the TWA asks again: not more often than every 3 s, for 2 minutes. */
export const POLL_INTERVAL_MS = 3000
export const POLL_ATTEMPTS = 40

/**
 * `/me` younger than this is taken from the store: the Today banner, the Profile tab, the Subscription screen and
 * the paywall all ask for it, and the plan changes rarely (and every change goes through `apply()` / `load({ force })`).
 */
export const ME_FRESH_MS = 30_000

const idlePurchase = (): PurchaseState => ({ status: 'idle', orderToken: null, error: '', notice: '' })

const sleep = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms))

/**
 * Plan, paywall settings and the purchase flow of the learner (docs/13 §5, docs/04 A.8): `GET /me`
 * (loaded when a paywall is about to be shown, refreshed from the answer of a promo redemption or of
 * cancel / resume), the catalog, and `buy()` — checkout → Telegram invoice / payment page → polling of
 * the order until the webhook has turned it `paid`.
 *
 * `/me` is kept for `ME_FRESH_MS`: `load()` does not ask again while the data is that young, so opening the
 * Profile tab, the Subscription screen and a paywall one after another costs one request. `load({ force: true })`
 * always asks (the Subscription screen on open, a confirmed payment); `invalidate()` makes the next `load()` ask
 * (the learner left a center, so the plan may have changed).
 */
export const useBillingStore = defineStore('billing', {
  state: (): BillingState => ({
    entitlement: null,
    paywall: null,
    loading: false,
    catalog: null,
    catalogLoading: false,
    purchase: idlePurchase(),
    acting: false,
    actionError: '',
    loadedAt: null,
  }),

  getters: {
    /** The server only reports `purchase` when something is for sale; the catalog is the second check. */
    canPurchase: (state): boolean => state.paywall?.mode === 'purchase' && (state.catalog?.items.length ?? 0) > 0,
  },

  actions: {
    /**
     * Best effort: the paywall still works without it (no support contact, no numeric limit).
     * Without `force` nothing is requested while the stored data is younger than `ME_FRESH_MS` and the last request
     * did not fail; a request already in flight is not repeated either way.
     */
    async load({ force = false }: { force?: boolean } = {}): Promise<void> {
      if (this.loading) return
      if (!force && this.loadedAt !== null && Date.now() - this.loadedAt < ME_FRESH_MS) return

      this.loading = true
      try {
        this.apply(await api.me.get())
      } catch (err) {
        // keep whatever we had (a screen can still show it), but never call it fresh: the next load() asks again
        this.loadedAt = null
        console.warn('billing: failed to load /me', err)
      } finally {
        this.loading = false
      }
    },

    /** Takes a `/me` (or an answer that carries the same `entitlement` / `paywall`) and marks the data fresh. */
    apply(me: Pick<MeResponse, 'entitlement' | 'paywall'>): void {
      this.entitlement = me.entitlement
      this.paywall = me.paywall
      this.loadedAt = Date.now()
    },

    /** The plan may have changed behind our back (e.g. the learner left a center): the next `load()` asks the server. */
    invalidate(): void {
      this.loadedAt = null
    },

    /** Best effort, like `load()`: without a catalog the sheet keeps showing "coming soon". */
    async loadCatalog(): Promise<void> {
      if (this.catalogLoading) return
      this.catalogLoading = true
      try {
        this.catalog = await api.billing.catalog()
      } catch (err) {
        console.warn('billing: failed to load the catalog', err)
      } finally {
        this.catalogLoading = false
      }
    },

    resetPurchase(): void {
      this.purchase = idlePurchase()
    },

    /** Creates the order, opens the payment and waits for the confirmation. */
    async buy(period: BillingPeriod, provider: BillingProviderKey): Promise<void> {
      if (this.purchase.status === 'starting') return

      this.purchase = { status: 'starting', orderToken: null, error: '', notice: '' }

      let url: string
      let kind: 'invoice_link' | 'redirect'
      let token: string
      try {
        const created = await api.billing.checkout(period, provider)
        token = created.order.token
        url = created.checkout.url
        kind = created.checkout.kind
      } catch (err) {
        this.fail(err)
        return
      }

      this.purchase = { status: 'awaiting', orderToken: token, error: '', notice: '' }

      if (kind === 'invoice_link') {
        const result = await useTelegram().openInvoice(url)
        if (this.purchase.orderToken !== token) return

        if (result === 'cancelled') {
          this.resetPurchase()
          return
        }
        if (result === 'failed') {
          this.fail(null)
          return
        }
        // `paid` and `pending` both wait for the webhook: the invoice callback is not the confirmation.
      } else {
        useTelegram().openLink(url)
      }

      await this.waitForPayment()
    },

    /** Polls the current order until it is paid or dead; also the "check again" button after a timeout. */
    async waitForPayment(): Promise<void> {
      let token = this.purchase.orderToken
      if (token === null) return

      this.purchase.status = 'awaiting'

      for (let attempt = 0; attempt < POLL_ATTEMPTS; attempt++) {
        if (attempt > 0) await sleep(POLL_INTERVAL_MS)
        if (this.purchase.orderToken !== token) return // reset or a new purchase

        try {
          const order = await api.billing.order(token)
          if (order.status === 'paid') {
            await this.onPaid()
            return
          }
          if (order.status === 'canceled' && order.reason === 'replaced') {
            // Not an error: the discount moved to another order. Follow it when we know it.
            if (order.replaced_by !== null && order.replaced_by !== token) {
              token = order.replaced_by
              this.purchase.orderToken = token
              this.purchase.notice = t('billing.order_replaced_follow')
              continue
            }
            this.purchase = { status: 'replaced', orderToken: null, error: '', notice: t('billing.order_replaced') }
            return
          }
          if (order.status !== 'pending') {
            this.fail(null)
            return
          }
        } catch (err) {
          // a network blip must not end the wait; anything else is final
          if (!(err instanceof ApiClientError) || err.kind !== 'network') {
            this.fail(err)
            return
          }
        }
      }

      if (this.purchase.orderToken === token) this.purchase.status = 'timeout'
    },

    async cancelRenewal(): Promise<void> {
      await this.act(() => api.billing.cancel())
    },

    async resumeRenewal(): Promise<void> {
      await this.act(() => api.billing.resume())
    },

    async act(call: () => Promise<MeResponse>): Promise<void> {
      if (this.acting) return
      this.acting = true
      this.actionError = ''
      try {
        this.apply(await call())
        await this.loadCatalog()
      } catch (err) {
        this.actionError = errorMessage(err, 'today')
      } finally {
        this.acting = false
      }
    },

    async onPaid(): Promise<void> {
      this.purchase.status = 'paid'
      this.loading = false // a /me request still in flight predates the payment; ask again
      await Promise.all([this.load({ force: true }), this.loadCatalog()])
      // The numbers on the Today screen (quota, plan badge) change with the plan.
      void useTodayStore().load()
    },

    fail(err: unknown): void {
      this.purchase = {
        status: 'failed',
        orderToken: null,
        error: err === null ? t('billing.payment_failed') : errorMessage(err, 'today'),
        notice: '',
      }
    },
  },
})
