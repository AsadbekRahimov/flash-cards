import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import MeaningCard from '../MeaningCard.vue'
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
    speak.mockClear()
  })

  it('shows the word with its transcription and speaker, the question and four translations — no translation of its own', () => {
    const w = mount(MeaningCard, { props: { card } })
    expect(w.get('[data-testid=word]').text()).toBe('Resilient')
    expect(w.get('[data-testid=transcription]').text()).toBe('/rɪˈzɪliənt/')
    expect(w.text()).toContain('Что значит это слово?')
    expect(w.findAll('button[data-testid^=option-]')).toHaveLength(4)
    expect(w.find('[data-testid=example]').exists()).toBe(false)
    expect(w.find('[data-testid=card-hooks]').exists()).toBe(false)
    expect(w.find('[data-testid=continue-btn]').exists()).toBe(false)
    // The options are outside the question card (docs/14 §6.2); a long translation wraps inside its option, nothing is clipped.
    expect(w.get('article').find('[data-testid=option-0]').exists()).toBe(false)
    expect(w.get('[data-testid=option-0]').classes()).toContain('option')
    expect(w.get('[data-testid=option-3] span').classes()).toEqual(expect.arrayContaining(['min-w-0', 'break-words']))
    expect(w.get('[data-testid=word]').classes()).toContain('break-words')
  })

  it('speaks the word on the speaker button, from the pre-generated audio', async () => {
    const w = mount(MeaningCard, { props: { card } })
    await w.get('[data-testid=speak-btn]').trigger('click')
    expect(speak).toHaveBeenCalledWith('Resilient', 'en-US', 'https://x.test/a.mp3')
    expect(w.get('[data-testid=speak-btn]').attributes('aria-label')).toBe('Произнести')
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
    expect(w.text()).toContain("Bu so'z nimani anglatadi?")
    await w.get('[data-testid=option-0]').trigger('click')
    await w.setProps({ result: answered(false) })
    expect(w.get('[data-testid=continue-btn]').text()).toBe('Keyingisi')
  })
})
