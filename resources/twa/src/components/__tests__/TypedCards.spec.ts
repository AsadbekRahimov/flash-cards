import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import SpellingCard from '../SpellingCard.vue'
import GapFillCard from '../GapFillCard.vue'
import AnswerVerdict from '../AnswerVerdict.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
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

describe('the Pro chip of the typed cards', () => {
  it.each([
    ['spelling', SpellingCard, spelling],
    ['gap fill', GapFillCard, gapFill],
  ])('%s: only when the screen asks for it', (_name, component, card) => {
    expect(mount(component, { props: { card } }).find('[data-testid=pro-chip]').exists()).toBe(false)
    expect(mount(component, { props: { card, proChip: false } }).find('[data-testid=pro-chip]').exists()).toBe(false)

    const w = mount(component, { props: { card, proChip: true } })
    expect(w.get('[data-testid=pro-chip]').text()).toBe('Pro')
    expect(w.get('article').find('[data-testid=pro-chip]').exists()).toBe(true)
  })
})

describe('SpellingCard', () => {
  it('shows the translation and the length hint, never the word or the example', () => {
    const w = mount(SpellingCard, { props: { card: spelling } })
    expect(w.get('[data-testid=front]').text()).toBe('идти пешком')
    expect(w.get('[data-testid=answer-length]').text()).toBe('Букв: 4')
    expect(w.text()).not.toContain('walk')
    expect(w.text()).not.toContain('They walked')
  })

  it('puts the question on the white screen, with no box, and the answer form below it, in the sticky block', () => {
    const w = mount(SpellingCard, { props: { card: spelling } })

    expect(w.get('article').text()).toContain('Напишите слово по-английски')
    expect(w.get('article').classes()).not.toContain('card')
    expect(w.get('article').find('[data-testid=answer-form]').exists()).toBe(false)
    expect(w.get('[data-testid=actions]').find('[data-testid=answer-form]').exists()).toBe(true)
  })

  it('emits the typed text once on submit and disables the form', async () => {
    const w = mount(SpellingCard, { props: { card: spelling } })
    await w.get('[data-testid=answer-input]').setValue('  Walk ')
    await w.get('[data-testid=answer-form]').trigger('submit')
    await w.get('[data-testid=answer-form]').trigger('submit')

    expect(w.emitted('answer')).toEqual([['  Walk ']])
    expect(w.get('[data-testid=answer-input]').attributes('disabled')).toBeDefined()
    expect(w.get('[data-testid=check-btn]').attributes('disabled')).toBeDefined()
    expect(w.get('[data-testid=skip-btn]').attributes('disabled')).toBeDefined()
  })

  it('"I don\'t know" sends an empty answer', async () => {
    const w = mount(SpellingCard, { props: { card: spelling } })
    await w.get('[data-testid=answer-input]').setValue('something')
    await w.get('[data-testid=skip-btn]').trigger('click')
    expect(w.emitted('answer')).toEqual([['']])
  })

  it('sends one answer per card, whichever button is pressed first', async () => {
    const w = mount(SpellingCard, { props: { card: spelling } })
    await w.get('[data-testid=answer-input]').setValue('walk')
    await w.get('[data-testid=skip-btn]').trigger('click')
    await w.get('[data-testid=answer-form]').trigger('submit')

    expect(w.emitted('answer')).toEqual([['']])
  })

  it('does nothing while disabled', async () => {
    const w = mount(SpellingCard, { props: { card: spelling, disabled: true } })
    await w.get('[data-testid=answer-form]').trigger('submit')
    expect(w.emitted('answer')).toBeUndefined()
  })

  it('has the centered TextField of the kit, a primary "Check" and a ghost "I don\'t know"', () => {
    const w = mount(SpellingCard, { props: { card: spelling } })
    const input = w.get('[data-testid=answer-input]')

    expect(input.classes()).toEqual(expect.arrayContaining(['field', 'text-center']))
    expect(input.attributes('aria-label')).toBe('Ваш ответ')
    expect(input.attributes('placeholder')).toBe('Ваш ответ')
    const check = w.get('[data-testid=check-btn]')
    expect(check.attributes('type')).toBe('submit')
    expect(check.classes()).toEqual(expect.arrayContaining(['btn-primary', 'flex-1']))
    const skip = w.get('[data-testid=skip-btn]')
    expect(skip.attributes('type')).toBe('button')
    expect(skip.classes()).toEqual(expect.arrayContaining(['btn-ghost', 'text-muted']))
    // docs/design Question: one row, the quiet "I don't know" on the left of the main button.
    expect(check.element.parentElement).toBe(skip.element.parentElement)
    expect(skip.element.nextElementSibling).toBe(check.element)
    // Enter sends the answer because the field and the submit button share one <form>.
    expect(w.get('form').findAll('input')).toHaveLength(1)
    expect(w.get('form').find('[data-testid=check-btn]').exists()).toBe(true)
  })

  it('renders in Uzbek', () => {
    setLocale('uz')
    const w = mount(SpellingCard, { props: { card: spelling } })
    expect(w.text()).toContain("So'zni inglizcha yozing")
    expect(w.get('[data-testid=answer-length]').text()).toBe('4 ta harf')
  })
})

