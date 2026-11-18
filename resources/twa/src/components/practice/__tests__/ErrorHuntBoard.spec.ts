import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import ErrorHuntBoard from '../ErrorHuntBoard.vue'
import { setLocale } from '@/i18n'
import { errorHuntElement, errorHuntFixElement } from '@/api/__tests__/practiceFixtures'
import type { ErrorHuntElement } from '@/api/types'

vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => ({ selectionHaptic: vi.fn() }) }))

afterEach(() => setLocale('ru'))

interface Options {
  element?: ErrorHuntElement
  mode?: 'solo' | 'battle'
  disabled?: boolean
}

const render = ({ element = errorHuntElement(), mode = 'solo', disabled = false }: Options = {}): VueWrapper =>
  mount(ErrorHuntBoard, { props: { element, mode, disabled }, attachTo: document.body })

const token = (wrapper: VueWrapper, id: string) => wrapper.get(`[data-testid=token-${id}]`)
const confirmBtn = (wrapper: VueWrapper) => wrapper.get<HTMLButtonElement>('[data-testid=confirm-btn]')
const skipBtn = (wrapper: VueWrapper) => wrapper.get<HTMLButtonElement>('[data-testid=skip-btn]')

/** The screen takes the lock (the request goes) and lets go of it with the element as it was: no reply came. */
async function lostReply(wrapper: VueWrapper): Promise<void> {
  await wrapper.setProps({ disabled: true })
  await wrapper.setProps({ disabled: false })
}

