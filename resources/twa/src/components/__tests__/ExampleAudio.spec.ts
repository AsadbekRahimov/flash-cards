import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import AudioCard from '../AudioCard.vue'
import ExampleAudioButton from '../ExampleAudioButton.vue'
import FlashCard from '../FlashCard.vue'
import MeaningCard from '../MeaningCard.vue'
import ReverseCard from '../ReverseCard.vue'
import type { Card, ChoiceResult, Revealed } from '@/api/types'
import { resetAutoSpeak } from '@/composables/useAutoSpeak'
import { useTts } from '@/composables/useTts'
import { setLocale } from '@/i18n'

/*
 * The speaker of the example (docs/26 §9.11): only with `example_audio_url`, the file played by `new Audio(url)` on a tap (no
 * fetch, no Web Speech, no autoplay), one player with the word (each stops the other), the "playing" look, a failed file quietly
 * back to the idle button. The real `useTts`; the `<audio>` and the speech synthesis are fakes.
 */

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ haptic: vi.fn(), selectionHaptic: vi.fn(), notificationHaptic: vi.fn() }),
}))

const EXAMPLE_URL = 'https://audio.example.uz/audio/example.mp3'
const WORD_URL = 'https://audio.example.uz/audio/word.mp3'

/** Every `<audio>` made, in order. */
let players: FakeAudio[] = []
let playImpl: (player: FakeAudio) => Promise<void> = () => Promise.resolve()
const utterances: string[] = []
const fetchSpy = vi.fn()

class FakeAudio {
  currentTime = 0
  paused = true
  ended = false
  private readonly listeners = new Map<string, Array<() => void>>()
  constructor(public src: string) {
    players.push(this)
  }
  addEventListener(type: string, listener: () => void): void {
    this.listeners.set(type, [...(this.listeners.get(type) ?? []), listener])
  }
  emit(type: string): void {
    for (const listener of this.listeners.get(type) ?? []) listener()
  }
  play(): Promise<void> {
    this.paused = false
    return playImpl(this)
  }
  pause(): void {
    this.paused = true
    this.emit('pause')
  }
}

const card: Card = {
  word_id: 1,
  word: 'Resilient',
  translation: 'Устойчивый',
  translation_fallback: false,
  example: 'She is a resilient girl.',
  example_audio_url: EXAMPLE_URL,
  transcription: 'rɪˈzɪl.i.ənt',
  audio_url: WORD_URL,
  card_kind: 'due',
  serve_token: null,
}

const revealed: Revealed = {
  word: 'Resilient',
  translation: 'устойчивый',
  correct: 'устойчивый',
  example: 'She is a resilient girl.',
  example_audio_url: EXAMPLE_URL,
  transcription: 'rɪˈzɪl.i.ənt',
  audio_url: WORD_URL,
  hook: null,
  note: null,
  confused_with: null,
}

function choiceCard(type: 'meaning' | 'reverse' | 'audio'): Card {
  return {
    ...card,
    // The server hides the example (and its sound) on these cards until the answer (docs/06 §12.2).
    example: null,
    example_audio_url: null,
    translation: '',
    prompt: { type, front: 'Resilient', masked_example: null, options: ['устойчивый', 'хрупкий', 'нежный', 'быстрый'], answer_length: null },
  }
}

function result(example_audio_url: string | null): ChoiceResult {
  return { correct: true, right: 'устойчивый', revealed: { ...revealed, example_audio_url } }
}

