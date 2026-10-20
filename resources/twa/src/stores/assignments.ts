import { defineStore } from 'pinia'
import { api } from '@/api'
import type { AssignmentItem } from '@/api/types'

interface AssignmentsState {
  items: AssignmentItem[]
  loaded: boolean
}

/**
 * "Assignments of the center" on Today (docs/04 A.9). It never breaks the screen: a failed load
 * simply leaves the block out — the rest of Today works without it.
 */
export const useAssignmentsStore = defineStore('assignments', {
  state: (): AssignmentsState => ({
    items: [],
    loaded: false,
  }),

  actions: {
    async load(): Promise<void> {
      try {
        this.items = (await api.assignments.list()).assignments
      } catch {
        this.items = []
      }
      this.loaded = true
    },
  },
})
