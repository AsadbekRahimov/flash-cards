import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import AllTimeRatingView from '../AllTimeRatingView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { settingsFixture } from '@/api/__tests__/settingsFixture'
import { setLocale } from '@/i18n'
import { useAllTimeRatingStore } from '@/stores/allTimeRating'
import { BOARD_FRESH_MS } from '@/stores/ratingBoard'
import { useSettingsStore } from '@/stores/settings'
import type { AllTimeMe, AllTimeResponse, BoardQuery, BoardRow, LeagueBoardPage, LearnerSettings, MeResponse } from '@/api/types'

/*
 * "All time" (docs/32 §12.11, docs/04 A.19a, docs/35 §2.4, R8 / S13b): the hero with my place, the next gap, my region and my own sum;
 * Free / Pro and "All / My region / Another region…"; the region states; the table with "Show more" and its races; the requests.
 */

const state = vi.hoisted(() => ({ ensure: vi.fn(), showBack: vi.fn(), hideBack: vi.fn() }))

vi.mock('@/api', () => ({
  api: {
    league: { get: vi.fn(), month: vi.fn(), monthPage: vi.fn(), allTime: vi.fn(), allTimePage: vi.fn() },
    me: { get: vi.fn(), updateSettings: vi.fn(), revertRegion: vi.fn(), dismissRegionPrompt: vi.fn() },
    today: { get: vi.fn() },
  },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: state.ensure, learner: { id: 1, locale: 'ru' } }) }))
vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ showBackButton: state.showBack, webApp: { BackButton: { hide: state.hideBack } } }),
}))

const row = (rank: number, overrides: Partial<BoardRow> = {}): BoardRow => ({
  rank, name: `Member ${rank}`, points: 10000 - rank * 10, long_words: 1, active_days: 5, is_me: false, pro_emoji: null, ...overrides,
})

const myRow = (overrides: Partial<AllTimeMe> = {}): AllTimeMe => ({
  category: 'free',
  rank: 340,
  points: 1400,
  long_words: 12,
  active_days: 31,
  participant: true,
  not_counted_reason: null,
  first_day: '2027-02-03',
  last_day: '2027-03-06',
  next_gap: 8,
  region: { code: 'UZ-TK', name: 'город Ташкент', participants: 13502, shown: true, min_participants: 5, rank: 112 },
  ...overrides,
})

/** The learner's own table (Free), the whole category: 340th place, the top of 10, neighbours, more pages. */
const answer = (overrides: Partial<AllTimeResponse> = {}): AllTimeResponse => ({
  enabled: true,
  period: 'all_time',
  rules_version: 2,
  counted_since: '2027-02-01',
  category: 'free',
  my_category: 'free',
  read_only: false,
  opted_out: false,
  scope: 'all',
  region: null,
  participants: 38623,
  top: Array.from({ length: 10 }, (_, i) => row(i + 1, { pro_emoji: i === 0 ? '🦁' : null })),
  next_cursor: 'af.page2',
  neighbours: { above: [row(338), row(339)], below: [row(341), row(342)] },
  me: myRow(),
  my_total: { points: 2350, free: 1400, pro: 950 },
  ...overrides,
})

/** The Pro table for a Free learner: read only, no row of mine. */
const proTable = (): AllTimeResponse =>
  answer({ category: 'pro', read_only: true, participants: 7, top: [row(1, { name: 'Pro One', pro_emoji: '🚀' })], next_cursor: null, neighbours: null, me: null })

/** The table of my region. */
const myRegionTable = (overrides: Partial<AllTimeResponse> = {}): AllTimeResponse =>
  answer({
    scope: 'region',
    region: { code: 'UZ-TK', name: 'город Ташкент', participants: 13502, shown: true, min_participants: 5 },
    participants: 13502,
    top: [row(1, { name: 'Tashkent One' }), row(2)],
    next_cursor: 'af~UZ-TK.page2',
    neighbours: { above: [row(110), row(111)], below: [row(113)] },
    me: myRow({ rank: 112, next_gap: 3 }),
    ...overrides,
  })

