import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'
import AnswerReveal from '../AnswerReveal.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import { usePracticeStore } from '@/stores/practice'
import { collocationElement, dictationElement, pairsElement, sentenceElement } from '@/api/__tests__/practiceFixtures'
import type { PracticeOutcome } from '@/stores/practice'
import type { CollocationReveal, DictationReveal, SentenceReveal } from '@/api/types'

// The review of a dictation fetches the recording of the answered element for "Listen again".
vi.mock('@/api', () => ({ api: { practice: { audioBlob: vi.fn() } } }))

/** An `<audio>` that records what it was asked to do. */
class FakeAudio {
  static instances: FakeAudio[] = []
  constructor(public src: string) {
    FakeAudio.instances.push(this)
  }
  play = vi.fn(() => Promise.resolve())
  pause = vi.fn()
  addEventListener = vi.fn()
}

let urlCounter = 0
const revoked = vi.fn()

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(api.practice.audioBlob).mockReset().mockResolvedValue(new Blob(['x'], { type: 'audio/mpeg' }))
  FakeAudio.instances = []
  urlCounter = 0
  revoked.mockReset()
  vi.stubGlobal('Audio', FakeAudio)
  URL.createObjectURL = () => `blob:answered-${++urlCounter}`
  URL.revokeObjectURL = (url: string) => revoked(url)
})

afterEach(() => {
  setLocale('ru')
  vi.unstubAllGlobals()
})

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
    expect(diff.findAll('[data-testid=diff-word]').map((word) => word.text())).toEqual(['see', 'you', 'tomorow'])
    const marked = diff.findAll('span.underline')
    expect(marked.map((span) => span.text())).toEqual(['tomorow'])
  })

  it('marks every word of the comparison by a symbol AND a word as well as the colour: right ones with a check, wrong ones with a cross and an underline', () => {
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

    const tokens = wrapper.findAll('[data-testid=diff-token]')
    expect(tokens.map((token) => token.attributes('data-ok'))).toEqual(['true', 'true', 'false'])
    expect(tokens.map((token) => token.findComponent(AppIcon).props('name'))).toEqual(['check', 'check', 'x-mark'])
    // The words for a screen reader, beside the symbol and the colour.
    expect(tokens.map((token) => token.get('.sr-only').text())).toEqual(['верно', 'верно', 'ошибка'])
    // Colour: the ink of success / of danger; the wrong word is also underlined and bold, the right ones are not.
    expect(tokens[0]!.classes()).toContain('text-success-ink')
    expect(tokens[2]!.classes()).toContain('text-danger-ink')
    expect(tokens[2]!.classes()).toContain('font-semibold')
    expect(tokens[0]!.find('.underline').exists()).toBe(false)
    expect(tokens[2]!.get('[data-testid=diff-word]').classes()).toContain('underline')
    expect(wrapper.get('[data-testid=diff-hint]').text()).toBe('Отмечены слова, в которых ошибка.')
  })

  it('marks every wrong word when there are several, and says which ones in Uzbek too', () => {
    setLocale('uz')
    const wrapper = render(
      outcome({
        element: dictationElement(),
        answer: { text: 'see me later' },
        verdict: 'wrong',
        reveal: dictation({
          diff: [
            { expected: 'see', got: 'see', ok: true },
            { expected: 'you', got: 'me', ok: false },
            { expected: 'tomorrow', got: 'later', ok: false },
          ],
        }),
      }),
    )

    const tokens = wrapper.findAll('[data-testid=diff-token]')
    expect(tokens.map((token) => token.attributes('data-ok'))).toEqual(['true', 'false', 'false'])
    expect(tokens.map((token) => token.get('.sr-only').text())).toEqual(["to'g'ri", 'xato', 'xato'])
    expect(wrapper.get('[data-testid=diff-hint]').text()).toBe("Xato bor so'zlar belgilangan.")
  })

  it('has no hint when nothing is marked, and falls back to the struck-out text when the server gave no comparison', () => {
    const allRight = render(
      outcome({
        element: dictationElement(),
        verdict: 'wrong',
        reveal: dictation({ diff: [{ expected: 'see', got: 'see', ok: true }] }),
      }),
    )
    expect(allRight.find('[data-testid=diff-hint]').exists()).toBe(false)

    const noDiff = render(outcome({ element: dictationElement(), verdict: 'wrong', reveal: dictation({ your_text: 'see you later today', diff: null }) }))
    expect(noDiff.find('[data-testid=diff]').exists()).toBe(false)
    expect(noDiff.find('[data-testid=diff-hint]').exists()).toBe(false)
    expect(noDiff.get('[data-testid=your-text]').text()).toBe('see you later today')
    expect(noDiff.get('[data-testid=your-text]').classes()).toContain('line-through')
  })

  it('shows the comparison for a typo and a mistake, and none for a right answer', () => {
    const diff = [{ expected: 'see', got: 'see', ok: true }]

    expect(render(outcome({ element: dictationElement(), verdict: 'correct', reveal: dictation({ diff }) })).find('[data-testid=diff]').exists()).toBe(false)
    expect(render(outcome({ element: dictationElement(), verdict: 'typo', reveal: dictation({ diff }) })).find('[data-testid=diff]').exists()).toBe(true)
    expect(render(outcome({ element: dictationElement(), verdict: 'wrong', reveal: dictation({ diff }) })).find('[data-testid=diff]').exists()).toBe(true)
  })

  it('shows no "your answer" for a right answer or an empty one', () => {
    expect(render(outcome({ element: dictationElement(), verdict: 'correct', reveal: dictation() })).find('[data-testid=your-text]').exists()).toBe(false)
    expect(render(outcome({ element: dictationElement(), verdict: 'skipped', reveal: dictation({ your_text: '  ' }) })).find('[data-testid=your-text]').exists()).toBe(false)
  })
})

