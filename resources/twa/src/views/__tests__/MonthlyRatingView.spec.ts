import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import MonthlyRatingView from '../MonthlyRatingView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useMonthlyRatingStore } from '@/stores/monthlyRating'
import { useSettingsStore } from '@/stores/settings'
import { settingsFixture } from '@/api/__tests__/settingsFixture'
import type { BoardQuery, BoardRow, LeagueBoardPage, MonthlyLastMonth, MonthlyMe, MonthlyRatingResponse, ScoreCategory } from '@/api/types'

const state = vi.hoisted(() => ({ ensure: vi.fn(), showBack: vi.fn(), hideBack: vi.fn() }))

vi.mock('@/api', () => ({ api: { league: { month: vi.fn(), monthPage: vi.fn() }, me: { get: vi.fn() }, today: { get: vi.fn() } } }))
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
  not_counted_reason: null,
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
      { path: '/league/all-time', name: 'league-all-time', component: { template: '<div />' } },
      { path: '/referrals', name: 'referrals', component: { template: '<div />' } },
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

  it('is drawn as the League mock-up: the brand row, my place in the big tinted card, the top as a card table', async () => {
    const { wrapper } = await render()

    expect(wrapper.find('[data-testid=brand-row]').exists()).toBe(true)
    expect(wrapper.get('[data-testid=my-card]').classes()).toEqual(expect.arrayContaining(['bg-highlight', 'rounded-hero']))
    expect(wrapper.get('[data-testid=top]').classes()).toContain('card')
    expect(wrapper.get('[data-testid=top] h2').text()).toBe('Топ месяца')
    expect(wrapper.html()).not.toContain('font-mono')
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
      expect(wrapper.get('[data-testid=start-btn]').text()).toBe('К плану на сегодня')
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
      expect(has(wrapper, 'not-counted')).toBe(false)
    })

    it('tells a blocked learner why there is no place, with no prize lines', async () => {
      const blocked = me({ rank: null, points: 40, participant: false, eligible: false, missing: { active_days: 1, account_days: 0 }, not_counted_reason: 'blocked' })
      const { wrapper } = await render(response({ me: blocked }))
      expect(text(wrapper, 'my-place')).toBe('Пока без места · 40 баллов')
      expect(text(wrapper, 'not-counted')).toBe('Ваш аккаунт не участвует в рейтинге, напишите в поддержку')
      expect(has(wrapper, 'prize-line')).toBe(false)
      expect(has(wrapper, 'opted-out')).toBe(false)
    })

    it('shows the opt-out card when the line says so, even before the opt-out flag', async () => {
      const out = me({ rank: null, points: 20, participant: false, eligible: false, not_counted_reason: 'opted_out' })
      const { wrapper } = await render(response({ opted_out: false, me: out }))
      expect(has(wrapper, 'opted-out')).toBe(true)
      expect(has(wrapper, 'not-counted')).toBe(false)
    })

    it('has no reason line for a participant', async () => {
      const { wrapper } = await render(response())
      expect(has(wrapper, 'not-counted')).toBe(false)
    })
  })

  describe('the top', () => {
    it('the place in a soft badge for everybody (no medals), "Ученик" for no name, my row tinted with "вы"', async () => {
      const { wrapper } = await render(response({ top: top(5, 5), me: me({ rank: 5 }) }))

      const rows = wrapper.findAll('[data-testid=row], [data-testid=row-me]')
      expect(rows).toHaveLength(5)
      expect(rows[0]?.get('[data-testid=rank]').text()).toBe('1')
      expect(rows[0]?.get('[data-testid=rank]').classes()).toEqual(expect.arrayContaining(['bg-accent-soft', 'text-accent']))
      expect(rows[0]?.text()).toContain('Member 1')
      expect(rows[0]?.get('[data-testid=points]').text()).toBe('1000')
      expect(wrapper.text()).not.toMatch(/[🥇🥈🥉]/u)
      expect(rows[3]?.text()).toContain('Ученик')
      expect(rows[3]?.text()).toContain('4')
      expect(rows[4]?.attributes('data-testid')).toBe('row-me')
      expect(rows[4]?.classes()).toContain('bg-nav-active')
      expect(rows[4]?.get('[data-testid=rank]').classes()).toContain('bg-surface')
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
      await wrapper.get('[data-testid=retry-btn]').trigger('click')
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
  describe('a month of the word points v2 (docs/32 §8.3, §12.9)', () => {
    const boardRow = (rank: number, overrides: Partial<BoardRow> = {}): BoardRow => ({
      rank, name: `Member ${rank}`, points: 1000 - rank * 10, long_words: 1, active_days: 5, is_me: false, pro_emoji: null, ...overrides,
    })

    /** The learner's own table is Free: 12th place, the top of 10, two neighbours above and below, more pages after the top. */
    const v2 = (overrides: Partial<MonthlyRatingResponse> = {}): MonthlyRatingResponse =>
      response({
        rules_version: 2,
        category: 'free',
        my_category: 'free',
        read_only: false,
        top: top(10, null).map((row, i) => ({ ...row, long_words: 2, pro_emoji: i === 0 ? '🦁' : null })),
        me: me({ category: 'free', long_words: 3, next_gap: 11 }),
        neighbours: { above: [boardRow(10, { points: 910, long_words: 2, active_days: 10 }), boardRow(11, { points: 350 })], below: [boardRow(13, { points: 300 }), boardRow(14, { points: 290 })] },
        next_cursor: 'm2026-11f.page2',
        ...overrides,
      })

    /** The other table (Pro), as the server answers `?category=pro` for a Free learner. */
    const proTable = (): MonthlyRatingResponse =>
      v2({
        category: 'pro',
        read_only: true,
        month: { ...response().month, participants: 7 },
        prizes: [{ place: 1, title: 'Планшет' }, { place: 2, title: 'Колонка' }, { place: 3, title: 'Книга' }],
        top: [{ rank: 1, name: 'Pro One', points: 500, active_days: 9, is_me: false, pro_emoji: '🚀', long_words: 4 }],
        me: me({ category: 'free', long_words: 3, next_gap: null }),
        neighbours: null,
        next_cursor: null,
      })

    function byCategory(tables: Partial<Record<ScoreCategory | 'own', MonthlyRatingResponse>>) {
      vi.mocked(api.league.month).mockImplementation(async (query?: BoardQuery) => tables[query?.category ?? 'own'] ?? v2())
    }

    beforeEach(() => {
      // `/me` (the region card asks it best effort): never answers here
      vi.mocked(api.me.get).mockReturnValue(new Promise(() => {}))
    })

    const page = (rows: BoardRow[], next: string | null = null): LeagueBoardPage => ({ rules_version: 2, category: 'free', read_only: false, rows, next_cursor: next })

    it('a v1 month: no switch, no "show more", no neighbours, the pinned row as before', async () => {
      const { wrapper } = await render()

      expect(has(wrapper, 'category-switch')).toBe(false)
      expect(has(wrapper, 'more')).toBe(false)
      expect(has(wrapper, 'neighbours')).toBe(false)
      expect(has(wrapper, 'next-gap')).toBe(false)
      expect(has(wrapper, 'participants')).toBe(false)
      expect(has(wrapper, 'row-pinned')).toBe(true)
      expect(wrapper.get('[data-testid=prizes] h2').text()).toBe('Призы')
    })

    it('my own table: the switch on Free, its prizes and participants, my place with the way to the next one, neighbours around me', async () => {
      const { wrapper } = await render(v2())

      expect(wrapper.get('[data-testid=category-free]').attributes('aria-checked')).toBe('true')
      expect(wrapper.get('[data-testid=category-pro]').attributes('aria-checked')).toBe('false')
      expect(has(wrapper, 'read-only')).toBe(false)
      expect(wrapper.get('[data-testid=prizes] h2').text()).toBe('Призы · Free')
      expect(wrapper.findAll('[data-testid=prize]')).toHaveLength(3)
      expect(text(wrapper, 'participants')).toBe('40 участников')
      expect(text(wrapper, 'my-place')).toBe('Вы на 12 месте · 340 баллов')
      // the nearest better row on screen is the neighbour on the 11th place
      expect(text(wrapper, 'next-gap')).toBe('До 11-го места — 11 баллов')
      expect(text(wrapper, 'long-words')).toBe('Долгих повторений: 3')

      // the neighbour on the 10th place is in the top already: not repeated
      const near = wrapper.findAll('[data-testid=row-near]').map((row) => row.get('[data-testid=rank]').text())
      expect(near).toEqual(['11', '13', '14'])
      expect(wrapper.get('[data-testid=row-near-me] [data-testid=rank]').text()).toBe('12')
      expect(has(wrapper, 'row-pinned')).toBe(false)
      expect(wrapper.findAll('[data-testid=row]')[0]?.get('[data-testid=pro-emoji]').text()).toBe('🦁')
      expect(wrapper.findAll('[data-testid=row]')[1]?.find('[data-testid=pro-emoji]').exists()).toBe(false)
      expect(text(wrapper, 'rules-points')).toContain('Free и Pro соревнуются в отдельных таблицах')
    })

    it('a tie on points: the place is the nearest better rank, whatever its points (`next_gap` counts the tie-break facts)', async () => {
      // places 10 / 11 / 12 (me): the 11th has as many points as I and more long repetitions - the server says 1 point to pass it
      const tie = v2({
        me: me({ category: 'free', long_words: 1, next_gap: 1 }),
        neighbours: { above: [boardRow(10, { points: 910 }), boardRow(11, { points: 340, long_words: 4 })], below: [] },
      })
      const { wrapper } = await render(tie)
      expect(text(wrapper, 'next-gap')).toBe('До 11-го места — 1 балл')

      // everyone shown above has as many points: still the nearest better rank, never a made-up one
      const even = v2({
        top: top(10, null).map((row) => ({ ...row, points: 340, long_words: 5 })),
        me: me({ category: 'free', long_words: 1, next_gap: 1 }),
        neighbours: { above: [boardRow(10, { points: 340 }), boardRow(10, { points: 340, name: 'Tie' })], below: [] },
      })
      const second = await render(even)
      expect(text(second.wrapper, 'next-gap')).toBe('До 10-го места — 1 балл')
    })

    it('no better row among the rows shown: "to the place above", no number', async () => {
      const { wrapper } = await render(v2({ top: [], neighbours: { above: [], below: [] }, me: me({ category: 'free', next_gap: 4 }) }))
      expect(text(wrapper, 'next-gap')).toBe('До места выше — 4 балла')

      setLocale('uz')
      const uz = await render(v2({ top: [], neighbours: { above: [], below: [] }, me: me({ category: 'free', next_gap: 4 }) }))
      expect(text(uz.wrapper, 'next-gap')).toBe("Yuqoridagi o'ringacha — 4 ball")
    })

    it('a page on its way while the table fails to reload: "Show more" is not left disabled', async () => {
      byCategory({ own: v2() })
      const router = makeRouter()
      await router.push('/league/month')
      await router.isReady()
      const wrapper = mount(MonthlyRatingView, { global: { plugins: [router] } })
      await flushPromises()
      vi.mocked(api.league.monthPage).mockReturnValueOnce(new Promise(() => {}))

      await wrapper.get('[data-testid=more]').trigger('click')
      expect(useMonthlyRatingStore().loadingMore).toBe(true)
      vi.mocked(api.league.month).mockRejectedValueOnce(new ApiClientError('network', 0, 'network', 'x'))
      await wrapper.get('[data-testid=category-pro]').trigger('click')
      await flushPromises()

      const store = useMonthlyRatingStore()
      expect(store.status).toBe('error')
      expect(store.loadingMore).toBe(false)
      expect(wrapper.get('[data-testid=more]').attributes('disabled')).toBeUndefined()

      // the next page is asked again on a tap
      vi.mocked(api.league.monthPage).mockResolvedValueOnce(page([boardRow(11)], null))
      await wrapper.get('[data-testid=more]').trigger('click')
      await flushPromises()
      expect(api.league.monthPage).toHaveBeenCalledTimes(2)
    })

    it('switches to the other table: one request of the month, nothing else; the table is read only, no neighbours, my card stays', async () => {
      byCategory({ own: v2(), pro: proTable() })
      const router = makeRouter()
      await router.push('/league/month')
      await router.isReady()
      const wrapper = mount(MonthlyRatingView, { global: { plugins: [router] } })
      await flushPromises()
      const meCalls = vi.mocked(api.me.get).mock.calls.length

      await wrapper.get('[data-testid=category-pro]').trigger('click')
      await flushPromises()

      expect(vi.mocked(api.league.month).mock.calls).toEqual([[{}], [{ category: 'pro' }]])
      expect(api.me.get).toHaveBeenCalledTimes(meCalls)
      expect(api.today.get).not.toHaveBeenCalled()
      expect(wrapper.get('[data-testid=category-pro]').attributes('aria-checked')).toBe('true')
      expect(text(wrapper, 'read-only')).toBe('Это таблица Pro. Вы соревнуетесь в таблице Free.')
      expect(wrapper.get('[data-testid=prizes] h2').text()).toBe('Призы · Pro')
      expect(wrapper.findAll('[data-testid=prize]')[0]?.text()).toBe('1 место — Планшет')
      expect(text(wrapper, 'participants')).toBe('7 участников')
      expect(text(wrapper, 'my-place')).toBe('Вы на 12 месте · 340 баллов')
      expect(has(wrapper, 'neighbours')).toBe(false)
      expect(has(wrapper, 'row-pinned')).toBe(false)
      expect(has(wrapper, 'next-gap')).toBe(false)
      expect(has(wrapper, 'more')).toBe(false)
      expect(wrapper.get('[data-testid=row] [data-testid=pro-emoji]').text()).toBe('🚀')

      // back to my own table, answered moments ago: shown from memory, nothing asked
      await wrapper.get('[data-testid=category-free]').trigger('click')
      await flushPromises()
      expect(api.league.month).toHaveBeenCalledTimes(2)
      expect(has(wrapper, 'read-only')).toBe(false)
      expect(text(wrapper, 'next-gap')).toBe('До 11-го места — 11 баллов')
    })

    it('a tap on the table shown asks nothing', async () => {
      const { wrapper } = await render(v2())
      await wrapper.get('[data-testid=category-free]').trigger('click')
      await flushPromises()

      expect(api.league.month).toHaveBeenCalledTimes(1)
    })

    it('"Show more" loads the page after the cursor and appends its rows as they are; the last page hides the button', async () => {
      const { wrapper } = await render(v2())
      vi.mocked(api.league.monthPage).mockResolvedValueOnce(page([boardRow(11, { pro_emoji: '⭐' }), boardRow(11, { name: 'Tie' })], 'm2026-11f.page3'))

      await wrapper.get('[data-testid=more]').trigger('click')
      await flushPromises()

      expect(api.league.monthPage).toHaveBeenCalledWith({ category: 'free' }, 'm2026-11f.page2')
      const ranks = wrapper.findAll('[data-testid=row]').map((row) => row.get('[data-testid=rank]').text())
      expect(ranks.slice(-2)).toEqual(['11', '11'])
      expect(wrapper.findAll('[data-testid=row]').at(-2)?.get('[data-testid=pro-emoji]').text()).toBe('⭐')

      vi.mocked(api.league.monthPage).mockResolvedValueOnce(page([boardRow(12, { is_me: true, points: 340 }), boardRow(13)], null))
      await wrapper.get('[data-testid=more]').trigger('click')
      await flushPromises()

      expect(api.league.monthPage).toHaveBeenLastCalledWith({ category: 'free' }, 'm2026-11f.page3')
      expect(has(wrapper, 'more')).toBe(false)
      // my row is in the list now: no neighbours block, no pinned row
      expect(has(wrapper, 'row-me')).toBe(true)
      expect(has(wrapper, 'neighbours')).toBe(false)
      expect(has(wrapper, 'row-pinned')).toBe(false)
      expect(api.league.month).toHaveBeenCalledTimes(1)
    })

    it('a double tap on "Show more" sends one request', async () => {
      const { wrapper } = await render(v2())
      let answer: (value: LeagueBoardPage) => void = () => {}
      vi.mocked(api.league.monthPage).mockReturnValueOnce(new Promise((resolve) => (answer = resolve)))

      await wrapper.get('[data-testid=more]').trigger('click')
      expect(wrapper.get('[data-testid=more]').attributes('disabled')).toBeDefined()
      await useMonthlyRatingStore().loadMore()
      answer(page([boardRow(11)], null))
      await flushPromises()

      expect(api.league.monthPage).toHaveBeenCalledTimes(1)
      expect(wrapper.findAll('[data-testid=row]')).toHaveLength(11)
    })

    it('a stale cursor (422 invalid_cursor) reloads the table from its start', async () => {
      const { wrapper } = await render(v2())
      vi.mocked(api.league.monthPage).mockRejectedValueOnce(new ApiClientError('validation', 422, 'invalid_cursor', 'x'))
      vi.mocked(api.league.month).mockResolvedValueOnce(v2({ next_cursor: 'm2026-12f.fresh' }))

      await wrapper.get('[data-testid=more]').trigger('click')
      await flushPromises()

      expect(api.league.month).toHaveBeenCalledTimes(2)
      expect(useMonthlyRatingStore().cursor).toBe('m2026-12f.fresh')
      expect(has(wrapper, 'more-error')).toBe(false)
      expect(wrapper.findAll('[data-testid=row]')).toHaveLength(10)
    })

    it('another failure of a page keeps the list and the button to try again', async () => {
      const { wrapper } = await render(v2())
      vi.mocked(api.league.monthPage).mockRejectedValueOnce(new ApiClientError('network', 0, 'network', 'x'))

      await wrapper.get('[data-testid=more]').trigger('click')
      await flushPromises()

      expect(has(wrapper, 'more-error')).toBe(true)
      expect(wrapper.get('[data-testid=more]').attributes('disabled')).toBeUndefined()
      expect(api.league.month).toHaveBeenCalledTimes(1)
    })

    it('a page that comes back after the table was switched is dropped', async () => {
      byCategory({ own: v2(), pro: proTable() })
      const router = makeRouter()
      await router.push('/league/month')
      await router.isReady()
      const wrapper = mount(MonthlyRatingView, { global: { plugins: [router] } })
      await flushPromises()
      let answer: (value: LeagueBoardPage) => void = () => {}
      vi.mocked(api.league.monthPage).mockReturnValueOnce(new Promise((resolve) => (answer = resolve)))

      await wrapper.get('[data-testid=more]').trigger('click')
      await wrapper.get('[data-testid=category-pro]').trigger('click')
      await flushPromises()
      answer(page([boardRow(11)], 'late'))
      await flushPromises()

      expect(useMonthlyRatingStore().more).toEqual([])
      expect(wrapper.findAll('[data-testid=row]')).toHaveLength(1)
    })

    it('last month: the winners of each table under its name, a table without winners left out', async () => {
      const winners = last().winners
      const { wrapper } = await render(v2({
        last_month: last({ winners, winners_by_category: { free: winners, pro: [{ place: 1, name: 'Pro One', points: 700 }] }, me: { rank: 4, points: 500, category: 'free' } }),
      }))

      expect(wrapper.findAll('[data-testid=winners-category]').map((el) => el.text())).toEqual(['Free', 'Pro'])
      expect(wrapper.findAll('[data-testid=winner]')).toHaveLength(4)
      expect(wrapper.findAll('[data-testid=winner]')[3]?.text()).toContain('Pro One')
      expect(text(wrapper, 'last-me')).toBe('Ваше место: 4')

      const onlyFree = await render(v2({ last_month: last({ winners_by_category: { free: winners, pro: [] } }) }))
      expect(onlyFree.wrapper.findAll('[data-testid=winners-category]').map((el) => el.text())).toEqual(['Free'])
    })

    describe('the tables of a region (docs/35 §2.4, R7)', () => {
      const regionSettings = (code: string | null) =>
        settingsFixture({
          region_code: code,
          region_prompt: false,
          options: { ...settingsFixture().options, regions: [{ code: 'UZ-TK', name: 'город Ташкент' }, { code: 'UZ-BU', name: 'Бухарская область' }] },
        })

      const tashkent = (overrides: Partial<MonthlyRatingResponse> = {}): MonthlyRatingResponse =>
        v2({
          scope: 'region',
          region: { code: 'UZ-TK', name: 'город Ташкент', participants: 30, shown: true, min_participants: 5 },
          prizes: [],
          month: { ...response().month, participants: 30 },
          top: [{ rank: 1, name: 'Tashkent One', points: 900, active_days: 9, is_me: false, long_words: 1 }],
          next_cursor: 'm2026-11f~UZ-TK.page2',
          me: me({ category: 'free', next_gap: 5, region: { code: 'UZ-TK', name: 'город Ташкент', participants: 30, shown: true, min_participants: 5, rank: 4 } }),
          ...overrides,
        })

      async function mountMonth(code: string | null = 'UZ-TK') {
        useSettingsStore().adopt(regionSettings(code))
        const router = makeRouter()
        await router.push('/league/month')
        await router.isReady()
        const wrapper = mount(MonthlyRatingView, { global: { plugins: [router] }, attachTo: document.body })
        await flushPromises()
        return wrapper
      }

      const own = () => v2({ me: me({ category: 'free', long_words: 3, next_gap: 11, region: { code: 'UZ-TK', name: 'город Ташкент', participants: 30, shown: true, min_participants: 5, rank: 4 } }) })

      beforeEach(() => {
        document.body.innerHTML = ''
      })

      it('my place in my region on the card; "My region" asks one table of the region, without prizes', async () => {
        vi.mocked(api.league.month).mockResolvedValueOnce(own()).mockResolvedValueOnce(tashkent())
        const wrapper = await mountMonth()

        expect(text(wrapper, 'region-rank')).toBe('Регион «город Ташкент»: 4 место')
        await wrapper.get('[data-testid=area-mine]').trigger('click')
        await flushPromises()

        expect(vi.mocked(api.league.month).mock.calls).toEqual([[{}], [{ scope: 'region' }]])
        expect(has(wrapper, 'prizes')).toBe(false)
        expect(has(wrapper, 'prize-line')).toBe(false)
        expect(text(wrapper, 'participants')).toBe('30 участников')
        expect(wrapper.findAll('[data-testid=row]').map((r) => r.text())).toEqual([expect.stringContaining('Tashkent One')])

        vi.mocked(api.league.monthPage).mockResolvedValueOnce(page([boardRow(2)], null))
        await wrapper.get('[data-testid=more]').trigger('click')
        await flushPromises()
        expect(api.league.monthPage).toHaveBeenCalledWith({ category: 'free', scope: 'region' }, 'm2026-11f~UZ-TK.page2')
        wrapper.unmount()
      })

      it('another region: the browse sheet, no PATCH /me/settings, read only with the line about my region', async () => {
        vi.mocked(api.league.month)
          .mockResolvedValueOnce(own())
          .mockResolvedValueOnce(tashkent({ region: { code: 'UZ-BU', name: 'Бухарская область', participants: 8, shown: true, min_participants: 5 }, read_only: true, neighbours: null, next_cursor: null }))
        const wrapper = await mountMonth()

        await wrapper.get('[data-testid=area-other]').trigger('click')
        await flushPromises()
        ;(document.querySelector('[data-testid=region-uzbekistan]') as HTMLElement).click()
        await flushPromises()
        ;(document.querySelector('[data-testid=region-UZ-BU]') as HTMLElement).click()
        await flushPromises()

        expect(vi.mocked(api.league.month).mock.calls.at(-1)).toEqual([{ scope: 'region', region: 'UZ-BU' }])
        expect(text(wrapper, 'read-only-region')).toBe('Это таблица региона «Бухарская область». Вы соревнуетесь в регионе «город Ташкент».')
        // the same category: no line about the tables Free / Pro
        expect(has(wrapper, 'read-only')).toBe(false)
        expect(has(wrapper, 'next-gap')).toBe(false)
        expect(has(wrapper, 'neighbours')).toBe(false)
        expect(useSettingsStore().data?.region_code).toBe('UZ-TK')
        wrapper.unmount()
      })

      it('422 region_not_set: the card "Choose your region" instead of the table', async () => {
        vi.mocked(api.league.month).mockResolvedValueOnce(v2()).mockRejectedValueOnce(new ApiClientError('validation', 422, 'region_not_set', 'x'))
        const wrapper = await mountMonth(null)

        await wrapper.get('[data-testid=area-mine]').trigger('click')
        await flushPromises()

        expect(has(wrapper, 'region-not-set')).toBe(true)
        expect(has(wrapper, 'top')).toBe(false)
        expect(has(wrapper, 'reload-error')).toBe(false)

        // "All" again: the table on screen, nothing asked
        await wrapper.get('[data-testid=area-all]').trigger('click')
        await flushPromises()
        expect(has(wrapper, 'region-not-set')).toBe(false)
        expect(has(wrapper, 'top')).toBe(true)
        expect(api.league.month).toHaveBeenCalledTimes(2)
        wrapper.unmount()
      })

      it('below the threshold: "N of M participants" and the way to the invitations, no rows', async () => {
        vi.mocked(api.league.month)
          .mockResolvedValueOnce(own())
          .mockResolvedValueOnce(tashkent({ region: { code: 'UZ-TK', name: 'город Ташкент', participants: 4, shown: false, min_participants: 5 }, top: [], next_cursor: null, neighbours: null }))
        const wrapper = await mountMonth()

        await wrapper.get('[data-testid=area-mine]').trigger('click')
        await flushPromises()

        expect(text(wrapper, 'region-below-title')).toBe('В регионе «город Ташкент» пока 4 из 5 участников')
        expect(has(wrapper, 'top')).toBe(false)
        expect(has(wrapper, 'empty')).toBe(false)
        wrapper.unmount()
      })

      it('422 region_scope_unavailable (a month by the old rules): the region switch is gone, the whole table stays', async () => {
        vi.mocked(api.league.month).mockResolvedValueOnce(v2()).mockRejectedValueOnce(new ApiClientError('validation', 422, 'region_scope_unavailable', 'x'))
        const wrapper = await mountMonth()

        await wrapper.get('[data-testid=area-mine]').trigger('click')
        await flushPromises()

        expect(has(wrapper, 'area-switch')).toBe(false)
        expect(has(wrapper, 'category-switch')).toBe(true)
        expect(has(wrapper, 'top')).toBe(true)
        expect(has(wrapper, 'reload-error')).toBe(false)
        // the whole table was answered moments ago: not asked again
        expect(api.league.month).toHaveBeenCalledTimes(2)
        wrapper.unmount()
      })
    })

    it('"Show more" while another table is on its way does nothing; the new table has none of the old rows', async () => {
      byCategory({ own: v2() })
      const wrapper = (await render(v2())).wrapper
      let resolveTable: (value: MonthlyRatingResponse) => void = () => {}
      vi.mocked(api.league.month).mockReturnValueOnce(new Promise((r) => (resolveTable = r)))

      await wrapper.get('[data-testid=category-pro]').trigger('click')
      expect(wrapper.get('[data-testid=more]').attributes('disabled')).toBeDefined()
      await useMonthlyRatingStore().loadMore()
      expect(api.league.monthPage).not.toHaveBeenCalled()

      resolveTable(proTable())
      await flushPromises()
      expect(useMonthlyRatingStore().more).toEqual([])
      expect(wrapper.findAll('[data-testid=row]')).toHaveLength(1)
    })

    it('a page asked before the switch that comes back after the new table is dropped', async () => {
      const { wrapper } = await render(v2())
      let resolvePage: (value: LeagueBoardPage) => void = () => {}
      let resolveTable: (value: MonthlyRatingResponse) => void = () => {}
      vi.mocked(api.league.monthPage).mockReturnValueOnce(new Promise((r) => (resolvePage = r)))
      vi.mocked(api.league.month).mockReturnValueOnce(new Promise((r) => (resolveTable = r)))

      await wrapper.get('[data-testid=more]').trigger('click')
      await wrapper.get('[data-testid=category-pro]').trigger('click')
      resolveTable(proTable())
      await flushPromises()
      resolvePage(page([boardRow(11), boardRow(12)], 'late'))
      await flushPromises()

      const store = useMonthlyRatingStore()
      expect(store.more).toEqual([])
      expect(store.cursor).toBeNull()
      expect(store.loadingMore).toBe(false)
      expect(wrapper.findAll('[data-testid=row]')).toHaveLength(1)
    })

    it('in Uzbek', async () => {
      setLocale('uz')
      byCategory({ own: v2(), pro: proTable() })
      const router = makeRouter()
      await router.push('/league/month')
      await router.isReady()
      const wrapper = mount(MonthlyRatingView, { global: { plugins: [router] } })
      await flushPromises()

      expect(text(wrapper, 'next-gap')).toBe("11-o'ringacha — 11 ball")
      expect(wrapper.get('[data-testid=more]').text()).toBe("Yana ko'rsatish")
      await wrapper.get('[data-testid=category-pro]').trigger('click')
      await flushPromises()
      expect(text(wrapper, 'read-only')).toBe('Bu Pro jadvali. Siz Free jadvalida bellashyapsiz.')
      expect(wrapper.get('[data-testid=prizes] h2').text()).toBe('Sovrinlar · Pro')
    })
  })
})
