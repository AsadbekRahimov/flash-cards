import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import MeaningCard from '../MeaningCard.vue'
import { AFTER_FEEDBACK_MS, resetAutoSpeak, setAutoSpeakEnabled } from '@/composables/useAutoSpeak'
import { CHOICE_FEEDBACK_MS } from '@/composables/useChoice'
import type { Card, ChoiceResult } from '@/api/types'
import { setLocale } from '@/i18n'

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ haptic: vi.fn(), selectionHaptic: vi.fn(), notificationHaptic: vi.fn() }),
}))
const { speak } = vi.hoisted(() => ({ speak: vi.fn().mockResolvedValue(undefined) }))
vi.mock('@/composables/useTts', () => ({ useTts: () => ({ supported: false, speak }) }))

// A meaning card as the server sends it (docs/06 §12.2): the word with its transcription and audio, without the translation
// (the right option), the example, the hooks.
const card: Card = {
  word_id: 1,
  word: 'Resilient',
  translation: '',
  translation_fallback: false,
  example: null,
  transcription: '/rɪˈzɪliənt/',
  audio_url: 'https://x.test/a.mp3',
  card_kind: 'due',
  serve_token: null,
  hook: null,
  note: null,
  confused_with: null,
  prompt: {
    type: 'meaning',
    front: 'Resilient',
    masked_example: null,
    options: ['Хрупкий', 'устойчивый', 'Нежный', 'Способный быстро восстанавливаться после трудностей и неудач'],
    answer_length: null,
  },
}

/** The server's word on the pick, with what the card was sent without. */
function answered(correct: boolean): ChoiceResult {
  return {
    correct,
    right: 'устойчивый',
    revealed: {
      word: 'Resilient',
      translation: 'устойчивый',
      correct: 'устойчивый',
      example: 'She is a resilient girl.',
      transcription: '/rɪˈzɪliənt/',
      audio_url: 'https://x.test/a.mp3',
      hook: 're + silient — снова встаёт',
      note: null,
      confused_with: null,
    },
  }
}

