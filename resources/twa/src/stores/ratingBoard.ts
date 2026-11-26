import { computed, ref, type Ref } from 'vue'
import { ApiClientError } from '@/api/client'
import { errorMessage } from '@/i18n/errors'
import type { BoardQuery, BoardRow, LeagueBoardPage, ScoreCategory } from '@/api/types'

export type BoardStatus = 'idle' | 'loading' | 'ready' | 'error'

/**
 * The part of a table shown (docs/35 §2.4, docs/31 §5г): the whole category, the learner's own region, or any other region - read
 * only, opened from the region sheet without changing the learner's region (`name` is the one of the sheet, for the chip).
 */
export type BoardArea = { kind: 'all' } | { kind: 'mine' } | { kind: 'other'; code: string; name: string }

/** What a table answer of the month and of "All time" has in common (the rest is the screen's). */
export interface BoardAnswer {
  category?: ScoreCategory
  next_cursor?: string | null
}

export interface BoardFetchers<T extends BoardAnswer> {
  /** The table (its first page with everything around it). */
  fetch: (query: BoardQuery) => Promise<T>
  /** The page after `next_cursor`. */
  page: (query: BoardQuery, after: string) => Promise<LeagueBoardPage>
}

/** A table chosen again this soon after its answer is shown from memory, without a request (docs/32 §12.11: the top lives 120 s). */
export const BOARD_FRESH_MS = 30_000

interface Snapshot<T> {
  data: T
  more: BoardRow[]
  cursor: string | null
  /** The query that was asked, `category` as the server answered it. */
  query: BoardQuery
  at: number
}

/** The query of a choice: nothing for the learner's own category of the whole table (the server decides, as before). */
export function boardQuery(category: ScoreCategory | null | undefined, area: BoardArea): BoardQuery {
  const query: BoardQuery = {}
  if (category) query.category = category
  if (area.kind !== 'all') query.scope = 'region'
  if (area.kind === 'other') query.region = area.code
  return query
}

const keyOf = (query: BoardQuery): string => `${query.category ?? '-'}|${query.scope ?? 'all'}|${query.region ?? ''}`

/**
 * The table of the month and of "All time" (docs/04 A.19, A.19a), for a setup store: the choice of the table (Free / Pro, the whole
 * category / my region / another region), its pages after the cursor and the states of the region.
 *
 * - One request per choice; a choice of a table answered less than `BOARD_FRESH_MS` ago is shown from memory (with its pages).
 * - Every request of a table takes a new generation: an answer of an older one is dropped. "Show more" does nothing while a table is
 *   on its way, and its page is dropped when another table was asked meanwhile - the rows of a table are never glued to another one.
 * - 422 `invalid_cursor` of a page reloads the table from its start; `region_not_set` keeps the table shown and raises `regionNotSet`
 *   (the screen asks to choose a region); `region_scope_unavailable` (a v1 month) raises `regionUnavailable` and goes back to the whole
 *   category.
 * - A failed choice goes back to the table shown (the switches say what is on screen).
 */