describe('the speaker of the example', () => {
  beforeEach(() => {
    players = []
    utterances.length = 0
    playImpl = () => Promise.resolve()
    fetchSpy.mockReset()
    vi.stubGlobal('Audio', FakeAudio)
    vi.stubGlobal('fetch', fetchSpy)
    vi.stubGlobal('SpeechSynthesisUtterance', class { constructor(public text: string) {} })
    vi.stubGlobal('speechSynthesis', {
      getVoices: () => [],
      addEventListener: () => {},
      cancel: () => {},
      speak: (u: { text: string }) => utterances.push(u.text),
      speaking: false,
    })
  })

  afterEach(() => {
    useTts().stop()
    vi.unstubAllGlobals()
    vi.useRealTimers()
    setLocale('ru')
    resetAutoSpeak()
  })

  describe('on the flash card', () => {
    it('is there next to the example only when the example has a file', () => {
      const w = mount(FlashCard, { props: { card, showTranslation: true } })
      const btn = w.get('[data-testid=example-speak-btn]')
      expect(btn.attributes('aria-label')).toBe('Прослушать пример')
      // Next to the sentence, on its row; the sentence wraps beside it.
      expect(w.get('[data-testid=example]').element.parentElement).toBe(btn.element.parentElement)
      expect(w.get('[data-testid=example]').classes()).toEqual(expect.arrayContaining(['min-w-0', 'break-words']))
      // The look of the word's speaker: a 44 px round button with the accent icon.
      expect(btn.classes()).toEqual(expect.arrayContaining(['size-11', 'rounded-full', 'text-accent']))

      for (const without of [{ ...card, example_audio_url: null }, { ...card, example_audio_url: '' }, { ...card, example_audio_url: undefined }]) {
        const plain = mount(FlashCard, { props: { card: without, showTranslation: true } })
        expect(plain.find('[data-testid=example]').exists()).toBe(true)
        expect(plain.find('[data-testid=example-speak-btn]').exists()).toBe(false)
      }
    })

    it('waits for the reveal on a review card, with the example', () => {
      expect(mount(FlashCard, { props: { card, showTranslation: false } }).find('[data-testid=example-speak-btn]').exists()).toBe(false)
    })

    it('never plays by itself: a new word sounds the word, not the example', async () => {
      vi.useFakeTimers()
      mount(FlashCard, { props: { card: { ...card, card_kind: 'new' }, showTranslation: false } })
      await vi.runAllTimersAsync()
      expect(players.map((p) => p.src)).toEqual([WORD_URL])
    })

    it('plays the address on a tap through <audio>, lit while it sounds, back when it ends', async () => {
      const w = mount(FlashCard, { props: { card, showTranslation: true } })
      const btn = w.get('[data-testid=example-speak-btn]')
      expect(btn.attributes('data-playing')).toBeUndefined()
      expect(btn.attributes('aria-pressed')).toBe('false')

      await btn.trigger('click')
      expect(players.map((p) => p.src)).toEqual([EXAMPLE_URL])
      expect(btn.attributes('data-playing')).toBe('true')
      expect(btn.attributes('aria-pressed')).toBe('true')
      expect(btn.classes()).toEqual(expect.arrayContaining(['bg-accent-soft', 'text-accent-ink']))
      expect(fetchSpy).not.toHaveBeenCalled()
      expect(utterances).toEqual([])

      players[0]!.emit('ended')
      await flushPromises()
      expect(btn.attributes('data-playing')).toBeUndefined()
    })

    it('stops on a second tap', async () => {
      const w = mount(FlashCard, { props: { card, showTranslation: true } })
      const btn = w.get('[data-testid=example-speak-btn]')
      await btn.trigger('click')
      await btn.trigger('click')
      expect(players).toHaveLength(1)
      expect(players[0]!.paused).toBe(true)
      expect(btn.attributes('data-playing')).toBeUndefined()
    })

    it('stops the word, and the word stops it: one sound at a time', async () => {
      const w = mount(FlashCard, { props: { card, showTranslation: true } })
      const example = w.get('[data-testid=example-speak-btn]')

      await w.get('[data-testid=speak-btn]').trigger('click')
      await example.trigger('click')
      expect(players.map((p) => p.src)).toEqual([WORD_URL, EXAMPLE_URL])
      expect(players[0]!.paused).toBe(true)
      expect(example.attributes('data-playing')).toBe('true')

      await w.get('[data-testid=speak-btn]').trigger('click')
      await flushPromises()
      expect(players.map((p) => p.src)).toEqual([WORD_URL, EXAMPLE_URL, WORD_URL])
      expect(players[1]!.paused).toBe(true)
      expect(example.attributes('data-playing')).toBeUndefined()
    })

    it('stops the phone voice of a word without a file', async () => {
      const cancel = vi.fn()
      vi.stubGlobal('speechSynthesis', { getVoices: () => [], addEventListener: () => {}, cancel, speak: (u: { text: string }) => utterances.push(u.text) })
      const w = mount(FlashCard, { props: { card: { ...card, audio_url: null }, showTranslation: true } })
      await w.get('[data-testid=speak-btn]').trigger('click')
      expect(utterances).toEqual(['Resilient'])
      cancel.mockClear()

      await w.get('[data-testid=example-speak-btn]').trigger('click')
      expect(cancel).toHaveBeenCalled()
      expect(utterances).toEqual(['Resilient'])
    })

    it('goes quietly back to the idle button when the file fails, without the phone voice', async () => {
      playImpl = () => Promise.reject(new Error('404'))
      const w = mount(FlashCard, { props: { card, showTranslation: true } })
      const btn = w.get('[data-testid=example-speak-btn]')
      await btn.trigger('click')
      await flushPromises()
      expect(btn.attributes('data-playing')).toBeUndefined()
      expect(utterances).toEqual([])
      expect(w.find('[role=alert]').exists()).toBe(false)

      // A load error after a start (the stream broke): the same.
      playImpl = () => Promise.resolve()
      await btn.trigger('click')
      expect(btn.attributes('data-playing')).toBe('true')
      players[1]!.emit('error')
      await flushPromises()
      expect(btn.attributes('data-playing')).toBeUndefined()
      expect(utterances).toEqual([])
    })

    it('stops its example when the card leaves', async () => {
      const w = mount(FlashCard, { props: { card, showTranslation: true } })
      await w.get('[data-testid=example-speak-btn]').trigger('click')
      w.unmount()
      expect(players[0]!.paused).toBe(true)
      expect(useTts().playingUrl.value).toBeNull()
    })
  })

  describe.each([
    ['meaning', MeaningCard],
    ['reverse', ReverseCard],
    ['audio', AudioCard],
  ] as const)('in the answer panel of the %s card', (type, Component) => {
    it('comes with `revealed.example_audio_url` and plays it', async () => {
      const w = mount(Component, { props: { card: choiceCard(type), result: null } })
      expect(w.find('[data-testid=example-speak-btn]').exists()).toBe(false)

      await w.get('[data-testid=option-0]').trigger('click')
      await w.setProps({ result: result(EXAMPLE_URL) })
      const btn = w.get('[data-testid=example-speak-btn]')
      expect(w.get('[data-testid=example]').element.parentElement).toBe(btn.element.parentElement)

      const before = players.length
      await btn.trigger('click')
      expect(players.slice(before).map((p) => p.src)).toEqual([EXAMPLE_URL])
      expect(btn.attributes('data-playing')).toBe('true')
    })

    it('is not there when the revealed example has no file', async () => {
      const w = mount(Component, { props: { card: choiceCard(type), result: null } })
      await w.get('[data-testid=option-0]').trigger('click')
      await w.setProps({ result: result(null) })
      expect(w.find('[data-testid=example]').exists()).toBe(true)
      expect(w.find('[data-testid=example-speak-btn]').exists()).toBe(false)
    })
  })

  it('reads its label in Uzbek', () => {
    setLocale('uz')
    const w = mount(ExampleAudioButton, { props: { url: EXAMPLE_URL } })
    expect(w.get('button').attributes('aria-label')).toBe('Misolni tinglash')
  })

  it('lights only the button of the address that sounds', async () => {
    const one = mount(ExampleAudioButton, { props: { url: EXAMPLE_URL } })
    const other = mount(ExampleAudioButton, { props: { url: 'https://audio.example.uz/audio/other.mp3' } })
    await one.get('button').trigger('click')
    expect(one.get('button').attributes('data-playing')).toBe('true')
    expect(other.get('button').attributes('data-playing')).toBeUndefined()
    expect(players.map((p) => p.src)).toEqual([EXAMPLE_URL])
  })
})