/** The table of Bukhara, opened from the sheet: read only. */
const bukharaTable = (): AllTimeResponse =>
  answer({
    scope: 'region',
    region: { code: 'UZ-BU', name: 'Бухарская область', participants: 40, shown: true, min_participants: 5 },
    read_only: true,
    participants: 40,
    top: [row(1, { name: 'Bukhara One' })],
    next_cursor: null,
    neighbours: null,
    me: myRow({ next_gap: null }),
  })

const page = (rows: BoardRow[], next: string | null = null): LeagueBoardPage => ({
  rules_version: 2, period: 'all_time', category: 'free', read_only: false, rows, next_cursor: next, scope: 'all', region: null,
})

const settings = (overrides: Partial<LearnerSettings> = {}): LearnerSettings =>
  settingsFixture({
    region_code: 'UZ-TK',
    region_prompt: false,
    ...overrides,
    options: {
      ...settingsFixture().options,
      regions: [
        { code: 'UZ-TK', name: 'город Ташкент' },
        { code: 'UZ-BU', name: 'Бухарская область' },
        { code: 'other', name: 'Другая страна' },
      ],
    },
  })

const meResponse = (s: LearnerSettings): MeResponse => ({
  learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
  groups: [],
  stats: { words_learned: 0, words_due_today: 0 },
  entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 10 },
  paywall: { mode: 'coming_soon', contact: null },
  settings: s,
})

const makeRouter = () =>
  createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: { template: '<div />' } },
      { path: '/settings', name: 'settings', component: { template: '<div />' } },
      { path: '/friends', name: 'friends', component: { template: '<div />' } },
      { path: '/referrals', name: 'referrals', component: { template: '<div />' } },
      { path: '/league', name: 'league', component: { template: '<div />' } },
      { path: '/league/month', name: 'league-month', component: { template: '<div />' } },
      { path: '/league/all-time', name: 'league-all-time', component: AllTimeRatingView },
    ],
  })

/** The tables by what is asked: `own` - nothing (the learner's own, all), else `category|scope|region`. */
function tables(map: Record<string, AllTimeResponse>) {
  vi.mocked(api.league.allTime).mockImplementation(async (query: BoardQuery = {}) => {
    const key = Object.keys(query).length === 0 ? 'own' : `${query.category ?? ''}|${query.scope ?? ''}|${query.region ?? ''}`
    const found = map[key]
    if (!found) throw new Error(`no table for ${key}`)
    return found
  })
}

