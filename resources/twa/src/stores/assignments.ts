import { defineStore } from 'pinia'
import { api } from '@/api'
import { readValue, writeValue } from '@/composables/seenFlag'
import type { AssignmentItem } from '@/api/types'

/** What the last `/today` of this device said about `assignments_possible` ('1' - maybe, '0' - surely none); none kept: not known yet. */
export const ASSIGNMENTS_POSSIBLE_KEY = 'lexiflow:assignments-possible'

interface AssignmentsState {
  items: AssignmentItem[]
  /** The list is known: loaded, failed (`failed`) or surely empty by `/today` (no request at all). */
  loaded: boolean
  /** A request is on its way. */
  loading: boolean
  /** The last request failed: `items` stay what they were (none on the first open). Not an empty class - the screen says so. */
  failed: boolean
  /** `/today` said "surely none" and nothing was asked: a later "maybe" holds the screen for the list again (`loaded` goes back). */
  skipped: boolean
  /** The number of the newest request: an older answer (or one `/today` made needless) is dropped. */
  ticket: number
}

/**
 * "Assignments of the center" on Today (docs/04 A.9). It never breaks the screen: a failed load leaves personal training as it is
 * and only shows a short line with a retry (`failed`).
 *
 * When to ask (docs/04 A.2a `assignments_possible`, the audit of 2026-11-18 «Рабочая спецификация первого входа»):
 *  - the device remembers that the last `/today` said "maybe" (a member of a center's group) - the list goes out at once, in parallel
 *    with `/today` (`loadEarly`), so a class member does not wait for two requests one after the other;
 *  - otherwise (the first open, or the last answer was "surely none") the list waits for `/today` (`followToday`): `false` - no request
 *    at all and the screen is not held; `true` or no field (an older server) - the list is asked then.
 */
export const useAssignmentsStore = defineStore('assignments', {
  state: (): AssignmentsState => ({
    items: [],
    loaded: false,
    loading: false,
    failed: false,
    skipped: false,
    ticket: 0,
  }),

  actions: {
    async load(): Promise<void> {
      const ticket = ++this.ticket
      this.loading = true
      // After "surely none" the list was never asked: a learner who has just joined a group waits for it like on the first open.
      if (this.skipped) this.loaded = false
      this.skipped = false
      try {
        const items = (await api.assignments.list()).assignments
        if (ticket !== this.ticket) return
        this.items = items
        this.failed = false
      } catch {
        if (ticket !== this.ticket) return
        this.failed = true
      }
      this.loading = false
      this.loaded = true
    },

    /** Asks the list in parallel with `/today` when the device remembers a "maybe"; returns whether it did. */
    loadEarly(): boolean {
      if (readValue(ASSIGNMENTS_POSSIBLE_KEY) !== '1') return false
      void this.load()
      return true
    },

    /**
     * What `/today` said (`assignments_possible`; `undefined` - an older server, the old path): `false` settles the list as empty without
     * a request (an answer already on its way is dropped), anything else asks it unless `loadEarly` already did. Remembered for the next open.
     */
    followToday(possible: boolean | undefined, started: boolean): void {
      writeValue(ASSIGNMENTS_POSSIBLE_KEY, possible === false ? '0' : '1')
      if (possible === false) {
        this.ticket++
        this.items = []
        this.failed = false
        this.loading = false
        this.skipped = true
        this.loaded = true
        return
      }
      if (!started) void this.load()
    },
  },
})
