import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useBackButton, type BackButtonTarget } from '../useBackButton'

const tg = vi.hoisted(() => ({
  handler: null as (() => void) | null,
  release: vi.fn(),
  showBackButton: vi.fn(),
  hide: vi.fn(),
}))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ showBackButton: tg.showBackButton, webApp: { BackButton: { hide: tg.hide } } }),
}))

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: { template: '<div />' } },
      { path: '/courses', name: 'courses', component: { template: '<div />' } },
      { path: '/profile', name: 'profile', component: { template: '<div />' } },
    ],
  })
}

function mountScreen(target: BackButtonTarget, router = makeRouter()) {
  const Screen = defineComponent({
    setup() {
      useBackButton(target)
      return () => h('div')
    },
  })
  return { wrapper: mount(Screen, { global: { plugins: [router] } }), router }
}

describe('useBackButton', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    tg.handler = null
    tg.showBackButton.mockImplementation((handler: () => void) => {
      tg.handler = handler
      return tg.release
    })
  })

  it('shows the button on mount and pushes the route on press', async () => {
    const { router } = mountScreen({ name: 'courses' })
    await router.push('/')

    expect(tg.showBackButton).toHaveBeenCalledTimes(1)
    expect(tg.hide).not.toHaveBeenCalled()

    tg.handler?.()
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('courses')
  })

  it('accepts a path string and a location with params', async () => {
    const { router } = mountScreen('/profile')
    await router.push('/')

    tg.handler?.()
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/profile')
  })

  it('calls a function target instead of navigating', async () => {
    const onBack = vi.fn()
    const { router } = mountScreen(onBack)
    await router.push('/')

    tg.handler?.()
    tg.handler?.()
    await flushPromises()

    expect(onBack).toHaveBeenCalledTimes(2)
    expect(router.currentRoute.value.name).toBe('home')
  })

  it('hides the button for a null target (tab root) and shows nothing', () => {
    mountScreen(null)

    expect(tg.hide).toHaveBeenCalledTimes(1)
    expect(tg.showBackButton).not.toHaveBeenCalled()
  })

  it('removes the handler (and hides the button) on unmount', () => {
    const { wrapper } = mountScreen({ name: 'courses' })
    expect(tg.release).not.toHaveBeenCalled()

    wrapper.unmount()

    expect(tg.release).toHaveBeenCalledTimes(1)
  })

  it('does not release anything for a null target on unmount', () => {
    const { wrapper } = mountScreen(null)

    wrapper.unmount()

    expect(tg.release).not.toHaveBeenCalled()
  })

  it('survives a Telegram without BackButton', () => {
    tg.hide.mockImplementation(() => {
      throw new Error('not supported')
    })

    expect(() => mountScreen(null)).not.toThrow()
  })
})
