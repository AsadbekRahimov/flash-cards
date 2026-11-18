import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import AnswerReveal from '../AnswerReveal.vue'
import { setLocale } from '@/i18n'
import { errorHuntElement, errorHuntFixElement, errorHuntReveal, wordFormElement, wordFormReveal } from '@/api/__tests__/practiceFixtures'
import type { PracticeOutcome } from '@/stores/practice'

beforeEach(() => setActivePinia(createPinia()))

afterEach(() => setLocale('ru'))

const outcome = (overrides: Partial<PracticeOutcome>): PracticeOutcome => ({
  element: wordFormElement(),
  answer: { text: 'decidion' },
  verdict: 'wrong',
  firstAttempt: true,
  reveal: wordFormReveal(),
  pair: null,
  roundDone: false,
  ...overrides,
})

const render = (done: PracticeOutcome) => mount(AnswerReveal, { props: { outcome: done } })

describe('AnswerReveal — word form', () => {
  it('a wrong form: the right one, what was typed struck through, the sentence with the form marked, the translation and the explanation', () => {
    const wrapper = render(outcome({}))

    expect(wrapper.get('[data-testid=answer-reveal]').attributes('data-kind')).toBe('word_form')
    expect(wrapper.get('[data-testid=reveal-text]').text()).toBe('Неверно')
    expect(wrapper.get('[data-testid=expected]').text()).toBe('decision')
    expect(wrapper.get('[data-testid=your-text]').text()).toBe('decidion')
    expect(wrapper.get('[data-testid=your-text]').classes()).toContain('line-through')
    expect(wrapper.find('[data-testid=same-as-base]').exists()).toBe(false)
    expect(wrapper.get('[data-testid=word-form-full]').text()).toBe('We need to make a decision by Friday.')
    expect(wrapper.get('[data-testid=word-form-fill]').text()).toBe('decision')
    expect(wrapper.get('[data-testid=translation]').text()).toContain('Нам нужно принять решение до пятницы.')
    expect(wrapper.get('[data-testid=explanation]').text()).toContain('decide → decision')
    expect(wrapper.find('[data-testid=alternatives]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('the base word typed as it is: "another form of the word is needed"', () => {
    const wrapper = render(outcome({ answer: { text: 'decide' }, reveal: wordFormReveal({ your_text: 'decide', same_as_base: true }) }))

    expect(wrapper.get('[data-testid=same-as-base]').text()).toBe('Нужна другая форма слова')
    expect(wrapper.get('[data-testid=your-text]').text()).toBe('decide')
    wrapper.unmount()
  })

  it('a right form: no struck-through answer, the other accepted forms', () => {
    const wrapper = render(
      outcome({
        verdict: 'correct',
        answer: { text: 'practise' },
        reveal: wordFormReveal({ expected: 'practice', alternatives: ['practise'], your_text: 'practise', full_text: 'We need to make a practice by Friday.' }),
      }),
    )

    expect(wrapper.get('[data-testid=reveal-text]').text()).toBe('Верно!')
    expect(wrapper.find('[data-testid=your-text]').exists()).toBe(false)
    expect(wrapper.get('[data-testid=alternatives]').text()).toContain('practise')
    wrapper.unmount()
  })

  it('a skip: the right form with the note of a hint, nothing typed', () => {
    const wrapper = render(outcome({ verdict: 'skipped', answer: { skip: true }, reveal: wordFormReveal({ your_text: null }) }))

    expect(wrapper.get('[data-testid=reveal-text]').text()).toBe('Пропущено')
    expect(wrapper.get('[data-testid=reveal-note]').text()).toBe('Вот правильный ответ. Это считается подсказкой.')
    expect(wrapper.find('[data-testid=your-text]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('a sentence that does not add up with its blank is shown plain', () => {
    const wrapper = render(outcome({ reveal: wordFormReveal({ full_text: 'Something else entirely.' }) }))

    expect(wrapper.get('[data-testid=word-form-full]').text()).toBe('Something else entirely.')
    expect(wrapper.find('[data-testid=word-form-fill]').exists()).toBe(false)
    wrapper.unmount()
  })
})

describe('AnswerReveal — error hunt', () => {
  const hunt = (overrides: Partial<PracticeOutcome> = {}): PracticeOutcome =>
    outcome({ element: errorHuntFixElement('tAm1'), answer: { option_id: 'oIn2' }, verdict: 'wrong', reveal: errorHuntReveal(), ...overrides })

  it('the mistake missed and fixed: two lines with a symbol and a word each, "Was" and "Now" with their words marked', () => {
    const wrapper = render(hunt())

    expect(wrapper.get('[data-testid=answer-reveal]').attributes('data-kind')).toBe('error_hunt')
    expect(wrapper.get('[data-testid=reveal-text]').text()).toBe('Неверно')
    const found = wrapper.get('[data-testid=hunt-found]')
    expect(found.attributes('data-ok')).toBe('false')
    expect(found.text()).toContain('Ошибку нашли')
    expect(found.text()).toContain('нет')
    const fixed = wrapper.get('[data-testid=hunt-fixed]')
    expect(fixed.attributes('data-ok')).toBe('true')
    expect(fixed.text()).toContain('Исправили')
    expect(fixed.text()).toContain('да')

    expect(wrapper.get('[data-testid=hunt-was]').text()).toContain('Было')
    expect(wrapper.get('[data-testid=hunt-was]').text()).toContain('I am very interested on modern art.')
    expect(wrapper.get('[data-testid=hunt-error-word]').text()).toBe('on')
    expect(wrapper.get('[data-testid=hunt-became]').text()).toContain('Стало')
    expect(wrapper.get('[data-testid=hunt-became]').text()).toContain('I am very interested in modern art.')
    expect(wrapper.get('[data-testid=hunt-fix-word]').text()).toBe('in')

    expect(wrapper.get('[data-testid=translation]').text()).toContain('Меня очень интересует современное искусство.')
    expect(wrapper.get('[data-testid=explanation]').text()).toContain('interested in')
    // Not a collocation: no options of the collocation review.
    expect(wrapper.find('[data-testid=reveal-options]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('both steps right', () => {
    const wrapper = render(hunt({ verdict: 'correct', element: errorHuntFixElement('tOn4'), reveal: errorHuntReveal({ located_ok: true, your_token_id: 'tOn4' }) }))

    expect(wrapper.get('[data-testid=reveal-text]').text()).toBe('Верно!')
    expect(wrapper.get('[data-testid=hunt-found]').attributes('data-ok')).toBe('true')
    expect(wrapper.get('[data-testid=hunt-fixed]').attributes('data-ok')).toBe('true')
    wrapper.unmount()
  })

  it('a skip at the first step: nothing found, nothing fixed, the sentences still told (the words of the element are enough)', () => {
    const wrapper = render(
      hunt({
        verdict: 'skipped',
        element: errorHuntElement(),
        answer: { skip: true },
        reveal: errorHuntReveal({ located_ok: false, fixed_ok: false, your_token_id: null, your_option_id: null }),
      }),
    )

    expect(wrapper.get('[data-testid=hunt-found]').attributes('data-ok')).toBe('false')
    expect(wrapper.get('[data-testid=hunt-fixed]').attributes('data-ok')).toBe('false')
    expect(wrapper.get('[data-testid=hunt-error-word]').text()).toBe('on')
    expect(wrapper.get('[data-testid=hunt-fix-word]').text()).toBe('in')
    wrapper.unmount()
  })

  it('a correction of two words, with the punctuation of the word kept', () => {
    const element = errorHuntElement({
      tokens: [
        { id: 't0', text: 'I' },
        { id: 't1', text: 'want' },
        { id: 't2', text: 'go' },
        { id: 't3', text: 'home.' },
      ],
    })
    const wrapper = render(
      hunt({ element, reveal: errorHuntReveal({ error_token_id: 't2', wrong_text: 'I want go home.', full_text: 'I want to go home.' }) }),
    )

    expect(wrapper.get('[data-testid=hunt-error-word]').text()).toBe('go')
    expect(wrapper.get('[data-testid=hunt-fix-word]').text()).toBe('to go')
    wrapper.unmount()
  })

  it('speaks Uzbek', () => {
    setLocale('uz')
    const wrapper = render(hunt())

    expect(wrapper.get('[data-testid=hunt-found]').text()).toContain('Xato topildi')
    expect(wrapper.get('[data-testid=hunt-fixed]').text()).toContain('Tuzatildi')
    expect(wrapper.get('[data-testid=hunt-was]').text()).toContain('Edi')
    expect(wrapper.get('[data-testid=hunt-became]').text()).toContain("Bo'ldi")
    wrapper.unmount()
  })
})
