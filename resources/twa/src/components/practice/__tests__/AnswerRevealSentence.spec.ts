import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AnswerReveal from '../AnswerReveal.vue'
import { sentenceElement } from '@/api/__tests__/practiceFixtures'
import { setLocale } from '@/i18n'
import type { PracticeOutcome } from '@/stores/practice'
import type { PracticeVerdict, SentenceReveal } from '@/api/types'

/**
 * The review of "Build the sentence" (docs/15 §3.2, docs/16 §5.3): the right sentence, the other orders that are right
 * ("Можно и так"), what the learner put together when it was not right, and the explanation. Everything is the server's
 * text, drawn as text.
 */
afterEach(() => setLocale('ru'))

const reveal = (overrides: Partial<SentenceReveal> = {}): SentenceReveal => ({
  correct_text: 'I usually drink tea.',
  alternatives: ['Usually I drink tea.'],
  your_text: 'Tea drink I usually',
  explanation: 'Наречие частоты стоит перед глаголом или в начале предложения.',
  ...overrides,
})

const render = (verdict: PracticeVerdict, revealed: SentenceReveal | null = reveal()) =>
  mount(AnswerReveal, {
    props: {
      outcome: { element: sentenceElement(), answer: { order: ['fW2'] }, verdict, firstAttempt: true, reveal: revealed, pair: null, roundDone: false } satisfies PracticeOutcome,
    },
  })

describe('AnswerReveal — sentence', () => {
  it('shows the right sentence, what was put together (struck through), the other right orders under "Можно и так", and the explanation', () => {
    const w = render('wrong')

    expect(w.get('[data-testid=expected]').text()).toBe('I usually drink tea.')
    expect(w.get('[data-testid=your-text]').text()).toBe('Tea drink I usually')
    expect(w.get('[data-testid=your-text]').classes()).toContain('line-through')
    expect(w.get('[data-testid=alternatives]').text()).toContain('Можно и так')
    expect(w.findAll('[data-testid=alternative]').map((p) => p.text())).toEqual(['Usually I drink tea.'])
    expect(w.get('[data-testid=explanation]').text()).toContain('Наречие частоты')
  })

  it('lists every other right order, one line each', () => {
    const w = render('wrong', reveal({ alternatives: ['Usually I drink tea.', 'I drink tea usually.'] }))

    expect(w.findAll('[data-testid=alternative]').map((p) => p.text())).toEqual(['Usually I drink tea.', 'I drink tea usually.'])
  })

  it('a right answer shows the sentence and the other orders, and not "your answer"', () => {
    const w = render('correct', reveal({ your_text: 'Usually I drink tea' }))

    expect(w.get('[data-testid=expected]').text()).toBe('I usually drink tea.')
    expect(w.find('[data-testid=your-text]').exists()).toBe(false)
    expect(w.get('[data-testid=alternatives]').text()).toContain('Usually I drink tea.')
  })

  it('"I don\'t know" shows the sentence and no line of the learner', () => {
    const w = render('skipped', reveal({ your_text: null }))

    expect(w.get('[data-testid=expected]').text()).toBe('I usually drink tea.')
    expect(w.find('[data-testid=your-text]').exists()).toBe(false)
    expect(w.get('[data-testid=reveal-note]').text()).toContain('подсказкой')
  })

  it('has no "Можно и так" and no explanation when the task has neither', () => {
    const w = render('wrong', reveal({ alternatives: [], explanation: null }))

    expect(w.find('[data-testid=alternatives]').exists()).toBe(false)
    expect(w.find('[data-testid=explanation]').exists()).toBe(false)
    expect(w.find('[data-testid=expected]').exists()).toBe(true)
  })

  it('speaks Uzbek', () => {
    setLocale('uz')
    const w = render('wrong')

    expect(w.get('[data-testid=alternatives]').text()).toContain("Bunday ham bo'ladi")
    expect(w.get('[data-testid=reveal-text]').text()).toBe('Tuzatish kerak')
  })

  it('draws the text of the server as text: markup in the sentence, in the other orders and in the explanation is not HTML', () => {
    const w = render('wrong', reveal({ correct_text: '<b>I</b> drink', alternatives: ['<i>Tea</i> I drink'], explanation: '<img src=x onerror=alert(1)>' }))

    expect(w.get('[data-testid=expected]').text()).toBe('<b>I</b> drink')
    expect(w.get('[data-testid=alternative]').text()).toBe('<i>Tea</i> I drink')
    expect(w.find('b, i, img').exists()).toBe(false)
  })
})
