import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import StickyActions from '../StickyActions.vue'
import { ACTIONS_HEIGHT_VAR } from '@/composables/stickyInset'

const published = () => document.documentElement.style.getPropertyValue(ACTIONS_HEIGHT_VAR)

let height = 0
let callbacks: (() => void)[] = []

class FakeResizeObserver {
  constructor(callback: () => void) {
    callbacks.push(callback)
  }
  observe(): void {}
  disconnect(): void {}
}

beforeEach(() => {
  height = 136
  callbacks = []
  vi.stubGlobal('ResizeObserver', FakeResizeObserver)
  vi.spyOn(HTMLElement.prototype, 'offsetHeight', 'get').mockImplementation(() => height)
})
afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.documentElement.style.removeProperty(ACTIONS_HEIGHT_VAR)
})

describe('StickyActions', () => {
  it('holds the buttons of the screen at the bottom: sticky, page-coloured, with the inset of the home indicator', () => {
    const w = mount(StickyActions, { slots: { default: '<button type="button">Check</button>' } })

    const block = w.get('[data-testid=actions]')
    expect(block.text()).toBe('Check')
    expect(block.classes()).toEqual(expect.arrayContaining(['sticky', 'bottom-0', 'mt-auto', 'bg-page']))
    expect(block.attributes('style')).toContain('var(--app-safe-b)')
    w.unmount()
  })

  it('takes the card colour on a white screen (`surface`), instead of the page colour, unless the screen names its own (`--app-screen`)', () => {
    const w = mount(StickyActions, { props: { surface: true }, slots: { default: '<button type="button">Check</button>' } })

    const block = w.get('[data-testid=actions]')
    expect(block.classes()).toContain('bg-[var(--app-screen,var(--app-surface))]')
    expect(block.classes()).not.toContain('bg-page')
    expect(block.classes()).toEqual(expect.arrayContaining(['px-5', 'pt-3']))
    w.unmount()
  })

  it('is the feedback sheet of the kit with a `tone`: the soft colour, the top corners 28, padding 20; it slides up once', () => {
    const animate = vi.fn()
    Object.defineProperty(HTMLElement.prototype, 'animate', { value: animate, configurable: true, writable: true })
    try {
      const danger = mount(StickyActions, { props: { tone: 'danger' }, slots: { default: '<button type="button">Next</button>' } })
      const block = danger.get('[data-testid=actions]')
      expect(block.classes()).toEqual(expect.arrayContaining(['bg-danger-soft', 'rounded-t-xl', 'px-5', 'pt-5', 'sticky', 'bottom-0']))
      expect(block.classes()).not.toContain('bg-page')
      expect(block.attributes('data-tone')).toBe('danger')
      expect(animate).toHaveBeenCalledTimes(1)
      expect(animate.mock.calls[0]?.[0]).toEqual([{ transform: 'translateY(100%)' }, { transform: 'translateY(0)' }])
      danger.unmount()

      const success = mount(StickyActions, { props: { tone: 'success' } })
      expect(success.get('[data-testid=actions]').classes()).toContain('bg-success-soft')
      success.unmount()

      // The plain block does not move.
      mount(StickyActions).unmount()
      expect(animate).toHaveBeenCalledTimes(2)
    } finally {
      delete (HTMLElement.prototype as { animate?: unknown }).animate
    }
  })

  it('tells the toast how high it is while it is on screen, and takes that back when it goes', () => {
    expect(published()).toBe('')

    const w = mount(StickyActions, { slots: { default: '<button type="button">Check</button>' }, attachTo: document.body })
    expect(published()).toBe('136px')

    w.unmount()
    expect(published()).toBe('')
  })

  it('follows its height: a button that comes (a rematch is offered) lifts the toast higher', () => {
    const w = mount(StickyActions, { slots: { default: '<button type="button">Check</button>' }, attachTo: document.body })

    height = 192
    for (const callback of callbacks) callback()

    expect(published()).toBe('192px')
    w.unmount()
  })
})
