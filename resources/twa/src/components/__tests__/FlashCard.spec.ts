import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import FlashCard from '../FlashCard.vue'
import type { Card } from '@/api/types'

// Stub Telegram SDK and TTS to avoid DOM/Telegram dependencies in unit tests.
vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    haptic: vi.fn(),
    selectionHaptic: vi.fn(),
  }),
}))
vi.mock('@/composables/useTts', () => ({
  useTts: () => ({ supported: false, speak: vi.fn().mockResolvedValue(undefined) }),
}))

const card: Card = {
  word_id: 1,
  word: 'Resilient',
  translation: 'Устойчивый',
  example: 'She is a resilient girl.',
  transcription: 'rɪˈzɪl.i.ənt',
  card_kind: 'new',
}

describe('FlashCard', () => {
  it('shows the word, transcription and hides translation by default', () => {
    const w = mount(FlashCard, { props: { card, showTranslation: false } })
    expect(w.get('[data-testid=word]').text()).toBe('Resilient')
    expect(w.get('[data-testid=transcription]').text()).toBe('rɪˈzɪl.i.ənt')
    expect(w.find('[data-testid=translation]').exists()).toBe(false)
    expect(w.find('[data-testid=reveal-btn]').exists()).toBe(true)
  })

  it('emits reveal when "Show translation" is clicked', async () => {
    const w = mount(FlashCard, { props: { card, showTranslation: false } })
    await w.get('[data-testid=reveal-btn]').trigger('click')
    expect(w.emitted('reveal')).toHaveLength(1)
  })

  it('renders translation and 3 grade buttons when revealed', () => {
    const w = mount(FlashCard, { props: { card, showTranslation: true } })
    expect(w.get('[data-testid=translation]').text()).toBe('Устойчивый')
    expect(w.get('[data-testid=example]').text()).toContain('resilient')
    expect(w.find('[data-testid=grade-hard]').exists()).toBe(true)
    expect(w.find('[data-testid=grade-medium]').exists()).toBe(true)
    expect(w.find('[data-testid=grade-easy]').exists()).toBe(true)
  })

  it('emits grade event with correct quality (2/3/5) per button', async () => {
    const w = mount(FlashCard, { props: { card, showTranslation: true } })
    await w.get('[data-testid=grade-hard]').trigger('click')
    await w.get('[data-testid=grade-medium]').trigger('click')
    await w.get('[data-testid=grade-easy]').trigger('click')
    const events = w.emitted('grade') as unknown[][] | undefined
    expect(events).toHaveLength(3)
    expect(events?.[0]).toEqual([2])
    expect(events?.[1]).toEqual([3])
    expect(events?.[2]).toEqual([5])
  })

  it('does not emit grade when disabled', async () => {
    const w = mount(FlashCard, { props: { card, showTranslation: true, disabled: true } })
    await w.get('[data-testid=grade-easy]').trigger('click')
    expect(w.emitted('grade')).toBeUndefined()
  })
})
