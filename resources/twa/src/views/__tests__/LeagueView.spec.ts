import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import LeagueView from '../LeagueView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import type { LeagueMembership, LeagueResponse } from '@/api/types'

const state = vi.hoisted(() => ({
  ensure: vi.fn(),
  back: undefined as undefined | (() => void),
  hideBack: vi.fn(),
}))

vi.mock('@/api', () => ({ api: { league: { get: vi.fn() } } }))

vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: state.ensure }) }))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    showBackButton: (handler: () => void) => {
      state.back = handler
      return state.hideBack
    },
  }),
}))

const membership = (overrides: Partial<LeagueMembership> = {}): LeagueMembership => ({
  tier: 1,
  tier_key: 'silver',
  rank: 3,
  points: 41,
  members_count: 4,
  zone: 'safe',
  points_to_promote: 10,
  points_to_safety: null,
  standings: [
    { rank: 1, name: 'Dilnoza', points: 90, is_me: false },
    { rank: 2, name: 'Bek', points: 50, is_me: false },
    { rank: 3, name: 'Aziz', points: 41, is_me: true },
    { rank: 4, name: null, points: 2, is_me: false },
  ],
  ...overrides,
})

const response = (overrides: Partial<LeagueResponse> = {}): LeagueResponse => ({
  enabled: true,
  opted_out: false,
  week: { starts_on: '2026-10-19', ends_on: '2026-10-25', closes_at: '2026-10-25T19:00:00+00:00' },
  rules: { capacity: 30, promote: 5, demote: 5, tiers: ['bronze', 'silver', 'gold', 'platinum', 'diamond'] },
  membership: membership(),
  last_week: { starts_on: '2026-10-12', tier: 0, tier_key: 'bronze', result: 'promoted', rank: 2, points: 120 },
  ...overrides,
})

const makeRouter = () =>
  createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: { template: '<div />' } },
      { path: '/settings', name: 'settings', component: { template: '<div />' } },
      { path: '/league', name: 'league', component: LeagueView },
    ],
  })

async function render(data: LeagueResponse = response()) {
  vi.mocked(api.league.get).mockResolvedValue(data)
  const router = makeRouter()
  await router.push('/league')
  await router.isReady()
  const wrapper = mount(LeagueView, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

const text = (w: Awaited<ReturnType<typeof render>>['wrapper'], id: string): string => w.get(`[data-testid=${id}]`).text()

describe('LeagueView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    state.back = undefined
    state.ensure.mockResolvedValue(undefined)
  })
  afterEach(() => setLocale('ru'))

  it('shows my place, points, zone, what is missing, the standings and last week', async () => {
    const { wrapper } = await render()

    expect(text(wrapper, 'week')).toContain('Неделя 19 окт. – 25 окт.')
    expect(text(wrapper, 'my-card')).toContain('Серебро')
    expect(text(wrapper, 'my-place')).toBe('3-е место из 4')
    expect(text(wrapper, 'my-points')).toBe('41 очко')
    expect(text(wrapper, 'zone')).toBe('Вы в безопасной зоне')
    expect(text(wrapper, 'to-promote')).toBe('До зоны повышения: 10 очков')
    expect(wrapper.find('[data-testid=to-safety]').exists()).toBe(false)

    const rows = wrapper.findAll('[data-testid=standings] li')
    expect(rows).toHaveLength(4)
    expect(rows[0]?.text()).toContain('Dilnoza')
    expect(rows[0]?.text()).toContain('90 очков')
    expect(rows[2]?.attributes('data-testid')).toBe('row-me')
    expect(rows[2]?.text()).toContain('Aziz · вы')
    expect(rows[3]?.text()).toContain('Ученик')
    expect(rows[3]?.text()).toContain('2 очка')

    expect(text(wrapper, 'last-week')).toContain('Бронза: 2-е место, 120 очков — повышение!')
    expect(text(wrapper, 'rules')).toContain('Группы до 30 человек. Топ-5 переходят выше, последние 5 — ниже.')
    expect(wrapper.get('[data-testid=settings-link]').attributes('href')).toBe('/settings')
  })

  it('warns in the demotion zone and congratulates in the top tier', async () => {
    const demotion = await render(response({ membership: membership({ zone: 'demotion', points_to_safety: 7, points_to_promote: 49 }) }))
    expect(text(demotion.wrapper, 'zone')).toBe('⬇️ Вы в зоне понижения')
    expect(text(demotion.wrapper, 'to-safety')).toBe('До безопасной зоны: 7 очков')

    const top = await render(response({ membership: membership({ tier: 4, tier_key: 'diamond', zone: 'safe', points_to_promote: null }) }))
    expect(text(top.wrapper, 'top-tier')).toBe('Это высшая лига — так держать!')
    expect(top.wrapper.find('[data-testid=to-promote]').exists()).toBe(false)
  })

  it('explains an empty week, an opt-out and switched-off leagues', async () => {
    const empty = await render(response({ membership: null, last_week: null }))
    expect(text(empty.wrapper, 'not-placed')).toBe('На этой неделе вы ещё не в группе: повторите несколько карточек — и вы в лиге.')
    expect(empty.wrapper.find('[data-testid=last-week]').exists()).toBe(false)

    const out = await render(response({ membership: null, opted_out: true }))
    expect(text(out.wrapper, 'opted-out')).toBe('Вы вышли из лиг. Включить их снова можно в настройках.')

    const off = await render(response({ enabled: false, membership: null }))
    expect(text(off.wrapper, 'disabled')).toBe('Лиги сейчас выключены.')
    expect(off.wrapper.find('[data-testid=not-placed]').exists()).toBe(false)
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const { wrapper } = await render()

    expect(wrapper.get('h1').text()).toContain('Liga')
    expect(text(wrapper, 'my-place')).toBe("4 tadan 3-o'rin")
    expect(text(wrapper, 'my-points')).toBe('41 ochko')
    expect(text(wrapper, 'my-card')).toContain('Kumush')
  })

  it('shows the error with a retry and goes back to Today with the Telegram back button', async () => {
    vi.mocked(api.league.get).mockRejectedValueOnce(new ApiClientError('network', 0, 'network', 'x'))
    const { wrapper, router } = await render()

    expect(wrapper.find('[data-testid=error]').exists()).toBe(true)
    vi.mocked(api.league.get).mockResolvedValue(response())
    await wrapper.get('[data-testid=retry-btn]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid=my-card]').exists()).toBe(true)

    state.back?.()
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('today')

    wrapper.unmount()
    expect(state.hideBack).toHaveBeenCalled()
  })
})
