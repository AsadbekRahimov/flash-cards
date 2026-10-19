import { defineStore } from 'pinia'
import { api } from '@/api'
import type { Entitlement, MeResponse, Paywall } from '@/api/types'

interface BillingState {
  entitlement: Entitlement | null
  paywall: Paywall | null
  loading: boolean
}

/**
 * Plan and paywall settings of the learner (`GET /me`), loaded when a paywall is about to be shown
 * and refreshed from the answer of a successful promo redemption.
 */
export const useBillingStore = defineStore('billing', {
  state: (): BillingState => ({
    entitlement: null,
    paywall: null,
    loading: false,
  }),

  actions: {
    /** Best effort: the paywall still works without it (no support contact, no numeric limit). */
    async load(): Promise<void> {
      if (this.loading) return
      this.loading = true
      try {
        this.apply(await api.me.get())
      } catch (err) {
        // keep whatever we had, but leave a trace for debugging
        console.warn('billing: failed to load /me', err)
      } finally {
        this.loading = false
      }
    },

    apply(me: Pick<MeResponse, 'entitlement' | 'paywall'>): void {
      this.entitlement = me.entitlement
      this.paywall = me.paywall
    },
  },
})
