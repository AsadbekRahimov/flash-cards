import { defineStore } from 'pinia'
import { api } from '@/api'
import { BACKGROUND } from '@/api/background'
import { ApiClientError } from '@/api/client'
import type { CupListResponse } from '@/api/types'

export type CupListItem = CupListResponse['cups'][number]

interface CupsState {
  /** `GET /cups` as the server sent it last; null - not read yet (or every read failed). */
  cups: CupListItem[] | null
  /** When the last read ended (`Date.now()`), success or failure: a younger one is not asked again. */
  checkedAt: number | null
  /**
   * 403 `cup_unavailable`: the cup is switched off. Kept for the whole session (the app is not reloaded while it is open): "Today" and
   * "Profile" ask nothing more, and no card or row is shown.
   */
  unavailable: boolean
}

/** How long a read of "my cups" (or its failure) is trusted by "Today" and "Profile". */
export const CUPS_FRESH_MS = 60_000

let inFlight: Promise<void> | null = null

/**
 * "My cups" (docs/04 A.23 `GET /cups`, docs/33 §7.1): the entries of the cup on "Today" (one card) and in "Profile" (a row), and the
 * list `/cups`. One light request with a short freshness: "Today" opened again within `CUPS_FRESH_MS` asks nothing; a switched off cup
 * (`cup_unavailable`) is never asked again in this session. A failure is silent (the entries are secondary) and is trusted as long.
 * The state of today's round of each cup is the server's (`me.today`); a round played here makes the next reader ask again.
 */
export const useCupsStore = defineStore('cups', {
  state: (): CupsState => ({
    cups: null,
    checkedAt: null,
    unavailable: false,
  }),

  getters: {
    /** The running cup the learner plays in (the first: the server sends the open ones first), for the card on "Today". */
    running: (s): CupListItem | null =>
      s.unavailable ? null : (s.cups ?? []).find((cup) => cup.status === 'running' && cup.paused === null && cup.day !== null && cup.me?.status === 'active') ?? null,
    /** The learner has a cup to look at (the row in "Profile"). */
    hasAny: (s): boolean => !s.unavailable && (s.cups ?? []).length > 0,
  },

  actions: {
    /** Reads "my cups" unless a read is young (`force` - the list screen: always). One request at a time. */
    async load(force = false): Promise<void> {
      if (this.unavailable) return
      if (!force && this.checkedAt !== null && Date.now() - this.checkedAt < CUPS_FRESH_MS) return
      if (inFlight !== null) return inFlight
      inFlight = (async () => {
        try {
          // Only the list screen (`force`) stands on it; for "Today" and "Profile" it is a background read.
          const res = await api.cups.list(force ? undefined : BACKGROUND)
          this.cups = res.cups
        } catch (err) {
          if (err instanceof ApiClientError && err.code === 'cup_unavailable') {
            this.unavailable = true
            this.cups = null
          } else if (force) {
            throw err
          }
        } finally {
          this.checkedAt = Date.now()
          inFlight = null
        }
      })()
      return inFlight
    },

    /** Something the list shows has changed (joined, played): the next reader asks again. */
    invalidate(): void {
      this.checkedAt = null
    },
  },
})

/** For the tests: forgets the request on its way. */
export function resetCupsInFlight(): void {
  inFlight = null
}
