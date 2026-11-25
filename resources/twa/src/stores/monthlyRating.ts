import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/api'
import { useBoardTable } from '@/stores/ratingBoard'
import type { MonthlyRatingMeResponse, MonthlyRatingResponse } from '@/api/types'

export type { BoardStatus as MonthlyRatingStatus } from '@/stores/ratingBoard'

/**
 * The monthly rating (docs/04 A.19, docs/13 §7.6): the whole screen and the learner's own line. The table - its choice (Free / Pro of
 * a v2 month, the whole category / a region, docs/35 §2.4), the pages after the cursor, the races between them - is the one of
 * `useBoardTable`, shared with "All time".
 */
export const useMonthlyRatingStore = defineStore('monthlyRating', () => {
  const board = useBoardTable<MonthlyRatingResponse>({
    fetch: (query) => api.league.month(query),
    page: (query, after) => api.league.monthPage(query, after),
  })

  /** The light answer for the finish screen; null until fetched or when the request failed. */
  const mine = ref<MonthlyRatingMeResponse | null>(null)

  /** The learner's line only, for the finish screen: best effort, a failure leaves nothing to show and says nothing. */
  async function loadMine(): Promise<void> {
    mine.value = null
    try {
      mine.value = (await api.league.monthMe()) ?? null
    } catch {
      mine.value = null
    }
  }

  return { ...board, mine, loadMine }
})
