import type { WordStageName } from './types'

/**
 * "My dictionary" (docs/40 §2.3, docs/04 A.25): `GET /vocabulary` - the words the learner has graded at least once, of active words, in
 * the order of the alphabet, a page of up to 50 after a keyset cursor.
 */

/** The chips of the screen: all words, the weak ones (strength ≤ 2), the strong ones (strength ≥ 4). */
export type VocabularyFilter = 'all' | 'weak' | 'strong'

export const VOCABULARY_FILTERS: readonly VocabularyFilter[] = ['all', 'weak', 'strong']

/** The query: `q` 1..50 characters after trimming (left out when empty), `after` the `next_cursor` of the page before. */
export interface VocabularyQuery {
  q?: string
  filter?: VocabularyFilter
  after?: string
  limit?: number
}

/** One word of the dictionary; `translation` is in the learner's language (the Russian one with `translation_fallback`). */
export interface VocabularyItem {
  word_id: number
  word: string
  translation: string
  translation_fallback: boolean
  transcription: string | null
  audio_url: string | null
  /** 0..5, the dots (docs/40 §2.2). */
  strength: number
  stage: WordStageName
  next_in_days: number | null
  is_hard: boolean
}

export interface VocabularyCounts {
  all: number
  weak: number
  strong: number
}

/** `total` and `counts` come on a first page only (no `after`), null after; they do not depend on `q`. */
export interface VocabularyPage {
  total: number | null
  counts: VocabularyCounts | null
  items: VocabularyItem[]
  /** The cursor of the next page; null - this was the last one. */
  next_cursor: string | null
}
