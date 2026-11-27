import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import TodayView from '../TodayView.vue'
import ProfileView from '../ProfileView.vue'
import CupsView from '../CupsView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { settingsFixture } from '@/api/__tests__/settingsFixture'
import { cupFields, cupListItem } from '@/api/__tests__/cupFixtures'
import { setLocale } from '@/i18n'
import { CUPS_FRESH_MS, resetCupsInFlight, useCupsStore } from '@/stores/cups'
import type { CupListResponse, CupTodayState, MeResponse, TodayResponse } from '@/api/types'

/**
 * The entries of the friends' cup (docs/33 §7.1): the card on "Today" and the row in "Profile" from ONE light `GET /cups` with a short
 * freshness; a switched off cup (`cup_unavailable`) shows neither and is not asked again in the session. And the list `/cups`.
 */

vi.mock('@/api', () => ({
  api: {
    today: { get: vi.fn() },
    assignments: { list: vi.fn() },
    training: { open: vi.fn() },
    me: { get: vi.fn() },
    gameShop: { profile: vi.fn() },
    billing: { catalog: vi.fn(), offerViewed: vi.fn() },
    cups: { list: vi.fn() },
  },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined), learner: { id: 1, first_name: 'Aziza' } }) }))
vi.mock('@/composables/useBackButton', () => ({ useBackButton: vi.fn() }))

const today = (): TodayResponse => ({
  due_count: 3,
  new_words_remaining: null,
  goal: { target: 20, done: 8, met: false },
  streak: { current: 5, best: 12, met_today: false, at_risk: false, freezes: 0, frozen_yesterday: false },
  active_course: { id: 3, slug: 'english-a1', title: 'English A1', progress: { learned: 120, total: 900 }, next_lesson: null },
  open_session_id: null,
  entitlement: { plan: 'pro', source: 'subscription', until: null },
  onboarding_completed: true,
  league: null,
  timezone: 'Asia/Tashkent',
})

const me = (): MeResponse => ({
  learner: { id: 1, first_name: 'Aziza', username: null, locale: 'ru', onboarding_completed: true },
  groups: [],
  stats: { words_learned: 0, words_due_today: 0 },
  entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 20 },
  paywall: { mode: 'purchase', contact: null },
  settings: settingsFixture(),
})

const routes = ['today', 'courses', 'billing', 'league', 'settings', 'profile', 'referrals', 'achievements', 'friends', 'cups'].map((name) => ({
  path: `/${name}`,
  name,
  component: { template: '<div />' },
}))