export function useBoardTable<T extends BoardAnswer>(fetchers: BoardFetchers<T>) {
  const data = ref(null) as Ref<T | null>
  const status = ref<BoardStatus>('idle')
  const errorMessageRef = ref('')
  /** The category asked for; null - the learner's own (the server decides). Kept between the visits of the pane. */
  const category = ref<ScoreCategory | null>(null)
  const area = ref<BoardArea>({ kind: 'all' })
  /** The rows of the pages after the top (`?after=`), in the order they came; reset with every table. */
  const more = ref<BoardRow[]>([])
  const cursor = ref<string | null>(null)
  const loadingMore = ref(false)
  const moreError = ref('')
  /** A table is on its way (a choice or a reload): "Show more" waits for it. */
  const switching = ref(false)
  /** `scope=region` answered 422 `region_not_set`: the learner has no region. */
  const regionNotSet = ref(false)
  /** `scope=region` answered 422 `region_scope_unavailable` (a v1 month): no switch of the region. */
  const regionUnavailable = ref(false)

  /** The query of the table on screen (`category` as the server answered it): the pages are asked with it. */
  let shownQuery: BoardQuery = {}
  let shownCategory: ScoreCategory | null = null
  let shownArea: BoardArea = { kind: 'all' }
  let generation = 0
  const cache = new Map<string, Snapshot<T>>()

  const requestedQuery = computed(() => boardQuery(category.value, area.value))

  function remember(snapshot: Snapshot<T>, asked: BoardQuery): void {
    cache.set(keyOf(asked), snapshot)
    cache.set(keyOf(snapshot.query), snapshot)
  }

  function show(snapshot: Snapshot<T>): void {
    data.value = snapshot.data
    more.value = snapshot.more
    cursor.value = snapshot.cursor
    shownQuery = snapshot.query
    shownCategory = category.value
    shownArea = area.value
    moreError.value = ''
    regionNotSet.value = false
    status.value = 'ready'
  }

  /** The table chosen (`category`, `area`), always asked. */
  async function fetchTable(): Promise<void> {
    const mine = ++generation
    const asked = requestedQuery.value
    switching.value = true
    status.value = data.value === null ? 'loading' : status.value
    errorMessageRef.value = ''
    try {
      const answer = await fetchers.fetch(asked)
      if (mine !== generation) return
      const query: BoardQuery = { ...asked }
      if (answer.category !== undefined) query.category = answer.category
      const snapshot: Snapshot<T> = { data: answer, more: [], cursor: answer.next_cursor ?? null, query, at: Date.now() }
      remember(snapshot, asked)
      show(snapshot)
    } catch (err) {
      if (mine !== generation) return
      const code = err instanceof ApiClientError ? err.code : ''
      if (code === 'region_not_set') {
        // The table shown stays (the hero is the learner's own anyway); the screen draws the card "Choose your region" in its place.
        regionNotSet.value = true
        if (status.value === 'loading') status.value = 'ready'
        return
      }
      if (code === 'region_scope_unavailable') {
        regionUnavailable.value = true
        area.value = { kind: 'all' }
        switching.value = false
        await choose()
        return
      }
      status.value = 'error'
      errorMessageRef.value = errorMessage(err, 'settings')
      // The switches say what is on screen: a failed choice goes back to the table shown.
      if (data.value !== null) {
        category.value = shownCategory
        area.value = shownArea
      }
    } finally {
      // A page asked before this table is dropped (its generation is gone, it never resets the flag itself): reset here.
      if (mine === generation) {
        switching.value = false
        loadingMore.value = false
      }
    }
  }

  /** The table chosen: from memory when answered less than `BOARD_FRESH_MS` ago, else asked. */
  async function choose(): Promise<void> {
    const cached = cache.get(keyOf(requestedQuery.value))
    if (cached && Date.now() - cached.at < BOARD_FRESH_MS) {
      generation++
      switching.value = false
      loadingMore.value = false
      errorMessageRef.value = ''
      show(cached)
      return
    }
    await fetchTable()
  }

  const isShown = (query: BoardQuery): boolean => data.value !== null && keyOf(query) === keyOf(shownQuery)

  return {
    data,
    status,
    errorMessage: errorMessageRef,
    category,
    area,
    more,
    cursor,
    loadingMore,
    moreError,
    switching,
    regionNotSet,
    regionUnavailable,

    /**
     * The table chosen last (or the learner's own), always asked: the top moves with every session. A stale screen stays visible while
     * reloading.
     */
    load: fetchTable,

    /** Shows the other table (Free ↔ Pro): one request, none for the table shown or one answered moments ago. */
    async selectCategory(next: ScoreCategory): Promise<void> {
      const query = boardQuery(next, area.value)
      if ((isShown(query) && !regionNotSet.value) || (category.value === next && switching.value)) return
      category.value = next
      await choose()
    },

    /** Shows another part of the table (all / my region / another region): one request, as `selectCategory`. */
    async selectArea(next: BoardArea): Promise<void> {
      const query = boardQuery(category.value ?? shownQuery.category, next)
      if (isShown(query)) {
        // Back to the table on screen (e.g. "All" after "My region" asked for a region the learner has not chosen): nothing to ask.
        generation++
        switching.value = false
        loadingMore.value = false
        area.value = next
        regionNotSet.value = false
        return
      }
      area.value = next
      await choose()
    },

    /** The learner's region changed (the region sheet): every remembered table of a region is stale; the one on screen is asked again. */
    async regionChanged(): Promise<void> {
      cache.clear()
      regionNotSet.value = false
      if (area.value.kind !== 'all') await fetchTable()
    },

    /**
     * The next page of the table shown (`next_cursor`). A tap while a page or a table is on its way does nothing. A cursor the server no
     * longer takes (422 `invalid_cursor`: the month turned, the table changed) reloads the table from its start.
     */
    async loadMore(): Promise<void> {
      const after = cursor.value
      if (loadingMore.value || switching.value || after === null || data.value === null) return
      const mine = generation
      const query = shownQuery
      loadingMore.value = true
      moreError.value = ''
      try {
        const page = await fetchers.page(query, after)
        if (mine !== generation) return
        more.value = [...more.value, ...page.rows]
        cursor.value = page.next_cursor
        const remembered = cache.get(keyOf(query))
        if (remembered && remembered.data === data.value) {
          remembered.more = more.value
          remembered.cursor = cursor.value
        }
      } catch (err) {
        if (mine !== generation) return
        if (err instanceof ApiClientError && err.code === 'invalid_cursor') {
          loadingMore.value = false
          category.value = shownCategory
          area.value = shownArea
          await fetchTable()
          return
        }
        moreError.value = errorMessage(err, 'settings')
      } finally {
        if (mine === generation) loadingMore.value = false
      }
    },
  }
}
