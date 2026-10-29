import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AudioTrouble from '../AudioTrouble.vue'
import { setLocale } from '@/i18n'

afterEach(() => setLocale('ru'))

const render = (props: { replacementsLeft: number; busy?: boolean }) => mount(AudioTrouble, { props, attachTo: document.body })

describe('AudioTrouble', () => {
  it('is a dialog that says the sound is not available, and what the learner can do about it', () => {
    const wrapper = render({ replacementsLeft: 2 })

    const dialog = wrapper.get('[role=dialog]')
    expect(dialog.attributes('aria-modal')).toBe('true')
    expect(dialog.attributes('aria-label')).toBe('Звук недоступен')
    expect(wrapper.get('[data-testid=trouble-title]').text()).toBe('Звук недоступен')
    expect(wrapper.get('[data-testid=trouble-text]').text()).toContain('штрафа не будет')
    expect(wrapper.get('[data-testid=replacements-left]').text()).toBe('Замен осталось: 2')
    wrapper.unmount()
  })

  it('has three ways on: retry (primary), replace (tinted), go on without sound (plain)', async () => {
    const wrapper = render({ replacementsLeft: 1 })

    expect(wrapper.get('[data-testid=trouble-retry]').classes()).toContain('btn-primary')
    expect(wrapper.get('[data-testid=trouble-retry]').text()).toBe('Повторить загрузку')
    expect(wrapper.get('[data-testid=trouble-replace]').classes()).toContain('btn-tinted')
    expect(wrapper.get('[data-testid=trouble-replace]').text()).toBe('Заменить задание')
    expect(wrapper.get('[data-testid=trouble-silent]').classes()).toContain('btn-plain')
    expect(wrapper.get('[data-testid=trouble-silent]').text()).toBe('Сейчас без звука')
    expect(wrapper.findAll('.btn-primary')).toHaveLength(1)

    await wrapper.get('[data-testid=trouble-retry]').trigger('click')
    await wrapper.get('[data-testid=trouble-replace]').trigger('click')
    await wrapper.get('[data-testid=trouble-silent]').trigger('click')
    expect(wrapper.emitted('retry')).toHaveLength(1)
    expect(wrapper.emitted('replace')).toHaveLength(1)
    expect(wrapper.emitted('silent')).toHaveLength(1)
    wrapper.unmount()
  })

  it('with no replacements left it does not offer one, and says why', () => {
    const wrapper = render({ replacementsLeft: 0 })

    expect(wrapper.find('[data-testid=trouble-replace]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=replacements-left]').exists()).toBe(false)
    expect(wrapper.get('[data-testid=trouble-text]').text()).toContain('замен больше нет')
    expect(wrapper.find('[data-testid=trouble-retry]').exists()).toBe(true)
    expect(wrapper.find('[data-testid=trouble-silent]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('the ✕, the dimmed page and Escape only close the sheet', async () => {
    const wrapper = render({ replacementsLeft: 2 })

    await wrapper.get('[data-testid=sheet-x]').trigger('click')
    await wrapper.get('[data-testid=sheet-backdrop]').trigger('click')
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))

    expect(wrapper.emitted('close')).toHaveLength(3)
    expect(wrapper.emitted('retry')).toBeUndefined()
    expect(wrapper.emitted('silent')).toBeUndefined()
    wrapper.unmount()
  })

  it('while a retry or a swap is on its way the two that start one wait', () => {
    const wrapper = render({ replacementsLeft: 2, busy: true })

    expect(wrapper.get('[data-testid=trouble-retry]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid=trouble-replace]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid=trouble-silent]').attributes('disabled')).toBeUndefined()
    wrapper.unmount()
  })

  it('speaks Uzbek', () => {
    setLocale('uz')

    const wrapper = render({ replacementsLeft: 2 })

    expect(wrapper.get('[data-testid=trouble-title]').text()).toBe('Ovoz mavjud emas')
    expect(wrapper.get('[data-testid=trouble-retry]').text()).toBe('Qayta yuklash')
    wrapper.unmount()
  })
})
