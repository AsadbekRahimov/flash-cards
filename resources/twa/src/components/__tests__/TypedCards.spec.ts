import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import SpellingCard from '../SpellingCard.vue'
import GapFillCard from '../GapFillCard.vue'
import AnswerVerdict from '../AnswerVerdict.vue'
import type { Card } from '@/api/types'
import { setLocale } from '@/i18n'

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ haptic: vi.fn(), selectionHaptic: vi.fn(), notificationHaptic: vi.fn() }),
}))

const base: Card = {
  word_id: 1,
  word: 'walk',
  translation: 'идти пешком',
  translation_fallback: false,
  example: 'They walked home late.',
  transcription: null,
  audio_url: null,
  card_kind: 'due',
  serve_token: null,
}

const spelling: Card = {
  ...base,
  prompt: { type: 'spelling', front: 'идти пешком', masked_example: null, options: null, answer_length: 4 },
}

const gapFill: Card = {
  ...base,
  prompt: { type: 'gap_fill', front: 'идти пешком', masked_example: 'They ____ home late.', options: null, answer_length: 6 },
}

afterEach(() => setLocale('ru'))

describe('SpellingCard', () => {
  it('shows the translation and the length hint, never the word or the example', () => {
    const w = mount(SpellingCard, { props: { card: spelling } })
    expect(w.get('[data-testid=front]').text()).toBe('идти пешком')
    expect(w.get('[data-testid=answer-length]').text()).toBe('Букв: 4')
    expect(w.text()).not.toContain('walk')
    expect(w.text()).not.toContain('They walked')
  })

  it('emits the typed text once on submit and disables the form', async () => {
    const w = mount(SpellingCard, { props: { card: spelling } })
    await w.get('[data-testid=answer-input]').setValue('  Walk ')
    await w.get('[data-testid=answer-form]').trigger('submit')
    await w.get('[data-testid=answer-form]').trigger('submit')

    expect(w.emitted('answer')).toEqual([['  Walk ']])
    expect(w.get('[data-testid=answer-input]').attributes('disabled')).toBeDefined()
    expect(w.get('[data-testid=check-btn]').attributes('disabled')).toBeDefined()
  })

  it('"I don\'t know" sends an empty answer', async () => {
    const w = mount(SpellingCard, { props: { card: spelling } })
    await w.get('[data-testid=answer-input]').setValue('something')
    await w.get('[data-testid=skip-btn]').trigger('click')
    expect(w.emitted('answer')).toEqual([['']])
  })

  it('does nothing while disabled', async () => {
    const w = mount(SpellingCard, { props: { card: spelling, disabled: true } })
    await w.get('[data-testid=answer-form]').trigger('submit')
    expect(w.emitted('answer')).toBeUndefined()
  })

  it('renders in Uzbek', () => {
    setLocale('uz')
    const w = mount(SpellingCard, { props: { card: spelling } })
    expect(w.text()).toContain("So'zni inglizcha yozing")
    expect(w.get('[data-testid=answer-length]').text()).toBe('Harflar: 4')
  })
})

describe('GapFillCard', () => {
  it('shows the masked example with the translation as a hint and emits the typed form', async () => {
    const w = mount(GapFillCard, { props: { card: gapFill } })
    expect(w.get('[data-testid=masked-example]').text()).toBe('They ____ home late.')
    expect(w.get('[data-testid=hint]').text()).toBe('идти пешком')
    expect(w.get('[data-testid=answer-length]').text()).toBe('Букв: 6')
    expect(w.text()).not.toContain('walked')

    await w.get('[data-testid=answer-input]').setValue('walked')
    await w.get('[data-testid=check-btn]').trigger('submit')
    expect(w.emitted('answer')).toEqual([['walked']])
  })
})

describe('AnswerVerdict', () => {
  it('says "right" for an exact answer', async () => {
    const w = mount(AnswerVerdict, { props: { verdict: { quality: 5, correct: true, expected: 'walked' }, answer: 'walked' } })
    expect(w.attributes('data-kind')).toBe('correct')
    expect(w.get('[data-testid=verdict-text]').text()).toBe('Верно!')
    expect(w.find('[data-testid=own-answer]').exists()).toBe(false)

    await w.get('[data-testid=continue-btn]').trigger('click')
    expect(w.emitted('continue')).toHaveLength(1)
  })

  it('shows the exact spelling for a close answer and the right one, crossed with the own, for a miss', () => {
    const close = mount(AnswerVerdict, { props: { verdict: { quality: 4, correct: true, expected: 'resilient' }, answer: 'resiliant' } })
    expect(close.attributes('data-kind')).toBe('close')
    expect(close.get('[data-testid=verdict-text]').text()).toBe('Почти! Правильно: resilient')
    expect(close.get('[data-testid=own-answer]').text()).toBe('resiliant')

    const wrong = mount(AnswerVerdict, { props: { verdict: { quality: 1, correct: false, expected: 'resilient' }, answer: '' } })
    expect(wrong.attributes('data-kind')).toBe('wrong')
    expect(wrong.get('[data-testid=verdict-text]').text()).toBe('Неверно. Правильно: resilient')
    expect(wrong.find('[data-testid=own-answer]').exists()).toBe(false)
  })

  it('renders in Uzbek', () => {
    setLocale('uz')
    const w = mount(AnswerVerdict, { props: { verdict: { quality: 1, correct: false, expected: 'walked' } } })
    expect(w.get('[data-testid=verdict-text]').text()).toBe("Noto'g'ri. To'g'risi: walked")
    expect(w.get('[data-testid=continue-btn]').text()).toBe('Keyingisi')
  })
})
