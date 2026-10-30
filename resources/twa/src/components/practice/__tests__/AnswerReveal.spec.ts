import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AnswerReveal from '../AnswerReveal.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { setLocale } from '@/i18n'
import { collocationElement, dictationElement, pairsElement, sentenceElement } from '@/api/__tests__/practiceFixtures'
import type { PracticeOutcome } from '@/stores/practice'
import type { CollocationReveal, DictationReveal, SentenceReveal } from '@/api/types'

afterEach(() => setLocale('ru'))

const outcome = (overrides: Partial<PracticeOutcome>): PracticeOutcome => ({
  element: sentenceElement(),
  answer: { order: ['fW2'] },
  verdict: 'correct',
  firstAttempt: true,
  reveal: null,
  pair: null,
  roundDone: false,
  ...overrides,
})

const render = (done: PracticeOutcome, last = false) => mount(AnswerReveal, { props: { outcome: done, last } })

const dictation = (overrides: Partial<DictationReveal> = {}): DictationReveal => ({
  expected: 'See you tomorrow',
  your_text: 'see you tomorow',
  diff: null,
  translation: 'До завтра',
  explanation: null,
  ...overrides,
})

const sentence = (overrides: Partial<SentenceReveal> = {}): SentenceReveal => ({
  correct_text: 'My mother is a teacher.',
  alternatives: [],
  your_text: 'mother My is teacher a',
  explanation: 'Порядок слов.',
  ...overrides,
})

const collocation = (overrides: Partial<CollocationReveal> = {}): CollocationReveal => ({
  correct_option_ids: ['oB2'],
  full_text: "Let's have a meeting on Monday.",
  translation: 'Давайте проведём встречу.',
  explanation: 'С meeting говорят have.',
  ...overrides,
})

describe('AnswerReveal — the verdict is a symbol and words, never a colour alone', () => {
  it.each([
    ['correct', 'Верно!', 'success'],
    ['typo', 'Есть опечатка', 'warning'],
    ['wrong', 'Нужно исправить', 'danger'],
    ['skipped', 'Подсказка', 'accent'],
  ] as const)('%s: "%s" in a %s circle with its own symbol', (verdict, text, tone) => {
    const wrapper = render(outcome({ element: dictationElement(), answer: { text: 'x' }, verdict, reveal: dictation() }))

    expect(wrapper.get('[data-testid=reveal-text]').text()).toBe(text)
    expect(wrapper.get('[data-testid=reveal-icon]').classes()).toContain(`bg-${tone}-soft`)
    expect(wrapper.get('[data-testid=reveal-icon]').findComponent(AppIcon).exists()).toBe(true)
    expect(wrapper.get('[data-testid=answer-reveal]').attributes('data-verdict')).toBe(verdict)
  })

  it('the four verdicts have four different symbols', () => {
    const icons = (['correct', 'typo', 'wrong', 'skipped'] as const).map((verdict) => {
      const wrapper = render(outcome({ verdict, reveal: sentence() }))
      return wrapper.get('[data-testid=reveal-icon]').findComponent(AppIcon).props('name')
    })

    expect(new Set(icons).size).toBe(4)
  })

  it('a typo says why it does not count, a hint says that it does', () => {
    const typo = render(outcome({ verdict: 'typo', element: dictationElement(), reveal: dictation() }))
    const hint = render(outcome({ verdict: 'skipped', element: dictationElement(), reveal: dictation({ your_text: null }) }))

    expect(typo.get('[data-testid=reveal-note]').text()).toBe('Почти верно, но в слове опечатка. С первой попытки это не засчитывается.')
    expect(hint.get('[data-testid=reveal-note]').text()).toBe('Вот правильный ответ. Это считается подсказкой.')
  })

  it('speaks Uzbek', () => {
    setLocale('uz')

    const wrapper = render(outcome({ verdict: 'wrong', reveal: sentence() }))

    expect(wrapper.get('[data-testid=reveal-text]').text()).toBe('Tuzatish kerak')
  })
})

