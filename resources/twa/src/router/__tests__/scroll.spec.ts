import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { createRouter, createWebHistory, type RouteLocationNormalized } from 'vue-router'
import router, { RESTORE_SCROLL_WAIT_MS, scrollBehavior } from '../index'

const place = (path: string) => ({ fullPath: path }) as RouteLocationNormalized

/** The page being `height` px tall in a window `viewport` px high (happy-dom has no layout). */
function setPage(height: number, viewport = 600): void {
  Object.defineProperty(document.documentElement, 'scrollHeight', { configurable: true, get: () => height })
  Object.defineProperty(window, 'innerHeight', { configurable: true, value: viewport })
}

describe('router: scroll position (docs/14 §5.3)', () => {
  it('uses one rule for the whole app', () => {
    expect(router.options.scrollBehavior).toBe(scrollBehavior)
  })

  it('a new screen opens from the top, not on the scroll offset of the previous one', () => {
    expect(scrollBehavior(place('/courses/ielts-core'), place('/courses'), null)).toEqual({ left: 0, top: 0 })
  })

  describe('going back', () => {
    beforeEach(() => {
      vi.useFakeTimers()
      setPage(2000)
    })
    afterEach(() => {
      vi.useRealTimers()
      Object.defineProperty(document.documentElement, 'scrollHeight', { configurable: true, value: 0 })
    })

    const back = (top: number) => scrollBehavior(place('/courses'), place('/courses/ielts-core'), { left: 0, top })

    it('returns to the saved position of a page that is tall enough', async () => {
      const position = back(700)

      await vi.advanceTimersByTimeAsync(60)

      await expect(position).resolves.toEqual({ left: 0, top: 700 })
    })

    it('does not trust the first look: the page may still be the stale one that is about to start loading again', async () => {
      let height = 2000
      Object.defineProperty(document.documentElement, 'scrollHeight', { configurable: true, get: () => height })
      const settled = vi.fn()
      void Promise.resolve(back(700)).then(settled)

      await vi.advanceTimersByTimeAsync(10)
      expect(settled).not.toHaveBeenCalled()

      // The screen starts loading (a skeleton): too short for the saved position.
      height = 650
      await vi.advanceTimersByTimeAsync(40)
      expect(settled).not.toHaveBeenCalled()

      // The data arrives and the page is tall again.
      height = 2000
      await vi.advanceTimersByTimeAsync(60)
      expect(settled).toHaveBeenCalledWith({ left: 0, top: 700 })
    })

    it('waits for the page to fill in, then restores', async () => {
      setPage(500)
      const settled = vi.fn()
      void Promise.resolve(back(700)).then(settled)

      await vi.advanceTimersByTimeAsync(400)
      expect(settled).not.toHaveBeenCalled()

      setPage(1800)
      await vi.advanceTimersByTimeAsync(60)
      expect(settled).toHaveBeenCalledWith({ left: 0, top: 700 })
    })

    it('does not wait for ever for a page that stays too short', async () => {
      setPage(500)
      const settled = vi.fn()
      void Promise.resolve(back(700)).then(settled)

      await vi.advanceTimersByTimeAsync(RESTORE_SCROLL_WAIT_MS - 100)
      expect(settled).not.toHaveBeenCalled()
      await vi.advanceTimersByTimeAsync(200)

      // The browser clamps the position to the end of the page.
      expect(settled).toHaveBeenCalledWith({ left: 0, top: 700 })
    })

    it('restores the very top at once: there is nothing to wait for', () => {
      expect(back(0)).toEqual({ left: 0, top: 0 })
    })

    it('a navigation that begins meanwhile cancels the restore: the learner is already elsewhere', async () => {
      setPage(500)
      const settled = vi.fn()
      void Promise.resolve(back(700)).then(settled)
      await vi.advanceTimersByTimeAsync(100)

      expect(scrollBehavior(place('/profile'), place('/courses'), null)).toEqual({ left: 0, top: 0 })
      setPage(1800)
      await vi.advanceTimersByTimeAsync(100)

      expect(settled).toHaveBeenCalledWith(false)
    })
  })
})

describe('router: scroll position through the browser history', () => {
  const scrollTo = vi.fn()

  beforeEach(() => {
    vi.clearAllMocks()
    setPage(3000)
    window.scrollTo = scrollTo as unknown as typeof window.scrollTo
  })

  /** A router of its own with the app's rule and the real (happy-dom) history, so `history.state.scroll` is saved. */
  function makeRouter() {
    return createRouter({
      history: createWebHistory('/scroll-test/'),
      routes: ['/a', '/b'].map((path) => ({ path, name: path.slice(1), component: { template: '<div />' } })),
      scrollBehavior,
    })
  }

  const scrolledTo = (top: number) => Object.defineProperty(window, 'scrollY', { configurable: true, value: top })

  it('a pushed screen starts at the top and "back" restores where the screen before was left', async () => {
    const r = makeRouter()
    await r.push('/a')
    await flushPromises()
    scrolledTo(420)
    scrollTo.mockClear()

    await r.push('/b')
    await flushPromises()
    expect(scrollTo).toHaveBeenLastCalledWith({ left: 0, top: 0 })

    scrolledTo(90)
    r.back()
    await vi.waitFor(() => expect(r.currentRoute.value.path).toBe('/a'))
    await vi.waitFor(() => expect(scrollTo).toHaveBeenLastCalledWith({ left: 0, top: 420 }))
  })
})
