import { defineStore } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { nextReset, reachedLimit, shownLimit } from '@/composables/practiceLimits'
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
  PracticeAccess,
  PracticeLimit,
  PracticeLimitHit,
  PracticeLimitKind,
  PracticeLimits,
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
  /** The `practice` block of `/me` (docs/16 §3.3); null until `/me` has come, or when the server sent none. Its `limits` are kept in `limits`. */
  practice: PracticeAccess | null
  /**
   * The Free limits of today's practice and battles (docs/18 §3, G1): from `/me`, from the practice hub, and from the 201 that creates a
   * session or a battle, which is the newest word on them. null: Pro, no limit, or not told yet - nothing is shown and nothing is blocked.
   * A kind can be null on its own (the server has no limit of that kind): it is treated the same way, the other kind goes on as usual.
   */
  limits: PracticeLimits | null
  /**
   * The moment (`Date.now()`) the counters of `limits` are judged at. The getters read it instead of the clock, so what they give moves with
   * time: `watchDay` sets it again when a counter's day ends, and with every new word on the counters.
   */
  now: number
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

/** The timer that wakes a store when the day of its nearest counter ends (`watchDay`); one per store. */
const dayTimers = new WeakMap<object, ReturnType<typeof setTimeout>>()

/** A little after the moment, so that the clock is surely past it when the store looks again (a timer may fire a hair early). */
const DAY_SLACK_MS = 100

/** `setTimeout` takes a signed 32-bit number of milliseconds and fires at once with more: a day that ends later is waited for in steps. */
const MAX_TIMER_MS = 2 ** 31 - 1

/** The `/me` request that is in flight, one per store: whoever asks for `/me` meanwhile gets ITS answer instead of a second request (`load`). */
const meRequests = new WeakMap<object, Promise<void>>()

/** The part of the store that a reading of `/me` writes into. */
interface MeTarget {
  loading: boolean
  loadedAt: number | null
  apply: (me: MeResponse) => void
}