describe('AnswerReveal — dictation', () => {
  it('shows the phrase as it should be written, what was typed, and the translation', () => {
    const wrapper = render(outcome({ element: dictationElement(), answer: { text: 'see you tomorow' }, verdict: 'wrong', reveal: dictation() }))

    expect(wrapper.get('[data-testid=expected]').text()).toBe('See you tomorrow')
    expect(wrapper.get('[data-testid=your-text]').text()).toBe('see you tomorow')
    expect(wrapper.get('[data-testid=your-text]').classes()).toContain('line-through')
    expect(wrapper.get('[data-testid=translation]').text()).toContain('До завтра')
    expect(wrapper.find('[data-testid=explanation]').exists()).toBe(false)
  })

  it('marks the words that differ, in the typed text, when the server gave the diff', () => {
    const wrapper = render(
      outcome({
        element: dictationElement(),
        answer: { text: 'see you tomorow' },
        verdict: 'typo',
        reveal: dictation({
          diff: [
            { expected: 'see', got: 'see', ok: true },
            { expected: 'you', got: 'you', ok: true },
            { expected: 'tomorrow', got: 'tomorow', ok: false },
          ],
        }),
      }),
    )

    const diff = wrapper.get('[data-testid=diff]')
    expect(diff.findAll('span').map((span) => span.text())).toEqual(['see', 'you', 'tomorow'])
    const marked = diff.findAll('span.underline')
    expect(marked.map((span) => span.text())).toEqual(['tomorow'])
  })

  it('shows no "your answer" for a right answer or an empty one', () => {
    expect(render(outcome({ element: dictationElement(), verdict: 'correct', reveal: dictation() })).find('[data-testid=your-text]').exists()).toBe(false)
    expect(render(outcome({ element: dictationElement(), verdict: 'skipped', reveal: dictation({ your_text: '  ' }) })).find('[data-testid=your-text]').exists()).toBe(false)
  })
})

describe('AnswerReveal — sentence', () => {
  it('shows the right sentence, the other orders that are right, what was built and the explanation', () => {
    const wrapper = render(
      outcome({ verdict: 'wrong', reveal: sentence({ alternatives: ['Mother is a teacher, my.', 'A teacher is my mother.'] }) }),
    )

    expect(wrapper.get('[data-testid=expected]').text()).toBe('My mother is a teacher.')
    expect(wrapper.get('[data-testid=your-text]').text()).toBe('mother My is teacher a')
    expect(wrapper.get('[data-testid=alternatives]').text()).toContain('Mother is a teacher, my.')
    expect(wrapper.get('[data-testid=alternatives]').text()).toContain('A teacher is my mother.')
    expect(wrapper.get('[data-testid=explanation]').text()).toContain('Порядок слов.')
  })

  it('has no list of other orders when there are none', () => {
    expect(render(outcome({ verdict: 'wrong', reveal: sentence() })).find('[data-testid=alternatives]').exists()).toBe(false)
  })
})

describe('AnswerReveal — collocation', () => {
  const element = collocationElement()

  it('shows the whole phrase and the three options: the right one and the picked one marked with a symbol and a word', () => {
    const wrapper = render(outcome({ element, answer: { option_id: 'oA1' }, verdict: 'wrong', reveal: collocation() }))

    expect(wrapper.get('[data-testid=expected]').text()).toBe("Let's have a meeting on Monday.")
    const picked = wrapper.get('[data-testid=reveal-option-oA1]')
    const right = wrapper.get('[data-testid=reveal-option-oB2]')
    const other = wrapper.get('[data-testid=reveal-option-oC3]')
    expect(picked.attributes('data-state')).toBe('incorrect')
    expect(right.attributes('data-state')).toBe('reveal')
    expect(other.attributes('data-state')).toBe('idle')
    expect(picked.text()).toContain('make')
    expect(picked.text()).toContain('ваш ответ')
    expect(right.text()).toContain('верный ответ')
    expect(picked.findComponent(AppIcon).props('name')).toBe('x-mark')
    expect(right.findComponent(AppIcon).props('name')).toBe('check')
    expect(other.findComponent(AppIcon).exists()).toBe(false)
  })

  it('a right pick is the right option itself', () => {
    const wrapper = render(outcome({ element, answer: { option_id: 'oB2' }, verdict: 'correct', reveal: collocation() }))

    expect(wrapper.get('[data-testid=reveal-option-oB2]').attributes('data-state')).toBe('correct')
    expect(wrapper.get('[data-testid=reveal-option-oA1]').attributes('data-state')).toBe('idle')
  })

  it('two right options are both marked', () => {
    const wrapper = render(outcome({ element, answer: { option_id: 'oC3' }, verdict: 'wrong', reveal: collocation({ correct_option_ids: ['oB2', 'oA1'] }) }))

    expect(wrapper.get('[data-testid=reveal-option-oA1]').attributes('data-state')).toBe('reveal')
    expect(wrapper.get('[data-testid=reveal-option-oB2]').attributes('data-state')).toBe('reveal')
    expect(wrapper.get('[data-testid=reveal-option-oC3]').attributes('data-state')).toBe('incorrect')
  })

  it('shows the translation and the explanation', () => {
    const wrapper = render(outcome({ element, answer: { option_id: 'oA1' }, verdict: 'wrong', reveal: collocation() }))

    expect(wrapper.get('[data-testid=translation]').text()).toContain('Давайте проведём встречу.')
    expect(wrapper.get('[data-testid=explanation]').text()).toContain('С meeting говорят have.')
  })
})