describe('ErrorHuntBoard — step `locate`', () => {
  it('draws the words of the sentence as buttons in a list that wraps, with no corrections yet', () => {
    const wrapper = render()

    expect(wrapper.get('[data-testid=error-hunt-board]').attributes('data-step')).toBe('locate')
    expect(wrapper.get('[data-testid=error-hunt-instruction]').text()).toBe('Найдите слово с ошибкой')
    const list = wrapper.get('[data-testid=error-hunt-tokens]')
    expect(list.attributes('role')).toBe('list')
    expect(list.attributes('aria-label')).toBe('Слова предложения')
    expect(list.attributes('lang')).toBe('en')
    expect(list.classes()).toContain('flex-wrap')

    const buttons = list.findAll('button')
    expect(buttons.map((button) => button.text())).toEqual(['I', 'am', 'very', 'interested', 'on', 'modern', 'art.'])
    for (const button of buttons) {
      expect(button.attributes('type')).toBe('button')
      expect(button.attributes('aria-pressed')).toBe('false')
      expect(button.classes()).toContain('min-h-11') // 44 px to press
      expect(button.classes()).toContain('min-w-11')
    }
    expect(wrapper.find('[data-testid=error-hunt-options]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=error-hunt-located]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('a tap selects a word (pressed, marked) and sends nothing; "This is the mistake" sends it', async () => {
    const wrapper = render()

    expect(confirmBtn(wrapper).element.disabled).toBe(true)
    expect(confirmBtn(wrapper).text()).toBe('Это ошибка')
    await token(wrapper, 'tAm1').trigger('click')

    expect(token(wrapper, 'tAm1').attributes('aria-pressed')).toBe('true')
    expect(token(wrapper, 'tAm1').attributes('data-look')).toBe('selected')
    expect(wrapper.emitted('answer')).toBeUndefined()

    // Another word takes the selection over.
    await token(wrapper, 'tOn4').trigger('click')
    expect(token(wrapper, 'tAm1').attributes('aria-pressed')).toBe('false')
    expect(token(wrapper, 'tOn4').attributes('aria-pressed')).toBe('true')

    await confirmBtn(wrapper).trigger('click')
    expect(wrapper.emitted('answer')).toEqual([[{ token_id: 'tOn4' }]])
    wrapper.unmount()
  })

  it('a second tap on the selected word takes the selection back', async () => {
    const wrapper = render()

    await token(wrapper, 'tAm1').trigger('click')
    await token(wrapper, 'tAm1').trigger('click')

    expect(token(wrapper, 'tAm1').attributes('aria-pressed')).toBe('false')
    expect(confirmBtn(wrapper).element.disabled).toBe(true)
    wrapper.unmount()
  })

  it('"I don\'t know" is a skip', async () => {
    const wrapper = render()

    await skipBtn(wrapper).trigger('click')

    expect(wrapper.emitted('skip')).toHaveLength(1)
    expect(wrapper.emitted('answer')).toBeUndefined()
    wrapper.unmount()
  })

  it('nothing reacts while an answer is on its way', async () => {
    const wrapper = render({ disabled: true })

    await token(wrapper, 'tAm1').trigger('click')
    expect(token(wrapper, 'tAm1').attributes('aria-pressed')).toBe('false')
    expect(confirmBtn(wrapper).element.disabled).toBe(true)
    expect(skipBtn(wrapper).element.disabled).toBe(true)
    wrapper.unmount()
  })

  it('after a lost reply keeps the word and sends the same one again', async () => {
    const wrapper = render()
    await token(wrapper, 'tAm1').trigger('click')
    await confirmBtn(wrapper).trigger('click')
    await lostReply(wrapper)

    expect(wrapper.find('[data-testid=error-hunt-resend-note]').exists()).toBe(true)
    await token(wrapper, 'tOn4').trigger('click') // the pick cannot change
    expect(token(wrapper, 'tAm1').attributes('aria-pressed')).toBe('true')
    expect(skipBtn(wrapper).element.disabled).toBe(true)

    await confirmBtn(wrapper).trigger('click')
    expect(wrapper.emitted('answer')).toEqual([[{ token_id: 'tAm1' }], [{ token_id: 'tAm1' }]])
    wrapper.unmount()
  })
})

describe('ErrorHuntBoard — step `fix`', () => {
  it('turns to the corrections when the element comes back at its second step, after a wrong search', async () => {
    const wrapper = render()
    await token(wrapper, 'tAm1').trigger('click')
    await confirmBtn(wrapper).trigger('click')
    await wrapper.setProps({ disabled: true })
    // The reply of `locate`: the same element, at its step `fix`, with the corrections.
    await wrapper.setProps({ element: errorHuntFixElement('tAm1'), disabled: false })

    expect(wrapper.get('[data-testid=error-hunt-board]').attributes('data-step')).toBe('fix')
    expect(wrapper.get('[data-testid=error-hunt-instruction]').text()).toBe('Как исправить?')
    expect(wrapper.find('[data-testid=error-hunt-resend-note]').exists()).toBe(false) // a new step, not a lost reply
    expect(wrapper.find('[data-testid=confirm-btn]').exists()).toBe(false)

    // Your word: red, a cross, and words for a screen reader; the real one: an orange frame and "mistake".
    const yours = token(wrapper, 'tAm1')
    expect(yours.element.tagName).toBe('SPAN')
    expect(yours.attributes('data-look')).toBe('yours')
    expect(yours.text()).toContain('ваш выбор')
    const error = token(wrapper, 'tOn4')
    expect(error.attributes('data-look')).toBe('error')
    expect(error.text()).toContain('ошибка')
    expect(token(wrapper, 'tVery2').attributes('data-look')).toBe('idle')

    const located = wrapper.get('[data-testid=error-hunt-located]')
    expect(located.attributes('role')).toBe('status')
    expect(located.attributes('data-ok')).toBe('false')
    expect(located.text()).toBe('Ошибка в другом слове')

    const options = wrapper.get('[data-testid=error-hunt-options]')
    expect(options.attributes('role')).toBe('group')
    expect(options.findAll('button').map((button) => button.text())).toEqual(['at', 'in', 'for'])
    wrapper.unmount()
  })

  it('a word found is green with a check and "Right, the mistake is here"', () => {
    const wrapper = render({ element: errorHuntFixElement('tOn4') })

    expect(token(wrapper, 'tOn4').attributes('data-look')).toBe('found')
    expect(wrapper.findAll('[data-look=yours]')).toHaveLength(0)
    expect(wrapper.get('[data-testid=error-hunt-located]').text()).toBe('Верно, ошибка здесь')
    expect(wrapper.get('[data-testid=error-hunt-located]').attributes('data-ok')).toBe('true')
    wrapper.unmount()
  })

  it('a tap on a correction is the answer, and locks the others', async () => {
    const wrapper = render({ element: errorHuntFixElement() })

    await wrapper.get('[data-testid=option-1]').trigger('click')
    expect(wrapper.emitted('answer')).toEqual([[{ option_id: 'oIn2' }]])
    expect(wrapper.get('[data-testid=option-1]').attributes('data-state')).toBe('selected')
    expect(wrapper.get('[data-testid=option-1]').attributes('aria-pressed')).toBe('true')

    await wrapper.get('[data-testid=option-0]').trigger('click')
    expect(wrapper.emitted('answer')).toHaveLength(1)
    wrapper.unmount()
  })

  it('"I don\'t know" is there at the second step too', async () => {
    const wrapper = render({ element: errorHuntFixElement() })

    await skipBtn(wrapper).trigger('click')
    expect(wrapper.emitted('skip')).toHaveLength(1)
    wrapper.unmount()
  })

  it('after a lost reply of a correction offers "Retry" with the same one', async () => {
    const wrapper = render({ element: errorHuntFixElement() })
    await wrapper.get('[data-testid=option-2]').trigger('click')
    await lostReply(wrapper)

    expect(wrapper.find('[data-testid=error-hunt-resend-note]').exists()).toBe(true)
    await wrapper.get('[data-testid=option-0]').trigger('click')
    await wrapper.get('[data-testid=resend-btn]').trigger('click')

    expect(wrapper.emitted('answer')).toEqual([[{ option_id: 'oFor3' }], [{ option_id: 'oFor3' }]])
    wrapper.unmount()
  })

  it('opens at the second step straight away (a reload in the middle of the task)', () => {
    const wrapper = render({ element: errorHuntFixElement('tAm1') })

    expect(wrapper.get('[data-testid=error-hunt-board]').attributes('data-step')).toBe('fix')
    expect(wrapper.findAll('[data-testid=error-hunt-options] button')).toHaveLength(3)
    wrapper.unmount()
  })

  it('speaks Uzbek', () => {
    setLocale('uz')
    const wrapper = render({ element: errorHuntFixElement('tAm1') })

    expect(wrapper.get('[data-testid=error-hunt-instruction]').text()).toBe('Qanday tuzatiladi?')
    expect(wrapper.get('[data-testid=error-hunt-located]').text()).toBe("Xato boshqa so'zda")
    wrapper.unmount()
  })
})
