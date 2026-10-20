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

  it('shows the translation and the four options, not the word', () => {
    const w = mount(ReverseCard, { props: { card } })
    expect(w.get('[data-testid=front]').text()).toBe('Устойчивый')
    expect(w.findAll('button[data-testid^=option-]')).toHaveLength(4)
    expect(w.text()).not.toContain('Resilient')
    expect(w.find('[data-testid=example]').exists()).toBe(false)
  })

  it('marks the right pick (case-insensitively), speaks the word and emits quality 4 after the pause', async () => {
    const w = mount(ReverseCard, { props: { card } })
    await w.get('[data-testid=option-1]').trigger('click')

    expect(w.get('[data-testid=option-1]').attributes('data-state')).toBe('correct')
    expect(w.get('[data-testid=option-0]').attributes('data-state')).toBe('idle')
    expect(speak).toHaveBeenCalledWith('Resilient', 'en-US', 'https://x.test/a.mp3')
    expect(w.get('[data-testid=example]').text()).toBe('She is a resilient girl.')
    expect(w.emitted('grade')).toBeUndefined()

    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS)
    expect(w.emitted('grade')).toEqual([[4]])
  })

  it('marks a wrong pick, reveals the right one and emits quality 2', async () => {
    const w = mount(ReverseCard, { props: { card } })
    await w.get('[data-testid=option-2]').trigger('click')

    expect(w.get('[data-testid=option-2]').attributes('data-state')).toBe('incorrect')
    expect(w.get('[data-testid=option-1]').attributes('data-state')).toBe('reveal')
    expect(speak).not.toHaveBeenCalled()

    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS)
    expect(w.emitted('grade')).toEqual([[2]])
  })

  it('accepts one pick only and none while disabled', async () => {
    const w = mount(ReverseCard, { props: { card } })
    await w.get('[data-testid=option-2]').trigger('click')
    await w.get('[data-testid=option-1]').trigger('click')
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS)
    expect(w.emitted('grade')).toEqual([[2]])

    const d = mount(ReverseCard, { props: { card, disabled: true } })
    await d.get('[data-testid=option-1]').trigger('click')
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS)
    expect(d.emitted('grade')).toBeUndefined()
  })

  it('renders in Uzbek', () => {
    setLocale('uz')
    expect(mount(ReverseCard, { props: { card } }).text()).toContain("So'zni tanlang")
  })
})
