import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ActiveSessionSheet from '../ActiveSessionSheet.vue'
import { setLocale } from '@/i18n'
import { activeSession } from '@/api/__tests__/practiceFixtures'

afterEach(() => setLocale('ru'))

const render = (props: InstanceType<typeof ActiveSessionSheet>['$props']) => mount(ActiveSessionSheet, { props, attachTo: document.body })

describe('ActiveSessionSheet — "Unfinished practice"', () => {
  it('names the session that is on: its mode and how far it got', () => {
    const wrapper = render({ session: activeSession({ mode: 'sentence', progress: { done: 2, total: 5 } }) })

    expect(wrapper.get('[role=dialog]').attributes('aria-label')).toBe('Незавершённая практика')
    expect(wrapper.get('[data-testid=active-title]').text()).toBe('Незавершённая практика')
    expect(wrapper.get('[data-testid=active-text]').text()).toBe('Собери предложение: выполнено 2 из 5. Продолжите её или начните новую — прежняя закроется.')
    wrapper.unmount()
  })

  it('asks "Continue" (primary) or "Start a new one" (tinted), and has a way out', async () => {
    const wrapper = render({ session: activeSession() })

    expect(wrapper.get('[data-testid=active-continue]').classes()).toContain('btn-primary')
    expect(wrapper.get('[data-testid=active-continue]').text()).toBe('Продолжить')
    expect(wrapper.get('[data-testid=active-replace]').classes()).toContain('btn-tinted')
    expect(wrapper.get('[data-testid=active-replace]').text()).toBe('Начать новую')
    expect(wrapper.get('[data-testid=active-cancel]').text()).toBe('Отмена')
    expect(wrapper.findAll('.btn-primary')).toHaveLength(1)

    await wrapper.get('[data-testid=active-continue]').trigger('click')
    await wrapper.get('[data-testid=active-replace]').trigger('click')
    await wrapper.get('[data-testid=active-cancel]').trigger('click')
    expect(wrapper.emitted('continue')).toHaveLength(1)
    expect(wrapper.emitted('replace')).toHaveLength(1)
    expect(wrapper.emitted('close')).toHaveLength(1)
    wrapper.unmount()
  })

  it('the ✕ and Escape change nothing: they close the question', async () => {
    const wrapper = render({ session: activeSession() })

    await wrapper.get('[data-testid=sheet-x]').trigger('click')
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))

    expect(wrapper.emitted('close')).toHaveLength(2)
    expect(wrapper.emitted('replace')).toBeUndefined()
    expect(wrapper.emitted('continue')).toBeUndefined()
    wrapper.unmount()
  })

  it('while the new session is being opened the two answers wait', () => {
    const wrapper = render({ session: activeSession(), busy: true })

    expect(wrapper.get('[data-testid=active-continue]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid=active-replace]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid=active-cancel]').attributes('disabled')).toBeUndefined()
    wrapper.unmount()
  })

  it('speaks Uzbek', () => {
    setLocale('uz')

    const wrapper = render({ session: activeSession({ mode: 'pairs', progress: { done: 1, total: 3 } }) })

    expect(wrapper.get('[data-testid=active-title]').text()).toBe('Tugallanmagan amaliyot')
    expect(wrapper.get('[data-testid=active-text]').text()).toContain('Juftlarni toping: 3 tadan 1 tasi bajarildi.')
    wrapper.unmount()
  })
})
