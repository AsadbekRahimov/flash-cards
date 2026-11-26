import { defineStore } from 'pinia'
import { computed } from 'vue'
import { api } from '@/api'
import { useBoardTable } from '@/stores/ratingBoard'
import type { AllTimeResponse } from '@/api/types'

/**
 * "All time" (docs/04 A.19a, docs/32 §12.11): the table of the whole category or of a region, Free / Pro, as the month's
 * (`useBoardTable`). Asked only when the pane is opened - nothing asks it to learn whether the pane exists. `disabled`: the server said
 * `enabled: false` (the word points are not live) - the segment is gone for the session.
 */
export const useAllTimeRatingStore = defineStore('allTimeRating', () => {
  const board = useBoardTable<AllTimeResponse>({
    fetch: (query) => api.league.allTime(query),
    page: (query, after) => api.league.allTimePage(query, after),
  })

  const disabled = computed(() => board.data.value?.enabled === false)

  return { ...board, disabled }
})
