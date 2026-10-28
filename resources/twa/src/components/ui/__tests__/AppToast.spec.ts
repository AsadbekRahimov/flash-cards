import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import AppToast from '../AppToast.vue'
import { useToast } from '@/composables/useToast'

describe('AppToast', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => {
    useToast().hide()
    vi.useRealTimers()
  })

  it('shows nothing until a toast is requested', () => {
    const w = mount(AppToast)

    expect(w.find('[data-testid=toast]').exists()).toBe(false)
  })

  it('has its live region in the page before any message, empty: a region that appears with its text is not announced', () => {
    const w = mount(AppToast)

    const region = w.get('[data-testid=toast-region]')
    expect(region.attributes('role')).toBe('status')
    expect(region.text()).toBe('')
    expect(region.find('[data-testid=toast]').exists()).toBe(false)
  })

  it('puts the message of useToast().show() into that region, the same element before and after', async () => {
    const w = mount(AppToast)
    const regionBefore = w.get('[data-testid=toast-region]').element

    useToast().show('Сохранено')
    await nextTick()

    const region = w.get('[data-testid=toast-region]')
    expect(region.element).toBe(regionBefore)
    const toast = region.get('[data-testid=toast]')
    expect(toast.text()).toBe('Сохранено')
    expect(toast.classes()).toContain('toast')
    // One live region only: a status inside a status would be read twice.
    expect(toast.attributes('role')).toBeUndefined()
    expect(w.findAll('[role=status]')).toHaveLength(1)
  })

  it('takes the new text when another message replaces it', async () => {
    const w = mount(AppToast, { global: { stubs: { transition: false } } })

    useToast().show('Скопировано')
    await nextTick()
    expect(w.get('[data-testid=toast]').text()).toBe('Скопировано')

    useToast().show('Сохранено')
    await nextTick()
    expect(w.findAll('[data-testid=toast]').some((t) => t.text() === 'Сохранено')).toBe(true)
  })

  it('goes away after its time', async () => {
    const w = mount(AppToast, { global: { stubs: { transition: true } } })

    useToast().show('Сохранено', 1000)
    await nextTick()
    expect(w.find('[data-testid=toast]').exists()).toBe(true)

    vi.advanceTimersByTime(1000)
    await nextTick()

    expect(w.find('[data-testid=toast]').exists()).toBe(false)
    // The region stays, ready for the next message.
    expect(w.find('[data-testid=toast-region]').exists()).toBe(true)
  })
})
