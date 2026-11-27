import { defineStore } from 'pinia'
import { api } from '@/api'
import { ApiClientError, REQUEST_ABORTED } from '@/api/client'
import { errorMessage } from '@/i18n/errors'
import type { VocabularyCounts, VocabularyFilter, VocabularyItem, VocabularyQuery } from '@/api/vocabularyTypes'

/**
 * "My dictionary" (docs/40 §2.3, kit F2): the pages of `GET /vocabulary` read so far, the cursor of the next one, the search and the filter.
 *
 * Requests - the owner's rule "nothing extra":
 * - `open()` - one request (the first page, no search, all words);
 * - `loadMore()` - one request per page, never while another read is on its way, never without a cursor; a word already on the list is
 *   not added twice;
 * - `search(text)` - one request after a pause of `SEARCH_DEBOUNCE_MS` in the typing (a burst of keys is one request), none when the trimmed
 *   text is the one shown already; `setFilter()` - one request at once (it takes a search still waiting along). A new first page drops the
 *   read still on its way (`AbortSignal`): its answer would be of the old search.
 *
 * `total` and `counts` come with a first page only and do not depend on the search (docs/04 A.25), so they are kept from the last first
 * page. Not cached between visits: the dictionary changes with every grade, and the request is cheap.
 */

/** The pause in the typing after which the search goes (docs/40 §2.3). */
export const SEARCH_DEBOUNCE_MS = 300
/** The server takes 1..50 characters of `q` after trimming. */
export const SEARCH_MAX = 50

export type VocabularyStatus = 'idle' | 'loading' | 'ready' | 'error'

interface VocabularyState {
  items: VocabularyItem[]
  total: number | null
  counts: VocabularyCounts | null
  nextCursor: string | null
  filter: VocabularyFilter
  /** The search of the list on screen (trimmed; '' - none). */
  query: string
  /** The first page: `loading` without a list yet, `error` when it failed. */
  status: VocabularyStatus
  /** A new first page (another search or filter) is on its way while the old list stays on screen. */
  refreshing: boolean
  loadingMore: boolean
  errorMessage: string
  /** The next page failed (not by the network: that is the screen "Нет соединения"); the list stays, "Retry" asks again. */
  moreError: string
  /** Bumped by every new first page: an answer of an older one is dropped. */
  seq: number
}

let searchTimer: ReturnType<typeof setTimeout> | null = null
let inFlight: AbortController | null = null

function clearSearchTimer(): void {
  if (searchTimer !== null) clearTimeout(searchTimer)
  searchTimer = null
}

function isAborted(err: unknown): boolean {
  return err instanceof ApiClientError && err.code === REQUEST_ABORTED
}

/** The text as the server takes it: trimmed, at most `SEARCH_MAX` characters. */
export function normalizedSearch(text: string): string {
  return [...text.trim()].slice(0, SEARCH_MAX).join('').trim()
}

export const useVocabularyStore = defineStore('vocabulary', {
  state: (): VocabularyState => ({
    items: [],
    total: null,
    counts: null,
    nextCursor: null,
    filter: 'all',
    query: '',
    status: 'idle',
    refreshing: false,
    loadingMore: false,
    errorMessage: '',
    moreError: '',
    seq: 0,
  }),

  getters: {
    /** The dictionary itself is empty: no graded word at all (not a search or a filter that found nothing). */
    isEmpty: (s): boolean => s.status === 'ready' && s.counts !== null && s.counts.all === 0,
  },

  actions: {
    /** The screen opens: a clean list, all words, no search - one request. */
    async open(): Promise<void> {
      clearSearchTimer()
      this.$reset()
      await this.loadFirst()
    },

    /** The screen is left: a search still waiting is not sent, a read on its way is dropped. */
    close(): void {
      clearSearchTimer()
      inFlight?.abort()
      inFlight = null
      this.seq += 1
      this.refreshing = false
      this.loadingMore = false
    },

    /** The text of the search field changed: the list follows after a pause in the typing. */
    search(text: string): void {
      clearSearchTimer()
      const query = normalizedSearch(text)
      if (query === this.query) return
      searchTimer = setTimeout(() => {
        searchTimer = null
        this.query = query
        void this.loadFirst()
      }, SEARCH_DEBOUNCE_MS)
    },

    /** A chip: another filter at once, with the search that may still be waiting. */
    setFilter(filter: VocabularyFilter, text?: string): void {
      const query = text === undefined ? this.query : normalizedSearch(text)
      if (filter === this.filter && query === this.query) return
      clearSearchTimer()
      this.filter = filter
      this.query = query
      void this.loadFirst()
    },

    /** The first page of the current search and filter; drops whatever read is still on its way. */
    async loadFirst(): Promise<void> {
      inFlight?.abort()
      const controller = new AbortController()
      inFlight = controller
      const seq = ++this.seq
      this.errorMessage = ''
      this.moreError = ''
      this.loadingMore = false
      if (this.status === 'ready') this.refreshing = true
      else this.status = 'loading'
      try {
        const page = await api.vocabulary.list(this.request(), controller.signal)
        if (seq !== this.seq) return
        this.items = unique(page.items)
        this.nextCursor = page.next_cursor
        if (page.counts !== null) this.counts = page.counts
        if (page.total !== null) this.total = page.total
        this.status = 'ready'
      } catch (err) {
        if (seq !== this.seq || isAborted(err)) return
        this.errorMessage = errorMessage(err, 'catalog')
        this.items = []
        this.nextCursor = null
        this.status = 'error'
      } finally {
        if (seq === this.seq) {
          this.refreshing = false
          if (inFlight === controller) inFlight = null
        }
      }
    },

    /** The next page after the cursor: one request, none while another read is on its way. */
    async loadMore(): Promise<void> {
      const after = this.nextCursor
      if (after === null || this.status !== 'ready' || this.refreshing || this.loadingMore) return
      const controller = new AbortController()
      inFlight = controller
      const seq = this.seq
      this.loadingMore = true
      this.moreError = ''
      try {
        const page = await api.vocabulary.list({ ...this.request(), after }, controller.signal)
        if (seq !== this.seq) return
        const known = new Set(this.items.map((item) => item.word_id))
        this.items = [...this.items, ...unique(page.items).filter((item) => !known.has(item.word_id))]
        this.nextCursor = page.next_cursor
      } catch (err) {
        if (seq !== this.seq || isAborted(err)) return
        // A cursor the server does not take any more: the list is read again from the start.
        if (err instanceof ApiClientError && err.code === 'invalid_cursor') {
          this.loadingMore = false
          await this.loadFirst()
          return
        }
        this.moreError = errorMessage(err, 'catalog')
      } finally {
        if (seq === this.seq) {
          this.loadingMore = false
          if (inFlight === controller) inFlight = null
        }
      }
    },

    /** "Retry" (the error of the screen, of the next page, or the screen "Нет соединения"): what failed is asked again. */
    async retry(): Promise<void> {
      if (this.status === 'error' || this.status === 'idle') await this.loadFirst()
      else if (this.moreError !== '') await this.loadMore()
    },

    request(): VocabularyQuery {
      return { filter: this.filter, ...(this.query !== '' ? { q: this.query } : {}) }
    },
  },
})

/** One row per word (a page never repeats one; this is the guard). */
function unique(items: VocabularyItem[]): VocabularyItem[] {
  const seen = new Set<number>()
  return items.filter((item) => (seen.has(item.word_id) ? false : (seen.add(item.word_id), true)))
}
