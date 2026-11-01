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
