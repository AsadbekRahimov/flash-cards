import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import MonthlyRatingView from '../MonthlyRatingView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import type { MonthlyLastMonth, MonthlyMe, MonthlyRatingResponse } from '@/api/types'

const state = vi.hoisted(() => ({ ensure: vi.fn(), showBack: vi.fn(), hideBack: vi.fn() }))

vi.mock('@/api', () => ({ api: { league: { month: vi.fn() } } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: state.ensure }) }))
vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ showBackButton: state.showBack, webApp: { BackButton: { hide: state.hideBack } } }),
}))

const me = (overrides: Partial<MonthlyMe> = {}): MonthlyMe => ({
  rank: 12,
  points: 340,
  active_days: 8,
  participant: true,
  eligible: true,
  missing: { active_days: 0, account_days: 0 },
  ...overrides,
})

const top = (count: number, meRank: number | null) =>
  Array.from({ length: count }, (_, i) => ({
    rank: i + 1,
    name: i === 3 ? null : `Member ${i + 1}`,
    points: 1000 - i * 10,
    active_days: 10,
    is_me: i + 1 === meRank,
  }))

const response = (overrides: Partial<MonthlyRatingResponse> = {}): MonthlyRatingResponse => ({
  enabled: true,
  opted_out: false,
  month: { starts_on: '2026-11-01', ends_on: '2026-11-30', closes_at: '2026-11-30T19:00:00+00:00', days_left: 14, status: 'open', participants: 40 },
  rules: { daily_cap: 60, min_active_days: 10, min_account_days: 7, places: 3, top: 10 },
  prizes: [
    { place: 1, title: 'Наушники' },
    { place: 2, title: 'Книга' },
    { place: 3, title: 'Блокнот' },
  ],
  top: top(5, 12),
  me: me(),
  last_month: null,
  ...overrides,
})

const last = (overrides: Partial<MonthlyLastMonth> = {}): MonthlyLastMonth => ({
  starts_on: '2026-10-01',
  ends_on: '2026-10-31',
  status: 'confirmed',
  winners: [
    { place: 1, name: 'Dilnoza', points: 900 },
    { place: 2, name: null, points: 800 },
    { place: 3, name: 'Bek', points: 700 },
  ],
  me: null,
  ...overrides,
})

const makeRouter = () =>
  createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: { template: '<div />' } },
      { path: '/settings', name: 'settings', component: { template: '<div />' } },
      { path: '/friends', name: 'friends', component: { template: '<div />' } },
      { path: '/league', name: 'league', component: { template: '<div />' } },
      { path: '/league/month', name: 'league-month', component: MonthlyRatingView },
    ],
  })