describe('MeaningCard', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => {
    vi.useRealTimers()
    setLocale('ru')
    resetAutoSpeak()
    speak.mockClear()
  })

  it('shows the word with its transcription and speaker, the question and four translations — no translation of its own', () => {
    const w = mount(MeaningCard, { props: { card } })
    expect(w.get('[data-testid=word]').text()).toBe('Resilient')
    expect(w.get('[data-testid=transcription]').text()).toBe('/rɪˈzɪliənt/')
    expect(w.text()).toContain('Выберите перевод слова')
    expect(w.findAll('button[data-testid^=option-]')).toHaveLength(4)
    expect(w.find('[data-testid=example]').exists()).toBe(false)
    expect(w.find('[data-testid=card-hooks]').exists()).toBe(false)
    expect(w.find('[data-testid=continue-btn]').exists()).toBe(false)
    // No box around the question (docs/design Question); a long translation wraps inside its option, nothing is clipped.
    expect(w.find('.card').exists()).toBe(false)
    expect(w.get('[data-testid=option-0]').classes()).toContain('option')
    expect(w.get('[data-testid=option-3] span.flex-1').classes()).toEqual(expect.arrayContaining(['min-w-0', 'break-words']))
    expect(w.get('[data-testid=word]').classes()).toContain('break-words')
  })

  it('speaks the word on the speaker button, from the pre-generated audio', async () => {
    const w = mount(MeaningCard, { props: { card } })
    await w.get('[data-testid=speak-btn]').trigger('click')
    expect(speak).toHaveBeenCalledWith('Resilient', 'en-US', 'https://x.test/a.mp3')
    expect(w.get('[data-testid=speak-btn]').attributes('aria-label')).toBe('Произнести')
  })

  it('reads the word aloud after the verdict (not before), unless auto-speak is off; shows the translation of the example', async () => {
    const w = mount(MeaningCard, { props: { card } })
    await w.get('[data-testid=option-1]').trigger('click')
    vi.advanceTimersByTime(AFTER_FEEDBACK_MS * 3)
    expect(speak).not.toHaveBeenCalled()

    await w.setProps({ result: { ...answered(true), revealed: { ...answered(true).revealed!, example_translation: 'Она стойкая девочка.' } } })
    expect(speak).not.toHaveBeenCalled()
    vi.advanceTimersByTime(AFTER_FEEDBACK_MS)
    expect(speak).toHaveBeenCalledWith('Resilient', 'en-US', 'https://x.test/a.mp3')
    expect(w.get('[data-testid=example-translation]').text()).toBe('Она стойкая девочка.')

    speak.mockClear()
    setAutoSpeakEnabled(false)
    const off = mount(MeaningCard, { props: { card } })
    await off.get('[data-testid=option-1]').trigger('click')
    await off.setProps({ result: answered(false) })
    vi.advanceTimersByTime(AFTER_FEEDBACK_MS * 3)
    expect(speak).not.toHaveBeenCalled()
    expect(off.find('[data-testid=example-translation]').exists()).toBe(false)
  })

  it('sends the pick at once and colours nothing but the pick until the server has answered', async () => {
    const w = mount(MeaningCard, { props: { card } })
    await w.get('[data-testid=option-1]').trigger('click')

    expect(w.emitted('pick')).toEqual([['устойчивый']])
    expect(w.get('[data-testid=option-1]').attributes('data-state')).toBe('selected')
    expect(w.get('[data-testid=option-0]').attributes('data-state')).toBe('idle')
    expect(w.find('[data-testid=example]').exists()).toBe(false)
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS * 10)
    expect(w.emitted('advance')).toBeUndefined()
  })

  it('a right verdict marks the pick, shows the example and the hooks, and moves on after the pause', async () => {
    const w = mount(MeaningCard, { props: { card } })
    await w.get('[data-testid=option-1]').trigger('click')
    await w.setProps({ result: answered(true) })

    expect(w.get('[data-testid=option-1]').attributes('data-state')).toBe('correct')
    expect(w.get('[data-testid=option-0]').attributes('data-state')).toBe('idle')
    expect(w.get('[data-testid=example]').text()).toBe('She is a resilient girl.')
    expect(w.get('[data-testid=hook]').text()).toContain('re + silient')
    expect(w.find('[data-testid=continue-btn]').exists()).toBe(false)

    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS - 1)
    expect(w.emitted('advance')).toBeUndefined()
    vi.advanceTimersByTime(1)
    expect(w.emitted('advance')).toHaveLength(1)
    expect(w.emitted('pick')).toEqual([['устойчивый']])
  })

  it('a wrong verdict reveals the right option (by the server, not by the card) and waits for "Next" (N6)', async () => {
    const w = mount(MeaningCard, { props: { card } })
    await w.get('[data-testid=option-2]').trigger('click')
    await w.setProps({ result: answered(false) })

    expect(w.get('[data-testid=option-2]').attributes('data-state')).toBe('incorrect')
    expect(w.get('[data-testid=option-1]').attributes('data-state')).toBe('reveal')
    expect(w.get('[data-testid=example]').text()).toBe('She is a resilient girl.')

    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS * 30)
    expect(w.emitted('advance')).toBeUndefined()

    const next = w.get('[data-testid=continue-btn]')
    expect(next.text()).toBe('Дальше')
    expect(next.classes()).toEqual(expect.arrayContaining(['btn', 'btn-primary', 'btn-lg']))
    await next.trigger('click')
    await next.trigger('click')
    expect(w.emitted('advance')).toHaveLength(1)
    expect(w.emitted('pick')).toEqual([['Нежный']])
  })

  it('puts the answer in the grey panel at the bottom (docs/design Feedback): the word and its meaning, the verdict in words', async () => {
    const before = mount(MeaningCard, { props: { card } })
    expect(before.find('[data-testid=feedback]').exists()).toBe(false)
    expect(before.find('[data-testid=actions]').exists()).toBe(false)

    const wrong = mount(MeaningCard, { props: { card } })
    await wrong.get('[data-testid=option-2]').trigger('click')
    // While the server is thinking there is no panel yet: nothing is known.
    expect(wrong.find('[data-testid=feedback]').exists()).toBe(false)
    await wrong.setProps({ result: answered(false) })

    const panel = wrong.get('[data-testid=actions]')
    expect(panel.classes()).toEqual(expect.arrayContaining(['bg-page', 'border-t', 'border-hairline']))
    expect(panel.get('[data-testid=feedback-title]').text()).toBe('Resilient — устойчивый')
    const status = panel.get('[data-testid=feedback-status]')
    expect(status.text()).toBe('неверно')
    expect(status.classes()).toContain('text-danger-ink')
    expect(panel.get('[data-testid=example]').classes()).toContain('italic')
    expect(panel.find('[data-testid=continue-btn]').exists()).toBe(true)
    // The options name the verdict in words too: the pick and the right one.
    expect(wrong.get('[data-testid=option-caption-2]').text()).toBe('ваш ответ')
    expect(wrong.get('[data-testid=option-caption-1]').text()).toBe('верный ответ')

    const right = mount(MeaningCard, { props: { card } })
    await right.get('[data-testid=option-1]').trigger('click')
    await right.setProps({ result: answered(true) })
    expect(right.get('[data-testid=feedback-status]').text()).toBe('верно')
    expect(right.get('[data-testid=feedback-status]').classes()).toContain('text-success-ink')
    expect(right.find('[data-testid=continue-btn]').exists()).toBe(false)
  })

  it('is laid out as the mock-up of the question (docs/design/2026-11-25-redesign Question): task, big word, round speaker, a radio group', () => {
    const w = mount(MeaningCard, { props: { card } })
    const title = w.get('[data-testid=exercise-title]')
    expect(title.text()).toBe('Выберите перевод слова')
    expect(title.classes()).toEqual(expect.arrayContaining(['text-base', 'text-muted']))
    expect(w.get('[data-testid=word]').classes()).toEqual(expect.arrayContaining(['text-[52px]', 'font-extrabold']))
    expect(w.get('[data-testid=speak-btn]').classes()).toEqual(expect.arrayContaining(['size-11', 'rounded-full', 'bg-accent-soft', 'text-accent']))
    const group = w.get('[role=radiogroup]')
    expect(group.attributes('aria-label')).toBe('Выберите перевод слова')
    expect(group.findAll('[role=radio]')).toHaveLength(4)
  })

  it('steps the word down a size once the answer is in, so the options stay above the panel (Feedback)', async () => {
    const w = mount(MeaningCard, { props: { card } })
    await w.get('[data-testid=option-2]').trigger('click')
    expect(w.get('[data-testid=word]').classes()).toContain('text-[52px]')
    await w.setProps({ result: answered(false) })
    expect(w.get('[data-testid=word]').classes()).toContain('text-[40px]')
    expect(w.get('[data-testid=word]').classes()).not.toContain('text-[52px]')
  })

  it('draws the answer panel as a grey sheet with a rounded top and the speaker of the example as the round accent button', async () => {
    const w = mount(MeaningCard, { props: { card } })
    await w.get('[data-testid=option-2]').trigger('click')
    await w.setProps({ result: answered(false) })
    const panel = w.get('[data-testid=actions]')
    expect(panel.classes()).toEqual(expect.arrayContaining(['bg-page', 'rounded-t-hero']))
    expect(panel.get('[data-testid=feedback-title]').classes()).toEqual(expect.arrayContaining(['text-xl', 'font-extrabold']))
  })

  it('after a miss says what the screen gave it about the word coming back, above "Next"; nothing after a right pick', async () => {
    const note = 'Слово вернётся ещё раз в этом уроке.'
    const wrong = mount(MeaningCard, { props: { card, missNote: note } })
    await wrong.get('[data-testid=option-2]').trigger('click')
    expect(wrong.find('[data-testid=miss-note]').exists()).toBe(false)
    await wrong.setProps({ result: answered(false) })
    const line = wrong.get('[data-testid=miss-note]')
    expect(line.text()).toBe(note)
    expect(line.classes()).toEqual(expect.arrayContaining(['text-[13px]', 'text-muted']))
    expect(line.element.nextElementSibling?.getAttribute('data-testid')).toBe('continue-btn')

    const right = mount(MeaningCard, { props: { card, missNote: note } })
    await right.get('[data-testid=option-1]').trigger('click')
    await right.setProps({ result: answered(true) })
    expect(right.find('[data-testid=miss-note]').exists()).toBe(false)

    const none = mount(MeaningCard, { props: { card, missNote: null } })
    await none.get('[data-testid=option-2]').trigger('click')
    await none.setProps({ result: answered(false) })
    expect(none.find('[data-testid=miss-note]').exists()).toBe(false)
  })

  it('accepts one pick only and none while disabled', async () => {
    const w = mount(MeaningCard, { props: { card } })
    await w.get('[data-testid=option-2]').trigger('click')
    await w.get('[data-testid=option-1]').trigger('click')
    expect(w.emitted('pick')).toEqual([['Нежный']])

    const d = mount(MeaningCard, { props: { card, disabled: true } })
    await d.get('[data-testid=option-1]').trigger('click')
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS)
    expect(d.emitted('pick')).toBeUndefined()
    expect(d.emitted('advance')).toBeUndefined()
  })

  it('"Next" waits for the server while the next card is being fetched', async () => {
    const w = mount(MeaningCard, { props: { card } })
    await w.get('[data-testid=option-2]').trigger('click')
    await w.setProps({ result: answered(false) })

    await w.get('[data-testid=continue-btn]').trigger('click')
    expect(w.emitted('advance')).toHaveLength(1)
    await w.setProps({ disabled: true })
    expect(w.get('[data-testid=continue-btn]').attributes('disabled')).toBeDefined()
    expect(w.get('[data-testid=continue-btn]').attributes('aria-busy')).toBe('true')
    expect(w.find('[data-testid=continue-waiting]').exists()).toBe(true)

    await w.get('[data-testid=continue-btn]').trigger('click')
    expect(w.emitted('advance')).toHaveLength(1)
  })

  it('shows the "hard word" chip and leaves the transcription out when the word has none', () => {
    const w = mount(MeaningCard, { props: { card: { ...card, is_leech: true, transcription: null } } })
    expect(w.get('[data-testid=leech-chip]').text()).toBe('Трудное слово')
    expect(w.find('[data-testid=transcription]').exists()).toBe(false)
  })

  it('renders in Uzbek', async () => {
    setLocale('uz')
    const w = mount(MeaningCard, { props: { card } })
    expect(w.text()).toContain("So'zning tarjimasini tanlang")
    await w.get('[data-testid=option-0]').trigger('click')
    await w.setProps({ result: answered(false) })
    expect(w.get('[data-testid=continue-btn]').text()).toBe('Keyingisi')
  })
})
