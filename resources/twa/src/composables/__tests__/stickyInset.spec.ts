import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ACTIONS_HEIGHT_VAR, trackActionsHeight as track } from '../stickyInset'

/** A block whose height the test sets (happy-dom has no layout, `offsetHeight` is always 0). */
function block(initial: number) {
  const el = document.createElement('div')
  const box = { height: initial }
  Object.defineProperty(el, 'offsetHeight', { configurable: true, get: () => box.height })
  return { el, box }
}

const published = () => document.documentElement.style.getPropertyValue(ACTIONS_HEIGHT_VAR)

/** The observers the module made, so the test can say "the block has been resized". */
let observers: { callback: () => void; target: Element | null; disconnected: boolean }[] = []

class FakeResizeObserver {
  private readonly seen = { callback: () => {}, target: null as Element | null, disconnected: false }
  constructor(callback: () => void) {
    this.seen.callback = callback
    observers.push(this.seen)
  }
  observe(target: Element): void {
    this.seen.target = target
  }
  disconnect(): void {
    this.seen.disconnected = true
  }
}

/** The blocks a test has started to follow: what it leaves behind is stopped, the module keeps them between tests. */
let running: (() => void)[] = []

/** Follows a block until the end of the test (or until the test stops it itself). */
function trackActionsHeight(el: HTMLElement): () => void {
  const stop = track(el)
  running.push(stop)
  return stop
}

beforeEach(() => {
  observers = []
  running = []
  vi.stubGlobal('ResizeObserver', FakeResizeObserver)
})
afterEach(() => {
  for (const stop of running) stop()
  vi.unstubAllGlobals()
  document.documentElement.style.removeProperty(ACTIONS_HEIGHT_VAR)
})

describe('trackActionsHeight', () => {
  it('is the variable that the toast of style.css stands on', () => {
    expect(ACTIONS_HEIGHT_VAR).toBe('--app-actions-h')
  })

  it('publishes the height of the block on <html>, and takes it back when it stops', () => {
    const { el } = block(136)

    const stop = trackActionsHeight(el)
    expect(published()).toBe('136px')

    stop()
    expect(published()).toBe('')
    expect(document.documentElement.style.cssText).not.toContain(ACTIONS_HEIGHT_VAR)
  })

  it('follows the block as it grows and shrinks (a button comes, the window turns)', () => {
    const { el, box } = block(80)
    trackActionsHeight(el)
    expect(observers).toHaveLength(1)
    expect(observers[0]?.target).toBe(el)

    box.height = 192
    observers[0]?.callback()
    expect(published()).toBe('192px')

    box.height = 80
    observers[0]?.callback()
    expect(published()).toBe('80px')
  })

  it('stops watching the block when it stops', () => {
    const { el } = block(80)

    trackActionsHeight(el)()

    expect(observers[0]?.disconnected).toBe(true)
  })

  it('publishes nothing for a block that has no height (not laid out, hidden): the toast keeps its place', () => {
    const { el } = block(0)

    const stop = trackActionsHeight(el)

    expect(published()).toBe('')
    stop()
  })

  it('with two blocks on screen the toast clears the taller one, and the other one stays when it goes', () => {
    const low = block(80)
    const tall = block(192)
    const stopLow = trackActionsHeight(low.el)
    const stopTall = trackActionsHeight(tall.el)
    expect(published()).toBe('192px')

    stopTall()
    expect(published()).toBe('80px')

    stopLow()
    expect(published()).toBe('')
  })

  it('a block that stops twice leaves the other one alone', () => {
    const a = block(80)
    const b = block(120)
    const stopA = trackActionsHeight(a.el)
    trackActionsHeight(b.el)

    stopA()
    stopA()

    expect(published()).toBe('120px')
  })

  it('where there is no ResizeObserver the height at the moment of showing is what is published', () => {
    vi.unstubAllGlobals()
    vi.stubGlobal('ResizeObserver', undefined)
    const { el } = block(96)

    const stop = trackActionsHeight(el)

    expect(published()).toBe('96px')
    stop()
    expect(published()).toBe('')
  })
})
