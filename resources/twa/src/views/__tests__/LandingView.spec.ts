import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import LandingView from '../LandingView.vue'
import { setLocale } from '@/i18n'

const state = vi.hoisted(() => ({ inTelegram: true }))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ isInTelegram: () => state.inTelegram }),
}))

async function render() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'landing', component: { template: '<div />' } },
      { path: '/courses', name: 'courses', component: { template: '<div />' } },
    ],
  })
  const wrapper = mount(LandingView, { global: { plugins: [router] } })
  await flushPromises()
  return wrapper
}

describe('LandingView', () => {
  afterEach(() => setLocale('ru'))

  it('links to the course catalog inside Telegram', async () => {
    state.inTelegram = true
    const link = (await render()).get('[data-testid=catalog-link]')

    expect(link.attributes('href')).toBe('/courses')
    expect(link.text()).toBe('Каталог курсов')
  })

  it('has no catalog link outside Telegram (nothing could authenticate)', async () => {
    state.inTelegram = false

    expect((await render()).find('[data-testid=catalog-link]').exists()).toBe(false)
  })

  it('localizes the link', async () => {
    state.inTelegram = true
    setLocale('uz')

    expect((await render()).get('[data-testid=catalog-link]').text()).toBe('Kurslar katalogi')
  })
})
