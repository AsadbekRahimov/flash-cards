import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import FlashCard from '../FlashCard.vue'
import PretestCard from '../PretestCard.vue'
import PretestReveal from '../PretestReveal.vue'
import ReverseCard from '../ReverseCard.vue'
import WordNoteSheet from '../WordNoteSheet.vue'
import { SAY_ALOUD_EVERY, resetSayAloud, setSayAloudEnabled } from '@/composables/useSayAloudHint'
import type { Card } from '@/api/types'
import { setLocale } from '@/i18n'

/*
 * The method on the cards (docs/06 §12): the "Hard word" chip, the memory hooks (authored, "my hint", the confusable
 * pair), the note sheet, the pretest and its reveal, the "say it aloud" line.
 */

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ haptic: vi.fn(), selectionHaptic: vi.fn(), notificationHaptic: vi.fn() }),
}))
vi.mock('@/composables/useTts', () => ({ useTts: () => ({ supported: false, speak: vi.fn().mockResolvedValue(undefined) }) }))
vi.mock('@/composables/useDialogFocus', () => ({ useDialogFocus: () => {} }))

const card: Card = {
  word_id: 1,
  word: 'mother',
  translation: 'мать',
  translation_fallback: false,
  example: 'My mother is kind.',
  transcription: null,
  card_kind: 'due',
  serve_token: null,
  is_leech: false,
  is_hard: false,
  hook: null,
  note: null,
  confused_with: null,
}

describe('FlashCard — hard words and hooks', () => {
  beforeEach(() => resetSayAloud())
  afterEach(() => {
    setLocale('ru')
    resetSayAloud()
  })

  it('shows the chip and opens the hooks before the reveal for a hard word, in Russian and Uzbek', () => {
    const leech: Card = { ...card, is_leech: true, hook: 'мать ~ mother', note: 'как мама', confused_with: { word: 'father', translation: 'отец' } }
    const w = mount(FlashCard, { props: { card: leech, showTranslation: false } })

    expect(w.get('[data-testid=leech-chip]').text()).toBe('Трудное слово')
    expect(w.get('[data-testid=leech-chip]').classes()).toContain('chip-warning')
    expect(w.find('[data-testid=translation]').exists()).toBe(false) // still a recall
    expect(w.get('[data-testid=hook]').text()).toContain('мать ~ mother')
    expect(w.get('[data-testid=note]').text()).toContain('как мама')
    expect(w.get('[data-testid=confused-with]').text()).toContain('father — отец')
    // "My hint" is edited after the reveal only.
    expect(w.find('[data-testid=note-btn]').exists()).toBe(false)

    setLocale('uz')
    const uz = mount(FlashCard, { props: { card: leech, showTranslation: true } })
    expect(uz.get('[data-testid=leech-chip]').text()).toBe("Qiyin so'z")
    expect(uz.get('[data-testid=hook]').text()).toContain('Eslab qolish uchun')
    expect(uz.get('[data-testid=note-btn]').text()).toBe("Eslatmani o'zgartirish")
  })

  it('keeps the hooks of a plain card behind the reveal and offers "my hint" once revealed', async () => {
    const hinted: Card = { ...card, is_hard: true, hook: 'un + happy' }
    const w = mount(FlashCard, { props: { card: hinted, showTranslation: false } })
    expect(w.find('[data-testid=card-hooks]').exists()).toBe(false)
    expect(w.find('[data-testid=leech-chip]').exists()).toBe(false)

    await w.setProps({ showTranslation: true })
    expect(w.get('[data-testid=hook]').text()).toContain('un + happy')
    expect(w.get('[data-testid=note-btn]').text()).toBe('Добавить свою подсказку')
    await w.get('[data-testid=note-btn]').trigger('click')
    expect(w.emitted('edit-note')).toHaveLength(1)
  })

  it('shows nothing of the method on a card without hooks, apart from the "my hint" button after the reveal', () => {
    const w = mount(FlashCard, { props: { card, showTranslation: true } })
    expect(w.find('[data-testid=hook]').exists()).toBe(false)
    expect(w.find('[data-testid=note]').exists()).toBe(false)
    expect(w.find('[data-testid=confused-with]').exists()).toBe(false)
    expect(w.find('[data-testid=note-btn]').exists()).toBe(true)
  })

  it('asks to say the word aloud on every third card only, and never when switched off', () => {
    const shown = Array.from({ length: SAY_ALOUD_EVERY * 2 }, () =>
      mount(FlashCard, { props: { card, showTranslation: false } }).find('[data-testid=say-aloud]').exists(),
    )
    expect(shown.filter(Boolean)).toHaveLength(2)
    expect(shown[SAY_ALOUD_EVERY - 1]).toBe(true)
    expect(shown[SAY_ALOUD_EVERY * 2 - 1]).toBe(true)

    setSayAloudEnabled(false)
    for (let i = 0; i < SAY_ALOUD_EVERY * 2; i++) {
      expect(mount(FlashCard, { props: { card, showTranslation: false } }).find('[data-testid=say-aloud]').exists()).toBe(false)
    }
  })
})

