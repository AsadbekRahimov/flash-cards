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
  example: 'She is a resilient girl.',
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

  it('asks "What did you hear?" and says how to listen again, on a big round play button', () => {
    const w = mount(AudioCard, { props: { card } })

    expect(w.text()).toContain('Что вы услышали?')
    expect(w.get('[data-testid=replay-hint]').text()).toBe('Нажмите, чтобы послушать ещё раз')
    const play = w.get('[data-testid=play-btn]')
    expect(play.attributes('aria-label')).toBe('Прослушать ещё раз')
    expect(play.classes()).toEqual(expect.arrayContaining(['rounded-full', 'bg-accent-soft', 'text-accent']))
    // The options are outside the question card.
    expect(w.get('article').find('[data-testid=option-0]').exists()).toBe(false)
    expect(w.get('[data-testid=option-0]').classes()).toContain('option')
    expect(w.find('[data-testid=continue-btn]').exists()).toBe(false)
  })

  it('hands audio_url to the speaker', () => {
    mount(AudioCard, { props: { card: { ...card, audio_url: 'https://x.test/a.mp3' } } })
    expect(speak).toHaveBeenCalledWith('Resilient', 'en-US', 'https://x.test/a.mp3')
  })

  it('a right pick reveals the word, grades 4 after the pause and has no button to press', async () => {
    const w = mount(AudioCard, { props: { card } })
    await w.get('[data-testid=option-2]').trigger('click')

    expect(w.get('[data-testid=word]').text()).toBe('Resilient')
    expect(w.get('[data-testid=transcription]').text()).toBe('rɪˈzɪl.i.ənt')
    expect(w.find('[data-testid=replay-hint]').exists()).toBe(false)
    expect(w.get('[data-testid=option-2]').attributes('data-state')).toBe('correct')
    expect(w.find('[data-testid=continue-btn]').exists()).toBe(false)
    expect(w.emitted('grade')).toBeUndefined()

    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS)
    expect(w.emitted('grade')).toEqual([[4]])
  })

  it('a wrong pick reveals the right translation, the word and the example, and waits for "Next" (N6)', async () => {
    const w = mount(AudioCard, { props: { card } })
    await w.get('[data-testid=option-0]').trigger('click')

    expect(w.get('[data-testid=option-0]').attributes('data-state')).toBe('incorrect')
    expect(w.get('[data-testid=option-2]').attributes('data-state')).toBe('reveal')
    expect(w.get('[data-testid=word]').text()).toBe('Resilient')
    expect(w.get('[data-testid=example]').text()).toBe('She is a resilient girl.')

    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS * 30)
    expect(w.emitted('grade')).toBeUndefined()

    const next = w.get('[data-testid=continue-btn]')
    expect(next.text()).toBe('Дальше')
    await next.trigger('click')
    await next.trigger('click')
    expect(w.emitted('grade')).toEqual([[2]])
  })

  it('does not take a pick while disabled', async () => {
    const w = mount(AudioCard, { props: { card, disabled: true } })
    await w.get('[data-testid=option-0]').trigger('click')

    expect(w.get('[data-testid=option-0]').attributes('data-state')).toBe('idle')
    expect(w.find('[data-testid=continue-btn]').exists()).toBe(false)
  })

  it('renders in Uzbek', async () => {
    setLocale('uz')
    const w = mount(AudioCard, { props: { card } })
    expect(w.text()).toContain('Nimani eshitdingiz?')
    expect(w.get('[data-testid=replay-hint]').text()).toBe('Yana tinglash uchun bosing')
    await w.get('[data-testid=option-0]').trigger('click')
    expect(w.get('[data-testid=continue-btn]').text()).toBe('Keyingisi')
  })
})
