import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import FlashCard from '../FlashCard.vue'
import type { Card } from '@/api/types'
import { setLocale } from '@/i18n'

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
  afterEach(() => setLocale('ru'))

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

  it('emits grade event with quality 2 / 4 / 5 (Again / Good / Easy) per button', async () => {
    const w = mount(FlashCard, { props: { card, showTranslation: true } })
    await w.get('[data-testid=grade-hard]').trigger('click')
    await w.get('[data-testid=grade-medium]').trigger('click')
    await w.get('[data-testid=grade-easy]').trigger('click')
    const events = w.emitted('grade') as unknown[][] | undefined
    expect(events).toHaveLength(3)
    expect(events?.[0]).toEqual([2])
    expect(events?.[1]).toEqual([4])
    expect(events?.[2]).toEqual([5])
  })

  it('labels learning-step cards distinctly from new and due cards', () => {
    expect(mount(FlashCard, { props: { card, showTranslation: false } }).text()).toContain('Новое слово')
    expect(
      mount(FlashCard, { props: { card: { ...card, card_kind: 'learning' }, showTranslation: false } }).text(),
    ).toContain('Закрепление')
    expect(
      mount(FlashCard, { props: { card: { ...card, card_kind: 'due' }, showTranslation: false } }).text(),
    ).toContain('Повторение')
  })

  it('does not emit grade when disabled', async () => {
    const w = mount(FlashCard, { props: { card, showTranslation: true, disabled: true } })
    await w.get('[data-testid=grade-easy]').trigger('click')
    expect(w.emitted('grade')).toBeUndefined()
  })

  it('renders the Uzbek interface (kind labels, reveal and grade buttons) when locale is uz', async () => {
    setLocale('uz')
    const kinds: [Card['card_kind'], string][] = [
      ['new', "Yangi so'z"],
      ['learning', 'Mustahkamlash'],
      ['due', 'Takrorlash'],
    ]
    for (const [card_kind, label] of kinds) {
      expect(mount(FlashCard, { props: { card: { ...card, card_kind }, showTranslation: false } }).text()).toContain(label)
    }

    const hidden = mount(FlashCard, { props: { card, showTranslation: false } })
    expect(hidden.get('[data-testid=reveal-btn]').text()).toBe("Tarjimani ko'rsatish")

    const revealed = mount(FlashCard, { props: { card, showTranslation: true } })
    expect(revealed.get('[data-testid=grade-hard]').text()).toContain('Qiyin')
    expect(revealed.get('[data-testid=grade-medium]').text()).toContain("O'rtacha")
    expect(revealed.get('[data-testid=grade-easy]').text()).toContain('Oson')
  })
})