describe('AnswerReveal — end of a round of pairs', () => {
  const round = (mismatches: number) =>
    outcome({ element: pairsElement({ round: 2, rounds: 3, mismatches }), answer: { left_id: 'lB4', right_id: 'rV3' }, roundDone: true })

  it('says which round is done, and how it went', () => {
    expect(render(round(0)).get('[data-testid=reveal-text]').text()).toBe('Раунд 2 из 3 пройден')
    expect(render(round(0)).get('[data-testid=round-mistakes]').text()).toBe('Без ошибок')
    expect(render(round(3)).get('[data-testid=round-mistakes]').text()).toBe('Ошибок в раунде: 3')
    expect(render(round(0)).get('[data-testid=answer-reveal]').attributes('data-verdict')).toBe('round')
  })

  it('has no phrase, no translation: pairs have no explanation', () => {
    const wrapper = render(round(1))

    expect(wrapper.find('[data-testid=expected]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=translation]').exists()).toBe(false)
  })

  describe('the pairs of the round, which have left the board one by one', () => {
    // Three pairs were matched before the last one; the last is the answer that ended the round.
    const finished = (overrides: Partial<PracticeOutcome> = {}) =>
      outcome({
        element: pairsElement({ round: 1, rounds: 3, matched: [['lS3', 'rT1'], ['lM1', 'rU2'], ['lF2', 'rW4']] }),
        answer: { left_id: 'lB4', right_id: 'rV3' },
        pair: { left_id: 'lB4', right_id: 'rV3' },
        roundDone: true,
        ...overrides,
      })

    it('lists every pair as a word and its translation, in the order of the words on the board', () => {
      const rows = render(finished()).findAll('[data-testid=round-pair]')

      // The board shows mother, father, sister, brother (fixtures/practiceFixtures.ts): the recap keeps that order, not the order of the matches.
      expect(rows.map((row) => row.findAll('span').map((span) => span.text()))).toEqual([
        ['mother', 'мама'],
        ['father', 'папа'],
        ['sister', 'сестра'],
        ['brother', 'брат'],
      ])
    })

    it('has a heading for them, in the language of the screen', () => {
      expect(render(finished()).get('[data-testid=round-pairs]').text()).toContain('Пары раунда')

      setLocale('uz')
      expect(render(finished()).get('[data-testid=round-pairs]').text()).toContain('Raund juftlari')
    })

    it('does not list the last pair twice when the element already holds it', () => {
      const rows = render(finished({ element: pairsElement({ matched: [['lM1', 'rU2'], ['lB4', 'rV3']] }) })).findAll('[data-testid=round-pair]')

      expect(rows.map((row) => row.findAll('span')[0]?.text())).toEqual(['mother', 'brother'])
    })

    it('has no list when nothing is known of the pairs', () => {
      expect(render(round(0)).find('[data-testid=round-pairs]').exists()).toBe(false)
    })

    it('shows no pair on the screen of any other kind of answer', () => {
      expect(render(outcome({ element: dictationElement(), reveal: dictation() })).find('[data-testid=round-pairs]').exists()).toBe(false)
    })
  })
})

describe('AnswerReveal — a verdict without the explanation', () => {
  it('is the verdict and the way on (the answer had been counted before, the explanation is not at hand)', () => {
    const wrapper = render(outcome({ verdict: 'wrong', reveal: null }))

    expect(wrapper.get('[data-testid=reveal-text]').text()).toBe('Нужно исправить')
    expect(wrapper.find('[data-testid=expected]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=your-text]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=continue-btn]').exists()).toBe(true)
  })
})

describe('AnswerReveal — the way on', () => {
  it('"Next" is the one primary button, at the bottom, and says continue', async () => {
    const wrapper = render(outcome({ reveal: sentence() }))

    const button = wrapper.get('[data-testid=continue-btn]')
    expect(button.text()).toBe('Дальше')
    expect(wrapper.findAll('.btn-primary')).toHaveLength(1)
    expect(wrapper.get('[data-testid=actions]').find('[data-testid=continue-btn]').exists()).toBe(true)

    await button.trigger('click')
    expect(wrapper.emitted('continue')).toHaveLength(1)
  })

  it('on the last element it leads to the summary, and says so', () => {
    expect(render(outcome({ reveal: sentence() }), true).get('[data-testid=continue-btn]').text()).toBe('К итогам')
  })
})

describe('AnswerReveal — text from the server is text', () => {
  it('shows markup of an explanation as it is, never as HTML', () => {
    const wrapper = render(outcome({ verdict: 'wrong', reveal: sentence({ explanation: '<b>bold</b><img src=x onerror=alert(1)>' }) }))

    expect(wrapper.get('[data-testid=explanation]').text()).toContain('<b>bold</b>')
    expect(wrapper.find('[data-testid=explanation] b').exists()).toBe(false)
    expect(wrapper.find('img').exists()).toBe(false)
  })
})
