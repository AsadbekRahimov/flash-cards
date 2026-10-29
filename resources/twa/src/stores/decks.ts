import { defineStore } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { errorMessage } from '@/i18n/errors'
import type { Deck, DeckDetail, DeckImportFormat, DeckImportResult, DeckLimits } from '@/api/types'

export type DecksStatus = 'idle' | 'loading' | 'ready' | 'error'

/** Which plan limit refused a write: a new deck (`decks`) or words over the size of a deck (`deck_words`). */
export type DeckLimitKind = 'decks' | 'deck_words'

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
  /**
   * the last write was refused by a plan limit (422 deck_limit_reached / deck_words_limit_reached): Free sees the
   * paywall, Pro an explanation (the screen decides by the plan). Dropped by the next write, the next load and
   * when the learner leaves the screen, so it never greets them on another one.
   */
  limitReached: boolean
  /** which limit it was; null while `limitReached` is false */
  limitKind: DeckLimitKind | null
  lastImport: DeckImportResult | null
}

function limitKindOf(err: unknown): DeckLimitKind | null {
  if (!(err instanceof ApiClientError)) return null
  if (err.code === 'deck_limit_reached') return 'decks'
  if (err.code === 'deck_words_limit_reached') return 'deck_words'
  return null
}

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
    limitKind: null,
    lastImport: null,
  }),

  actions: {
    async load(): Promise<void> {
      const seq = ++this.listSeq
      this.listStatus = 'loading'
      this.errorMessage = ''
      this.dismissLimit()
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
      this.dismissLimit()
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
      this.limitKind = null
    },

    /**
     * Takes the plan limits again, nothing else. A refused write calls it: the numbers the screen quotes must be
     * those the server just applied (the plan may have changed since the list was loaded, and a deck opened from a
     * link has no list at all). Best effort — the screen falls back to its general text without numbers.
     */
    async refreshLimits(): Promise<void> {
      try {
        this.limits = (await api.decks.list()).limits
      } catch {
        // keep what there is
      }
    },

    /** Runs one write: one at a time, errors become `errorMessage` (and `limitReached` for plan limits). */
    async write<T>(run: () => Promise<T>, fallback: T): Promise<T> {
      if (this.busy) return fallback
      this.busy = true
      this.errorMessage = ''
      this.dismissLimit()
      try {
        return await run()
      } catch (err) {
        const kind = limitKindOf(err)
        // The numbers the screen quotes first, then the refusal itself: one render, no text that changes under the eyes.
        if (kind !== null) await this.refreshLimits()
        this.errorMessage = errorMessage(err, 'decks')
        this.limitKind = kind
        this.limitReached = kind !== null
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
