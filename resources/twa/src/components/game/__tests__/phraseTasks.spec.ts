import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ChoiceTask from '../ChoiceTask.vue'
import GameReveal from '../GameReveal.vue'
import GapTask from '../GapTask.vue'
import { GAME_LONG_TEXT_MIN } from '@/composables/phraseText'
import { setLocale } from '@/i18n'
import { gap, translateBack, translateFwd } from '@/api/__tests__/gameRunFixtures'
import type { GameOutcome } from '@/stores/gameRun'

// Phrases in a run of Karvon (docs/35 §3.3 e, docs/18): `gap` is the phrase with its key word blanked, `translate_*` may carry a phrase.
// The logic is the words' one; only the long text is a size smaller and wraps, nothing is clipped.

vi.mock('@/api', () => ({ api: { gameRuns: { audioBlob: vi.fn() }, practice: { audioBlob: vi.fn() } } }))

beforeEach(() => setActivePinia(createPinia()))
afterEach(() => setLocale('ru'))

const PHRASE = "I'd rather stay at home tonight than go out in the ____"
const NOCLIP = ['truncate', 'line-clamp-1', 'line-clamp-2', 'line-clamp-3', 'whitespace-nowrap', 'overflow-hidden']

describe('GapTask with a phrase', () => {
  it('the long phrase is a size smaller and wraps by words; the answer goes as for a word', async () => {
    const wrapper = mount(GapTask, { props: { element: gap({ masked: PHRASE, translation: 'я бы лучше остался дома' }) } })

    const prompt = wrapper.get('[data-testid=task-prompt]')
    expect(prompt.text()).toBe(PHRASE)
    expect(prompt.attributes('data-long')).toBe('true')
    expect(prompt.classes()).toEqual(expect.arrayContaining(['break-words', 'text-[20px]']))
    for (const cls of NOCLIP) expect(prompt.classes()).not.toContain(cls)
    expect(wrapper.get('[data-testid=task-translation]').text()).toBe('я бы лучше остался дома')

    await wrapper.get('[data-testid=answer-input]').setValue('rain')
    await wrapper.get('[data-testid=check-btn]').trigger('click')
    expect(wrapper.emitted('answer')).toEqual([[{ text: 'rain' }]])
  })

  it('an example of a word keeps its size', () => {
    const prompt = mount(GapTask, { props: { element: gap() } }).get('[data-testid=task-prompt]')
    expect('I ____ tea every morning.'.length).toBeLessThan(GAME_LONG_TEXT_MIN)
    expect(prompt.classes()).toContain('text-[24px]')
    expect(prompt.attributes('data-long')).toBeUndefined()
  })
})

describe('ChoiceTask with a phrase', () => {
  it('translate_fwd: the phrase is not the 40 px of a word; the options wrap', () => {
    const wrapper = mount(ChoiceTask, { props: { element: translateFwd({ prompt: 'better late than never, as they say' }) } })
    const prompt = wrapper.get('[data-testid=task-prompt]')
    expect(prompt.classes()).toContain('text-[24px]')
    expect(prompt.attributes('lang')).toBe('en')
    for (const cls of NOCLIP) expect(prompt.classes()).not.toContain(cls)
  })

  it('translate_back: a long translation as the question, English phrases as options, nothing clipped', () => {
    const element = translateBack({
      prompt: 'легко досталось — легко потерялось',
      options: [
        { id: 'o1', text: 'easy come, easy go' },
        { id: 'o2', text: 'better late than never' },
        { id: 'o3', text: 'a piece of cake' },
        { id: 'o4', text: 'break the ice' },
      ],
    })
    const wrapper = mount(ChoiceTask, { props: { element } })
    expect(wrapper.get('[data-testid=task-prompt]').attributes('lang')).toBeUndefined()
    expect(wrapper.get('[data-testid=task-prompt]').classes()).toContain('text-[24px]')
    const text = wrapper.get('[data-testid=option-0] .break-words')
    expect(text.text()).toBe('easy come, easy go')
    for (const cls of NOCLIP) expect(text.classes()).not.toContain(cls)
  })

  it('a word keeps its size', () => {
    expect(mount(ChoiceTask, { props: { element: translateFwd() } }).get('[data-testid=task-prompt]').classes()).toContain('t-word-xl')
  })
})

describe('GameReveal of a phrase', () => {
  it('the question again a size smaller', () => {
    const outcome: GameOutcome = {
      element: gap({ masked: PHRASE }),
      answer: { text: 'rain' },
      verdict: 'correct',
      points: 10,
      reveal: { correct_text: 'rain', full_text: PHRASE.replace('____', 'rain'), word: 'rain', translation: null },
      flaskLost: false,
      combo: 1,
      eventApplied: null,
      pair: null,
      roundDone: false,
      position: 3,
    } as GameOutcome
    const prompt = mount(GameReveal, { props: { outcome } }).get('[data-testid=reveal-prompt]')
    expect(prompt.attributes('data-long')).toBe('true')
    expect(prompt.classes()).not.toContain('text-[28px]')
  })
})
