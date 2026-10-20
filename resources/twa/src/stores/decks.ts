import { defineStore } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { errorMessage } from '@/i18n/errors'
import type { Deck, DeckDetail, DeckImportFormat, DeckImportResult, DeckLimits } from '@/api/types'

export type DecksStatus = 'idle' | 'loading' | 'ready' | 'error'

interface DecksState {
  decks: Deck[]
  limits: DeckLimits | null
  detail: DeckDetail | null
  /** state of the list request; independent of the detail request (the screens overlap on "back") */
  listStatus: DecksStatus
  detailStatus: DecksStatus
  /** newest request number per kind: an older response that arrives late is ignored */
  listSeq: number
  detailSeq: number
  /** a write is in flight: the screens disable their buttons */
  busy: boolean
  errorMessage: string
  /** the last write was refused by a plan limit (422 deck_limit_reached / deck_words_limit_reached): show the paywall */
  limitReached: boolean
  lastImport: DeckImportResult | null
}

const LIMIT_CODES = ['deck_limit_reached', 'deck_words_limit_reached']

export const useDecksStore = defineStore('decks', {
  state: (): DecksState => ({
    decks: [],
    limits: null,
    detail: null,
    listStatus: 'idle',
    detailStatus: 'idle',
    listSeq: 0,
    detailSeq: 0,
    busy: false,
    errorMessage: '',
    limitReached: false,
    lastImport: null,
  }),

  actions: {
    async load(): Promise<void> {
      const seq = ++this.listSeq
      this.listStatus = 'loading'
      this.errorMessage = ''
      try {
        const res = await api.decks.list()
        if (seq !== this.listSeq) return
        this.decks = res.decks
        this.limits = res.limits
        this.listStatus = 'ready'
      } catch (err) {
        if (seq === this.listSeq) this.fail(err, 'list')
      }
    },

    async loadDetail(slug: string): Promise<void> {
      const seq = ++this.detailSeq
      this.detailStatus = 'loading'
      this.errorMessage = ''
      this.lastImport = null
      this.detail = null
      try {
        const res = await api.decks.get(slug)
        if (seq !== this.detailSeq) return
        this.detail = res
        this.detailStatus = 'ready'
      } catch (err) {
        if (seq === this.detailSeq) this.fail(err, 'detail')
      }
    },

    /** Creates a deck; null when the server refused (the message or the paywall flag says why). */
    async create(title: string): Promise<Deck | null> {
      return this.write(async () => {
        const deck = await api.decks.create(title)
        this.decks = [...this.decks, deck]
        if (this.limits) this.limits = { ...this.limits, used_decks: this.limits.used_decks + 1 }
        return deck
      }, null)
    },

    async rename(slug: string, title: string): Promise<boolean> {
      return this.write(async () => {
        const deck = await api.decks.rename(slug, title)
        this.decks = this.decks.map((d) => (d.slug === slug ? deck : d))
        if (this.detail?.slug === slug) this.detail = { ...this.detail, title: deck.title }
        return true
      }, false)
    },

    /** Adds the words and refreshes the deck screen; the result (counts, rejected lines) is kept in `lastImport`. */
    async importWords(slug: string, text: string, format: DeckImportFormat): Promise<boolean> {
      this.lastImport = null
      return this.write(async () => {
        const result = await api.decks.addWords(slug, text, format)
        this.lastImport = result
        this.decks = this.decks.map((d) => (d.slug === slug ? result.deck : d))
        // The import itself is done; a failed refresh only leaves the old word list until the next load.
        const fresh = await api.decks.get(slug).catch(() => null)
        if (fresh && this.detail?.slug === slug) this.detail = fresh
        return true
      }, false)
    },

    async removeWord(slug: string, wordId: number): Promise<boolean> {
      return this.write(async () => {
        await api.decks.removeWord(slug, wordId)
        if (this.detail?.slug === slug) {
          const words = this.detail.words.filter((w) => w.id !== wordId)
          this.detail = { ...this.detail, words, words_count: words.length }
        }
        return true
      }, false)
    },

    /** "Deleting" archives the deck on the server; the learner's progress stays. */
    async archive(slug: string): Promise<boolean> {
      return this.write(async () => {
        await api.decks.remove(slug)
        this.decks = this.decks.filter((d) => d.slug !== slug)
        if (this.limits) this.limits = { ...this.limits, used_decks: Math.max(0, this.limits.used_decks - 1) }
        if (this.detail?.slug === slug) this.detail = null
        return true
      }, false)
    },

    /** Makes the deck the active course (the existing course selection endpoint). */
    async activate(slug: string): Promise<boolean> {
      return this.write(async () => {
        await api.courses.select(slug)
        this.decks = this.decks.map((d) => ({ ...d, is_active: d.slug === slug }))
        if (this.detail) this.detail = { ...this.detail, is_active: this.detail.slug === slug }
        return true
      }, false)
    },

    dismissLimit(): void {
      this.limitReached = false
    },

    /** Runs one write: one at a time, errors become `errorMessage` (and `limitReached` for plan limits). */
    async write<T>(run: () => Promise<T>, fallback: T): Promise<T> {
      if (this.busy) return fallback
      this.busy = true
      this.errorMessage = ''
      this.limitReached = false
      try {
        return await run()
      } catch (err) {
        this.errorMessage = errorMessage(err, 'decks')
        this.limitReached = err instanceof ApiClientError && LIMIT_CODES.includes(err.code)
        return fallback
      } finally {
        this.busy = false
      }
    },

    fail(err: unknown, kind: 'list' | 'detail'): void {
      if (kind === 'list') this.listStatus = 'error'
      else this.detailStatus = 'error'
      this.errorMessage = errorMessage(err, 'decks')
    },
  },
})