async function mountView(view: typeof TodayView | typeof ProfileView | typeof CupsView) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [...routes, { path: '/cups/:id(\\d+)', name: 'cup', component: { template: '<div />' } }, { path: '/game/caravan', name: 'game-caravan', component: { template: '<div />' } }],
  })
  const wrapper = mount(view, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

const list = (...cups: CupListResponse['cups']): CupListResponse => ({ cups })
const cupListItemToday = (state: CupTodayState, over: Partial<CupListResponse['cups'][number]> = {}) =>
  cupListItem({ me: { participant_id: 2, display_name: 'Me', status: 'active', total: 20, days_played: 2, final_rank: null, today: { state, day: state === 'not_available' ? null : 3 } }, ...over })
const off = () => new ApiClientError('forbidden', 403, 'cup_unavailable', 'The cup is not available in this chat.')

beforeEach(() => {
  setActivePinia(createPinia())
  resetCupsInFlight()
  vi.clearAllMocks()
  setLocale('ru')
  vi.mocked(api.today.get).mockResolvedValue(today())
  vi.mocked(api.assignments.list).mockResolvedValue({ assignments: [] })
  vi.mocked(api.me.get).mockResolvedValue(me())
  vi.spyOn(console, 'warn').mockImplementation(() => {})
})

describe('Today: the card of the cup', () => {
  it('a running cup: "Cup «Group» · Day 3 of 7" leads to the cup', async () => {
    vi.mocked(api.cups.list).mockResolvedValue(list(cupListItemToday('not_started')))
    const { wrapper, router } = await mountView(TodayView)

    expect(api.cups.list).toHaveBeenCalledTimes(1)
    expect(wrapper.get('[data-testid=cup-card-title]').text()).toBe('Кубок «English Friends»')
    expect(wrapper.get('[data-testid=cup-card-text]').text()).toBe('День 3 из 7 · сыграйте раунд дня')
    expect(wrapper.get('[data-testid=cup-card-btn]').text()).toBe('Играть')
    await wrapper.get('[data-testid=cup-card]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.fullPath).toBe('/cups/5')
  })

  it.each([
    ['not_started', 'День 3 из 7 · сыграйте раунд дня', 'Играть'],
    ['active', 'День 3 из 7 · продолжите раунд', 'Продолжить'],
    ['done', 'День 3 из 7 · раунд сыгран', null],
    ['not_available', 'День 3 из 7', null],
  ] as const)('me.today.state %s: "%s", call %s', async (state, text, call) => {
    vi.mocked(api.cups.list).mockResolvedValue(list(cupListItemToday(state)))
    const { wrapper } = await mountView(TodayView)

    const card = wrapper.get('[data-testid=cup-card]')
    expect(card.attributes('data-state')).toBe(state)
    expect(wrapper.get('[data-testid=cup-card-text]').text()).toBe(text)
    if (call === null) expect(wrapper.find('[data-testid=cup-card-btn]').exists()).toBe(false)
    else expect(wrapper.get('[data-testid=cup-card-btn]').text()).toBe(call)
  })

  it('a played round is put after the other rows of "For today"', async () => {
    vi.mocked(api.today.get).mockResolvedValue({
      ...today(),
      game: { daily_available: false, active_run: null, class_caravan: { id: 4, stars_total: 10, target_stars: 50, ends_at: '2026-12-05T19:00:00.000Z' } },
    } as TodayResponse)
    vi.mocked(api.cups.list).mockResolvedValue(list(cupListItemToday('done')))
    const { wrapper } = await mountView(TodayView)

    const ids = Array.from(wrapper.get('[data-testid=today-list] > div').element.children).map((el) => el.getAttribute('data-testid'))
    expect(ids.indexOf('cup-card')).toBeGreaterThan(ids.indexOf('class-caravan-card'))
    expect(ids.indexOf('class-caravan-card')).toBeGreaterThanOrEqual(0)

    vi.mocked(api.cups.list).mockResolvedValue(list(cupListItemToday('not_started')))
    useCupsStore().checkedAt = null
    const second = await mountView(TodayView)
    const before = Array.from(second.wrapper.get('[data-testid=today-list] > div').element.children).map((el) => el.getAttribute('data-testid'))
    expect(before.indexOf('cup-card')).toBeLessThan(before.indexOf('class-caravan-card'))
  })

  it('an older server without me.today: the day and "Open", no guess about the round', async () => {
    vi.mocked(api.cups.list).mockResolvedValue(list(cupListItem()))
    const { wrapper } = await mountView(TodayView)

    expect(wrapper.get('[data-testid=cup-card]').attributes('data-state')).toBe('unknown')
    expect(wrapper.get('[data-testid=cup-card-text]').text()).toBe('День 3 из 7')
    expect(wrapper.get('[data-testid=cup-card-btn]').text()).toBe('Открыть')
  })

  it('no card for a cup that waits, is over, is paused, or that the learner left', async () => {
    vi.mocked(api.cups.list).mockResolvedValue(
      list(
        cupListItem({ ...cupFields({ id: 1, status: 'scheduled', day: null }) }),
        cupListItem({ ...cupFields({ id: 2, status: 'finished', day: null }) }),
        cupListItem({ ...cupFields({ id: 3, paused: 'blocked' }) }),
        cupListItem({ ...cupFields({ id: 4 }), me: { participant_id: 1, display_name: 'A', status: 'left', total: 0, days_played: 0, final_rank: null } }),
      ),
    )
    const { wrapper } = await mountView(TodayView)

    expect(wrapper.find('[data-testid=cup-card]').exists()).toBe(false)
  })

  it('"Today" opened again within the freshness asks nothing more; after it - once more', async () => {
    vi.mocked(api.cups.list).mockResolvedValue(list(cupListItem()))
    const first = await mountView(TodayView)
    first.wrapper.unmount()
    const second = await mountView(TodayView)

    expect(api.cups.list).toHaveBeenCalledTimes(1)
    expect(second.wrapper.find('[data-testid=cup-card]').exists()).toBe(true)

    useCupsStore().checkedAt = Date.now() - CUPS_FRESH_MS - 1
    await mountView(TodayView)
    expect(api.cups.list).toHaveBeenCalledTimes(2)
  })

  it('the cup switched off: no card, and neither Today nor Profile asks again in the session', async () => {
    vi.mocked(api.cups.list).mockRejectedValue(off())
    const first = await mountView(TodayView)

    expect(first.wrapper.find('[data-testid=cup-card]').exists()).toBe(false)
    expect(first.wrapper.find('[data-testid=today-view]').exists()).toBe(true)
    useCupsStore().checkedAt = Date.now() - CUPS_FRESH_MS - 1

    await mountView(TodayView)
    const profile = await mountView(ProfileView)

    expect(api.cups.list).toHaveBeenCalledTimes(1)
    expect(profile.wrapper.find('[data-testid=row-cups]').exists()).toBe(false)
  })

  it('a failed read is silent and Today stays', async () => {
    vi.mocked(api.cups.list).mockRejectedValue(new ApiClientError('network', 0, 'network', 'down'))
    const { wrapper } = await mountView(TodayView)

    expect(wrapper.find('[data-testid=cup-card]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=reload-error]').exists()).toBe(false)
  })

  it('uz', async () => {
    setLocale('uz')
    vi.mocked(api.cups.list).mockResolvedValue(list(cupListItemToday('not_started')))
    const { wrapper } = await mountView(TodayView)

    expect(wrapper.get('[data-testid=cup-card-title]').text()).toBe('«English Friends» kubogi')
    expect(wrapper.get('[data-testid=cup-card-text]').text()).toBe("7 kundan 3-kun · kun raundini o'ynang")
    expect(wrapper.get('[data-testid=cup-card-btn]').text()).toBe("O'ynash")
  })

  it.each([
    ['active', '7 kundan 3-kun · raundni davom ettiring', 'Davom ettirish'],
    ['done', "7 kundan 3-kun · raund o'ynaldi", null],
    ['not_available', '7 kundan 3-kun', null],
  ] as const)('uz: me.today.state %s', async (state, text, call) => {
    setLocale('uz')
    vi.mocked(api.cups.list).mockResolvedValue(list(cupListItemToday(state)))
    const { wrapper } = await mountView(TodayView)

    expect(wrapper.get('[data-testid=cup-card-text]').text()).toBe(text)
    if (call === null) expect(wrapper.find('[data-testid=cup-card-btn]').exists()).toBe(false)
    else expect(wrapper.get('[data-testid=cup-card-btn]').text()).toBe(call)
  })
})

describe('Profile: the row "Friends\' cups"', () => {
  it('shown once the learner has a cup; leads to the list', async () => {
    vi.mocked(api.cups.list).mockResolvedValue(list(cupListItem()))
    const { wrapper, router } = await mountView(ProfileView)

    expect(wrapper.get('[data-testid=row-cups]').text()).toContain('Кубки друзей')
    await wrapper.get('[data-testid=row-cups]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.fullPath).toBe('/cups')
  })

  it('no cups - no row', async () => {
    vi.mocked(api.cups.list).mockResolvedValue(list())
    const { wrapper } = await mountView(ProfileView)

    expect(wrapper.find('[data-testid=row-cups]').exists()).toBe(false)
  })

  it('the cup switched off - no row', async () => {
    vi.mocked(api.cups.list).mockRejectedValue(off())
    const { wrapper } = await mountView(ProfileView)

    expect(wrapper.find('[data-testid=row-cups]').exists()).toBe(false)
  })
})

describe('CupsView: my cups', () => {
  it('the open first, then the over; my points and the final place', async () => {
    vi.mocked(api.cups.list).mockResolvedValue(
      list(cupListItem(), cupListItem({ ...cupFields({ id: 9, status: 'finished', day: null, group_title: 'Old' }), me: { participant_id: 3, display_name: 'Me', status: 'active', total: 60, days_played: 6, final_rank: 1 } })),
    )
    const { wrapper } = await mountView(CupsView)

    expect(wrapper.get('[data-testid=cups-open]').text()).toContain('English Friends')
    expect(wrapper.get('[data-testid=cups-open]').text()).toContain('День 3 из 7 · 20 очков')
    expect(wrapper.get('[data-testid=cups-over]').text()).toContain('Кубок завершён · 60 очков · Итоговое место: 1')
  })

  it.each([
    ['not_started', 'День 3 из 7 · сыграйте раунд дня · 20 очков', "7 kundan 3-kun · kun raundini o'ynang · 20 ball"],
    ['active', 'День 3 из 7 · продолжите раунд · 20 очков', '7 kundan 3-kun · raundni davom ettiring · 20 ball'],
    ['done', 'День 3 из 7 · раунд сыгран · 20 очков', "7 kundan 3-kun · raund o'ynaldi · 20 ball"],
    ['not_available', 'День 3 из 7 · 20 очков', '7 kundan 3-kun · 20 ball'],
  ] as const)('the round of today of an open cup: %s (ru, uz)', async (state, ru, uz) => {
    vi.mocked(api.cups.list).mockResolvedValue(list(cupListItemToday(state)))
    const first = await mountView(CupsView)
    expect(first.wrapper.get('[data-testid=cup-row]').text()).toContain(ru)
    first.wrapper.unmount()

    setLocale('uz')
    const second = await mountView(CupsView)
    expect(second.wrapper.get('[data-testid=cup-row]').text()).toContain(uz)
  })

  it('a paused cup or one the learner left says nothing about the round', async () => {
    vi.mocked(api.cups.list).mockResolvedValue(
      list(
        cupListItemToday('not_started', { ...cupFields({ id: 1, paused: 'blocked' }) }),
        cupListItem({ ...cupFields({ id: 2 }), me: { participant_id: 1, display_name: 'A', status: 'left', total: 4, days_played: 1, final_rank: null, today: { state: 'not_started', day: 3 } } }),
      ),
    )
    const { wrapper } = await mountView(CupsView)

    expect(wrapper.text()).not.toContain('раунд')
  })

  it('always reads the list, even a young one', async () => {
    vi.mocked(api.cups.list).mockResolvedValue(list())
    useCupsStore().checkedAt = Date.now()
    const { wrapper } = await mountView(CupsView)

    expect(api.cups.list).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-testid=empty]').exists()).toBe(true)
    // no cups: the centred hero of the kit, the mascot greets
    expect(wrapper.get('[data-testid=empty] [data-testid=mascot-art]').attributes('data-pose')).toBe('hello')
  })

  it('the cup switched off', async () => {
    vi.mocked(api.cups.list).mockRejectedValue(off())
    const { wrapper } = await mountView(CupsView)

    expect(wrapper.find('[data-testid=off]').exists()).toBe(true)
  })

  it('no connection: "Retry"', async () => {
    vi.mocked(api.cups.list).mockRejectedValue(new ApiClientError('network', 0, 'network', 'down'))
    const { wrapper } = await mountView(CupsView)

    expect(wrapper.find('[data-testid=retry-btn]').exists()).toBe(true)
  })
})
