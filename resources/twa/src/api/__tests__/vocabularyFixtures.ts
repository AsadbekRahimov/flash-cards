import type { VocabularyItem, VocabularyPage } from '@/api/vocabularyTypes'

/** A word of "My dictionary" (docs/04 A.25) with what a test does not care about filled in. */
export function vocabularyItem(word_id: number, word: string, translation: string, strength = 3, extra: Partial<VocabularyItem> = {}): VocabularyItem {
  return {
    word_id,
    word,
    translation,
    translation_fallback: false,
    transcription: null,
    audio_url: `https://cdn.test/audio/${word}.mp3`,
    strength,
    stage: strength >= 5 ? 'mastered' : strength === 4 ? 'known' : strength >= 2 ? 'learning' : 'new',
    next_in_days: strength >= 2 ? strength : null,
    is_hard: false,
    ...extra,
  }
}

/** The seven words of the kit's F2. */
export const KIT_WORDS: VocabularyItem[] = [
  vocabularyItem(1, 'tea', 'чай', 5),
  vocabularyItem(2, 'coffee', 'кофе', 4),
  vocabularyItem(3, 'juice', 'сок', 2),
  vocabularyItem(4, 'water', 'вода', 5),
  vocabularyItem(5, 'milk', 'молоко', 1),
  vocabularyItem(6, 'kettle', 'чайник', 3),
  vocabularyItem(7, 'bread', 'хлеб', 4),
]

/** A first page (with the totals) or a next one (`first: false`: no totals, as the server sends after a cursor). */
export function vocabularyPage(items: VocabularyItem[], next_cursor: string | null = null, first = true, counts = { all: 120, weak: 18, strong: 64 }): VocabularyPage {
  return first ? { total: counts.all, counts, items, next_cursor } : { total: null, counts: null, items, next_cursor }
}
