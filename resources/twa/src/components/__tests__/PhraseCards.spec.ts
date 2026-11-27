import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import AudioCard from '../AudioCard.vue'
import FlashCard from '../FlashCard.vue'
import GapFillCard from '../GapFillCard.vue'
import MeaningCard from '../MeaningCard.vue'
import PhraseLabels from '../PhraseLabels.vue'
import PretestCard from '../PretestCard.vue'
import PretestReveal from '../PretestReveal.vue'
import QuizOption from '../QuizOption.vue'
import ReverseCard from '../ReverseCard.vue'
import { phraseHeadingClass, phraseLabels } from '@/composables/phraseText'
import { resetAutoSpeak } from '@/composables/useAutoSpeak'
import type { Card, ChoiceResult, PhraseInfo, Revealed } from '@/api/types'
import { setLocale } from '@/i18n'

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ haptic: vi.fn(), selectionHaptic: vi.fn(), notificationHaptic: vi.fn() }),
}))
const { speak } = vi.hoisted(() => ({ speak: vi.fn().mockResolvedValue(undefined) }))
vi.mock('@/composables/useTts', () => ({ useTts: () => ({ supported: false, speak }) }))

/** A phrase of exactly 100 characters: the longest the import lets in (docs/35 §3.3 h). */
const LONG = 'I would rather stay at home tonight than go out in the pouring rain to see a film I do not like at all'.slice(0, 100)
const LONG_RU = 'Я бы лучше остался сегодня дома, чем пошёл под проливным дождём на фильм, который мне совсем не нравится'

/** The labels as the server sends them while the card hides its answer: no literal yet. */
const hidden: PhraseInfo = { kind: 'idiom', register: 'informal', variant: 'uk', literal: null }

const base: Card = {
  word_id: 7,
  word: LONG,
  translation: '',
  translation_fallback: false,
  example: null,
  transcription: null,
  audio_url: null,
  card_kind: 'due',
  serve_token: null,
  hook: null,
  note: null,
  confused_with: null,
  phrase: hidden,
}

const meaning: Card = {
  ...base,
  prompt: {
    type: 'meaning',
    front: LONG,
    masked_example: null,
    options: [LONG_RU, 'Проще простого', 'Легко досталось — легко потерялось', 'Ни пуха ни пера'],
    answer_length: null,
  },
}

function revealed(overrides: Partial<Revealed> = {}): Revealed {
  return {
    word: LONG,
    translation: LONG_RU,
    correct: LONG_RU,
    example: null,
    transcription: null,
    audio_url: null,
    hook: null,
    note: null,
    confused_with: null,
    phrase: { ...hidden, literal: 'я бы скорее остался дома' },
    ...overrides,
  }
}

const result = (correct: boolean, overrides: Partial<Revealed> = {}): ChoiceResult => ({ correct, right: LONG_RU, revealed: revealed(overrides) })

afterEach(() => {
  setLocale('ru')
  resetAutoSpeak()
  speak.mockClear()
})

describe('phrase text', () => {
  it('steps the size down with the length; a 100-character phrase is never the 52 px of a word', () => {
    expect(phraseHeadingClass('No worries')).toContain('text-[36px]')
    expect(phraseHeadingClass('Easy come, easy go, they say')).toContain('text-[30px]')
    expect(phraseHeadingClass('Better late than never, as my grandma says')).toContain('text-[26px]')
    expect(phraseHeadingClass(LONG)).toContain('text-[22px]')
    expect(phraseHeadingClass(LONG, true)).toContain('text-[20px]')
    for (const text of ['No worries', LONG]) {
      expect(phraseHeadingClass(text)).not.toContain('52px')
      expect(phraseHeadingClass(text, true)).not.toContain('40px')
    }
  })

  it('labels the register, UK / US and the kind; `both` and unknown values have no label', () => {
    expect(phraseLabels(hidden).map((l) => l.label)).toEqual(['разговорное', 'UK', 'идиома'])
    expect(phraseLabels({ kind: 'proverb', register: 'formal', variant: 'us', literal: null }).map((l) => l.label)).toEqual(['официальное', 'US', 'пословица'])
    expect(phraseLabels({ kind: null, register: 'neutral', variant: 'both', literal: null }).map((l) => l.key)).toEqual(['register'])
    expect(phraseLabels({ kind: 'future' as never, register: null, variant: 'both', literal: null })).toEqual([])
    setLocale('uz')
    expect(phraseLabels(hidden).map((l) => l.label)).toEqual(["so'zlashuv", 'UK', 'idioma'])
  })

  it('draws no list for a phrase without labels', () => {
    const w = mount(PhraseLabels, { props: { phrase: { kind: null, register: null, variant: 'both', literal: null } } })
    expect(w.find('[data-testid=phrase-labels]').exists()).toBe(false)
  })
})

