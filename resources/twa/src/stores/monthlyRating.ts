import { defineStore } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { errorMessage } from '@/i18n/errors'
import type { BoardRow, MonthlyRatingMeResponse, MonthlyRatingResponse, ScoreCategory } from '@/api/types'

export type MonthlyRatingStatus = 'idle' | 'loading' | 'ready' | 'error'

interface MonthlyRatingState {
  data: MonthlyRatingResponse | null
  status: MonthlyRatingStatus
  errorMessage: string
  /** The light answer for the finish screen; null until fetched or when the request failed. */
  mine: MonthlyRatingMeResponse | null
  /**
   * The table asked for (docs/32 §8.3, v2): the learner's choice of Free / Pro; null - the learner's own, the server decides (also
   * every v1 month, which has no tables). Kept between the visits of the pane.
   */
  category: ScoreCategory | null
  /** The rows of the pages after the top (`?after=`), in the order they came; reset with every load of the table. */
  more: BoardRow[]
  /** `after` of the next page; null - there is none (or a v1 month). */
  cursor: string | null
  /** A page is on its way: a second tap waits for it. */
  loadingMore: boolean
  /** The last page failed (not a stale cursor): the button stays to try again. */
  moreError: string
}

/** Counts the loads of the table: an answer of an older one (another category, a page of the table before) is dropped. */
let generation = 0

/** The monthly rating (docs/04 A.19, docs/13 §7.6): the whole screen and the learner's own line. */
export const useMonthlyRatingStore = defineStore('monthlyRating', {
  state: (): MonthlyRatingState => ({
    data: null,
    status: 'idle',
    errorMessage: '',
    mine: null,
    category: null,
    more: [],
    cursor: null,
    loadingMore: false,
    moreError: '',
  }),

  actions: {
    /**
     * Always refetches (the top moves with every session); a stale screen stays visible while reloading. The table is the one chosen
     * last (`category`), or the learner's own; the pages loaded before are dropped - their cursor is of the table that was.
     */
    async load(): Promise<void> {
      const mine = ++generation
      this.status = this.data === null ? 'loading' : this.status
      this.errorMessage = ''
      try {
        const data = await api.league.month(this.category ?? undefined)
        if (mine !== generation) return
        this.data = data
        this.more = []
        this.cursor = data.next_cursor ?? null
        this.moreError = ''
        this.status = 'ready'
      } catch (err) {
        if (mine !== generation) return
        this.status = 'error'
        this.errorMessage = errorMessage(err, 'settings')
      } finally {
        // A page asked before this load is dropped (its generation is gone, it never resets the flag itself): the flag is reset with the
        // load, whether it succeeded or failed.
        if (mine === generation) this.loadingMore = false
      }
    },

    /** Shows the other table (Free ↔ Pro): one request of the month, nothing else is asked again. */
    async selectCategory(category: ScoreCategory): Promise<void> {
      if (category === (this.data?.category ?? this.category)) return
      this.category = category
      await this.load()
    },

    /**
     * The next page of the table (`next_cursor`). A tap while a page is on its way does nothing. A cursor the server no longer takes
     * (422 `invalid_cursor`: the month turned, the table changed) reloads the table from its start.
     */
    async loadMore(): Promise<void> {
      const cursor = this.cursor
      const category = this.data?.category
      if (this.loadingMore || cursor === null || category === undefined) return
      const mine = generation
      this.loadingMore = true
      this.moreError = ''
      try {
        const page = await api.league.monthPage(category, cursor)
        if (mine !== generation) return
        this.more = [...this.more, ...page.rows]
        this.cursor = page.next_cursor
      } catch (err) {
        if (mine !== generation) return
        if (err instanceof ApiClientError && err.code === 'invalid_cursor') {
          this.loadingMore = false
          await this.load()
          return
        }
        this.moreError = errorMessage(err, 'settings')
      } finally {
        if (mine === generation) this.loadingMore = false
      }
    },

    /** The learner's line only, for the finish screen: best effort, a failure leaves nothing to show and says nothing. */
    async loadMine(): Promise<void> {
      this.mine = null
      try {
        this.mine = (await api.league.monthMe()) ?? null
      } catch {
        this.mine = null
      }
    },
  },
})
