import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'
import DictationInput from '../DictationInput.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import { usePracticeStore } from '@/stores/practice'
import { dictationElement } from '@/api/__tests__/practiceFixtures'
import type { DictationElement } from '@/api/types'

// Web Speech would solve the dictation by machine (docs/16 §10.2): the component must never reach for it.
vi.mock('@/composables/useTts', () => ({
  useTts: vi.fn(() => {
    throw new Error('useTts must not be used by the dictation')
  }),
}))
vi.mock('@/api', () => ({ api: { practice: { audioBlob: vi.fn() } } }))

/** An `<audio>` that records what it was asked to do and lets the test fire its events. */
class FakeAudio {
  static instances: FakeAudio[] = []
  static playResult: () => Promise<void> = () => Promise.resolve()
  listeners = new Map<string, () => void>()
  constructor(public src: string) {
    FakeAudio.instances.push(this)
  }
  play = vi.fn(() => FakeAudio.playResult())
  pause = vi.fn()
  addEventListener(name: string, handler: () => void): void {
    this.listeners.set(name, handler)
  }
  emit(name: string): void {
    this.listeners.get(name)?.()
  }
}

const clip = (): Blob => new Blob(['x'], { type: 'audio/mpeg' })

let urlCounter = 0
const revoked = vi.fn()
const speak = vi.fn()
const utterance = vi.fn()

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(api.practice.audioBlob).mockReset().mockResolvedValue(clip())
  FakeAudio.instances = []
  FakeAudio.playResult = () => Promise.resolve()
  urlCounter = 0
  revoked.mockReset()
  speak.mockReset()
  utterance.mockReset()
  vi.stubGlobal('Audio', FakeAudio)
  vi.stubGlobal('speechSynthesis', { speak, cancel: vi.fn(), getVoices: () => [], addEventListener: vi.fn() })
  vi.stubGlobal('SpeechSynthesisUtterance', utterance)
  URL.createObjectURL = () => `blob:dictation-${++urlCounter}`
  URL.revokeObjectURL = (url: string) => revoked(url)
})

afterEach(() => {
  setLocale('ru')
  vi.unstubAllGlobals()
})

interface Options {
  element?: DictationElement
  mode?: 'solo' | 'battle'
  disabled?: boolean
}

