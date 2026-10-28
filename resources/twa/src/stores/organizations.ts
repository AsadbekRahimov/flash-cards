import { defineStore } from 'pinia'
import { api } from '@/api'
import { errorMessage } from '@/i18n/errors'
import { useBillingStore } from '@/stores/billing'
import type { OrganizationMembership } from '@/api/types'

export type OrganizationsStatus = 'idle' | 'loading' | 'ready' | 'error'

interface OrganizationsState {
  items: OrganizationMembership[]
  status: OrganizationsStatus
  /** a DELETE is in flight: the buttons are disabled so a double tap cannot send it twice */
  leaving: boolean
  errorMessage: string
}

/**
 * "My centers" of the settings screen (docs/04 A.11). It lives apart from the settings store: a failure here
 * must not hide the settings themselves, and most learners are in no center at all (the block is then absent).
 */
export const useOrganizationsStore = defineStore('organizations', {
  state: (): OrganizationsState => ({
    items: [],
    status: 'idle',
    leaving: false,
    errorMessage: '',
  }),

  actions: {
    async load(): Promise<void> {
      this.errorMessage = ''
      try {
        this.items = (await api.organizations.list()).organizations
        this.status = 'ready'
      } catch (err) {
        this.status = 'error'
        this.errorMessage = errorMessage(err, 'settings')
      }
    },

    /**
     * Leaves a center; the server's refreshed list replaces the data. When it is refused (somebody else's or an
     * already ended membership, a `group` one) `errorMessage` says why and the list is taken again, so the screen
     * matches the server. Once left, the plan may have changed (the center's Pro is gone), so the billing store
     * forgets how fresh its `/me` was and the next screen asks again.
     *
     * @returns whether the center was left
     */
    async leave(membershipId: number): Promise<boolean> {
      if (this.leaving) return false

      this.leaving = true
      this.errorMessage = ''
      try {
        this.items = (await api.organizations.leave(membershipId)).organizations
        useBillingStore().invalidate()
        return true
      } catch (err) {
        const message = errorMessage(err, 'settings')
        await this.load()
        this.errorMessage = message
        return false
      } finally {
        this.leaving = false
      }
    },
  },
})