describe('MeaningCard of a phrase', () => {
  it('shows the long phrase smaller, wrapping, with its labels; the options are long radios', () => {
    const w = mount(MeaningCard, { props: { card: meaning } })
    const heading = w.get('[data-testid=word]')
    expect(heading.text()).toBe(LONG)
    expect(heading.classes()).toEqual(expect.arrayContaining(['break-words', 'text-balance', 'text-[18px]']))
    expect(heading.classes()).not.toContain('text-[44px]')
    expect(heading.classes()).not.toContain('truncate')
    expect(w.get('[data-testid=exercise-title]').text()).toBe('Выберите перевод фразы')

    const labels = w.findAll('[data-testid=phrase-label]')
    expect(labels.map((l) => l.text())).toEqual(['разговорное', 'UK', 'идиома'])
    expect(w.get('[data-testid=phrase-labels]').attributes('aria-label')).toBe('Метки фразы')

    const group = w.get('[role=radiogroup]')
    expect(group.attributes('aria-label')).toBe('Выберите перевод фразы')
    const options = w.findAll('button[role=radio]')
    expect(options).toHaveLength(4)
    for (const option of options) {
      expect(option.attributes('data-long')).toBe('true')
      expect(option.attributes('aria-checked')).toBe('false')
      expect(option.classes()).toContain('min-h-[60px]')
      expect(option.classes()).toContain('text-base')
    }
    expect(w.get('[data-testid=option-0] [data-testid=option-label]').text()).toBe(LONG_RU)
    // The key stays on the left, in the middle of the box, however many lines the phrase takes.
    expect(w.get('[data-testid=option-key-0]').classes()).toEqual(expect.arrayContaining(['shrink-0', 'self-center']))
    // «Буквально» is not on the question: the server has not sent it.
    expect(w.find('[data-testid=phrase-literal]').exists()).toBe(false)
  })

  it('after the answer: the phrase and its meaning on two lines, «Буквально: …», the verdict caption under the option text', async () => {
    const w = mount(MeaningCard, { props: { card: meaning } })
    await w.get('[data-testid=option-1]').trigger('click')
    expect(w.find('[data-testid=phrase-literal]').exists()).toBe(false)

    await w.setProps({ result: result(false) })
    expect(w.get('[data-testid=feedback-phrase]').text()).toBe(LONG)
    expect(w.get('[data-testid=feedback-meaning]').text()).toBe(LONG_RU)
    expect(w.get('[data-testid=feedback-title]').text()).not.toContain(' — ')
    expect(w.get('[data-testid=phrase-literal]').text()).toBe('Буквально: я бы скорее остался дома')
    expect(w.get('[data-testid=word]').classes()).toContain('text-[17px]')

    // The caption of the verdict is inside the text column, so a long option keeps its width at 320 px.
    expect(w.get('[data-testid=option-0]').attributes('data-state')).toBe('reveal')
    expect(w.get('[data-testid=option-0] span.flex-1 [data-testid=option-caption-0]').text()).toBe('верный ответ')
    expect(w.get('[data-testid=option-1] span.flex-1 [data-testid=option-caption-1]').text()).toBe('ваш ответ')
  })

  it('an answer without a literal translation has no «Буквально» line', async () => {
    const w = mount(MeaningCard, { props: { card: meaning } })
    await w.get('[data-testid=option-0]').trigger('click')
    await w.setProps({ result: result(true, { phrase: hidden }) })
    expect(w.find('[data-testid=feedback-phrase]').exists()).toBe(true)
    expect(w.find('[data-testid=phrase-literal]').exists()).toBe(false)
  })

  it('a phrase of both variants has no UK / US label', () => {
    const w = mount(MeaningCard, { props: { card: { ...meaning, phrase: { ...hidden, variant: 'both' } } } })
    expect(w.findAll('[data-testid=phrase-label]').map((l) => l.attributes('data-key'))).toEqual(['register', 'kind'])
    expect(w.text()).not.toContain('UK')
  })

  it('a word card stays as it was: Word/XL 44 px, no labels, short options, «word — meaning»', async () => {
    const word: Card = { ...meaning, word: 'Resilient', phrase: null, prompt: { ...meaning.prompt!, front: 'Resilient', options: ['a', 'b', 'c', 'd'] } }
    const w = mount(MeaningCard, { props: { card: word } })
    expect(w.get('[data-testid=word]').classes()).toContain('text-[44px]')
    expect(w.find('[data-testid=phrase-labels]').exists()).toBe(false)
    // the Body/L of `.option` (17 px), not the smaller text of a long option
    expect(w.get('[data-testid=option-0]').classes()).not.toContain('text-base')
    expect(w.get('[data-testid=option-0]').attributes('data-long')).toBeUndefined()
    expect(w.get('[data-testid=exercise-title]').text()).toBe('Выберите перевод слова')
    await w.get('[data-testid=option-0]').trigger('click')
    await w.setProps({
      result: { correct: true, right: 'a', revealed: { ...revealed({ word: 'Resilient', translation: 'a', correct: 'a' }), phrase: undefined } },
    })
    expect(w.get('[data-testid=feedback-title]').text()).toBe('Resilient — a')
    expect(w.find('[data-testid=phrase-literal]').exists()).toBe(false)
  })
})

