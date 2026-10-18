import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import LandingView from '../LandingView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'

const state = vi.hoisted(() => ({ inTelegram: true, ensure: vi.fn() }))

vi.mock('@/api', () => ({ api: { training: { open: vi.fn() } } }))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ ensure: state.ensure }),
}))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ isInTelegram: () => state.inTelegram }),
}))

async function render() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'landing', component: { template: '<div />' } },
      { path: '/courses', name: 'courses', component: { template: '<div />' } },
      { path: '/training/:sessionId(\\d+)', name: 'training', component: { template: '<div />' } },
    ],
  })
  const wrapper = mount(LandingView, { global: { plugins: [router] } })
  await flushPromises()
  return Object.assign(wrapper, { router })
}

describe('LandingView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    state.ensure.mockResolvedValue(undefined)
  })
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

  it('opens the daily review and goes to the training screen', async () => {
    state.inTelegram = true
    vi.mocked(api.training.open).mockResolvedValue({
      session_id: 9, mode: 'daily_review', lesson: null, total_words: 10,
    })

    const wrapper = await render()
    const button = wrapper.get('[data-testid=review-all-btn]')
    expect(button.text()).toBe('Повторить всё')
    await button.trigger('click')
    await flushPromises()

    expect(state.ensure).toHaveBeenCalled()
    expect(api.training.open).toHaveBeenCalledWith({ mode: 'daily_review', source: 'self' })
    expect(wrapper.router.currentRoute.value.name).toBe('training')
    expect(wrapper.router.currentRoute.value.params.sessionId).toBe('9')
  })

  it('explains a failed open and stays on the landing', async () => {
    state.inTelegram = true
    vi.mocked(api.training.open).mockRejectedValue(new ApiClientError('rate_limited', 429, 'unknown', 'Too Many Attempts.'))

    const wrapper = await render()
    await wrapper.get('[data-testid=review-all-btn]').trigger('click')
    await flushPromises()

    expect(wrapper.router.currentRoute.value.name).not.toBe('training')
    expect(wrapper.get('[data-testid=open-error]').text()).toBe('Слишком много запросов. Подождите немного и повторите.')
  })

  it('does not open anything when authentication fails', async () => {
    state.inTelegram = true
    state.ensure.mockRejectedValue(new Error('opened_outside_telegram'))

    const wrapper = await render()
    await wrapper.get('[data-testid=review-all-btn]').trigger('click')
    await flushPromises()

    expect(api.training.open).not.toHaveBeenCalled()
    expect(wrapper.get('[data-testid=open-error]').text()).toBe('Откройте приложение через Telegram.')
  })

  it('has no review button outside Telegram and localizes it inside', async () => {
    state.inTelegram = false
    expect((await render()).find('[data-testid=review-all-btn]').exists()).toBe(false)

    state.inTelegram = true
    setLocale('uz')
    expect((await render()).get('[data-testid=review-all-btn]').text()).toBe('Hammasini takrorlash')
  })
})