describe('ReverseCard — hard words and hooks', () => {
  it('shows the chip at once and the hooks only when the server has answered (the card is sent without them)', async () => {
    const reverse: Card = {
      ...card,
      word: '',
      hook: null,
      confused_with: null,
      is_leech: true,
      prompt: { type: 'reverse', front: 'мать', masked_example: null, options: ['father', 'mother', 'sister', 'brother'], answer_length: null },
    }
    const w = mount(ReverseCard, { props: { card: reverse } })
    expect(w.get('[data-testid=leech-chip]').text()).toBe('Трудное слово')
    expect(w.find('[data-testid=card-hooks]').exists()).toBe(false)

    await w.get('[data-testid=option-0]').trigger('click')
    expect(w.emitted('pick')).toEqual([['father']])
    // The pick is on its way: still nothing of the method on screen.
    expect(w.find('[data-testid=card-hooks]').exists()).toBe(false)

    await w.setProps({
      result: {
        correct: false,
        right: 'mother',
        revealed: {
          word: 'mother', translation: 'мать', correct: 'mother', example: null, transcription: null, audio_url: null,
          hook: 'мать ~ mother', note: null, confused_with: { word: 'father', translation: null },
        },
      },
    })
    expect(w.get('[data-testid=hook]').text()).toContain('мать ~ mother')
    expect(w.get('[data-testid=confused-with]').text().replace(/\s+/g, ' ')).toBe('Не путайте с: father')
  })
})

describe('WordNoteSheet', () => {
  it('saves the typed text, clears an existing note, and closes', async () => {
    const w = mount(WordNoteSheet, { props: { word: 'mother', note: 'old', max: 200 } })
    expect(w.get('[data-testid=note-word]').text()).toBe('mother')
    expect((w.get('[data-testid=note-input]').element as HTMLTextAreaElement).value).toBe('old')
    expect(w.text()).toContain('До 200 символов')

    await w.get('[data-testid=note-input]').setValue('  mother ~ мать  ')
    await w.get('[data-testid=note-save]').trigger('click')
    expect(w.emitted('save')).toEqual([['  mother ~ мать  ']])

    await w.get('[data-testid=note-clear]').trigger('click')
    expect(w.emitted('save')?.[1]).toEqual([''])

    await w.get('[data-testid=sheet-x]').trigger('click')
    expect(w.emitted('close')).toHaveLength(1)

    const fresh = mount(WordNoteSheet, { props: { word: 'mother', note: null } })
    expect(fresh.find('[data-testid=note-clear]').exists()).toBe(false)
  })

  it('labels the textarea for a screen reader and counts code points, not UTF-16 units', async () => {
    const w = mount(WordNoteSheet, { props: { word: 'mother', note: null, max: 5 } })
    expect(w.get('[data-testid=note-input]').attributes('aria-label')).toBe('Моя подсказка')
    expect(w.get('[data-testid=note-counter]').text()).toBe('0 / 5')

    // Three emoji are six UTF-16 units but three characters: they fit; the server counts them the same way.
    await w.get('[data-testid=note-input]').setValue('😀😀😀')
    expect(w.get('[data-testid=note-counter]').text()).toBe('3 / 5')
    expect((w.get('[data-testid=note-input]').element as HTMLTextAreaElement).value).toBe('😀😀😀')

    // Past the limit the text is cut at a character boundary, never inside an emoji.
    await w.get('[data-testid=note-input]').setValue('😀😀😀😀😀😀😀')
    expect(w.get('[data-testid=note-counter]').text()).toBe('5 / 5')
    expect((w.get('[data-testid=note-input]').element as HTMLTextAreaElement).value).toBe('😀😀😀😀😀')

    await w.get('[data-testid=note-save]').trigger('click')
    expect(w.emitted('save')).toEqual([['😀😀😀😀😀']])
  })
})

describe('PretestCard and PretestReveal', () => {
  const pretest: Card = {
    ...card,
    card_kind: 'new',
    translation: '',
    example: null,
    prompt: { type: 'pretest', front: 'mother', masked_example: null, options: ['отец', 'мать', 'сестра', 'брат'], answer_length: null },
  }

  it('shows the word and four meanings, sends the pick once, or "I don\'t know" as an empty answer', async () => {
    const w = mount(PretestCard, { props: { card: pretest } })
    expect(w.text()).toContain('Знаете это слово?')
    expect(w.get('[data-testid=word]').text()).toBe('mother')
    expect(w.text()).not.toContain('мать…')
    expect(w.findAll('button[data-testid^=option-]')).toHaveLength(4)

    await w.get('[data-testid=option-1]').trigger('click')
    await w.get('[data-testid=option-0]').trigger('click')
    expect(w.emitted('answer')).toEqual([['мать']])
    expect(w.get('[data-testid=option-1]').attributes('data-state')).toBe('selected')

    const skip = mount(PretestCard, { props: { card: pretest } })
    await skip.get('[data-testid=skip-btn]').trigger('click')
    expect(skip.emitted('answer')).toEqual([['']])
    expect(skip.get('[data-testid=skip-btn]').attributes('disabled')).toBeDefined()
  })

  it('reveals the meaning: "you know it" after a right pick, "a new word" after a wrong one', async () => {
    const known = mount(PretestReveal, { props: { card: pretest, verdict: { quality: 5, correct: true, expected: 'мать' } } })
    expect(known.attributes('data-kind')).toBe('known')
    expect(known.get('[data-testid=translation]').text()).toBe('мать')
    expect(known.get('[data-testid=reveal-text]').text()).toContain('уже знаете')

    const learn = mount(PretestReveal, { props: { card: pretest, verdict: { quality: 2, correct: false, expected: 'мать' } } })
    expect(learn.attributes('data-kind')).toBe('learn')
    expect(learn.get('[data-testid=reveal-text]').text()).toContain('Новое слово')
    await learn.get('[data-testid=continue-btn]').trigger('click')
    expect(learn.emitted('continue')).toHaveLength(1)
  })
})