describe('ReverseCard of a phrase', () => {
  const reverse: Card = {
    ...base,
    word: '',
    prompt: {
      type: 'reverse',
      front: LONG_RU,
      masked_example: null,
      options: [LONG, 'piece of cake', 'easy come, easy go', 'break the ice'],
      answer_length: null,
    },
  }

  it('the translation is the question, the options are English phrases on several lines; the answer brings the phrase and the literal', async () => {
    const w = mount(ReverseCard, { props: { card: reverse } })
    expect(w.get('[data-testid=front]').classes()).not.toContain('text-[44px]')
    expect(w.get('[data-testid=exercise-title]').text()).toBe('Выберите английскую фразу')
    expect(w.findAll('[data-testid=phrase-label]')).toHaveLength(3)
    const label = w.get('[data-testid=option-0] [data-testid=option-label]')
    expect(label.text()).toBe(LONG)
    expect(label.attributes('lang')).toBe('en')

    await w.get('[data-testid=option-0]').trigger('click')
    await w.setProps({ result: { ...result(true), right: LONG, revealed: revealed({ correct: LONG }) } })
    expect(w.get('[data-testid=feedback-phrase]').text()).toBe(LONG)
    expect(w.get('[data-testid=feedback-meaning]').text()).toBe(LONG_RU)
    expect(w.get('[data-testid=phrase-literal]').text()).toContain('я бы скорее остался дома')
  })
})

describe('AudioCard of a phrase', () => {
  it('the labels under the speaker, long options; the phrase after the answer is not the 32 px of a word', async () => {
    const audio: Card = { ...meaning, prompt: { ...meaning.prompt!, type: 'audio', front: '' } }
    const w = mount(AudioCard, { props: { card: audio } })
    expect(w.findAll('[data-testid=phrase-label]')).toHaveLength(3)
    expect(w.get('[data-testid=option-0]').attributes('data-long')).toBe('true')
    await w.get('[data-testid=option-0]').trigger('click')
    await w.setProps({ result: result(true) })
    expect(w.get('[data-testid=word]').classes()).not.toContain('text-[36px]')
    expect(w.find('[data-testid=phrase-literal]').exists()).toBe(true)
  })
})

describe('PretestCard and PretestReveal of a phrase', () => {
  const pretest: Card = { ...meaning, card_kind: 'new', prompt: { ...meaning.prompt!, type: 'pretest' } }

  it('the phrase smaller with its labels, long options, the lines say «фраза»', () => {
    const w = mount(PretestCard, { props: { card: pretest } })
    expect(w.get('[data-testid=word]').classes()).not.toContain('text-[44px]')
    expect(w.get('[data-testid=exercise-title]').text()).toBe('Выберите перевод, если знаете фразу')
    expect(w.get('[data-testid=option-3]').attributes('data-long')).toBe('true')
    expect(w.findAll('[data-testid=phrase-label]')).toHaveLength(3)

    const reveal = mount(PretestReveal, { props: { card: pretest, verdict: { quality: 1, correct: false, expected: LONG_RU } } })
    expect(reveal.get('[data-testid=reveal-text]').text()).toBe('Новая фраза. Запомните её')
    expect(reveal.get('[data-testid=word]').classes()).not.toContain('text-[44px]')
  })
})

