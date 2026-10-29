import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useDecksStore } from '../decks'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import type { Deck, DeckDetail, DeckImportResult } from '@/api/types'

vi.mock('@/api', () => ({
  api: {
    decks: {
      list: vi.fn(),
      get: vi.fn(),
      create: vi.fn(),
      rename: vi.fn(),
      remove: vi.fn(),
      addWords: vi.fn(),
      removeWord: vi.fn(),
    },
    courses: { select: vi.fn() },
  },
}))

const deck = (slug: string, overrides: Partial<Deck> = {}): Deck => ({
  slug,
  title: slug,
  words_count: 2,
  is_active: false,
  progress: { done: 0, total: 2 },
  ...overrides,
})

const detail = (slug: string, overrides: Partial<DeckDetail> = {}): DeckDetail => ({
  ...deck(slug),
  words: [
    { id: 1, word: 'apple', translation: 'яблоко', translation_uz: null, example: null },
    { id: 2, word: 'pear', translation: 'груша', translation_uz: null, example: null },
  ],
  ...overrides,
})

const apiError = (status: number, code: string) =>
  new ApiClientError(status === 422 ? 'validation' : 'not_found', status, code, 'English message')

describe('decks store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('loads the decks with the limits', async () => {
    vi.mocked(api.decks.list).mockResolvedValue({
      decks: [deck('a')],
      limits: { decks: 1, deck_words: 50, used_decks: 1 },
    })
    const store = useDecksStore()

    await store.load()

    expect(store.listStatus).toBe('ready')
    expect(store.decks.map((d) => d.slug)).toEqual(['a'])
    expect(store.limits).toEqual({ decks: 1, deck_words: 50, used_decks: 1 })
  })

  it('turns a failed load into a localized message', async () => {
    vi.mocked(api.decks.list).mockRejectedValue(new ApiClientError('network', 0, 'unknown', 'x'))
    const store = useDecksStore()

    await store.load()

    expect(store.listStatus).toBe('error')
    expect(store.errorMessage).toBe('Нет связи с сервером. Попробуйте ещё раз.')
  })

  it('ignores a list response that arrives after a newer request', async () => {
    let resolveFirst!: (v: Awaited<ReturnType<typeof api.decks.list>>) => void
    vi.mocked(api.decks.list)
      .mockReturnValueOnce(new Promise((res) => (resolveFirst = res)))
      .mockResolvedValueOnce({ decks: [deck('new')], limits: { decks: 1, deck_words: 50, used_decks: 1 } })
    const store = useDecksStore()

    const first = store.load()
    await store.load()
    resolveFirst({ decks: [deck('stale')], limits: { decks: 1, deck_words: 50, used_decks: 1 } })
    await first

    expect(store.decks.map((d) => d.slug)).toEqual(['new'])
  })

  it('creates a deck and counts it against the limit', async () => {
    vi.mocked(api.decks.create).mockResolvedValue(deck('fresh', { words_count: 0 }))
    const store = useDecksStore()
    store.limits = { decks: 20, deck_words: 2000, used_decks: 0 }

    const created = await store.create('Fresh')

    expect(created?.slug).toBe('fresh')
    expect(store.decks.map((d) => d.slug)).toEqual(['fresh'])
    expect(store.limits.used_decks).toBe(1)
    expect(store.limitReached).toBe(false)
  })

  it.each(['deck_limit_reached', 'deck_words_limit_reached'])('raises the paywall flag on %s', async (code) => {
    vi.mocked(api.decks.create).mockRejectedValue(apiError(422, code))
    const store = useDecksStore()

    expect(await store.create('Second')).toBeNull()

    expect(store.limitReached).toBe(true)
    expect(store.errorMessage).not.toBe('')
    expect(store.errorMessage).not.toContain('English message')

    store.dismissLimit()
    expect(store.limitReached).toBe(false)
  })

  it('tells which limit refused the write: a new deck or words over the size of a deck', async () => {
    vi.mocked(api.decks.create).mockRejectedValueOnce(apiError(422, 'deck_limit_reached'))
    vi.mocked(api.decks.addWords).mockRejectedValueOnce(apiError(422, 'deck_words_limit_reached'))
    const store = useDecksStore()
    expect(store.limitKind).toBeNull()

    await store.create('Second')
    expect(store.limitKind).toBe('decks')

    await store.importWords('a', 'text', 'list')
    expect(store.limitKind).toBe('deck_words')

    store.dismissLimit()
    expect(store.limitKind).toBeNull()
    expect(store.limitReached).toBe(false)
  })

  it('takes the plan limits again when a limit refuses a write: the numbers it quotes are the ones the server applied', async () => {
    vi.mocked(api.decks.create).mockRejectedValue(apiError(422, 'deck_limit_reached'))
    vi.mocked(api.decks.list).mockResolvedValue({ decks: [], limits: { decks: 20, deck_words: 2000, used_decks: 20 } })
    const store = useDecksStore()
    store.limits = { decks: 1, deck_words: 50, used_decks: 1 } // a list loaded before the plan changed

    await store.create('21st')

    expect(store.limits).toEqual({ decks: 20, deck_words: 2000, used_decks: 20 })
    expect(store.limitReached).toBe(true)
    expect(store.decks).toEqual([]) // only the limits are taken: the list on screen is not replaced behind the learner
  })

  it('has limits after a refusal even for a deck opened from a link (no list was loaded)', async () => {
    vi.mocked(api.decks.addWords).mockRejectedValue(apiError(422, 'deck_words_limit_reached'))
    vi.mocked(api.decks.list).mockResolvedValue({ decks: [], limits: { decks: 20, deck_words: 2000, used_decks: 3 } })
    const store = useDecksStore()
    store.detail = detail('a')
    expect(store.limits).toBeNull()

    await store.importWords('a', 'text', 'csv')

    expect(store.limits).toEqual({ decks: 20, deck_words: 2000, used_decks: 3 })
    expect(store.limitKind).toBe('deck_words')
  })

  it('keeps the limits it has, and still reports the refusal, when they cannot be taken again', async () => {
    vi.mocked(api.decks.create).mockRejectedValue(apiError(422, 'deck_limit_reached'))
    vi.mocked(api.decks.list).mockRejectedValue(new ApiClientError('network', 0, 'unknown', 'x'))
    const store = useDecksStore()
    store.limits = { decks: 1, deck_words: 50, used_decks: 1 }

    await store.create('Second')

    expect(store.limits).toEqual({ decks: 1, deck_words: 50, used_decks: 1 })
    expect(store.limitReached).toBe(true)
    expect(store.errorMessage).toBe('Достигнут лимит колод вашего тарифа.')
    expect(store.busy).toBe(false)
  })

  it('does not ask for the limits again for any other refusal', async () => {
    vi.mocked(api.decks.rename).mockRejectedValue(apiError(404, 'deck_not_found'))
    const store = useDecksStore()

    await store.rename('a', 'B')

    expect(api.decks.list).not.toHaveBeenCalled()
    expect(store.limitKind).toBeNull()
  })

  it('drops a refusal when the list or the deck is loaded again: it never greets the learner on another screen', async () => {
    vi.mocked(api.decks.create).mockRejectedValue(apiError(422, 'deck_limit_reached'))
    vi.mocked(api.decks.list).mockResolvedValue({ decks: [], limits: { decks: 1, deck_words: 50, used_decks: 0 } })
    vi.mocked(api.decks.get).mockResolvedValue(detail('a'))
    const store = useDecksStore()

    await store.create('Second')
    expect(store.limitReached).toBe(true)
    await store.load()
    expect(store.limitReached).toBe(false)
    expect(store.limitKind).toBeNull()

    await store.create('Second')
    expect(store.limitReached).toBe(true)
    await store.loadDetail('a')
    expect(store.limitReached).toBe(false)
    expect(store.limitKind).toBeNull()
  })

  it('does not raise the paywall for other refusals, and clears it on the next write', async () => {
    vi.mocked(api.decks.create).mockRejectedValueOnce(apiError(422, 'deck_limit_reached'))
    vi.mocked(api.decks.rename).mockRejectedValueOnce(apiError(404, 'deck_not_found'))
    const store = useDecksStore()

    await store.create('x')
    expect(store.limitReached).toBe(true)

    expect(await store.rename('a', 'B')).toBe(false)
    expect(store.limitReached).toBe(false)
    expect(store.errorMessage).toBe('Колода не найдена.')
  })

  it('runs one write at a time', async () => {
    let finish!: (d: Deck) => void
    vi.mocked(api.decks.create).mockReturnValue(new Promise((res) => (finish = res)))
    const store = useDecksStore()

    const first = store.create('one')
    expect(store.busy).toBe(true)
    expect(await store.create('two')).toBeNull()
    finish(deck('one'))
    await first

    expect(api.decks.create).toHaveBeenCalledTimes(1)
    expect(store.busy).toBe(false)
  })

  it('renames in the list and in the open deck', async () => {
    vi.mocked(api.decks.rename).mockResolvedValue(deck('a', { title: 'Новое' }))
    const store = useDecksStore()
    store.decks = [deck('a'), deck('b')]
    store.detail = detail('a')

    expect(await store.rename('a', 'Новое')).toBe(true)

    expect(store.decks[0]?.title).toBe('Новое')
    expect(store.detail?.title).toBe('Новое')
    expect(store.decks[1]?.title).toBe('b')
  })

  it('imports words, keeps the report and refreshes the open deck', async () => {
    const result: DeckImportResult = {
      added: 2,
      reactivated: 0,
      duplicates: 1,
      rejected: [{ line: 3, reason: 'junk' }],
      deck: deck('a', { words_count: 4 }),
    }
    vi.mocked(api.decks.addWords).mockResolvedValue(result)
    vi.mocked(api.decks.get).mockResolvedValue(detail('a', { words_count: 4 }))
    const store = useDecksStore()
    store.decks = [deck('a')]
    store.detail = detail('a')

    expect(await store.importWords('a', 'x — у', 'list')).toBe(true)

    expect(api.decks.addWords).toHaveBeenCalledWith('a', 'x — у', 'list')
    expect(store.lastImport).toEqual(result)
    expect(store.decks[0]?.words_count).toBe(4)
    expect(store.detail?.words_count).toBe(4)
  })

  it('keeps a successful import even when the refresh fails', async () => {
    vi.mocked(api.decks.addWords).mockResolvedValue({ added: 1, reactivated: 0, duplicates: 0, rejected: [], deck: deck('a') })
    vi.mocked(api.decks.get).mockRejectedValue(new ApiClientError('network', 0, 'unknown', 'x'))
    const store = useDecksStore()
    store.detail = detail('a')

    expect(await store.importWords('a', 'x — у', 'list')).toBe(true)

    expect(store.errorMessage).toBe('')
    expect(store.lastImport?.added).toBe(1)
  })

  it('leaves the deck and reports the paywall when an import does not fit', async () => {
    vi.mocked(api.decks.addWords).mockRejectedValue(apiError(422, 'deck_words_limit_reached'))
    const store = useDecksStore()
    store.detail = detail('a')

    expect(await store.importWords('a', 'text', 'csv')).toBe(false)

    expect(store.limitReached).toBe(true)
    expect(store.lastImport).toBeNull()
    expect(store.detail?.words).toHaveLength(2)
  })

  it('removes a word locally after the server confirms', async () => {
    vi.mocked(api.decks.removeWord).mockResolvedValue(undefined)
    const store = useDecksStore()
    store.detail = detail('a')

    await store.removeWord('a', 1)

    expect(api.decks.removeWord).toHaveBeenCalledWith('a', 1)
    expect(store.detail?.words.map((w) => w.id)).toEqual([2])
    expect(store.detail?.words_count).toBe(1)
  })

  it('archives: the deck leaves the list and frees a slot', async () => {
    vi.mocked(api.decks.remove).mockResolvedValue(undefined)
    const store = useDecksStore()
    store.decks = [deck('a'), deck('b')]
    store.limits = { decks: 20, deck_words: 2000, used_decks: 2 }
    store.detail = detail('a')

    expect(await store.archive('a')).toBe(true)

    expect(store.decks.map((d) => d.slug)).toEqual(['b'])
    expect(store.limits.used_decks).toBe(1)
    expect(store.detail).toBeNull()
  })

  it('keeps the deck when the archive request fails', async () => {
    vi.mocked(api.decks.remove).mockRejectedValue(apiError(404, 'deck_not_found'))
    const store = useDecksStore()
    store.decks = [deck('a')]

    expect(await store.archive('a')).toBe(false)

    expect(store.decks).toHaveLength(1)
  })

  it('activates a deck through the course selection and flips the flags', async () => {
    vi.mocked(api.courses.select).mockResolvedValue({
      slug: 'b',
      title: 'b',
      description: null,
      level: null,
      exam_tag: null,
      words_count: 2,
      is_featured: false,
      is_active: true,
      progress: { done: 0, total: 2 },
    })
    const store = useDecksStore()
    store.decks = [deck('a', { is_active: true }), deck('b')]

    expect(await store.activate('b')).toBe(true)

    expect(api.courses.select).toHaveBeenCalledWith('b')
    expect(store.decks.map((d) => d.is_active)).toEqual([false, true])
  })
})
