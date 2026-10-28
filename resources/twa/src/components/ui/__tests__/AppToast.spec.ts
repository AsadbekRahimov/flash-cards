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

  it('shows the message of useToast().show() as a status', async () => {
    const w = mount(AppToast)

    useToast().show('Сохранено')
    await nextTick()

    const toast = w.get('[data-testid=toast]')
    expect(toast.text()).toBe('Сохранено')
    expect(toast.attributes('role')).toBe('status')
    expect(toast.classes()).toContain('toast')
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
  })
})