describe('GapFillCard', () => {
  it('shows the masked example with the translation as a hint and emits the typed form', async () => {
    const w = mount(GapFillCard, { props: { card: gapFill } })
    expect(w.get('[data-testid=masked-example]').text()).toBe('They ____ home late.')
    expect(w.get('[data-testid=hint]').text()).toBe('идти пешком')
    expect(w.get('[data-testid=answer-length]').text()).toBe('Букв: 6')
    expect(w.text()).toContain('Заполните пропуск')
    expect(w.text()).not.toContain('walked')

    await w.get('[data-testid=answer-input]').setValue('walked')
    await w.get('[data-testid=check-btn]').trigger('submit')
    expect(w.emitted('answer')).toEqual([['walked']])
  })

  it('keeps the field out of the question card', () => {
    const w = mount(GapFillCard, { props: { card: gapFill } })

    expect(w.get('article').find('[data-testid=answer-input]').exists()).toBe(false)
    expect(w.get('[data-testid=actions]').find('[data-testid=answer-input]').exists()).toBe(true)
  })
})

describe('AnswerVerdict', () => {
  it('says "right" for an exact answer, with a check in a success circle on the success sheet', async () => {
    const w = mount(AnswerVerdict, { props: { verdict: { quality: 5, correct: true, expected: 'walked' }, answer: 'walked' } })
    expect(w.attributes('data-kind')).toBe('correct')
    expect(w.get('[data-testid=verdict-text]').text()).toBe('Верно!')
    expect(w.find('[data-testid=own-answer]').exists()).toBe(false)
    expect(w.find('[data-testid=expected]').exists()).toBe(false)
    expect(w.text()).not.toContain('верный ответ')

    const icon = w.get('[data-testid=verdict-icon]')
    expect(icon.classes()).toEqual(expect.arrayContaining(['bg-success', 'rounded-full']))
    expect(w.get('[data-testid=actions]').classes()).toContain('bg-success-soft')
    expect(w.get('[data-testid=mascot-art]').attributes('data-pose')).toBe('celebrate')
    expect(icon.findComponent(AppIcon).props()).toMatchObject({ name: 'check', solid: true })

    await w.get('[data-testid=continue-btn]').trigger('click')
    expect(w.emitted('continue')).toHaveLength(1)
  })

  it('shows the exact spelling for a close answer and the right one, crossed with the own, for a miss', () => {
    const close = mount(AnswerVerdict, { props: { verdict: { quality: 4, correct: true, expected: 'resilient' }, answer: 'resiliant' } })
    expect(close.attributes('data-kind')).toBe('close')
    expect(close.get('[data-testid=verdict-text]').text()).toBe('Почти!')
    expect(close.get('[data-testid=expected]').text()).toBe('resilient')
    expect(close.get('[data-testid=own-answer]').text()).toBe('resiliant')
    expect(close.get('[data-testid=own-answer]').classes()).toContain('line-through')
    // Each answer is named in words, not by colour only (docs/design Feedback): the own one is neutral for a close answer.
    expect(close.get('[data-testid=own-answer-box]').text()).toContain('ваш ответ')
    expect(close.get('[data-testid=own-answer-box]').attributes('data-state')).toBe('dimmed')
    expect(close.get('[data-testid=expected-box]').text()).toContain('верный ответ')
    expect(close.get('[data-testid=expected-box]').attributes('data-state')).toBe('reveal')
    expect(close.get('[data-testid=verdict-icon]').classes()).toContain('bg-warning')
    expect(close.get('[data-testid=actions]').classes()).toContain('bg-success-soft')
    expect(close.get('[data-testid=verdict-icon]').findComponent(AppIcon).props('name')).toBe('exclamation-circle')

    const wrong = mount(AnswerVerdict, { props: { verdict: { quality: 1, correct: false, expected: 'resilient' }, answer: '' } })
    expect(wrong.attributes('data-kind')).toBe('wrong')
    expect(wrong.get('[data-testid=verdict-text]').text()).toBe('Неверно')
    expect(wrong.get('[data-testid=expected]').text()).toBe('resilient')
    expect(wrong.find('[data-testid=own-answer]').exists()).toBe(false)
    const typed = mount(AnswerVerdict, { props: { verdict: { quality: 1, correct: false, expected: 'resilient' }, answer: 'resolute' } })
    expect(typed.get('[data-testid=own-answer-box]').attributes('data-state')).toBe('incorrect')
    expect(wrong.get('[data-testid=verdict-icon]').classes()).toContain('bg-danger')
    expect(wrong.get('[data-testid=actions]').classes()).toContain('bg-danger-soft')
    expect(wrong.get('[data-testid=verdict-text]').classes()).toContain('text-danger-ink')
    expect(wrong.get('[data-testid=verdict-icon]').findComponent(AppIcon).props('name')).toBe('x-mark')
  })

  it('has one big primary "Next" in the sticky block', () => {
    const w = mount(AnswerVerdict, { props: { verdict: { quality: 1, correct: false, expected: 'walked' } } })
    const next = w.get('[data-testid=continue-btn]')

    expect(next.text()).toBe('Дальше')
    expect(next.classes()).toEqual(expect.arrayContaining(['btn-primary', 'btn-block']))
    expect(w.get('[data-testid=actions]').find('[data-testid=continue-btn]').exists()).toBe(true)
  })

  it('renders in Uzbek', () => {
    setLocale('uz')
    const w = mount(AnswerVerdict, { props: { verdict: { quality: 1, correct: false, expected: 'walked' } } })
    expect(w.get('[data-testid=verdict-text]').text()).toBe("Noto'g'ri")
    expect(w.get('[data-testid=expected]').text()).toBe('walked')
    expect(w.get('[data-testid=expected-box]').text()).toContain("to'g'ri javob")
    expect(w.get('[data-testid=continue-btn]').text()).toBe('Keyingisi')

    const close = mount(AnswerVerdict, { props: { verdict: { quality: 4, correct: true, expected: 'walked' } } })
    expect(close.get('[data-testid=verdict-text]').text()).toBe('Deyarli!')
  })
})
