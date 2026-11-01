import { afterEach, describe, expect, it } from 'vitest'
import { defineComponent, h, nextTick, ref } from 'vue'
import { mount } from '@vue/test-utils'
import BottomSheet from '../BottomSheet.vue'

afterEach(() => {
  document.body.innerHTML = ''
})

const render = (props: { label: string; dismissible?: boolean }, attrs: Record<string, unknown> = {}) =>
  mount(BottomSheet, { props, attrs, slots: { default: '<h2>Title</h2><button type="button" data-testid="inner">Inside</button>' }, attachTo: document.body })

describe('BottomSheet', () => {
  it('is a modal dialog with a name, over a dimmed page, with the content in it', () => {
    const wrapper = render({ label: 'Who do we play with?' })

    const dialog = wrapper.get('[role=dialog]')
    expect(dialog.attributes('aria-modal')).toBe('true')
    expect(dialog.attributes('aria-label')).toBe('Who do we play with?')
    expect(dialog.classes()).toContain('sheet')
    expect(wrapper.get('[data-testid=sheet-backdrop]').classes()).toContain('scrim')
    expect(dialog.get('h2').text()).toBe('Title')
    wrapper.unmount()
  })

  it('gives attributes (a test id, a class) to the dialog, not to the dimmed page', () => {
    const wrapper = render({ label: 'x' }, { 'data-testid': 'friend-picker', class: 'extra' })

    expect(wrapper.get('[role=dialog]').attributes('data-testid')).toBe('friend-picker')
    expect(wrapper.get('[role=dialog]').classes()).toContain('extra')
    expect(wrapper.get('[data-testid=sheet-backdrop]').attributes('data-testid')).toBe('sheet-backdrop')
    wrapper.unmount()
  })

  it('has a close button of the size of a finger: 44 px around a 32 px circle', () => {
    const wrapper = render({ label: 'x' })

    const close = wrapper.get('[data-testid=sheet-x]')
    expect(close.classes()).toContain('size-11')
    expect(close.attributes('aria-label')).toBe('Закрыть')
    expect(close.get('span').classes()).toContain('size-8')
    wrapper.unmount()
  })

  it('closes by the ✕, by a tap on the dimmed page and by Escape', async () => {
    const wrapper = render({ label: 'x' })

    await wrapper.get('[data-testid=sheet-x]').trigger('click')
    await wrapper.get('[data-testid=sheet-backdrop]').trigger('click')
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }))

    expect(wrapper.emitted('close')).toHaveLength(3)
    wrapper.unmount()
  })

  it('a tap inside the sheet does not close it', async () => {
    const wrapper = render({ label: 'x' })

    await wrapper.get('[data-testid=inner]').trigger('click')
    await wrapper.get('[role=dialog]').trigger('click')

    expect(wrapper.emitted('close')).toBeUndefined()
    wrapper.unmount()
  })

  it('a sheet that must be answered has no ✕ and does not close by the page or Escape', async () => {
    const wrapper = render({ label: 'x', dismissible: false })

    expect(wrapper.find('[data-testid=sheet-x]').exists()).toBe(false)
    await wrapper.get('[data-testid=sheet-backdrop]').trigger('click')
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))

    expect(wrapper.emitted('close')).toBeUndefined()
    wrapper.unmount()
  })

  it('takes the focus when it opens and gives it back to what had it when it closes', async () => {
    const opener = document.createElement('button')
    document.body.appendChild(opener)
    opener.focus()
    const open = ref(true)
    const Host = defineComponent({
      setup: () => () => (open.value ? h(BottomSheet, { label: 'x' }, { default: () => h('p', 'hi') }) : null),
    })

    const wrapper = mount(Host, { attachTo: document.body })
    await nextTick()
    await nextTick()
    expect(document.activeElement).toBe(wrapper.get('[role=dialog]').element)

    open.value = false
    await nextTick()
    expect(document.activeElement).toBe(opener)
    wrapper.unmount()
  })

  it('stops listening for Escape when it is gone', async () => {
    const open = ref(true)
    const closed: number[] = []
    const Host = defineComponent({
      setup: () => () => (open.value ? h(BottomSheet, { label: 'x', onClose: () => closed.push(1) }) : null),
    })
    const wrapper = mount(Host, { attachTo: document.body })

    open.value = false
    await nextTick()
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))

    expect(closed).toEqual([])
    wrapper.unmount()
  })
})
