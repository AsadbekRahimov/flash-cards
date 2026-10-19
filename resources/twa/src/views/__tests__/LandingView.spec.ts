import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import LandingView from '../LandingView.vue'
import { setLocale } from '@/i18n'

const state = vi.hoisted(() => ({ inTelegram: true }))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ isInTelegram: () => state.inTelegram }),
}))

async function render(path = '/') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'landing', component: LandingView },
      { path: '/today', name: 'today', component: { template: '<div />' } },
    ],
  })
  await router.push(path)
  await router.isReady()
  const wrapper = mount(LandingView, { global: { plugins: [router] } })
  await flushPromises()
  return Object.assign(wrapper, { router })
}

describe('LandingView', () => {
  beforeEach(() => {
    state.inTelegram = true
  })
  afterEach(() => setLocale('ru'))

  it('hands a personal launch (no session context) over to the Today screen', async () => {
    const wrapper = await render()

    expect(wrapper.router.currentRoute.value.name).toBe('today')
  })

  it('keeps the query of the launch link (?from=reminder) on the way', async () => {
    const wrapper = await render('/?from=reminder')

    expect(wrapper.router.currentRoute.value.name).toBe('today')
    expect(wrapper.router.currentRoute.value.query.from).toBe('reminder')
  })

  it('has no review shortcut of its own any more: the Today screen owns it', async () => {
    const wrapper = await render()

    expect(wrapper.find('[data-testid=review-all-btn]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=catalog-link]').exists()).toBe(false)
  })

  it('outside Telegram stays and explains why', async () => {
    state.inTelegram = false
    const wrapper = await render()

    expect(wrapper.router.currentRoute.value.name).toBe('landing')
    expect(wrapper.text()).toContain('Эта страница работает только внутри Telegram Web App')
  })

  it('explains in Uzbek too', async () => {
    state.inTelegram = false
    setLocale('uz')

    expect((await render()).text()).toContain('Bu sahifa faqat Telegram Web App ichida ishlaydi')
  })
})
