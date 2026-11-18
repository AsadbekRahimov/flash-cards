import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import WordFormInput from '../WordFormInput.vue'
import { setLocale } from '@/i18n'
import { forgetWordFormDrafts, readWordFormDraft } from '@/composables/wordFormDraft'
import { wordFormElement } from '@/api/__tests__/practiceFixtures'
import type { WordFormElement } from '@/api/types'

beforeEach(() => forgetWordFormDrafts())

afterEach(() => setLocale('ru'))

interface Options {
  element?: WordFormElement
  mode?: 'solo' | 'battle'
  disabled?: boolean
}

const render = ({ element = wordFormElement(), mode = 'solo', disabled = false }: Options = {}): VueWrapper =>
  mount(WordFormInput, { props: { element, mode, disabled }, attachTo: document.body })

const field = (wrapper: VueWrapper) => wrapper.get<HTMLInputElement>('[data-testid=word-form-field]')
const checkBtn = (wrapper: VueWrapper) => wrapper.get<HTMLButtonElement>('[data-testid=check-btn]')
const skipBtn = (wrapper: VueWrapper) => wrapper.get<HTMLButtonElement>('[data-testid=skip-btn]')

describe('WordFormInput — the sentence, the base word, the field', () => {
  it('shows the sentence with its blank, the base word and the instruction', () => {
    const wrapper = render()

    expect(wrapper.get('[data-testid=word-form-instruction]').text()).toBe('Вставьте слово в нужной форме')
    const text = wrapper.get('[data-testid=word-form-text]')
    expect(text.attributes('lang')).toBe('en')
    expect(text.text()).toContain('We need to make a')
    expect(text.text()).toContain('by Friday.')
    expect(text.text()).not.toContain('___')
    expect(wrapper.get('[data-testid=word-form-blank]').attributes('data-tone')).toBe('empty')
    expect(wrapper.get('[data-testid=word-form-base]').text()).toBe('decide')
    expect(wrapper.get('[data-testid=word-form-base-row]').text()).toContain('Образуйте форму от слова')
    wrapper.unmount()
  })

  it('is a field the phone keyboard does not change: no capitals, no autocorrection, no spell check, Enter is "done"', () => {
    const wrapper = render()
    const input = field(wrapper)

    expect(input.attributes('autocapitalize')).toBe('off')
    expect(input.attributes('autocorrect')).toBe('off')
    expect(input.attributes('autocomplete')).toBe('off')
    expect(input.attributes('spellcheck')).toBe('false')
    expect(input.attributes('enterkeyhint')).toBe('done')
    expect(input.attributes('lang')).toBe('en')
    expect(input.attributes('placeholder')).toBe('Ваш ответ')
    expect(input.attributes('aria-label')).toContain('decide')
    wrapper.unmount()
  })

  it('shows the typed word in the blank as well', async () => {
    const wrapper = render()

    await field(wrapper).setValue('  decision ')
    const blank = wrapper.get('[data-testid=word-form-blank]')
    expect(blank.text()).toBe('decision')
    expect(blank.attributes('data-tone')).toBe('typed')
    wrapper.unmount()
  })

  it('speaks Uzbek', () => {
    setLocale('uz')
    const wrapper = render()

    expect(wrapper.get('[data-testid=word-form-instruction]').text()).toBe("So'zni kerakli shaklda qo'ying")
    expect(field(wrapper).attributes('placeholder')).toBe('Javobingiz')
    wrapper.unmount()
  })
})

describe('WordFormInput — answering', () => {
  it('keeps "Check" off while the field is empty or blank', async () => {
    const wrapper = render()

    expect(checkBtn(wrapper).element.disabled).toBe(true)
    await field(wrapper).setValue('   ')
    expect(checkBtn(wrapper).element.disabled).toBe(true)
    await checkBtn(wrapper).trigger('click')
    expect(wrapper.emitted('answer')).toBeUndefined()
    expect(skipBtn(wrapper).element.disabled).toBe(false)
    wrapper.unmount()
  })

  it('sends the text, trimmed and with single spaces, on "Check"', async () => {
    const wrapper = render()

    await field(wrapper).setValue('  more   beautiful ')
    await checkBtn(wrapper).trigger('click')

    expect(wrapper.emitted('answer')).toEqual([[{ text: 'more beautiful' }]])
    wrapper.unmount()
  })

  it('sends on Enter', async () => {
    const wrapper = render()

    await field(wrapper).setValue('decision')
    await field(wrapper).trigger('keydown', { key: 'Enter' })

    expect(wrapper.emitted('answer')).toEqual([[{ text: 'decision' }]])
    wrapper.unmount()
  })

  it('does not send on Enter with an empty field', async () => {
    const wrapper = render()

    await field(wrapper).trigger('keydown', { key: 'Enter' })

    expect(wrapper.emitted('answer')).toBeUndefined()
    wrapper.unmount()
  })

  it('"I don\'t know" is a skip', async () => {
    const wrapper = render()

    await skipBtn(wrapper).trigger('click')

    expect(wrapper.emitted('skip')).toHaveLength(1)
    expect(wrapper.emitted('answer')).toBeUndefined()
    wrapper.unmount()
  })

  it('waits while an answer is on its way: the field is read-only, the buttons are off', async () => {
    const wrapper = render({ disabled: true })

    expect(field(wrapper).attributes('readonly')).toBeDefined()
    expect(field(wrapper).attributes('disabled')).toBeUndefined() // the keyboard stays up
    expect(checkBtn(wrapper).element.disabled).toBe(true)
    expect(skipBtn(wrapper).element.disabled).toBe(true)
    wrapper.unmount()
  })
})

