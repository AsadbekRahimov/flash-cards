import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import ReverseCard from '../ReverseCard.vue'
import { CHOICE_FEEDBACK_MS } from '@/composables/useChoice'
import type { Card, ChoiceResult } from '@/api/types'
import { setLocale } from '@/i18n'

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ haptic: vi.fn(), selectionHaptic: vi.fn(), notificationHaptic: vi.fn() }),
}))
const { speak } = vi.hoisted(() => ({ speak: vi.fn().mockResolvedValue(undefined) }))
vi.mock('@/composables/useTts', () => ({ useTts: () => ({ supported: false, speak }) }))

// A reverse card as the server sends it: without the word (the right option), its example, transcription and audio.
const card: Card = {
  word_id: 1,
  word: '',
  translation: 'Устойчивый',
  translation_fallback: false,
  example: null,
  transcription: null,
  audio_url: null,
  card_kind: 'due',
  serve_token: null,
  prompt: { type: 'reverse', front: 'Устойчивый', masked_example: null, options: ['Brittle', 'resilient', 'Fragile', 'Tender'], answer_length: null },
}

/** The server's word on the pick, with what the card was sent without. */
function answered(correct: boolean): ChoiceResult {
  return {
    correct,
    right: 'resilient',
    revealed: {
      word: 'Resilient',
      translation: 'Устойчивый',
      correct: 'resilient',
      example: 'She is a resilient girl.',
      transcription: null,
      audio_url: 'https://x.test/a.mp3',
      hook: null,
      note: null,
      confused_with: null,
    },
  }
}

describe('ReverseCard', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => {
    vi.useRealTimers()
    setLocale('ru')
    speak.mockClear()
  })

  it('shows the translation and the four options under the card, not the word', () => {
    const w = mount(ReverseCard, { props: { card } })
    expect(w.get('[data-testid=front]').text()).toBe('Устойчивый')
    expect(w.text()).toContain('Выберите английское слово')
    expect(w.findAll('button[data-testid^=option-]')).toHaveLength(4)
    expect(w.text()).not.toContain('Resilient')
    expect(w.find('[data-testid=example]').exists()).toBe(false)
    expect(w.find('[data-testid=continue-btn]').exists()).toBe(false)
    // The options are outside the question card (docs/14 §6.2).
    expect(w.get('article').find('[data-testid=option-0]').exists()).toBe(false)
    expect(w.get('[data-testid=option-0]').classes()).toContain('option')
  })

  it('sends the pick at once and colours nothing but the pick until the server has answered', async () => {
    const w = mount(ReverseCard, { props: { card } })
    await w.get('[data-testid=option-1]').trigger('click')

    expect(w.emitted('pick')).toEqual([['resilient']])
    expect(w.get('[data-testid=option-1]').attributes('data-state')).toBe('selected')
    expect(w.get('[data-testid=option-0]').attributes('data-state')).toBe('idle')
    expect(w.find('[data-testid=example]').exists()).toBe(false)
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS * 10)
    expect(w.emitted('advance')).toBeUndefined()
    expect(speak).not.toHaveBeenCalled()
  })

  it('a right verdict marks the pick, speaks the revealed word, shows the example and moves on after the pause', async () => {
    const w = mount(ReverseCard, { props: { card } })
    await w.get('[data-testid=option-1]').trigger('click')
    await w.setProps({ result: answered(true) })

    expect(w.get('[data-testid=option-1]').attributes('data-state')).toBe('correct')
    expect(w.get('[data-testid=option-0]').attributes('data-state')).toBe('idle')
    expect(speak).toHaveBeenCalledWith('Resilient', 'en-US', 'https://x.test/a.mp3')
    expect(w.get('[data-testid=example]').text()).toBe('She is a resilient girl.')
    // A right pick moves on by itself: there is nothing to press.
    expect(w.find('[data-testid=continue-btn]').exists()).toBe(false)

    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS - 1)
    expect(w.emitted('advance')).toBeUndefined()
    vi.advanceTimersByTime(1)
    expect(w.emitted('advance')).toHaveLength(1)
    expect(w.emitted('pick')).toEqual([['resilient']])
  })

  it('a wrong verdict reveals the right option and the example and waits for "Next" (N6)', async () => {
    const w = mount(ReverseCard, { props: { card } })
    await w.get('[data-testid=option-2]').trigger('click')
    await w.setProps({ result: answered(false) })

    expect(w.get('[data-testid=option-2]').attributes('data-state')).toBe('incorrect')
    expect(w.get('[data-testid=option-1]').attributes('data-state')).toBe('reveal')
    expect(w.get('[data-testid=example]').text()).toBe('She is a resilient girl.')
    expect(speak).not.toHaveBeenCalled()

    // Reading takes as long as it takes: the next card does not come by itself.
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS * 30)
    expect(w.emitted('advance')).toBeUndefined()

    const next = w.get('[data-testid=continue-btn]')
    expect(next.text()).toBe('Дальше')
    expect(next.classes()).toEqual(expect.arrayContaining(['btn', 'btn-primary', 'btn-lg']))
    await next.trigger('click')
    await next.trigger('click')
    expect(w.emitted('advance')).toHaveLength(1)
    // "Next" only moves on: the pick went at the pick and is not sent a second time.
    expect(w.emitted('pick')).toEqual([['Fragile']])
  })

  it('accepts one pick only and none while disabled', async () => {
    const w = mount(ReverseCard, { props: { card } })
    await w.get('[data-testid=option-2]').trigger('click')
    await w.get('[data-testid=option-1]').trigger('click')
    expect(w.emitted('pick')).toEqual([['Fragile']])

    const d = mount(ReverseCard, { props: { card, disabled: true } })
    await d.get('[data-testid=option-1]').trigger('click')
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS)
    expect(d.emitted('pick')).toBeUndefined()
    expect(d.emitted('advance')).toBeUndefined()
    expect(d.find('[data-testid=continue-btn]').exists()).toBe(false)
  })

  it('"Next" waits for the server while the next card is being fetched', async () => {
    const w = mount(ReverseCard, { props: { card } })
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

  it('notes that the translation is a fallback only when the server says so', () => {
    expect(mount(ReverseCard, { props: { card } }).find('[data-testid=translation-fallback]').exists()).toBe(false)
    const w = mount(ReverseCard, { props: { card: { ...card, translation_fallback: true } } })
    expect(w.get('[data-testid=translation-fallback]').text()).toBe('Перевода на язык занятия пока нет')
  })

  it('renders in Uzbek', async () => {
    setLocale('uz')
    const w = mount(ReverseCard, { props: { card } })
    expect(w.text()).toContain("Inglizcha so'zni tanlang")
    await w.get('[data-testid=option-0]').trigger('click')
    await w.setProps({ result: answered(false) })
    expect(w.get('[data-testid=continue-btn]').text()).toBe('Keyingisi')
  })
})