async function render(data: MonthlyRatingResponse = response()) {
  vi.mocked(api.league.month).mockResolvedValue(data)
  const router = makeRouter()
  await router.push('/league/month')
  await router.isReady()
  const wrapper = mount(MonthlyRatingView, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

type Wrapper = Awaited<ReturnType<typeof render>>['wrapper']
const text = (w: Wrapper, id: string): string => w.get(`[data-testid=${id}]`).text()
const has = (w: Wrapper, id: string): boolean => w.find(`[data-testid=${id}]`).exists()

describe('MonthlyRatingView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    state.ensure.mockResolvedValue(undefined)
  })
  afterEach(() => setLocale('ru'))

  it('shows the month title and the days left, the prizes, my place and the top', async () => {
    const { wrapper } = await render()

    expect(text(wrapper, 'month')).toContain('Ноябрь 2026')
    expect(text(wrapper, 'month')).toContain('До конца месяца — 14 дн.')
    const prizes = wrapper.findAll('[data-testid=prize]').map((p) => p.text())
    expect(prizes).toEqual(['1 место — Наушники', '2 место — Книга', '3 место — Блокнот'])
    expect(text(wrapper, 'prizes-note')).toBe('Призы вручаются после проверки результатов')
    expect(text(wrapper, 'my-place')).toBe('Вы на 12 месте · 340 баллов')
    expect(text(wrapper, 'prize-line')).toBe('Вы участвуете в розыгрыше призов')
    expect(wrapper.get('h1').text()).toBe('Ноябрь 2026')
    expect(wrapper.get('[data-testid=seg-month]').attributes('aria-current')).toBe('page')
    expect(state.showBack).not.toHaveBeenCalled()
  })

  it('has the three segments and leads to the other panes', async () => {
    const { wrapper, router } = await render()

    expect(['seg-league', 'seg-month', 'seg-friends'].map((id) => text(wrapper, id))).toEqual(['Лига', 'Месяц', 'Друзья'])
    await wrapper.get('[data-testid=seg-league]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('league')
  })

  describe('days left and status', () => {
    it('says "last day" for 1 day', async () => {
      const { wrapper } = await render(response({ month: { ...response().month, days_left: 1 } }))
      expect(text(wrapper, 'month')).toContain('Сегодня последний день месяца')
    })

    it.each(['closing', 'pending_review', 'confirmed'] as const)('says the results are being counted for status %s', async (status) => {
      const { wrapper } = await render(response({ month: { ...response().month, status } }))
      expect(text(wrapper, 'month')).toContain('Итоги месяца подводятся')
      expect(text(wrapper, 'month')).not.toContain('До конца')
    })

    it('treats a month without a row (status null) as open', async () => {
      const { wrapper } = await render(response({ month: { ...response().month, status: null } }))
      expect(text(wrapper, 'month')).toContain('До конца месяца — 14 дн.')
    })
  })

  it('hides the prize card when there are no prizes, and the prize lines and conditions with it', async () => {
    const { wrapper } = await render(response({ prizes: [], me: me({ eligible: false, missing: { active_days: 2, account_days: 0 } }) }))

    expect(has(wrapper, 'prizes')).toBe(false)
    expect(has(wrapper, 'prize-line')).toBe(false)
    expect(has(wrapper, 'rules-prize')).toBe(false)
    expect(has(wrapper, 'my-place')).toBe(true)
  })

  describe('my card', () => {
    it('invites a learner who has not studied yet, with a button to Today', async () => {
      const { wrapper } = await render(response({ me: null, top: [] }))

      expect(text(wrapper, 'not-started')).toContain('Начните заниматься — и попадёте в рейтинг месяца')
      expect(wrapper.get('[data-testid=start-btn]').text()).toBe('Начать занятие')
      expect(wrapper.get('[data-testid=start-btn]').attributes('href')).toBe('/today')
      expect(has(wrapper, 'my-place')).toBe(false)
    })

    it('says that the learner is out of the ratings and links to the settings', async () => {
      const { wrapper } = await render(response({ opted_out: true, me: null }))

      expect(text(wrapper, 'opted-out')).toContain('Вы не участвуете в рейтингах. Включить можно в настройках')
      expect(wrapper.get('[data-testid=settings-link]').attributes('href')).toBe('/settings')
      expect(has(wrapper, 'not-started')).toBe(false)
    })

    it('says what is missing for a prize in words, never "eligible"', async () => {
      const { wrapper } = await render(response({ me: me({ eligible: false, missing: { active_days: 3, account_days: 2 } }) }))

      const lines = wrapper.findAll('[data-testid=prize-line]').map((l) => l.text())
      expect(lines).toEqual(['Для приза нужно заниматься ещё 3 дн. в этом месяце', 'Для приза аккаунту должно быть не меньше 7 дн.'])
      expect(wrapper.text().toLowerCase()).not.toContain('eligible')
    })

    it('says only the days when the account is old enough', async () => {
      const { wrapper } = await render(response({ me: me({ eligible: false, missing: { active_days: 1, account_days: 0 } }) }))
      expect(wrapper.findAll('[data-testid=prize-line]')).toHaveLength(1)
    })

    it('has no place yet (rank null): says so without a number', async () => {
      const { wrapper } = await render(response({ me: me({ rank: null, points: 0, eligible: false, missing: { active_days: 10, account_days: 0 } }) }))
      expect(text(wrapper, 'my-place')).toBe('Пока без места · 0 баллов')
    })
  })

  describe('the top', () => {
    it('the place in mono for everybody (no medals), "Ученик" for no name, my row highlighted with "вы"', async () => {
      const { wrapper } = await render(response({ top: top(5, 5), me: me({ rank: 5 }) }))

      const rows = wrapper.findAll('[data-testid=row], [data-testid=row-me]')
      expect(rows).toHaveLength(5)
      expect(rows[0]?.get('[data-testid=rank]').text()).toBe('1')
      expect(rows[0]?.get('[data-testid=rank]').classes()).toContain('font-mono')
      expect(rows[0]?.text()).toContain('Member 1')
      expect(rows[0]?.get('[data-testid=points]').text()).toBe('1000')
      expect(wrapper.text()).not.toMatch(/[🥇🥈🥉]/u)
      expect(rows[3]?.text()).toContain('Ученик')
      expect(rows[3]?.text()).toContain('4')
      expect(rows[4]?.attributes('data-testid')).toBe('row-me')
      expect(rows[4]?.classes()).toContain('bg-accent-soft')
      expect(rows[4]?.text()).toContain('· вы')
      expect(has(wrapper, 'row-pinned')).toBe(false)
    })

    it('pins my row under the list when I am not in the top', async () => {
      const { wrapper } = await render()

      expect(has(wrapper, 'row-me')).toBe(false)
      expect(text(wrapper, 'row-pinned')).toContain('12')
      expect(wrapper.get('[data-testid=row-pinned] [data-testid=points]').text()).toBe('340')
    })

    it('has an empty state when nobody has points', async () => {
      const { wrapper } = await render(response({ top: [], me: null }))

      expect(text(wrapper, 'empty')).toContain('Пока никто не набрал баллов в этом месяце')
      expect(has(wrapper, 'top')).toBe(false)
    })
  })

  it('explains the points without numbers of weights, the daily cap and the prize conditions', async () => {
    const { wrapper } = await render()

    expect(text(wrapper, 'rules-points')).toContain('В рейтинг месяца идёт')
    expect(text(wrapper, 'rules-points')).toContain('не больше 60 баллов в день')
    expect(text(wrapper, 'rules-prize')).toBe('Призы получают места с 1 по 3, если за месяц вы занимались не меньше 10 дн., а аккаунту не меньше 7 дн.')
  })

  describe('last month', () => {
    it('lists the winners of a confirmed month with my place', async () => {
      const { wrapper } = await render(response({ last_month: last({ me: { rank: 9, points: 250 } }) }))

      expect(wrapper.get('[data-testid=last-month]').text()).toContain('Победители: Октябрь 2026')
      const winners = wrapper.findAll('[data-testid=winner]')
      expect(winners).toHaveLength(3)
      expect(winners[0]?.text()).toContain('Dilnoza')
      expect(winners[0]?.text()).toContain('900')
      expect(winners[1]?.text()).toContain('Ученик')
      expect(text(wrapper, 'last-me')).toBe('Ваше место: 9')
    })

    it('has no "my place" line when I was not placed', async () => {
      const { wrapper } = await render(response({ last_month: last() }))
      expect(has(wrapper, 'last-me')).toBe(false)
    })

    it.each(['pending_review', 'closing'] as const)('says the results are being checked for %s, with no winners', async (status) => {
      const { wrapper } = await render(response({ last_month: last({ status }) }))

      expect(text(wrapper, 'last-month-pending')).toContain('Итоги месяца (Октябрь 2026) проверяются')
      expect(has(wrapper, 'winner')).toBe(false)
    })

    it('has no block without last month', async () => {
      const { wrapper } = await render()
      expect(has(wrapper, 'last-month')).toBe(false)
      expect(has(wrapper, 'last-month-pending')).toBe(false)
    })
  })

  it('shows a stub when the rating is off, keeping the switch', async () => {
    const { wrapper } = await render(response({ enabled: false, prizes: [], top: [], me: null }))

    expect(text(wrapper, 'disabled')).toContain('Рейтинг месяца скоро появится')
    expect(has(wrapper, 'my-card')).toBe(false)
    expect(has(wrapper, 'empty')).toBe(false)
    expect(has(wrapper, 'seg-month')).toBe(true)
  })

  describe('errors', () => {
    it('shows an error and loads again on "Retry"', async () => {
      vi.mocked(api.league.month).mockRejectedValueOnce(new ApiClientError('network', 0, 'network', 'x'))
      const router = makeRouter()
      await router.push('/league/month')
      await router.isReady()
      const wrapper = mount(MonthlyRatingView, { global: { plugins: [router] } })
      await flushPromises()
      expect(has(wrapper, 'my-card')).toBe(false)

      vi.mocked(api.league.month).mockResolvedValue(response())
      await wrapper.get('button').trigger('click')
      await flushPromises()

      expect(api.league.month).toHaveBeenCalledTimes(2)
      expect(has(wrapper, 'my-card')).toBe(true)
    })

    it('does not load when the sign-in fails', async () => {
      state.ensure.mockRejectedValue(new ApiClientError('network', 0, 'network', 'x'))
      vi.mocked(api.league.month).mockResolvedValue(response())
      const router = makeRouter()
      await router.push('/league/month')
      await router.isReady()
      mount(MonthlyRatingView, { global: { plugins: [router] } })
      await flushPromises()

      expect(api.league.month).not.toHaveBeenCalled()
    })
  })

  it('speaks Uzbek: month title, places, points and the prize line', async () => {
    setLocale('uz')
    const { wrapper } = await render(response({ last_month: last() }))

    expect(text(wrapper, 'month')).toContain('2026-yil noyabr')
    expect(text(wrapper, 'month')).toContain('Oy tugashiga 14 kun qoldi')
    expect(text(wrapper, 'my-place')).toBe("Siz 12-o'rindasiz · 340 ball")
    expect(wrapper.findAll('[data-testid=prize]')[0]?.text()).toBe("1-o'rin — Наушники")
    expect(wrapper.get('[data-testid=seg-month]').text()).toBe('Oy')
    expect(wrapper.get('[data-testid=last-month]').text()).toContain("G'oliblar: 2026-yil oktabr")
  })
})
