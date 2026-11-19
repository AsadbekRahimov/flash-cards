import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
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

  it('selecting a word changes only its look, never its size: the same elements in the flow, the same layout classes', async () => {
    const wrapper = render()
    /** The markup that makes the size of the word: its elements and their classes, all but the look (colour, frame, background). */
    const shape = (id: string) => {
      const button = token(wrapper, id).element as HTMLElement
      // The look: background, colour of the text (not its size, `text-[17px]`), frame (an inset shadow), dimming.
      const layout = [...button.classList].filter((name) => !/^(bg-|text-(?!\[)|shadow-|opacity-)/.test(name))
      return {
        classes: layout,
        children: [...button.children].map((child) => ({ tag: child.tagName, classes: [...child.classList], hidden: (child as HTMLElement).style.display === 'none' })),
      }
    }
    const before = shape('tAm1')

    await token(wrapper, 'tAm1').trigger('click')
    const after = shape('tAm1')

    expect(token(wrapper, 'tAm1').attributes('aria-pressed')).toBe('true')
    expect(after.classes).toEqual(before.classes)
    expect(after.children.map(({ tag, classes }) => ({ tag, classes }))).toEqual(before.children.map(({ tag, classes }) => ({ tag, classes })))
    // The only thing that appears is the symbol in the corner, out of the flow and silent for a screen reader.
    const mark = token(wrapper, 'tAm1').get('[data-testid=token-mark]')
    expect(before.children.find((child) => child.classes.includes('absolute'))?.hidden).toBe(true)
    expect(mark.isVisible()).toBe(true)
    expect(mark.classes()).toContain('absolute')
    expect(mark.attributes('aria-hidden')).toBe('true')
    expect(token(wrapper, 'tAm1').classes()).toContain('relative')
    // Every element in the flow of the button is the same before and after: only the word itself.
    expect(token(wrapper, 'tAm1').findAll(':scope > :not([data-testid=token-mark])').map((child) => child.text())).toEqual(['am'])
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

  it('brings the corrections into view above the sticky buttons when the step comes', async () => {
    const scroll = vi.fn()
    Element.prototype.scrollIntoView = scroll
    const wrapper = render()
    await token(wrapper, 'tAm1').trigger('click')
    await confirmBtn(wrapper).trigger('click')
    expect(scroll).not.toHaveBeenCalled()

    await wrapper.setProps({ element: errorHuntFixElement('tAm1') })
    await nextTick()

    const options = wrapper.get('[data-testid=error-hunt-options]')
    expect(scroll).toHaveBeenCalledTimes(1)
    expect(scroll.mock.contexts[0]).toBe(options.element)
    expect(scroll).toHaveBeenCalledWith({ block: 'nearest', behavior: 'smooth' })
    // The margin of the height of the sticky buttons: the last option ends above them.
    expect(options.attributes('style')).toContain('scroll-margin-bottom: calc(var(--app-actions-h, 0px) + 12px)')
    wrapper.unmount()
    Reflect.deleteProperty(Element.prototype, 'scrollIntoView')
  })

  it('scrolls without animation when the device asks for reduced motion, and on a screen opened at the second step', async () => {
    const scroll = vi.fn()
    Element.prototype.scrollIntoView = scroll
    vi.stubGlobal('matchMedia', (query: string) => ({ matches: query === '(prefers-reduced-motion: reduce)' }))
    const wrapper = render({ element: errorHuntFixElement('tAm1') })
    await nextTick()

    expect(scroll).toHaveBeenCalledTimes(1)
    expect(scroll).toHaveBeenCalledWith({ block: 'nearest', behavior: 'auto' })
    wrapper.unmount()
    vi.unstubAllGlobals()
    Reflect.deleteProperty(Element.prototype, 'scrollIntoView')
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
