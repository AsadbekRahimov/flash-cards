import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '@/api'
import { ApiClientError, REQUEST_ABORTED } from '@/api/client'
import { KIT_WORDS, vocabularyItem, vocabularyPage } from '@/api/__tests__/vocabularyFixtures'
import { SEARCH_DEBOUNCE_MS, normalizedSearch, useVocabularyStore } from '../vocabulary'
import type { VocabularyPage } from '@/api/vocabularyTypes'

/*
 * The store of "My dictionary" (docs/40 §2.3): the owner's rule "no extra requests" - one on opening, one per page, one per change of the
 * search (after the pause in the typing) or of the filter; a page never adds a word twice; a new search drops the read still on its way.
 */

vi.mock('@/api', () => ({ api: { vocabulary: { list: vi.fn() } } }))

const list = () => vi.mocked(api.vocabulary.list)

/** A read that answers when the test says so; it rejects as `aborted` when its signal fires (the way the client does). */
function deferred() {
  let resolve: (page: VocabularyPage) => void = () => {}
  let signal: AbortSignal | undefined
  const promise = new Promise<VocabularyPage>((res, rej) => {
    resolve = res
    queueMicrotask(() => signal?.addEventListener('abort', () => rej(new ApiClientError('network', 0, REQUEST_ABORTED, 'cancelled'))))
  })
  return {
    promise,
    resolve: (page: VocabularyPage) => resolve(page),
    bind: (s?: AbortSignal) => {
      signal = s
      return promise
    },
  }
}

beforeEach(() => {
  setActivePinia(createPinia())
  list().mockReset()
})
afterEach(() => vi.useRealTimers())