/** Mounted, with the clip fetched (the common case). */
async function render({ element = dictationElement(), mode = 'solo', disabled = false }: Options = {}): Promise<VueWrapper> {
  const wrapper = mount(DictationInput, { props: { element, mode, disabled }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

const circle = (wrapper: VueWrapper) => wrapper.get('[data-testid=listen-btn]')
const field = (wrapper: VueWrapper) => wrapper.get('[data-testid=dictation-text]')
const check = (wrapper: VueWrapper) => wrapper.get('[data-testid=check-btn]')
const skip = (wrapper: VueWrapper) => wrapper.get('[data-testid=skip-btn]')

describe('DictationInput — the recording', () => {
  it('asks for the recording as soon as it is shown, and the circle waits for it', async () => {
    let finish!: (blob: Blob) => void
    vi.mocked(api.practice.audioBlob).mockReturnValue(new Promise<Blob>((resolve) => (finish = resolve)))

    const wrapper = mount(DictationInput, { props: { element: dictationElement(), mode: 'solo' } })
    await nextTick()

    expect(api.practice.audioBlob).toHaveBeenCalledTimes(1)
    expect(api.practice.audioBlob).toHaveBeenCalledWith('/api/twa/practice/sessions/77/audio/eDict00001', expect.any(AbortSignal))
    expect(circle(wrapper).attributes('disabled')).toBeDefined()
    expect(circle(wrapper).attributes('aria-busy')).toBe('true')
    expect(circle(wrapper).attributes('aria-label')).toBe('Загружаем звук…')
    expect(wrapper.find('[data-testid=listen-spinner]').exists()).toBe(true)
    expect(wrapper.get('[data-testid=audio-state]').text()).toBe('Загружаем звук…')
    // Loading the recording does not hold the screen: the field and both buttons are alive.
    expect(field(wrapper).attributes('disabled')).toBeUndefined()
    expect(skip(wrapper).attributes('disabled')).toBeUndefined()

    finish(clip())
    await flushPromises()

    expect(circle(wrapper).attributes('disabled')).toBeUndefined()
    expect(circle(wrapper).attributes('aria-label')).toBe('Прослушать')
    expect(wrapper.find('[data-testid=listen-spinner]').exists()).toBe(false)
    expect(wrapper.get('[data-testid=audio-state]').text()).toBe('Слушайте сколько нужно и пишите по-английски')
    wrapper.unmount()
  })

  it('does not fetch a recording that the session screen has already got', async () => {
    const store = usePracticeStore()
    store.audio.state = 'ready'
    store.audio.blobUrl = 'blob:from-the-screen'

    const wrapper = await render()
    await circle(wrapper).trigger('click')

    expect(api.practice.audioBlob).not.toHaveBeenCalled()
    expect(FakeAudio.instances.map((player) => player.src)).toEqual(['blob:from-the-screen'])
    wrapper.unmount()
  })

  it('plays it from the tap, in the very call of the tap, as often as asked, each time from the start', async () => {
    const wrapper = await render()

    await circle(wrapper).trigger('click')
    await circle(wrapper).trigger('click')
    await circle(wrapper).trigger('click')

    expect(FakeAudio.instances).toHaveLength(3)
    expect(FakeAudio.instances.map((player) => player.src)).toEqual(['blob:dictation-1', 'blob:dictation-1', 'blob:dictation-1'])
    expect(FakeAudio.instances.every((player) => player.play.mock.calls.length === 1)).toBe(true)
    expect(FakeAudio.instances[0]!.pause).toHaveBeenCalled()
    expect(api.practice.audioBlob).toHaveBeenCalledTimes(1) // listening again does not ask the network
    wrapper.unmount()
  })

  it('says that it is playing from the press to the end of the clip', async () => {
    const wrapper = await render()

    await circle(wrapper).trigger('click')
    await flushPromises()
    expect(circle(wrapper).attributes('data-playing')).toBe('true')
    expect(wrapper.get('[data-testid=audio-state]').text()).toBe('Играет запись…')

    FakeAudio.instances[0]!.emit('ended')
    await nextTick()
    expect(circle(wrapper).attributes('data-playing')).toBeUndefined()
    expect(wrapper.get('[data-testid=audio-state]').text()).toBe('Слушайте сколько нужно и пишите по-английски')
    wrapper.unmount()
  })

  it('is a circle of at least 64 px - 80 px, and 64 px on a short window where the keyboard is up', async () => {
    const wrapper = await render()

    expect(circle(wrapper).classes()).toContain('size-20')
    expect(circle(wrapper).classes()).toContain('[@media(max-height:560px)]:size-16')
    expect(circle(wrapper).classes()).toContain('rounded-full')
    expect(circle(wrapper).findComponent(AppIcon).props('name')).toBe('speaker-wave')
    // The title gives way on a short window, so that the field stays in view.
    expect(wrapper.get('[data-testid=dictation-title]').classes()).toContain('[@media(max-height:560px)]:hidden')
    expect(wrapper.get('[data-testid=dictation-title]').text()).toBe('Слушай и пиши')
    wrapper.unmount()
  })
})

describe('DictationInput — a recording that does not load', () => {
  it('turns the circle to "Retry", offers no guessing, and loads again from the circle', async () => {
    vi.mocked(api.practice.audioBlob).mockRejectedValueOnce(new Error('offline'))
    const wrapper = await render()

    expect(circle(wrapper).attributes('aria-label')).toBe('Повторить загрузку')
    expect(circle(wrapper).findComponent(AppIcon).props('name')).toBe('arrow-path')
    expect(wrapper.get('[data-testid=audio-state]').text()).toBe('Звук недоступен')
    expect(wrapper.get('[data-testid=audio-state]').classes()).toContain('text-warning-ink')
    expect(circle(wrapper).classes()).toContain('bg-warning-soft')
    // Nothing was heard: no answer is offered, and the learner is told why.
    expect(field(wrapper).attributes('disabled')).toBeDefined()
    expect(check(wrapper).attributes('disabled')).toBeDefined()
    expect(skip(wrapper).attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid=blocked-note]').text()).toBe('Без записи отвечать нельзя. Повторите загрузку или замените задание.')

    await circle(wrapper).trigger('click')
    await flushPromises()

    expect(api.practice.audioBlob).toHaveBeenCalledTimes(2)
    expect(FakeAudio.instances).toHaveLength(0) // a retry loads, it does not play
    expect(circle(wrapper).attributes('aria-label')).toBe('Прослушать')
    expect(field(wrapper).attributes('disabled')).toBeUndefined()
    expect(check(wrapper).attributes('disabled')).toBeDefined() // still no text
    expect(skip(wrapper).attributes('disabled')).toBeUndefined()
    expect(wrapper.find('[data-testid=blocked-note]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('sends nothing while it is blocked: neither Enter nor a press', async () => {
    vi.mocked(api.practice.audioBlob).mockRejectedValue(new Error('offline'))
    const wrapper = await render()

    await field(wrapper).trigger('keydown.enter')
    await check(wrapper).trigger('click')
    await skip(wrapper).trigger('click')

    expect(wrapper.emitted('answer')).toBeUndefined()
    expect(wrapper.emitted('skip')).toBeUndefined()
    wrapper.unmount()
  })

  it('takes the answer away from a learner who typed a text but never heard the recording, because it will not play', async () => {
    const wrapper = await render()
    await field(wrapper).setValue('see you tomorrow')
    FakeAudio.playResult = () => Promise.reject(Object.assign(new Error('bad media'), { name: 'NotSupportedError' }))

    await circle(wrapper).trigger('click') // the clip does not play: nothing was heard
    await flushPromises()

    expect(circle(wrapper).attributes('aria-label')).toBe('Повторить загрузку')
    expect(field(wrapper).attributes('disabled')).toBeDefined()
    expect(check(wrapper).attributes('disabled')).toBeDefined()
    expect(skip(wrapper).attributes('disabled')).toBeDefined()
    await field(wrapper).trigger('keydown.enter')
    await check(wrapper).trigger('click')
    expect(wrapper.emitted('answer')).toBeUndefined()
    wrapper.unmount()
  })

  it('does not take the answer away from a learner who has heard the recording: a later failure only offers a retry', async () => {
    const wrapper = await render()
    await field(wrapper).setValue('see you tomorrow')
    await circle(wrapper).trigger('click')
    await flushPromises()

    FakeAudio.instances[0]!.emit('error') // the next play of the clip will not work
    await nextTick()

    expect(circle(wrapper).attributes('aria-label')).toBe('Повторить загрузку')
    expect(field(wrapper).attributes('disabled')).toBeUndefined()
    expect(check(wrapper).attributes('disabled')).toBeUndefined()
    expect(skip(wrapper).attributes('disabled')).toBeUndefined()
    expect(wrapper.find('[data-testid=blocked-note]').exists()).toBe(false)

    await check(wrapper).trigger('click')
    expect(wrapper.emitted('answer')).toEqual([[{ text: 'see you tomorrow' }]])
    wrapper.unmount()
  })
})

describe('DictationInput — the empty tiles', () => {
  it.each([
    [1, '1 слово'],
    [3, '3 слова'],
    [5, '5 слов'],
  ])('draws one empty tile for each of %i words and says how many, without a single letter of the phrase', async (words, label) => {
    const wrapper = await render({ element: dictationElement({ words }) })

    const tiles = wrapper.findAll('[data-testid=word-tile]')
    expect(tiles).toHaveLength(words)
    expect(tiles.every((tile) => tile.text() === '' && tile.attributes('data-filled') === 'false')).toBe(true)
    expect(wrapper.get('[data-testid=word-count]').text()).toBe(label)
    wrapper.unmount()
  })

  it('says it in Uzbek', async () => {
    setLocale('uz')
    const wrapper = await render({ element: dictationElement({ words: 3 }) })

    expect(wrapper.get('[data-testid=word-count]').text()).toBe("3 ta so'z")
    expect(wrapper.get('[data-testid=dictation-title]').text()).toBe('Eshiting va yozing')
    expect(field(wrapper).attributes('placeholder')).toBe('Eshitganingizni yozing')
    expect(check(wrapper).text()).toBe('Tekshirish')
    expect(skip(wrapper).text()).toBe('Bilmayman')
    wrapper.unmount()
  })

  it('fills a tile for each word typed, up to the number of words, and tells a screen reader', async () => {
    const wrapper = await render({ element: dictationElement({ words: 3 }) })
    const filled = () => wrapper.findAll('[data-testid=word-tile]').map((tile) => tile.attributes('data-filled'))
    const spoken = () => wrapper.get('[data-testid=word-tiles]').attributes('aria-label')

    expect(filled()).toEqual(['false', 'false', 'false'])
    expect(spoken()).toBe('Написано слов: 0 из 3')

    await field(wrapper).setValue('see')
    expect(filled()).toEqual(['true', 'false', 'false'])

    await field(wrapper).setValue('  see   you ')
    expect(filled()).toEqual(['true', 'true', 'false'])
    expect(spoken()).toBe('Написано слов: 2 из 3')

    await field(wrapper).setValue('see you tomorrow and more')
    expect(filled()).toEqual(['true', 'true', 'true'])
    expect(spoken()).toBe('Написано слов: 3 из 3')

    await field(wrapper).setValue('   ')
    expect(filled()).toEqual(['false', 'false', 'false'])
    wrapper.unmount()
  })

  it('does not show the empty tile as a fill by colour only: a filled tile is solid, an empty one is dashed', async () => {
    const wrapper = await render({ element: dictationElement({ words: 2 }) })
    await field(wrapper).setValue('see')

    const [full, empty] = wrapper.findAll('[data-testid=word-tile]')
    expect(full!.classes()).toContain('border-accent')
    expect(full!.classes()).not.toContain('border-dashed')
    expect(empty!.classes()).toContain('border-dashed')
    wrapper.unmount()
  })
})

describe('DictationInput — the field and the on-screen keyboard', () => {
  it('turns off what spoils a dictation: capitals, autocorrect, spell check, suggestions', async () => {
    const wrapper = await render()

    expect(field(wrapper).element.tagName).toBe('INPUT')
    expect(field(wrapper).attributes('type')).toBe('text')
    expect(field(wrapper).attributes('autocapitalize')).toBe('off')
    expect(field(wrapper).attributes('autocorrect')).toBe('off')
    expect(field(wrapper).attributes('spellcheck')).toBe('false')
    expect(field(wrapper).attributes('autocomplete')).toBe('off')
    expect(field(wrapper).attributes('enterkeyhint')).toBe('done')
    expect(field(wrapper).attributes('lang')).toBe('en')
    expect(field(wrapper).attributes('maxlength')).toBe('200') // what the server takes
    expect(field(wrapper).attributes('aria-label')).toBe('Напишите, что услышали')
    expect(field(wrapper).attributes('placeholder')).toBe('Напишите, что услышали')
    wrapper.unmount()
  })

  it('is a field of the design system placed on the page, not an unframed text', async () => {
    const wrapper = await render()

    expect(field(wrapper).classes()).toEqual(expect.arrayContaining(['field', 'field-surface']))
    wrapper.unmount()
  })

  it('does not take the focus by itself - the keyboard must not cover the recording button before it is pressed', async () => {
    const wrapper = await render()

    expect(document.activeElement).not.toBe(field(wrapper).element)
    wrapper.unmount()
  })

  it('keeps the field above the actions: the actions are one row in the sticky block, the skip first, the check wide', async () => {
    const wrapper = await render()

    const actions = wrapper.get('[data-testid=actions]')
    expect(actions.classes()).toContain('sticky')
    expect(actions.findAll('button').map((button) => button.attributes('data-testid'))).toEqual(['skip-btn', 'check-btn'])
    expect(check(wrapper).classes()).toEqual(expect.arrayContaining(['btn', 'btn-primary', 'btn-lg', 'flex-1']))
    expect(skip(wrapper).classes()).toEqual(expect.arrayContaining(['btn', 'btn-plain']))
    expect(wrapper.findAll('.btn-primary')).toHaveLength(1)
    // The field comes before the actions in the page, so that it is in the upper part of the screen.
    const html = wrapper.html()
    expect(html.indexOf('data-testid="dictation-text"')).toBeLessThan(html.indexOf('data-testid="actions"'))
    wrapper.unmount()
  })
})

describe('DictationInput — the answer', () => {
  it('does not send an empty text: the button waits, Enter and a press do nothing, spaces are empty too', async () => {
    const wrapper = await render()

    expect(check(wrapper).attributes('disabled')).toBeDefined()
    await field(wrapper).trigger('keydown.enter')
    await check(wrapper).trigger('click')
    await field(wrapper).setValue('    ')
    await field(wrapper).trigger('keydown.enter')
    await check(wrapper).trigger('click')

    expect(check(wrapper).attributes('disabled')).toBeDefined()
    expect(wrapper.emitted('answer')).toBeUndefined()
    expect(wrapper.emitted('skip')).toBeUndefined()
    wrapper.unmount()
  })

  it('sends the trimmed text with "Check"', async () => {
    const wrapper = await render()

    await field(wrapper).setValue('  See you tomorrow  ')
    expect(check(wrapper).attributes('disabled')).toBeUndefined()
    await check(wrapper).trigger('click')

    expect(wrapper.emitted('answer')).toEqual([[{ text: 'See you tomorrow' }]])
    expect(wrapper.emitted('skip')).toBeUndefined()
    wrapper.unmount()
  })

  it('sends the same with Enter', async () => {
    const wrapper = await render()

    await field(wrapper).setValue('see you tomorrow')
    await field(wrapper).trigger('keydown.enter')

    expect(wrapper.emitted('answer')).toEqual([[{ text: 'see you tomorrow' }]])
    wrapper.unmount()
  })

  it('"I don\'t know" is a skip, with the field empty and with a text in it, and sends no answer', async () => {
    const wrapper = await render()

    await skip(wrapper).trigger('click')
    await field(wrapper).setValue('some guess')
    await skip(wrapper).trigger('click')

    expect(wrapper.emitted('skip')).toHaveLength(2)
    expect(wrapper.emitted('answer')).toBeUndefined()
    wrapper.unmount()
  })

  it('waits while an answer is on its way: the field (read-only, not disabled) and both buttons, but not the recording', async () => {
    const wrapper = await render({ disabled: true })

    expect(field(wrapper).attributes('readonly')).toBeDefined()
    expect(field(wrapper).attributes('disabled')).toBeUndefined() // a disabled field drops the focus and the keyboard
    expect(check(wrapper).attributes('disabled')).toBeDefined()
    expect(skip(wrapper).attributes('disabled')).toBeDefined()
    await skip(wrapper).trigger('click')
    await check(wrapper).trigger('click')
    expect(wrapper.emitted('skip')).toBeUndefined()
    expect(wrapper.emitted('answer')).toBeUndefined()

    expect(circle(wrapper).attributes('disabled')).toBeUndefined()
    await circle(wrapper).trigger('click')
    expect(FakeAudio.instances).toHaveLength(1)
    wrapper.unmount()
  })

  it('solo: a refusal that leaves the learner here lets them send the same text again', async () => {
    const wrapper = await render()
    await field(wrapper).setValue('see you tomorrow')

    await check(wrapper).trigger('click')
    await wrapper.setProps({ disabled: true }) // the answer is on its way
    await wrapper.setProps({ disabled: false }) // and did not arrive: the text is still in the field
    expect((field(wrapper).element as HTMLInputElement).value).toBe('see you tomorrow')
    await check(wrapper).trigger('click')

    expect(wrapper.emitted('answer')).toEqual([[{ text: 'see you tomorrow' }], [{ text: 'see you tomorrow' }]])
    wrapper.unmount()
  })

  it('battle: the answer is the one final answer - after "Check" the component is locked', async () => {
    const wrapper = await render({ mode: 'battle' })
    await field(wrapper).setValue('see you tomorrow')

    await check(wrapper).trigger('click')
    await field(wrapper).trigger('keydown.enter')
    await check(wrapper).trigger('click')
    await skip(wrapper).trigger('click')

    expect(wrapper.emitted('answer')).toEqual([[{ text: 'see you tomorrow' }]])
    expect(wrapper.emitted('skip')).toBeUndefined()
    expect(field(wrapper).attributes('readonly')).toBeDefined()
    expect(check(wrapper).attributes('disabled')).toBeDefined()
    expect(skip(wrapper).attributes('disabled')).toBeDefined()
    // The recording can still be heard.
    expect(circle(wrapper).attributes('disabled')).toBeUndefined()
    wrapper.unmount()
  })

  it('battle: "I don\'t know" is final too', async () => {
    const wrapper = await render({ mode: 'battle' })

    await skip(wrapper).trigger('click')
    await skip(wrapper).trigger('click')
    await field(wrapper).setValue('late guess')
    await check(wrapper).trigger('click')

    expect(wrapper.emitted('skip')).toHaveLength(1)
    expect(wrapper.emitted('answer')).toBeUndefined()
    wrapper.unmount()
  })

  it('starts clean for the next element when the same component is given one: no text, no heard clip, its own recording', async () => {
    const wrapper = await render({ mode: 'battle' })
    await field(wrapper).setValue('see you tomorrow')
    await check(wrapper).trigger('click')

    await wrapper.setProps({ element: dictationElement({ id: 'eDict00002', audio_url: '/api/twa/practice/battles/15/audio/eDict00002', words: 2 }) })
    await flushPromises()

    expect((field(wrapper).element as HTMLInputElement).value).toBe('')
    expect(field(wrapper).attributes('disabled')).toBeUndefined()
    expect(api.practice.audioBlob).toHaveBeenLastCalledWith('/api/twa/practice/battles/15/audio/eDict00002', expect.any(AbortSignal))
    expect(wrapper.findAll('[data-testid=word-tile]')).toHaveLength(2)
    wrapper.unmount()
  })
})

// The text is ONE answer per element (docs/16 §11.4). When its request gets no reply the server may have counted it, so the field is not
// given back for editing: another text would be a second answer, refused as "already accepted" and shown as the verdict of the first.
describe('DictationInput — an answer that got no reply (solo)', () => {
  /** The learner types `text`, presses what they press, the screen locks the component for the request, and no reply comes. */
  async function lostReply(how: 'check' | 'skip' = 'check', text = 'see you tomorow', options: Options = {}) {
    const wrapper = await render(options)
    await field(wrapper).setValue(text)
    await (how === 'check' ? check(wrapper) : skip(wrapper)).trigger('click')
    await wrapper.setProps({ disabled: true })
    await wrapper.setProps({ disabled: false })
    return wrapper
  }

  it('keeps the text in the field, which can be read but not changed: read-only, and the text is still what was typed', async () => {
    const wrapper = await lostReply()

    expect((field(wrapper).element as HTMLInputElement).value).toBe('see you tomorow')
    expect(field(wrapper).attributes('readonly')).toBeDefined()
    expect(field(wrapper).attributes('disabled')).toBeUndefined()
    wrapper.unmount()
  })

  it('"Check" sends the very same text again, as often as the request gets no reply', async () => {
    const wrapper = await lostReply()

    for (let again = 0; again < 2; again++) {
      expect(check(wrapper).attributes('disabled')).toBeUndefined()
      await check(wrapper).trigger('click')
      await wrapper.setProps({ disabled: true })
      await wrapper.setProps({ disabled: false })
    }

    expect(wrapper.emitted('answer')).toEqual([[{ text: 'see you tomorow' }], [{ text: 'see you tomorow' }], [{ text: 'see you tomorow' }]])
    expect(wrapper.emitted('skip')).toBeUndefined()
    wrapper.unmount()
  })

  it('Enter sends it again too', async () => {
    const wrapper = await lostReply()

    await field(wrapper).trigger('keydown.enter')

    expect(wrapper.emitted('answer')).toEqual([[{ text: 'see you tomorow' }], [{ text: 'see you tomorow' }]])
    wrapper.unmount()
  })

  it('"I don\'t know" is off: it would be a second answer', async () => {
    const wrapper = await lostReply()

    expect(skip(wrapper).attributes('disabled')).toBeDefined()
    await skip(wrapper).trigger('click')

    expect(wrapper.emitted('skip')).toBeUndefined()
    wrapper.unmount()
  })

  it('an "I don\'t know" that got no reply is what goes again, and "Check" cannot take its place', async () => {
    const wrapper = await lostReply('skip')

    expect(skip(wrapper).attributes('disabled')).toBeUndefined()
    expect(check(wrapper).attributes('disabled')).toBeDefined()
    await check(wrapper).trigger('click')
    await skip(wrapper).trigger('click')

    expect(wrapper.emitted('answer')).toBeUndefined()
    expect(wrapper.emitted('skip')).toHaveLength(2)
    wrapper.unmount()
  })

  it('says so, in a note under the field', async () => {
    const wrapper = await lostReply()

    const note = wrapper.get('[data-testid=dictation-resend-note]')
    expect(note.text()).toBe('Ответа от сервера нет. Свой ответ уже не изменить — отправьте его ещё раз.')
    expect(note.attributes('role')).toBe('status')
    wrapper.unmount()
  })

  it('has no note before a request has failed, nor while one is on its way', async () => {
    const wrapper = await render()
    await field(wrapper).setValue('see you tomorow')
    await check(wrapper).trigger('click')
    await wrapper.setProps({ disabled: true })

    expect(wrapper.find('[data-testid=dictation-resend-note]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('does not take away the repeat when the sound fails meanwhile: a text that has gone out once is not a guess in the dark', async () => {
    const wrapper = await render()
    await field(wrapper).setValue('see you tomorow')
    await check(wrapper).trigger('click')
    await wrapper.setProps({ disabled: true })
    await wrapper.setProps({ disabled: false })
    vi.mocked(api.practice.audioBlob).mockRejectedValue(new Error('offline'))
    await circle(wrapper).trigger('click') // a press that fails the clip: still failed, never heard
    FakeAudio.playResult = () => Promise.reject(Object.assign(new Error('bad media'), { name: 'NotSupportedError' }))
    await flushPromises()

    expect(check(wrapper).attributes('disabled')).toBeUndefined()
    expect(wrapper.find('[data-testid=blocked-note]').exists()).toBe(false) // "no answer without the recording" would contradict the note
    await check(wrapper).trigger('click')
    expect(wrapper.emitted('answer')).toHaveLength(2)
    wrapper.unmount()
  })

  it('a lock that no answer of this component caused (a replacement of the clip) is no failure', async () => {
    const wrapper = await render()
    await field(wrapper).setValue('see you')

    await wrapper.setProps({ disabled: true })
    await wrapper.setProps({ disabled: false })

    expect(field(wrapper).attributes('readonly')).toBeUndefined()
    expect(wrapper.find('[data-testid=dictation-resend-note]').exists()).toBe(false)
    expect(skip(wrapper).attributes('disabled')).toBeUndefined()
    wrapper.unmount()
  })

  it('starts clean for another element in the same component', async () => {
    const wrapper = await lostReply()

    await wrapper.setProps({ element: dictationElement({ id: 'eDict00002', audio_url: '/api/twa/practice/sessions/77/audio/eDict00002' }) })
    await flushPromises()

    expect(field(wrapper).attributes('readonly')).toBeUndefined()
    expect((field(wrapper).element as HTMLInputElement).value).toBe('')
    expect(wrapper.find('[data-testid=dictation-resend-note]').exists()).toBe(false)
    expect(skip(wrapper).attributes('disabled')).toBeUndefined()
    wrapper.unmount()
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')

    const wrapper = await lostReply()

    expect(wrapper.get('[data-testid=dictation-resend-note]').text()).toBe("Serverdan javob kelmadi. Javobingizni endi o'zgartirib bo'lmaydi — uni yana yuboring.")
    wrapper.unmount()
  })

  it('in a battle there is no such thing: the battle screen has its own retry', async () => {
    const wrapper = await lostReply('check', 'see you tomorow', { mode: 'battle' })

    expect(wrapper.find('[data-testid=dictation-resend-note]').exists()).toBe(false)
    expect(check(wrapper).attributes('disabled')).toBeDefined() // final: the one answer has gone
    wrapper.unmount()
  })
})

describe('DictationInput — the field keeps the keyboard while the answer is on its way', () => {
  it('is read-only, not disabled, so that the focus (and with it the on-screen keyboard) stays where it was', async () => {
    const wrapper = await render()
    ;(field(wrapper).element as HTMLInputElement).focus()
    expect(document.activeElement).toBe(field(wrapper).element)

    await field(wrapper).setValue('see you tomorrow')
    await check(wrapper).trigger('click')
    await wrapper.setProps({ disabled: true }) // the answer is on its way

    expect(field(wrapper).attributes('readonly')).toBeDefined()
    expect(document.activeElement).toBe(field(wrapper).element)
    wrapper.unmount()
  })

  it('takes nothing typed while it waits, and gives the text back for typing only when no answer is out', async () => {
    const wrapper = await render({ disabled: true })
    expect(field(wrapper).attributes('readonly')).toBeDefined()

    await wrapper.setProps({ disabled: false })

    expect(field(wrapper).attributes('readonly')).toBeUndefined()
    wrapper.unmount()
  })

  it('stays disabled where the answer is not allowed at all: the recording cannot be loaded and was never heard', async () => {
    vi.mocked(api.practice.audioBlob).mockRejectedValue(new Error('offline'))
    const wrapper = await render()

    expect(field(wrapper).attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-testid=blocked-note]').exists()).toBe(true)
    wrapper.unmount()
  })
})

// docs/15 §3.4: "an explicit button «No sound for now» with the way back to choosing another mode". Not only in the sheet of the sound
// that failed: a learner whose sound loaded but who cannot listen now (no headphones) has it too.
describe('DictationInput — "No sound for now"', () => {
  const silent = (wrapper: VueWrapper) => wrapper.get('[data-testid=silent-btn]')

  it('is always on the screen of a solo session: when the sound is fine', async () => {
    const wrapper = await render()

    expect(silent(wrapper).text()).toBe('Сейчас без звука')
    expect(silent(wrapper).classes()).toEqual(expect.arrayContaining(['btn', 'btn-plain']))
    expect(silent(wrapper).attributes('type')).toBe('button')
    wrapper.unmount()
  })

  it('and while the sound loads, and when it failed, and while an answer is on its way', async () => {
    vi.mocked(api.practice.audioBlob).mockReturnValue(new Promise<Blob>(() => {}))
    const loading = await render()
    expect(loading.find('[data-testid=silent-btn]').exists()).toBe(true)
    loading.unmount()

    vi.mocked(api.practice.audioBlob).mockRejectedValue(new Error('offline'))
    const failed = await render()
    expect(failed.find('[data-testid=silent-btn]').exists()).toBe(true)
    failed.unmount()

    vi.mocked(api.practice.audioBlob).mockResolvedValue(clip())
    const busy = await render({ disabled: true })
    expect(busy.find('[data-testid=silent-btn]').exists()).toBe(true)
    expect(silent(busy).attributes('disabled')).toBeUndefined() // leaving is not an answer
    busy.unmount()
  })

  it('sends `silent`, and nothing else: it is not an answer', async () => {
    const wrapper = await render()
    await field(wrapper).setValue('some text')

    await silent(wrapper).trigger('click')

    expect(wrapper.emitted('silent')).toHaveLength(1)
    expect(wrapper.emitted('answer')).toBeUndefined()
    expect(wrapper.emitted('skip')).toBeUndefined()
    wrapper.unmount()
  })

  it('is no second primary button, and it is not in the sticky block with "Check": it does not compete with the main action', async () => {
    const wrapper = await render()

    expect(wrapper.findAll('.btn-primary')).toHaveLength(1)
    expect(wrapper.get('[data-testid=actions]').find('[data-testid=silent-btn]').exists()).toBe(false)
    const html = wrapper.html()
    expect(html.indexOf('data-testid="dictation-text"')).toBeLessThan(html.indexOf('data-testid="silent-btn"')) // under the field
    wrapper.unmount()
  })

  it('is a button a finger can hit: 44 px (the small button keeps a touch area of that size)', async () => {
    const wrapper = await render()

    expect(silent(wrapper).classes()).toContain('btn-sm') // .btn-sm has an invisible 44 × 44 px layer (style.css)
    wrapper.unmount()
  })

  it('is not in a battle: there is no hub to go back to in the middle of a match', async () => {
    const wrapper = await render({ mode: 'battle' })

    expect(wrapper.find('[data-testid=silent-btn]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')

    const wrapper = await render()

    expect(silent(wrapper).text()).toBe('Hozircha ovozsiz')
    wrapper.unmount()
  })
})

describe('DictationInput — no Web Speech', () => {
  it('never reads anything aloud: not on a press, not when the clip fails, not on an answer', async () => {
    vi.mocked(api.practice.audioBlob).mockRejectedValueOnce(new Error('offline'))
    const wrapper = await render()

    await circle(wrapper).trigger('click') // a retry
    await flushPromises()
    await circle(wrapper).trigger('click') // a play
    FakeAudio.instances[0]!.emit('error')
    await field(wrapper).setValue('see you tomorrow')
    await check(wrapper).trigger('click')
    await skip(wrapper).trigger('click')

    expect(speak).not.toHaveBeenCalled()
    expect(utterance).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('has no useTts, no speech synthesis and no TTS endpoint in its source', () => {
    const source = readFileSync(join(process.cwd(), 'src/components/practice/DictationInput.vue'), 'utf8')
      .replace(/\/\*[\s\S]*?\*\//g, '')
      .replace(/<!--[\s\S]*?-->/g, '')
      .replace(/(^|\s)\/\/.*$/gm, '$1')

    expect(source).not.toMatch(/useTts|speechSynthesis|SpeechSynthesisUtterance|\/api\/twa\/tts/)
  })

  it('never shows the learner the text of the task: the element has none to show', async () => {
    const wrapper = await render()

    const element = dictationElement()
    expect(Object.keys(element).sort()).toEqual(['audio_url', 'id', 'kind', 'replacements_left', 'source', 'words'])
    expect(wrapper.text()).not.toMatch(/tomorrow|See you/i)
    wrapper.unmount()
  })
})
