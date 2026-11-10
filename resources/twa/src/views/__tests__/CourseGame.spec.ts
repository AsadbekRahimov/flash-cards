import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import CourseView from '../CourseView.vue'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import { settingsFixture } from '@/api/__tests__/settingsFixture'
import type { CourseDetail, GameAccess, MeResponse } from '@/api/types'

vi.mock('@/api', () => ({
  api: {
    courses: { list: vi.fn(), get: vi.fn(), select: vi.fn() },
    training: { open: vi.fn() },
    exam: { openMock: vi.fn() },
    me: { get: vi.fn() },
    promo: { redeem: vi.fn() },
    today: { get: vi.fn() },
  },
}))

vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined) }) }))
vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ showBackButton: () => () => {}, webApp: { BackButton: { hide: () => {} } } }),
}))

const detail = (): CourseDetail => ({
  slug: 'english-a1',
  title: 'Английский A1',
  description: null,
  level: 'A1',
  exam_tag: null,
  words_count: 6,
  is_active: true,
  progress: { done: 2, total: 5 },
  stages: [{ number: 1, title: 'Basics', progress: { done: 2, total: 5 }, lessons: [{ id: 11, number: 1, title: 'Greetings', progress: { done: 2, total: 3 } }] }],
})

const me = (game?: GameAccess): MeResponse => ({
  learner: { id: 1, first_name: 'Асадбек', locale: 'ru', onboarding_completed: true, username: null },
  groups: [],
  stats: { words_learned: 0, words_due_today: 0 },
  entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 20 },
  paywall: { mode: 'purchase', contact: null },
  settings: settingsFixture(),
  ...(game ? { game } : {}),
})

const access = (overrides: Partial<GameAccess> = {}): GameAccess => ({ available: true, limits: null, pro_promise: 'none', coins: 0, tickets: 0, ...overrides })

async function render(game: GameAccess | undefined) {
  vi.mocked(api.courses.get).mockResolvedValue(detail())
  vi.mocked(api.me.get).mockResolvedValue(me(game))
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/courses', name: 'courses', component: stub },
      { path: '/courses/:slug', name: 'course', component: stub },
      { path: '/game', name: 'game', component: stub },
    ],
  })
  await router.push({ name: 'course', params: { slug: 'english-a1' } })
  const wrapper = mount(CourseView, { props: { slug: 'english-a1' }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
})
afterEach(() => {
  setLocale('ru')
  document.body.innerHTML = ''
})

describe('CourseView: the entry to the Karvon game (docs/05 §5b.1)', () => {
  it('is a tinted full-width "Караван" when /me says the game is available, and leads to the map of the course', async () => {
    const { wrapper, router } = await render(access())

    const button = wrapper.get('[data-testid=game-btn]')
    expect(button.text()).toBe('Игра «Караван»')
    expect(button.classes()).toEqual(expect.arrayContaining(['btn', 'btn-tinted', 'btn-block']))

    await button.trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.fullPath).toBe('/game?course=english-a1')
    wrapper.unmount()
  })

  it('is not there when the game is not available', async () => {
    const { wrapper } = await render(access({ available: false }))

    expect(wrapper.find('[data-testid=game-btn]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('is not there when /me has no game block (an old server)', async () => {
    const { wrapper } = await render(undefined)

    expect(wrapper.find('[data-testid=game-btn]').exists()).toBe(false)
    wrapper.unmount()
  })
})