describe('WordFormInput — a lost reply: the same text goes again', () => {
  it('keeps the text read-only after a send that got no reply and sends the same text again', async () => {
    const wrapper = render()
    await field(wrapper).setValue('decidion')
    await checkBtn(wrapper).trigger('click')

    // The screen locks while the request is on its way, then lets go of the lock: no reply came.
    await wrapper.setProps({ disabled: true })
    await wrapper.setProps({ disabled: false })

    expect(wrapper.find('[data-testid=word-form-resend-note]').exists()).toBe(true)
    expect(field(wrapper).attributes('readonly')).toBeDefined()
    expect(field(wrapper).element.value).toBe('decidion')
    expect(skipBtn(wrapper).element.disabled).toBe(true) // the text went first: only the text goes again

    await checkBtn(wrapper).trigger('click')
    expect(wrapper.emitted('answer')).toEqual([[{ text: 'decidion' }], [{ text: 'decidion' }]])
    wrapper.unmount()
  })

  it('after a lost "I don\'t know" offers only "I don\'t know" again', async () => {
    const wrapper = render()
    await field(wrapper).setValue('decision')
    await skipBtn(wrapper).trigger('click')
    await wrapper.setProps({ disabled: true })
    await wrapper.setProps({ disabled: false })

    expect(checkBtn(wrapper).element.disabled).toBe(true)
    expect(skipBtn(wrapper).element.disabled).toBe(false)
    await skipBtn(wrapper).trigger('click')
    expect(wrapper.emitted('skip')).toHaveLength(2)
    wrapper.unmount()
  })

  it('a battle locks after the one answer', async () => {
    const wrapper = render({ mode: 'battle' })
    await field(wrapper).setValue('decision')
    await checkBtn(wrapper).trigger('click')

    expect(checkBtn(wrapper).element.disabled).toBe(true)
    expect(skipBtn(wrapper).element.disabled).toBe(true)
    expect(field(wrapper).attributes('readonly')).toBeDefined()
    wrapper.unmount()
  })
})

describe('WordFormInput — the draft', () => {
  it('brings the typed text back when the learner leaves the element and comes back', async () => {
    const first = render()
    await field(first).setValue('decis')
    first.unmount()

    const again = render()
    expect(field(again).element.value).toBe('decis')
    again.unmount()
  })

  it('forgets the text once it went out and the element is over', async () => {
    const wrapper = render()
    await field(wrapper).setValue('decision')
    await checkBtn(wrapper).trigger('click')
    await wrapper.setProps({ disabled: true })
    wrapper.unmount()

    expect(readWordFormDraft('eWord00001')).toBe('')
  })

  it('keeps a text that got no reply: it is the one to send again', async () => {
    const wrapper = render()
    await field(wrapper).setValue('decision')
    await checkBtn(wrapper).trigger('click')
    await wrapper.setProps({ disabled: true })
    await wrapper.setProps({ disabled: false })
    wrapper.unmount()

    expect(readWordFormDraft('eWord00001')).toBe('decision')
  })

  it('another element in the same component starts with its own text', async () => {
    const wrapper = render()
    await field(wrapper).setValue('decision')
    await wrapper.setProps({ element: wordFormElement({ id: 'eWord00002', text: 'She sings ___.', base: 'beautiful' }) })

    expect(field(wrapper).element.value).toBe('')
    expect(wrapper.get('[data-testid=word-form-base]').text()).toBe('beautiful')
    wrapper.unmount()
  })
})