describe('vocabulary store', () => {
  it('opens with one request: the first page of all words, no search, no cursor', async () => {
    list().mockResolvedValue(vocabularyPage(KIT_WORDS, 'c1'))
    const store = useVocabularyStore()

    await store.open()

    expect(list()).toHaveBeenCalledTimes(1)
    expect(list().mock.calls[0]?.[0]).toEqual({ filter: 'all' })
    expect(store.status).toBe('ready')
    expect(store.items.map((i) => i.word)).toEqual(KIT_WORDS.map((i) => i.word))
    expect(store.total).toBe(120)
    expect(store.counts).toEqual({ all: 120, weak: 18, strong: 64 })
    expect(store.nextCursor).toBe('c1')
    expect(store.isEmpty).toBe(false)
  })

  it('loads the next page with the cursor, keeps the totals, adds no word twice and stops at the last page', async () => {
    list().mockResolvedValueOnce(vocabularyPage(KIT_WORDS.slice(0, 4), 'c1'))
    const store = useVocabularyStore()
    await store.open()

    list().mockResolvedValueOnce(vocabularyPage([KIT_WORDS[3]!, ...KIT_WORDS.slice(4)], null, false))
    await store.loadMore()

    expect(list()).toHaveBeenCalledTimes(2)
    expect(list().mock.calls[1]?.[0]).toEqual({ filter: 'all', after: 'c1' })
    expect(store.items.map((i) => i.word_id)).toEqual([1, 2, 3, 4, 5, 6, 7])
    expect(store.total).toBe(120)
    expect(store.counts).toEqual({ all: 120, weak: 18, strong: 64 })
    expect(store.nextCursor).toBeNull()

    await store.loadMore()
    expect(list()).toHaveBeenCalledTimes(2)
  })

  it('never asks for a page while another read is on its way', async () => {
    list().mockResolvedValueOnce(vocabularyPage(KIT_WORDS.slice(0, 2), 'c1'))
    const store = useVocabularyStore()
    await store.open()

    const next = deferred()
    list().mockImplementationOnce((_q, signal) => next.bind(signal))
    const first = store.loadMore()
    void store.loadMore()
    void store.loadMore()
    expect(list()).toHaveBeenCalledTimes(2)
    expect(store.loadingMore).toBe(true)

    next.resolve(vocabularyPage(KIT_WORDS.slice(2, 4), 'c2', false))
    await first
    expect(store.loadingMore).toBe(false)
    expect(store.items).toHaveLength(4)
  })

  it('sends one search after the pause in the typing, trimmed; the same text again sends nothing', async () => {
    vi.useFakeTimers()
    list().mockResolvedValue(vocabularyPage(KIT_WORDS))
    const store = useVocabularyStore()
    await store.open()

    store.search('t')
    store.search('te')
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS - 1)
    store.search('  tea ')
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS - 1)
    expect(list()).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(1)

    expect(list()).toHaveBeenCalledTimes(2)
    expect(list().mock.calls[1]?.[0]).toEqual({ filter: 'all', q: 'tea' })
    expect(store.query).toBe('tea')

    store.search('tea  ')
    store.search('teap')
    store.search('tea')
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS * 2)
    expect(list()).toHaveBeenCalledTimes(2)

    // Cleared: the list without a search, one request.
    store.search('   ')
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS)
    expect(list()).toHaveBeenCalledTimes(3)
    expect(list().mock.calls[2]?.[0]).toEqual({ filter: 'all' })
  })

  it('changes the filter at once with one request that starts from the first page, the waiting search along', async () => {
    vi.useFakeTimers()
    list().mockResolvedValue(vocabularyPage(KIT_WORDS, 'c1'))
    const store = useVocabularyStore()
    await store.open()

    store.search('mi')
    store.setFilter('weak', 'mi')
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS * 2)

    expect(list()).toHaveBeenCalledTimes(2)
    expect(list().mock.calls[1]?.[0]).toEqual({ filter: 'weak', q: 'mi' })
    expect(store.filter).toBe('weak')

    store.setFilter('weak', 'mi')
    expect(list()).toHaveBeenCalledTimes(2)
    store.setFilter('strong')
    expect(list()).toHaveBeenCalledTimes(3)
    expect(list().mock.calls[2]?.[0]).toEqual({ filter: 'strong', q: 'mi' })
  })

  it('drops the read still on its way when a new search starts: its late answer changes nothing', async () => {
    list().mockResolvedValueOnce(vocabularyPage(KIT_WORDS, 'c1'))
    const store = useVocabularyStore()
    await store.open()

    const old = deferred()
    const signals: (AbortSignal | undefined)[] = []
    list().mockImplementationOnce((_q, signal) => {
      signals.push(signal)
      return old.bind(signal)
    })
    void store.loadMore()
    list().mockResolvedValueOnce(vocabularyPage([vocabularyItem(5, 'milk', 'молоко', 1)], null, true, { all: 120, weak: 18, strong: 64 }))
    store.setFilter('weak')
    await vi.waitFor(() => expect(store.refreshing).toBe(false))

    expect(signals[0]?.aborted).toBe(true)
    old.resolve(vocabularyPage([vocabularyItem(99, 'zzz', 'z')], 'late', false))
    await Promise.resolve()
    expect(store.items.map((i) => i.word)).toEqual(['milk'])
    expect(store.nextCursor).toBeNull()
    expect(store.errorMessage).toBe('')
  })

  it('tells an empty dictionary from a search that found nothing', async () => {
    list().mockResolvedValueOnce(vocabularyPage([], null, true, { all: 0, weak: 0, strong: 0 }))
    const store = useVocabularyStore()
    await store.open()
    expect(store.isEmpty).toBe(true)

    list().mockResolvedValueOnce(vocabularyPage(KIT_WORDS))
    await store.open()
    list().mockResolvedValueOnce(vocabularyPage([]))
    store.setFilter('all', 'xyz')
    await vi.waitFor(() => expect(store.refreshing).toBe(false))
    expect(store.items).toEqual([])
    expect(store.isEmpty).toBe(false)
  })

  it('keeps the error of the first page and asks again on retry', async () => {
    list().mockRejectedValueOnce(new ApiClientError('server', 500, 'server_error', 'boom'))
    const store = useVocabularyStore()
    await store.open()
    expect(store.status).toBe('error')
    expect(store.errorMessage).not.toBe('')

    list().mockResolvedValueOnce(vocabularyPage(KIT_WORDS))
    await store.retry()
    expect(list()).toHaveBeenCalledTimes(2)
    expect(store.status).toBe('ready')
  })

  it('keeps the list when the next page fails, and retry asks for that page again; retry with nothing failed sends nothing', async () => {
    list().mockResolvedValueOnce(vocabularyPage(KIT_WORDS.slice(0, 3), 'c1'))
    const store = useVocabularyStore()
    await store.open()
    await store.retry()
    expect(list()).toHaveBeenCalledTimes(1)

    list().mockRejectedValueOnce(new ApiClientError('network', 0, 'network', 'fetch failed'))
    await store.loadMore()
    expect(store.items).toHaveLength(3)
    expect(store.moreError).not.toBe('')

    list().mockResolvedValueOnce(vocabularyPage(KIT_WORDS.slice(3), null, false))
    await store.retry()
    expect(list()).toHaveBeenCalledTimes(3)
    expect(list().mock.calls[2]?.[0]).toEqual({ filter: 'all', after: 'c1' })
    expect(store.items).toHaveLength(7)
    expect(store.moreError).toBe('')
  })

  it('reads the list again from the start when the server refuses the cursor', async () => {
    list().mockResolvedValueOnce(vocabularyPage(KIT_WORDS.slice(0, 3), 'stale'))
    const store = useVocabularyStore()
    await store.open()

    list().mockRejectedValueOnce(new ApiClientError('validation', 422, 'invalid_cursor', 'bad'))
    list().mockResolvedValueOnce(vocabularyPage(KIT_WORDS))
    await store.loadMore()

    expect(list()).toHaveBeenCalledTimes(3)
    expect(list().mock.calls[2]?.[0]).toEqual({ filter: 'all' })
    expect(store.items).toHaveLength(7)
  })

  it('sends no waiting search after the screen is left', async () => {
    vi.useFakeTimers()
    list().mockResolvedValue(vocabularyPage(KIT_WORDS))
    const store = useVocabularyStore()
    await store.open()

    store.search('tea')
    store.close()
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS * 2)
    expect(list()).toHaveBeenCalledTimes(1)
  })

  it('cuts the search to what the server takes (1..50 characters after trimming)', () => {
    expect(normalizedSearch('  tea  ')).toBe('tea')
    expect(normalizedSearch('a'.repeat(60))).toHaveLength(50)
    expect(normalizedSearch('   ')).toBe('')
  })
})