describe('AnswerReveal — dictation: listen again', () => {
  const answered = (overrides: Partial<PracticeOutcome> = {}) =>
    outcome({ element: dictationElement({ audio_url: '/api/twa/practice/sessions/77/audio/eDict00001' }), answer: { text: 'x' }, verdict: 'wrong', reveal: dictation(), ...overrides })

  it('has a button to hear the recording again, for every verdict and also when the explanation is not at hand', async () => {
    for (const verdict of ['correct', 'typo', 'wrong', 'skipped'] as const) {
      const wrapper = render(answered({ verdict }))
      await flushPromises()
      expect(wrapper.get('[data-testid=listen-again-btn]').text()).toBe('Прослушать ещё раз')
      expect(wrapper.get('[data-testid=listen-again-btn]').classes()).toContain('btn-tinted')
      expect(wrapper.findAll('.btn-primary')).toHaveLength(1) // "Next" stays the one primary button
      wrapper.unmount()
    }

    // A verdict recovered from `answer_already_accepted` has no explanation, and the recording is still there to hear.
    const recovered = render(answered({ reveal: null }))
    await flushPromises()
    expect(recovered.find('[data-testid=expected]').exists()).toBe(false)
    expect(recovered.find('[data-testid=listen-again-btn]').exists()).toBe(true)
    recovered.unmount()
  })

  it('is only for a dictation', () => {
    expect(render(outcome({ element: sentenceElement(), verdict: 'wrong', reveal: sentence() })).find('[data-testid=listen-again-btn]').exists()).toBe(false)
    expect(render(outcome({ element: collocationElement(), answer: { option_id: 'oA1' }, verdict: 'wrong', reveal: collocation() })).find('[data-testid=listen-again-btn]').exists()).toBe(false)
    expect(render(outcome({ element: pairsElement(), answer: { left_id: 'lB4', right_id: 'rV3' }, roundDone: true })).find('[data-testid=listen-again-btn]').exists()).toBe(false)
    expect(api.practice.audioBlob).not.toHaveBeenCalled()
  })

  it('fetches the recording of the ANSWERED element when it appears, and waits for it with a spinner', async () => {
    let finish!: (blob: Blob) => void
    vi.mocked(api.practice.audioBlob).mockReturnValue(new Promise<Blob>((resolve) => (finish = resolve)))

    const wrapper = render(answered())
    await nextTick()

    expect(api.practice.audioBlob).toHaveBeenCalledTimes(1)
    expect(api.practice.audioBlob).toHaveBeenCalledWith('/api/twa/practice/sessions/77/audio/eDict00001')
    const button = wrapper.get('[data-testid=listen-again-btn]')
    expect(button.attributes('disabled')).toBeDefined()
    expect(button.attributes('aria-busy')).toBe('true')
    expect(wrapper.find('[data-testid=listen-again-spinner]').exists()).toBe(true)

    finish(new Blob(['x'], { type: 'audio/mpeg' }))
    await flushPromises()

    expect(button.attributes('disabled')).toBeUndefined()
    expect(button.attributes('aria-busy')).toBeUndefined()
    expect(wrapper.find('[data-testid=listen-again-spinner]').exists()).toBe(false)
    expect(wrapper.findComponent(AppIcon).exists()).toBe(true)
  })

  it('plays it from the tap, as often as asked, from the clip of this review and not from the shared slot of the session', async () => {
    const store = usePracticeStore()
    store.audio.state = 'ready'
    store.audio.blobUrl = 'blob:next-element'
    const wrapper = render(answered())
    await flushPromises()

    await wrapper.get('[data-testid=listen-again-btn]').trigger('click')
    await wrapper.get('[data-testid=listen-again-btn]').trigger('click')

    expect(FakeAudio.instances).toHaveLength(2)
    expect(FakeAudio.instances.map((player) => player.src)).toEqual(['blob:answered-1', 'blob:answered-1'])
    expect(FakeAudio.instances[0]!.pause).toHaveBeenCalled() // the second press starts the clip over
    // The clip of the next element, which the session screen fetched, is untouched.
    expect(store.audio).toEqual({ state: 'ready', blobUrl: 'blob:next-element' })
  })

  it('gives the clip back when the review goes', async () => {
    const wrapper = render(answered())
    await flushPromises()

    wrapper.unmount()

    expect(revoked).toHaveBeenCalledWith('blob:answered-1')
  })

  it('offers to load it again when it could not be fetched', async () => {
    vi.mocked(api.practice.audioBlob).mockRejectedValueOnce(new Error('offline'))
    const wrapper = render(answered())
    await flushPromises()

    const button = wrapper.get('[data-testid=listen-again-btn]')
    expect(button.text()).toBe('Повторить загрузку')
    expect(button.findComponent(AppIcon).props('name')).toBe('arrow-path')
    await button.trigger('click')
    await flushPromises()

    expect(api.practice.audioBlob).toHaveBeenCalledTimes(2)
    expect(wrapper.get('[data-testid=listen-again-btn]').text()).toBe('Прослушать ещё раз')
    expect(FakeAudio.instances).toHaveLength(0) // loading again is not playing
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const wrapper = render(answered())
    await flushPromises()

    expect(wrapper.get('[data-testid=listen-again-btn]').text()).toBe('Yana eshitish')
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

  it('writes the whole collocation under "Right answer" and marks the word that goes into the blank', () => {
    const wrapper = render(outcome({ element, answer: { option_id: 'oA1' }, verdict: 'wrong', reveal: collocation() }))

    const phrase = wrapper.get('[data-testid=expected]')
    expect(phrase.text()).toBe("Let's have a meeting on Monday.")
    expect(phrase.attributes('lang')).toBe('en')
    expect(wrapper.get('[data-testid=expected-fill]').text()).toBe('have')
    expect(wrapper.get('[data-testid=expected-fill]').classes()).toEqual(expect.arrayContaining(['bg-success-soft', 'text-success-ink']))
    expect(wrapper.get('[data-testid=answer-reveal]').text()).toContain('Правильный ответ')
  })

  it('sets a long phrase in a smaller type than a short one', () => {
    const long = 'Every morning my grandmother and I take a very long walk in the old park near our house and the river.'
    const short = render(outcome({ element, answer: { option_id: 'oA1' }, verdict: 'wrong', reveal: collocation() }))
    const wrapper = render(
      outcome({ element: collocationElement({ text: long.replace('take', '___') }), answer: { option_id: 'oA1' }, verdict: 'wrong', reveal: collocation({ full_text: long }) }),
    )

    expect(short.get('[data-testid=expected]').classes()).toContain('text-2xl')
    expect(wrapper.get('[data-testid=expected]').classes()).toContain('text-lg')
    expect(wrapper.get('[data-testid=expected-fill]').text()).toBe('take')
  })

  it('marks a several-word collocation as one piece', () => {
    const wrapper = render(
      outcome({
        element: collocationElement({ text: 'I need to ___ now.', options: [{ id: 'oA1', text: 'take a break' }, { id: 'oB2', text: 'make a break' }, { id: 'oC3', text: 'do a break' }] }),
        answer: { option_id: 'oB2' },
        verdict: 'wrong',
        reveal: collocation({ correct_option_ids: ['oA1'], full_text: 'I need to take a break now.' }),
      }),
    )

    expect(wrapper.get('[data-testid=expected]').text()).toBe('I need to take a break now.')
    expect(wrapper.get('[data-testid=expected-fill]').text()).toBe('take a break')
  })

  it('shows the whole phrase plain when it is not the text with its blank filled (nothing to mark)', () => {
    const wrapper = render(outcome({ element, answer: { option_id: 'oA1' }, verdict: 'wrong', reveal: collocation({ full_text: 'Another phrase.' }) }))

    expect(wrapper.get('[data-testid=expected]').text()).toBe('Another phrase.')
    expect(wrapper.find('[data-testid=expected-fill]').exists()).toBe(false)
  })

  it('names the picked and the right option in words, visible, with the first letter big', () => {
    const wrapper = render(outcome({ element, answer: { option_id: 'oA1' }, verdict: 'wrong', reveal: collocation() }))

    const picked = wrapper.get('[data-testid=reveal-caption-oA1]')
    const right = wrapper.get('[data-testid=reveal-caption-oB2]')
    expect(picked.text()).toBe('ваш ответ')
    expect(right.text()).toBe('верный ответ')
    expect(picked.classes()).not.toContain('sr-only')
    expect(right.classes()).toContain('first-letter:uppercase')
    expect(wrapper.find('[data-testid=reveal-caption-oC3]').exists()).toBe(false)
  })

  it('judges the pick by the verdict of the server: the picked option is right when the verdict says so', () => {
    const right = render(outcome({ element, answer: { option_id: 'oB2' }, verdict: 'correct', reveal: collocation() }))
    const wrong = render(outcome({ element, answer: { option_id: 'oB2' }, verdict: 'wrong', reveal: collocation() }))

    expect(right.get('[data-testid=reveal-option-oB2]').attributes('data-state')).toBe('correct')
    expect(wrong.get('[data-testid=reveal-option-oB2]').attributes('data-state')).toBe('incorrect')
  })

  it('after a hint nothing was picked: the right option is shown and no option is "yours"', () => {
    const wrapper = render(outcome({ element, answer: { skip: true }, verdict: 'skipped', reveal: collocation() }))

    expect(wrapper.get('[data-testid=reveal-option-oB2]').attributes('data-state')).toBe('reveal')
    expect(wrapper.get('[data-testid=reveal-option-oA1]').attributes('data-state')).toBe('idle')
    expect(wrapper.find('[data-testid=reveal-caption-oA1]').exists()).toBe(false)
    expect(wrapper.get('[data-testid=reveal-note]').text()).toBe('Вот правильный ответ. Это считается подсказкой.')
  })

  it('has no explanation card when the task has neither a translation nor an explanation', () => {
    const wrapper = render(outcome({ element, answer: { option_id: 'oA1' }, verdict: 'wrong', reveal: collocation({ translation: null, explanation: null }) }))

    expect(wrapper.find('[data-testid=translation]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=explanation]').exists()).toBe(false)
  })

  it('speaks Uzbek', () => {
    setLocale('uz')

    const wrapper = render(outcome({ element, answer: { option_id: 'oA1' }, verdict: 'wrong', reveal: collocation() }))

    expect(wrapper.get('[data-testid=reveal-caption-oA1]').text()).toBe('sizning javobingiz')
    expect(wrapper.get('[data-testid=reveal-caption-oB2]').text()).toBe("to'g'ri javob")
    expect(wrapper.get('[data-testid=answer-reveal]').text()).toContain("To'g'ri javob")
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