async function render(opts: { data?: AllTimeResponse; settings?: LearnerSettings | null } = {}) {
  if (opts.data) vi.mocked(api.league.allTime).mockResolvedValue(opts.data)
  const s = opts.settings === undefined ? settings() : opts.settings
  if (s !== null) useSettingsStore().adopt(s)
  const router = makeRouter()
  await router.push('/league/all-time')
  await router.isReady()
  const wrapper = mount(AllTimeRatingView, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

type Wrapper = Awaited<ReturnType<typeof render>>['wrapper']
const text = (w: Wrapper, id: string): string => w.get(`[data-testid=${id}]`).text()
const has = (w: Wrapper, id: string): boolean => w.find(`[data-testid=${id}]`).exists()
const click = async (selector: string): Promise<void> => {
  ;(document.querySelector(selector) as HTMLElement).click()
  await flushPromises()
}
const ranks = (w: Wrapper): string[] => w.findAll('[data-testid=row]').map((r) => r.get('[data-testid=rank]').text())

describe('AllTimeRatingView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    vi.restoreAllMocks()
    state.ensure.mockResolvedValue(undefined)
    vi.mocked(api.me.get).mockReturnValue(new Promise(() => {}))
    document.body.innerHTML = ''
  })
  afterEach(() => setLocale('ru'))

  it('the hero: my place, the way to the next one, my place in my region, my own sum in one line and since when it counts', async () => {
    const { wrapper } = await render({ data: answer() })

    expect(api.league.allTime).toHaveBeenCalledWith({})
    expect(wrapper.get('h1').text()).toBe('Рейтинг за всё время')
    expect(text(wrapper, 'all-time')).toContain('Считается с 1 февраля 2027')
    expect(text(wrapper, 'my-place')).toBe('Вы на 340 месте · 1400 баллов')
    // the nearest better row on screen is the neighbour on the 339th place
    expect(text(wrapper, 'next-gap')).toBe('До 339-го места — 8 баллов')
    expect(text(wrapper, 'region-rank')).toBe('Регион «город Ташкент»: 112 место')
    expect(text(wrapper, 'my-total')).toBe('Мои баллы за всё время: 2350 баллов (Free 1400 + Pro 950)')
    expect(text(wrapper, 'participants')).toBe('38623 участника')
    expect(wrapper.get('[data-testid=top] h2').text()).toBe('Лучшие за всё время')
    expect(wrapper.findAll('[data-testid=row]')).toHaveLength(10)
    expect(wrapper.get('[data-testid=row] [data-testid=pro-emoji]').text()).toBe('🦁')
    expect(wrapper.findAll('[data-testid=row-near]').map((r) => r.get('[data-testid=rank]').text())).toEqual(['338', '339', '341', '342'])
    expect(wrapper.get('[data-testid=row-near-me] [data-testid=rank]').text()).toBe('340')
    expect(wrapper.get('[data-testid=seg-all-time]').attributes('aria-current')).toBe('page')
    expect(wrapper.get('[data-testid=category-free]').attributes('aria-checked')).toBe('true')
    expect(wrapper.get('[data-testid=area-all]').attributes('aria-checked')).toBe('true')
    expect(has(wrapper, 'read-only')).toBe(false)
    expect(has(wrapper, 'read-only-region')).toBe(false)
    expect(state.showBack).not.toHaveBeenCalled()
  })

  it('my own sum: only the total when one table has no points; the opt-out keeps it and says why there is no place', async () => {
    const { wrapper } = await render({ data: answer({ my_total: { points: 1400, free: 1400, pro: null } }) })
    expect(text(wrapper, 'my-total')).toBe('Мои баллы за всё время: 1400 баллов')

    setActivePinia(createPinia())
    const optedOut = await render({
      data: answer({ opted_out: true, me: myRow({ rank: null, participant: false, not_counted_reason: 'opted_out', next_gap: null }) }),
    })
    expect(text(optedOut.wrapper, 'opted-out')).toBe('Вы не участвуете в рейтингах. Включить можно в настройках')
    expect(has(optedOut.wrapper, 'my-place')).toBe(false)
    expect(text(optedOut.wrapper, 'my-total')).toBe('Мои баллы за всё время: 2350 баллов (Free 1400 + Pro 950)')
    expect(has(optedOut.wrapper, 'settings-link')).toBe(true)

    setActivePinia(createPinia())
    const blocked = await render({ data: answer({ me: myRow({ rank: null, participant: false, not_counted_reason: 'blocked', next_gap: null }) }) })
    expect(text(blocked.wrapper, 'my-place')).toBe('Пока без места · 1400 баллов')
    expect(text(blocked.wrapper, 'not-counted')).toBe('Ваш аккаунт не участвует в рейтинге, напишите в поддержку')
  })

  it('a learner without points: the way to the plan of the day', async () => {
    const { wrapper } = await render({ data: answer({ me: null, neighbours: null, my_total: { points: 0, free: null, pro: null } }) })

    expect(text(wrapper, 'no-row')).toBe('Занимайтесь — баллы попадут в рейтинг за всё время')
    expect(has(wrapper, 'start-btn')).toBe(true)
    expect(has(wrapper, 'neighbours')).toBe(false)
  })

  describe('Free / Pro', () => {
    it('the other table: one request, read only, no neighbours, no next gap; back to mine from memory', async () => {
      tables({ own: answer(), 'pro||': proTable() })
      const { wrapper } = await render()

      await wrapper.get('[data-testid=category-pro]').trigger('click')
      await flushPromises()

      expect(vi.mocked(api.league.allTime).mock.calls).toEqual([[{}], [{ category: 'pro' }]])
      expect(wrapper.get('[data-testid=category-pro]').attributes('aria-checked')).toBe('true')
      expect(text(wrapper, 'read-only')).toBe('Это таблица Pro. Вы соревнуетесь в таблице Free.')
      expect(text(wrapper, 'no-row')).toBe('В таблице Pro у вас пока нет баллов')
      expect(text(wrapper, 'my-total')).toContain('2350')
      expect(text(wrapper, 'participants')).toBe('7 участников')
      expect(has(wrapper, 'neighbours')).toBe(false)
      expect(has(wrapper, 'next-gap')).toBe(false)
      expect(has(wrapper, 'more')).toBe(false)

      await wrapper.get('[data-testid=category-free]').trigger('click')
      await flushPromises()
      expect(api.league.allTime).toHaveBeenCalledTimes(2)
      expect(text(wrapper, 'my-place')).toBe('Вы на 340 месте · 1400 баллов')
      expect(has(wrapper, 'read-only')).toBe(false)
    })

    it('a table answered longer ago than the freshness is asked again', async () => {
      tables({ own: answer(), 'pro||': proTable(), 'free||': answer() })
      const { wrapper } = await render()
      await wrapper.get('[data-testid=category-pro]').trigger('click')
      await flushPromises()

      const later = Date.now() + BOARD_FRESH_MS + 1
      vi.spyOn(Date, 'now').mockReturnValue(later)
      await wrapper.get('[data-testid=category-free]').trigger('click')
      await flushPromises()

      expect(vi.mocked(api.league.allTime).mock.calls.at(-1)).toEqual([{ category: 'free' }])
      expect(api.league.allTime).toHaveBeenCalledTimes(3)
    })
  })

  describe('the tables of a region', () => {
    it('my region: one request, my place in it, neighbours inside it; no other request of the app', async () => {
      tables({ own: answer(), '|region|': myRegionTable() })
      const { wrapper } = await render()

      await wrapper.get('[data-testid=area-mine]').trigger('click')
      await flushPromises()

      expect(vi.mocked(api.league.allTime).mock.calls.at(-1)).toEqual([{ scope: 'region' }])
      expect(wrapper.get('[data-testid=area-mine]').attributes('aria-checked')).toBe('true')
      expect(text(wrapper, 'my-place')).toBe('Вы на 112 месте · 1400 баллов')
      expect(text(wrapper, 'participants')).toBe('13502 участника')
      expect(ranks(wrapper)).toEqual(['1', '2'])
      expect(has(wrapper, 'read-only-region')).toBe(false)
      expect(has(wrapper, 'region-not-set')).toBe(false)

      // back to all: from memory
      await wrapper.get('[data-testid=area-all]').trigger('click')
      await flushPromises()
      expect(api.league.allTime).toHaveBeenCalledTimes(2)
      expect(ranks(wrapper)).toHaveLength(10)
      expect(api.me.get).not.toHaveBeenCalled()
      expect(api.today.get).not.toHaveBeenCalled()
      expect(api.league.get).not.toHaveBeenCalled()
      expect(api.league.month).not.toHaveBeenCalled()
    })

    it('another region: picked in the sheet without saving it, read only, the line says where the learner competes', async () => {
      tables({ own: answer(), '|region|UZ-BU': bukharaTable() })
      const { wrapper } = await render()

      await wrapper.get('[data-testid=area-other]').trigger('click')
      await flushPromises()
      expect(document.querySelector('[data-testid=region-title]')?.textContent).toBe('Таблица какого региона?')
      // only a choice: no "Rather not say"
      expect(document.querySelector('[data-testid=region-none]')).toBeNull()
      await click('[data-testid=region-uzbekistan]')
      await click('[data-testid=region-UZ-BU]')

      expect(vi.mocked(api.league.allTime).mock.calls.at(-1)).toEqual([{ scope: 'region', region: 'UZ-BU' }])
      expect(api.me.updateSettings).not.toHaveBeenCalled()
      expect(document.querySelector('[data-testid=region-sheet]')).toBeNull()
      expect(text(wrapper, 'area-other')).toBe('Бухарская область')
      expect(wrapper.get('[data-testid=area-other]').attributes('aria-checked')).toBe('true')
      expect(text(wrapper, 'read-only-region')).toBe('Это таблица региона «Бухарская область». Вы соревнуетесь в регионе «город Ташкент».')
      expect(has(wrapper, 'neighbours')).toBe(false)
      expect(has(wrapper, 'next-gap')).toBe(false)
      expect(has(wrapper, 'row-pinned')).toBe(false)
      expect(ranks(wrapper)).toEqual(['1'])
      expect(useSettingsStore().data?.region_code).toBe('UZ-TK')
    })

    it('another region for a learner without one: "you have not chosen your region"', async () => {
      tables({ own: answer({ me: myRow({ region: null }) }), '|region|UZ-BU': { ...bukharaTable(), me: myRow({ region: null, next_gap: null }) } })
      const { wrapper } = await render({ settings: settings({ region_code: null }) })

      await wrapper.get('[data-testid=area-other]').trigger('click')
      await flushPromises()
      await click('[data-testid=region-uzbekistan]')
      await click('[data-testid=region-UZ-BU]')

      expect(text(wrapper, 'read-only-region')).toBe('Это таблица региона «Бухарская область». Вы не указали свой регион.')
      expect(has(wrapper, 'region-rank')).toBe(false)
    })

    it('no region (422 region_not_set): the card instead of the table; the region chosen there is saved and the table asked again', async () => {
      tables({ own: answer({ me: myRow({ region: null }) }), '|region|': myRegionTable() })
      vi.mocked(api.league.allTime).mockImplementationOnce(async () => answer({ me: myRow({ region: null }) }))
      vi.mocked(api.league.allTime).mockImplementationOnce(async () => {
        throw new ApiClientError('validation', 422, 'region_not_set', 'x')
      })
      vi.mocked(api.me.updateSettings).mockResolvedValue(meResponse(settings({ region_code: 'UZ-TK' })))
      const { wrapper } = await render({ settings: settings({ region_code: null }) })

      await wrapper.get('[data-testid=area-mine]').trigger('click')
      await flushPromises()

      expect(wrapper.get('[data-testid=region-not-set] h2').text()).toBe('Укажите регион, чтобы участвовать')
      expect(text(wrapper, 'region-not-set')).toContain('Сменить регион можно раз в 30 дней')
      expect(has(wrapper, 'top')).toBe(false)
      expect(has(wrapper, 'region-next-change')).toBe(false)
      expect(has(wrapper, 'reload-error')).toBe(false)
      // the hero stays
      expect(has(wrapper, 'my-place')).toBe(true)

      await wrapper.get('[data-testid=region-choose]').trigger('click')
      await flushPromises()
      expect(document.querySelector('[data-testid=region-title]')?.textContent).toBe('Где вы живёте?')
      await click('[data-testid=region-uzbekistan]')
      await click('[data-testid=region-UZ-TK]')

      expect(vi.mocked(api.me.updateSettings).mock.calls).toEqual([[{ region_code: 'UZ-TK', region_via: 'prompt' }]])
      expect(vi.mocked(api.league.allTime).mock.calls.at(-1)).toEqual([{ scope: 'region' }])
      expect(has(wrapper, 'region-not-set')).toBe(false)
      expect(ranks(wrapper)).toEqual(['1', '2'])
    })

    it('the card says from when another region can be chosen (the 30 days after the last change)', async () => {
      vi.mocked(api.league.allTime).mockResolvedValueOnce(answer({ me: myRow({ region: null }) }))
      vi.mocked(api.league.allTime).mockRejectedValueOnce(new ApiClientError('validation', 422, 'region_not_set', 'x'))
      const { wrapper } = await render({ settings: settings({ region_code: null, region_change_available_at: '2099-12-24T10:00:00Z' }) })

      await wrapper.get('[data-testid=area-mine]').trigger('click')
      await flushPromises()

      expect(text(wrapper, 'region-next-change')).toContain('Сменить регион можно с 24 дек')
    })

    it('a region below the threshold: no rows, "N of M participants", the way to the invitations', async () => {
      tables({
        own: answer(),
        '|region|': myRegionTable({
          region: { code: 'UZ-TK', name: 'город Ташкент', participants: 3, shown: false, min_participants: 5 },
          top: [], next_cursor: null, neighbours: null,
          me: myRow({ next_gap: null, region: { code: 'UZ-TK', name: 'город Ташкент', participants: 3, shown: false, min_participants: 5, rank: null } }),
        }),
      })
      const { wrapper, router } = await render()

      await wrapper.get('[data-testid=area-mine]').trigger('click')
      await flushPromises()

      expect(text(wrapper, 'region-below-title')).toBe('В регионе «город Ташкент» пока 3 из 5 участников')
      expect(text(wrapper, 'region-below')).toContain('Таблица появится, когда участников будет 5. Пригласите друзей')
      expect(has(wrapper, 'top')).toBe(false)
      expect(has(wrapper, 'empty')).toBe(false)
      expect(has(wrapper, 'region-rank')).toBe(false)

      await wrapper.get('[data-testid=region-invite]').trigger('click')
      await flushPromises()
      expect(router.currentRoute.value.name).toBe('referrals')
    })
  })

  describe('"Show more"', () => {
    it('loads the page after the cursor with the same table; the last page hides the button', async () => {
      const { wrapper } = await render({ data: answer() })
      vi.mocked(api.league.allTimePage).mockResolvedValueOnce(page([row(11), row(11, { name: 'Tie' })], 'af.page3'))

      await wrapper.get('[data-testid=more]').trigger('click')
      await flushPromises()

      expect(api.league.allTimePage).toHaveBeenCalledWith({ category: 'free' }, 'af.page2')
      expect(ranks(wrapper).slice(-2)).toEqual(['11', '11'])

      vi.mocked(api.league.allTimePage).mockResolvedValueOnce(page([row(12)], null))
      await wrapper.get('[data-testid=more]').trigger('click')
      await flushPromises()
      expect(api.league.allTimePage).toHaveBeenLastCalledWith({ category: 'free' }, 'af.page3')
      expect(has(wrapper, 'more')).toBe(false)
      expect(api.league.allTime).toHaveBeenCalledTimes(1)
    })

    it('a page of my region carries the region', async () => {
      tables({ own: answer(), '|region|': myRegionTable() })
      const { wrapper } = await render()
      await wrapper.get('[data-testid=area-mine]').trigger('click')
      await flushPromises()
      vi.mocked(api.league.allTimePage).mockResolvedValueOnce(page([row(3)], null))

      await wrapper.get('[data-testid=more]').trigger('click')
      await flushPromises()

      expect(api.league.allTimePage).toHaveBeenCalledWith({ category: 'free', scope: 'region' }, 'af~UZ-TK.page2')
    })

    it('a double tap sends one request', async () => {
      const { wrapper } = await render({ data: answer() })
      let resolve: (value: LeagueBoardPage) => void = () => {}
      vi.mocked(api.league.allTimePage).mockReturnValueOnce(new Promise((r) => (resolve = r)))

      await wrapper.get('[data-testid=more]').trigger('click')
      expect(wrapper.get('[data-testid=more]').attributes('disabled')).toBeDefined()
      await useAllTimeRatingStore().loadMore()
      resolve(page([row(11)], null))
      await flushPromises()

      expect(api.league.allTimePage).toHaveBeenCalledTimes(1)
      expect(wrapper.findAll('[data-testid=row]')).toHaveLength(11)
    })

    it('a stale cursor (422 invalid_cursor) reloads the table from its start', async () => {
      const { wrapper } = await render({ data: answer() })
      vi.mocked(api.league.allTimePage).mockRejectedValueOnce(new ApiClientError('validation', 422, 'invalid_cursor', 'x'))
      vi.mocked(api.league.allTime).mockResolvedValueOnce(answer({ next_cursor: 'af.fresh' }))

      await wrapper.get('[data-testid=more]').trigger('click')
      await flushPromises()

      expect(api.league.allTime).toHaveBeenCalledTimes(2)
      expect(useAllTimeRatingStore().cursor).toBe('af.fresh')
      expect(has(wrapper, 'more-error')).toBe(false)
      expect(wrapper.findAll('[data-testid=row]')).toHaveLength(10)
    })

    it('a tap while another table is on its way does nothing; its rows never join the new table', async () => {
      let resolveTable: (value: AllTimeResponse) => void = () => {}
      const { wrapper } = await render({ data: answer() })
      vi.mocked(api.league.allTime).mockReturnValueOnce(new Promise((r) => (resolveTable = r)))

      await wrapper.get('[data-testid=category-pro]').trigger('click')
      expect(wrapper.get('[data-testid=more]').attributes('disabled')).toBeDefined()
      await useAllTimeRatingStore().loadMore()
      expect(api.league.allTimePage).not.toHaveBeenCalled()

      resolveTable(proTable())
      await flushPromises()
      expect(ranks(wrapper)).toEqual(['1'])
      expect(useAllTimeRatingStore().more).toEqual([])
    })

    it('a page asked before the switch comes back after the new table: dropped', async () => {
      const { wrapper } = await render({ data: answer() })
      let resolvePage: (value: LeagueBoardPage) => void = () => {}
      let resolveTable: (value: AllTimeResponse) => void = () => {}
      vi.mocked(api.league.allTimePage).mockReturnValueOnce(new Promise((r) => (resolvePage = r)))
      vi.mocked(api.league.allTime).mockReturnValueOnce(new Promise((r) => (resolveTable = r)))

      await wrapper.get('[data-testid=more]').trigger('click')
      await wrapper.get('[data-testid=category-pro]').trigger('click')
      resolveTable(proTable())
      await flushPromises()
      resolvePage(page([row(11), row(12)], 'af.page3'))
      await flushPromises()

      const store = useAllTimeRatingStore()
      expect(store.more).toEqual([])
      expect(store.cursor).toBeNull()
      expect(store.loadingMore).toBe(false)
      expect(ranks(wrapper)).toEqual(['1'])
    })

    it('an answer of an older table is dropped (Pro asked, then my region of Pro before it came)', async () => {
      tables({ own: answer() })
      const { wrapper } = await render()
      let resolvePro: (value: AllTimeResponse) => void = () => {}
      vi.mocked(api.league.allTime).mockReturnValueOnce(new Promise((r) => (resolvePro = r)))
      vi.mocked(api.league.allTime).mockResolvedValueOnce(answer({ participants: 99 }))

      await wrapper.get('[data-testid=category-pro]').trigger('click')
      await wrapper.get('[data-testid=area-mine]').trigger('click')
      await flushPromises()
      resolvePro(proTable())
      await flushPromises()

      expect(text(wrapper, 'participants')).toBe('99 участников')
    })
  })

  it('the word points are not live (`enabled: false`): back to the Month, the segment is gone', async () => {
    const { wrapper, router } = await render({ data: { enabled: false, period: 'all_time' } })

    expect(router.currentRoute.value.name).toBe('league-month')
    expect(has(wrapper, 'seg-all-time')).toBe(false)
    expect(has(wrapper, 'seg-month')).toBe(true)
    expect(has(wrapper, 'my-card')).toBe(false)
  })

  it('fits 320 px: the segments and the chips scroll sideways instead of breaking the line', async () => {
    const { wrapper } = await render({ data: answer() })

    expect(wrapper.get('nav').attributes('data-scrollable')).toBe('true')
    expect(wrapper.get('nav').classes()).toContain('overflow-x-auto')
    expect(wrapper.get('[data-testid=area-switch]').classes()).toContain('overflow-x-auto')
    expect(wrapper.get('[data-testid=area-other]').classes()).toContain('whitespace-nowrap')
  })

  it('in Uzbek', async () => {
    setLocale('uz')
    tables({ own: answer(), '|region|UZ-BU': bukharaTable() })
    const { wrapper } = await render()

    expect(wrapper.get('h1').text()).toBe('Barcha vaqt reytingi')
    expect(text(wrapper, 'seg-all-time')).toBe('Barcha vaqt')
    expect(text(wrapper, 'my-total')).toBe('Barcha vaqtdagi ballarim: 2350 ball (Free 1400 + Pro 950)')
    expect(text(wrapper, 'region-rank')).toBe("«город Ташкент» hududi: 112-o'rin")
    expect(text(wrapper, 'area-all')).toBe('Hammasi')
    expect(text(wrapper, 'area-mine')).toBe('Mening hududim')
    expect(text(wrapper, 'area-other')).toBe('Boshqa hudud…')

    await wrapper.get('[data-testid=area-other]').trigger('click')
    await flushPromises()
    await click('[data-testid=region-uzbekistan]')
    await click('[data-testid=region-UZ-BU]')
    expect(text(wrapper, 'read-only-region')).toBe('Bu «Бухарская область» hududi jadvali. Siz «город Ташкент» hududida bellashyapsiz.')
  })
})
