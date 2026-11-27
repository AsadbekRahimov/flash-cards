import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick, ref } from 'vue'
import { mount } from '@vue/test-utils'
import { useRevealInView } from '../useRevealInView'

/** A card with the right option revealed; `verdict` is the server's answer. */
function mountCard(verdict = ref<boolean | null>(null)) {
  const scrollIntoView = vi.fn()
  const Card = defineComponent({
    setup() {
      const root = ref<HTMLElement | null>(null)
      useRevealInView(root, verdict)
      return () => h('div', { ref: root }, [h('button', { role: 'radio', 'data-state': 'reveal' })])
    },
  })
  const wrapper = mount(Card)
  ;(wrapper.get('[role=radio]').element as HTMLElement).scrollIntoView = scrollIntoView
  return { wrapper, verdict, scrollIntoView }
}

describe('useRevealInView', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('scrolls the right option into view on the next frame after a miss', async () => {
    const frames: FrameRequestCallback[] = []
    vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) => frames.push(callback))
    vi.stubGlobal('cancelAnimationFrame', vi.fn())
    const { verdict, scrollIntoView } = mountCard()

    verdict.value = false
    await nextTick()
    expect(scrollIntoView).not.toHaveBeenCalled()
    frames.forEach((callback) => callback(0))

    expect(scrollIntoView).toHaveBeenCalledWith({ block: 'nearest', behavior: 'smooth' })
  })

  it('cancels the frame it asked for when the card goes before it runs', async () => {
    vi.stubGlobal('requestAnimationFrame', () => 42)
    const cancel = vi.fn()
    vi.stubGlobal('cancelAnimationFrame', cancel)
    const { wrapper, verdict } = mountCard()

    verdict.value = false
    await nextTick()
    wrapper.unmount()

    expect(cancel).toHaveBeenCalledWith(42)
  })
})
