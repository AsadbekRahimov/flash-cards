import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import ReverseCard from '../ReverseCard.vue'
import { CHOICE_FEEDBACK_MS } from '@/composables/useChoice'
import type { Card } from '@/api/types'
import { setLocale } from '@/i18n'

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ haptic: vi.fn(), selectionHaptic: vi.fn(), notificationHaptic: vi.fn() }),
}))
const { speak } = vi.hoisted(() => ({ speak: vi.fn().mockResolvedValue(undefined) }))
vi.mock('@/composables/useTts', () => ({ useTts: () => ({ supported: false, speak }) }))

const card: Card = {
  word_id: 1,
  word: 'Resilient',
  translation: 'Устойчивый',
  translation_fallback: false,
  example: 'She is a resilient girl.',
  transcription: null,
  audio_url: 'https://x.test/a.mp3',
  card_kind: 'due',
  serve_token: null,
  prompt: { type: 'reverse', front: 'Устойчивый', masked_example: null, options: ['Brittle', 'resilient', 'Fragile', 'Tender'], answer_length: null },
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

  it('marks the right pick (case-insensitively), speaks the word, grades 4 at once and moves on after the pause', async () => {
    const w = mount(ReverseCard, { props: { card } })
    await w.get('[data-testid=option-1]').trigger('click')

    expect(w.get('[data-testid=option-1]').attributes('data-state')).toBe('correct')
    expect(w.get('[data-testid=option-0]').attributes('data-state')).toBe('idle')
    expect(speak).toHaveBeenCalledWith('Resilient', 'en-US', 'https://x.test/a.mp3')
    expect(w.get('[data-testid=example]').text()).toBe('She is a resilient girl.')
    // The grade is final at the pick: it goes now, not after the pause.
    expect(w.emitted('grade')).toEqual([[4]])
    expect(w.emitted('advance')).toBeUndefined()
    // A right pick moves on by itself: there is nothing to press.
    expect(w.find('[data-testid=continue-btn]').exists()).toBe(false)

    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS - 1)
    expect(w.emitted('advance')).toBeUndefined()
    vi.advanceTimersByTime(1)
    expect(w.emitted('advance')).toHaveLength(1)
    expect(w.emitted('grade')).toEqual([[4]])
  })

  it('a wrong pick grades 2 at once, reveals the right option and the example and waits for "Next" (N6)', async () => {
    const w = mount(ReverseCard, { props: { card } })
    await w.get('[data-testid=option-2]').trigger('click')

    expect(w.get('[data-testid=option-2]').attributes('data-state')).toBe('incorrect')
    expect(w.get('[data-testid=option-1]').attributes('data-state')).toBe('reveal')
    expect(w.get('[data-testid=example]').text()).toBe('She is a resilient girl.')
    expect(speak).not.toHaveBeenCalled()
    expect(w.emitted('grade')).toEqual([[2]])

    // Reading takes as long as it takes: the next card does not come by itself.
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS * 30)
    expect(w.emitted('advance')).toBeUndefined()

    const next = w.get('[data-testid=continue-btn]')
    expect(next.text()).toBe('Дальше')
    expect(next.classes()).toEqual(expect.arrayContaining(['btn', 'btn-primary', 'btn-lg']))
    await next.trigger('click')
    expect(w.emitted('advance')).toHaveLength(1)
    // "Next" only moves on: the grade has gone at the pick and is not sent a second time.
    expect(w.emitted('grade')).toEqual([[2]])
  })

  it('"Next" moves on once, however often it is pressed', async () => {
    const w = mount(ReverseCard, { props: { card } })
    await w.get('[data-testid=option-0]').trigger('click')

    const next = w.get('[data-testid=continue-btn]')
    await next.trigger('click')
    await next.trigger('click')

    expect(w.emitted('advance')).toHaveLength(1)
    expect(w.emitted('grade')).toEqual([[2]])
  })

  it('accepts one pick only and none while disabled', async () => {
    const w = mount(ReverseCard, { props: { card } })
    await w.get('[data-testid=option-2]').trigger('click')
    await w.get('[data-testid=option-1]').trigger('click')
    expect(w.get('[data-testid=option-2]').attributes('data-state')).toBe('incorrect')
    expect(w.get('[data-testid=option-1]').attributes('data-state')).toBe('reveal')
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS)
    await w.get('[data-testid=continue-btn]').trigger('click')
    expect(w.emitted('grade')).toEqual([[2]])
    expect(w.emitted('advance')).toHaveLength(1)

    const d = mount(ReverseCard, { props: { card, disabled: true } })
    await d.get('[data-testid=option-1]').trigger('click')
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS)
    expect(d.emitted('grade')).toBeUndefined()
    expect(d.emitted('advance')).toBeUndefined()
    expect(d.find('[data-testid=continue-btn]').exists()).toBe(false)
  })

  it('lets "Next" be pressed while the grade is on its way, then waits for the server', async () => {
    const w = mount(ReverseCard, { props: { card } })
    await w.get('[data-testid=option-2]').trigger('click')
    // The grade is in flight (the screen is busy), but "Next" has not been asked for yet.
    await w.setProps({ disabled: true })
    const next = w.get('[data-testid=continue-btn]')
    expect(next.attributes('disabled')).toBeUndefined()
    expect(w.find('[data-testid=continue-waiting]').exists()).toBe(false)

    await next.trigger('click')
    expect(w.emitted('advance')).toHaveLength(1)
    expect(w.get('[data-testid=continue-btn]').attributes('disabled')).toBeDefined()
    expect(w.get('[data-testid=continue-btn]').attributes('aria-busy')).toBe('true')
    expect(w.find('[data-testid=continue-waiting]').exists()).toBe(true)

    // A second press while waiting changes nothing.
    await w.get('[data-testid=continue-btn]').trigger('click')
    expect(w.emitted('advance')).toHaveLength(1)
    expect(w.emitted('grade')).toEqual([[2]])
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
    expect(w.get('[data-testid=continue-btn]').text()).toBe('Keyingisi')
  })
})