describe('FlashCard of a phrase', () => {
  const flash: Card = { ...base, translation: LONG_RU, phrase: { ...hidden, literal: 'я бы скорее остался дома' } }

  it('«Буквально» only with the translation, the labels from the start', async () => {
    const w = mount(FlashCard, { props: { card: flash, showTranslation: false } })
    expect(w.get('[data-testid=word]').classes()).toContain('text-[18px]')
    expect(w.findAll('[data-testid=phrase-label]').map((l) => l.text())).toEqual(['разговорное', 'UK', 'идиома'])
    expect(w.find('[data-testid=phrase-literal]').exists()).toBe(false)

    await w.setProps({ showTranslation: true })
    expect(w.get('[data-testid=phrase-literal]').text()).toBe('Буквально: я бы скорее остался дома')
  })

  it('in Uzbek', async () => {
    setLocale('uz')
    const w = mount(FlashCard, { props: { card: flash, showTranslation: true } })
    expect(w.get('[data-testid=phrase-literal]').text()).toBe("So'zma-so'z: я бы скорее остался дома")
    expect(w.findAll('[data-testid=phrase-label]').map((l) => l.text())).toEqual(["so'zlashuv", 'UK', 'idioma'])
  })
})

describe('GapFillCard of a phrase', () => {
  const gap: Card = {
    ...base,
    word: '',
    phrase: { kind: 'proverb', register: 'informal', variant: 'both', literal: null },
    prompt: { type: 'gap_fill', front: 'легко досталось — легко потерялось', masked_example: 'Easy come, easy ____', options: null, answer_length: 2 },
  }

  it('the translation first, the phrase with its gap under it, the labels, the field with the length', () => {
    const w = mount(GapFillCard, { props: { card: gap } })
    const article = w.get('article')
    const order = article.findAll('[data-testid]').map((el) => el.attributes('data-testid'))
    expect(order.indexOf('hint')).toBeLessThan(order.indexOf('masked-example'))
    expect(w.get('[data-testid=exercise-title]').text()).toBe('Вставьте пропущенное слово во фразу')
    expect(w.get('[data-testid=hint]').text()).toBe('легко досталось — легко потерялось')
    expect(w.get('[data-testid=masked-example]').text()).toBe('Easy come, easy ____')
    expect(w.get('[data-testid=masked-example]').classes()).toContain('break-words')
    expect(w.findAll('[data-testid=phrase-label]').map((l) => l.text())).toEqual(['разговорное', 'пословица'])
    expect(w.get('[data-testid=answer-length]').text()).toBe('Букв: 2')
  })

  it('sends the typed word as a word card does', async () => {
    const w = mount(GapFillCard, { props: { card: gap } })
    await w.get('[data-testid=answer-input]').setValue('go')
    await w.get('[data-testid=answer-form]').trigger('submit')
    expect(w.emitted('answer')).toEqual([['go']])
  })

  it('a word keeps the example first and the translation under it', () => {
    const word: Card = { ...gap, phrase: null, prompt: { ...gap.prompt!, front: 'идти', masked_example: 'They ____ home.' } }
    const w = mount(GapFillCard, { props: { card: word } })
    const order = w.get('article').findAll('[data-testid]').map((el) => el.attributes('data-testid'))
    expect(order.indexOf('masked-example')).toBeLessThan(order.indexOf('hint'))
    expect(w.get('[data-testid=exercise-title]').text()).toBe('Заполните пропуск')
    expect(w.find('[data-testid=phrase-labels]').exists()).toBe(false)
  })
})

describe('QuizOption long', () => {
  it('a radio with its text, the caption under it, at least 60 px high', () => {
    const w = mount(QuizOption, { props: { label: LONG, index: 2, state: 'incorrect', long: true, disabled: true, lang: 'en' } })
    const button = w.get('button')
    expect(button.attributes('role')).toBe('radio')
    expect(button.attributes('aria-checked')).toBe('true')
    expect(button.attributes('aria-disabled')).toBe('true')
    expect(button.classes()).toContain('min-h-[60px]')
    expect(button.text()).toContain(LONG)
    expect(w.get('[data-testid=option-label]').attributes('lang')).toBe('en')
    expect(w.get('span.flex-1 [data-testid=option-caption-2]').text()).toBe('ваш ответ')
    expect(w.findAll('[data-testid=option-caption-2]')).toHaveLength(1)
  })
})
