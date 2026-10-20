import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import AudioCard from '../AudioCard.vue'
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
  example: null,
  transcription: 'rɪˈzɪl.i.ənt',
  audio_url: null,
  card_kind: 'due',
  serve_token: null,
  prompt: { type: 'audio', front: '', masked_example: null, options: ['Хрупкий', 'Нежный', 'устойчивый', 'Ломкий'], answer_length: null },
}

describe('AudioCard', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => {
    vi.useRealTimers()
    setLocale('ru')
    speak.mockClear()
  })

  it('plays the word on mount (TTS fallback without audio_url) and again on the speaker, hiding the word', async () => {
    const w = mount(AudioCard, { props: { card } })
    expect(speak).toHaveBeenCalledWith('Resilient', 'en-US', null)
    expect(w.find('[data-testid=word]').exists()).toBe(false)
    expect(w.text()).not.toContain('Resilient')

    await w.get('[data-testid=play-btn]').trigger('click')
    expect(speak).toHaveBeenCalledTimes(2)
  })

  it('hands audio_url to the speaker', () => {
    mount(AudioCard, { props: { card: { ...card, audio_url: 'https://x.test/a.mp3' } } })
    expect(speak).toHaveBeenCalledWith('Resilient', 'en-US', 'https://x.test/a.mp3')
  })

  it('reveals the word after the pick and grades 4 for the right translation, 2 for a wrong one', async () => {
    const w = mount(AudioCard, { props: { card } })
    await w.get('[data-testid=option-2]').trigger('click')
    expect(w.get('[data-testid=word]').text()).toBe('Resilient')
    expect(w.get('[data-testid=transcription]').text()).toBe('rɪˈzɪl.i.ənt')
    expect(w.get('[data-testid=option-2]').attributes('data-state')).toBe('correct')
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS)
    expect(w.emitted('grade')).toEqual([[4]])

    const wrong = mount(AudioCard, { props: { card } })
    await wrong.get('[data-testid=option-0]').trigger('click')
    expect(wrong.get('[data-testid=option-0]').attributes('data-state')).toBe('incorrect')
    expect(wrong.get('[data-testid=option-2]').attributes('data-state')).toBe('reveal')
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS)
    expect(wrong.emitted('grade')).toEqual([[2]])
  })

  it('renders in Uzbek', () => {
    setLocale('uz')
    expect(mount(AudioCard, { props: { card } }).text()).toContain('Nimani eshitdingiz?')
  })
})