/** One reading of `/me`, best effort: it never throws - a failure keeps what the store had and makes the next `load()` ask again. */
async function readMe(store: MeTarget): Promise<void> {
  store.loading = true
  try {
    store.apply(await api.me.get())
  } catch (err) {
    // keep whatever we had (a screen can still show it), but never call it fresh: the next load() asks again
    store.loadedAt = null
    console.warn('billing: failed to load /me', err)
  } finally {
    store.loading = false
  }
}

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
    practice: null,
    limits: null,
    now: Date.now(),
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

    /** What the learner may do with practice; "nothing" until `/me` says otherwise, so no entry shows by mistake. */
    practiceAccess: (state): PracticeAccess => state.practice ?? { available: false, pro_required: false, modes: [], battle: false },

    /**
     * The counter of today's practice sessions to show; null for Pro, for sessions without a limit, for sessions that are for Pro only (limit 0:
     * nothing to count), when none was told, and once its day is over (it says nothing then - and a screen left open overnight redraws by
     * itself at that moment: the getter reads `now`, which `watchDay` moves). The wall (`limitReached`) knows the limit 0.
     */
    sessionLimit: (state): PracticeLimit | null => shownLimit(state.limits?.sessions, state.now),

    /** The counter of the battles the learner starts today (accepting one is never counted); null as above. */
    battleLimit: (state): PracticeLimit | null => shownLimit(state.limits?.battles, state.now),
  },

  actions: {
    /**
     * Best effort: the paywall still works without it (no support contact, no numeric limit).
     * Without `force` nothing is requested while the stored data is younger than `ME_FRESH_MS` and the last request
     * did not fail. A request already in flight is not repeated either way: whoever asks meanwhile, `force` or not, WAITS for its
     * answer and finds it in the store (`confirmLimit` decides by it).
     */
    async load({ force = false }: { force?: boolean } = {}): Promise<void> {
      if (this.loading) return meRequests.get(this)
      if (!force && this.loadedAt !== null && Date.now() - this.loadedAt < ME_FRESH_MS) return

      const request = readMe(this)
      meRequests.set(this, request)
      void request.then(() => {
        // a newer request (after a payment, `onPaid`) may have taken the place of this one meanwhile
        if (meRequests.get(this) === request) meRequests.delete(this)
      })
      return request
    },

    /** Takes a `/me` (or an answer that carries the same `entitlement` / `paywall`) and marks the data fresh. */
    apply(me: Pick<MeResponse, 'entitlement' | 'paywall' | 'practice'>): void {
      this.entitlement = me.entitlement
      this.paywall = me.paywall
      if (me.practice) {
        // The limits live apart: the hub and the creation of a session or a battle bring newer ones than this `/me`.
        const { limits, ...access } = me.practice
        this.practice = access
        this.setLimits(limits)
      } else {
        this.practice = null
      }
      this.loadedAt = Date.now()
    },

    /** An answer that carries the counters (`/me`, the hub, the 201 of a session or a battle). `undefined`: it did not say - nothing changes. */
    setLimits(limits: PracticeLimits | null | undefined): void {
      if (limits === undefined) return
      this.limits = limits
      this.watchDay()
    },

    /**
     * Keeps `now` true, the moment the counters are judged at: the getters `sessionLimit` / `battleLimit` read it, so a line drawn for today
     * goes by itself when the day of its counter ends - an app left open overnight does not keep yesterday's "0 of 2" on the screen. One
     * timer per store, set for the nearest end of a day among the counters and only while there is one; every new word on the counters
     * sets it again. (`useNow` is a clock of a component and stops with it, a store has no such scope; it would also tick all the time,
     * where this wakes once, at the very moment.)
     */
    watchDay(): void {
      const pending = dayTimers.get(this)
      if (pending !== undefined) clearTimeout(pending)
      dayTimers.delete(this)

      this.now = Date.now()
      const at = nextReset(this.limits, this.now)
      if (at === null) return
      dayTimers.set(this, setTimeout(() => this.watchDay(), Math.min(at - this.now + DAY_SLACK_MS, MAX_TIMER_MS)))
    },

    /**
     * The server refused for today's limit: that counter is spent, whatever this screen believed - a counter that was not there included
     * (the learner taken for Pro, a kind taken for unlimited). The other kind is not touched; when no counters were told at all it stays
     * `null` (no counter: nothing shown, nothing stopped) until the next answer says more.
     */
    noteLimitHit(hit: PracticeLimitHit): void {
      const spent: PracticeLimit = { limit: hit.limit, used: hit.used, remaining: 0, resets_at: hit.resets_at }
      this.limits = { sessions: null, battles: null, ...this.limits, [hit.kind]: spent }
      this.watchDay()
    },

    /** The wall of `kind` if today's free allowance of it is spent (the paywall opens without a request), else null: the server decides. */
    limitReached(kind: PracticeLimitKind): PracticeLimitHit | null {
      return reachedLimit(this.limits, kind)
    },

    /**
     * The counter of `kind` is not known any more: a place may have been given back (a battle that did not take place is not counted,
     * and the screen may have seen it close). Nothing is shown for it and nothing is stopped by it, and the next reading of `/me` asks the
     * server instead of the cache - that brings the counter back with the right number.
     */
    forgetLimit(kind: PracticeLimitKind): void {
      if (this.limits !== null) {
        this.limits = { ...this.limits, [kind]: null }
        this.watchDay()
      }
      this.loadedAt = null
    },

    /**
     * The wall of `kind` that the SERVER stands behind. A counter that says the free allowance is spent may be old (a battle that never
     * started gives its place back, and this screen may not have seen it), so `/me` is read again first and the wall is what the FRESH
     * counter says. A `/me` that is already on its way (the screen's own reading at its start) is waited for - its answer is the newest
     * there is, and the stale zero must not decide before it - else a new one is asked for, whatever the cache says. Null: nothing is
     * spent, the server gave a place back, or it could not be asked (no network) - the action itself then goes to the server and tells what
     * is wrong, rather than a sales pitch on a number that cannot be checked. A kind that is for Pro only (limit 0) needs no question:
     * nothing gives a place back there.
     */
    async confirmLimit(kind: PracticeLimitKind): Promise<PracticeLimitHit | null> {
      const remembered = this.limitReached(kind)
      if (remembered === null || remembered.limit === 0) return remembered
      await this.load({ force: true })
      return this.loadedAt === null ? null : this.limitReached(kind)
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
      this.loading = false // a /me request still in flight predates the payment: `load` does not join it, it asks again
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
