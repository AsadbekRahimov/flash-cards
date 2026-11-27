import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import LeagueView from '../LeagueView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useBillingStore } from '@/stores/billing'
import { useLeagueStore } from '@/stores/league'
import type { LeagueMembership, LeagueResponse, LeagueStandingItem } from '@/api/types'

const state = vi.hoisted(() => ({
  ensure: vi.fn(),
  showBack: vi.fn(),
  hideBack: vi.fn(),
}))

vi.mock('@/api', () => ({ api: { league: { get: vi.fn() }, me: { get: vi.fn() } } }))

vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: state.ensure }) }))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ showBackButton: state.showBack, webApp: { BackButton: { hide: state.hideBack } } }),
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

const CLOSES_AT = '2026-10-25T19:00:00+00:00'

const response = (overrides: Partial<LeagueResponse> = {}): LeagueResponse => ({
  enabled: true,
  opted_out: false,
  week: { starts_on: '2026-10-19', ends_on: '2026-10-25', closes_at: CLOSES_AT },
  rules: { capacity: 30, promote: 5, demote: 5, tiers: ['bronze', 'silver', 'gold', 'platinum', 'diamond'] },
  membership: membership(),
  last_week: { starts_on: '2026-10-12', tier: 0, tier_key: 'bronze', result: 'promoted', rank: 2, points: 120 },
  ...overrides,
})

/** A group of `count` members, the learner is number `me`. */
const group = (count: number, me: number): LeagueStandingItem[] =>
  Array.from({ length: count }, (_, i) => ({ rank: i + 1, name: `Member ${i + 1}`, points: 1000 - i * 10, is_me: i + 1 === me }))

const makeRouter = () =>
  createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: { template: '<div />' } },
      { path: '/settings', name: 'settings', component: { template: '<div />' } },
      { path: '/league/month', name: 'league-month', component: { template: '<div />' } },
      { path: '/league/all-time', name: 'league-all-time', component: { template: '<div />' } },
      { path: '/friends', name: 'friends', component: { template: '<div />' } },
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

type Wrapper = Awaited<ReturnType<typeof render>>['wrapper']

const text = (w: Wrapper, id: string): string => w.get(`[data-testid=${id}]`).text()

describe('LeagueView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    state.ensure.mockResolvedValue(undefined)
    // `/me` (asked best effort for the Caravan line): never answers unless a test says otherwise
    vi.mocked(api.me.get).mockReturnValue(new Promise(() => {}))
  })
  afterEach(() => setLocale('ru'))

  it('shows the brand row, "Недельная лига", the hero of the kit with the week, the league, my place, my points and the way up (08-liga)', async () => {
    const { wrapper } = await render()

    expect(wrapper.find('[data-testid=brand-row]').exists()).toBe(true)
    expect(wrapper.get('h1').text()).toBe('Недельная лига')
    const hero = wrapper.get('[data-testid=my-card]')
    // the hero of the kit lies on the page on the centre axis: the mascot with the cup, no card under it
    expect(hero.classes()).toEqual(expect.arrayContaining(['items-center', 'text-center']))
    expect(hero.classes()).not.toContain('bg-highlight')
    expect(hero.get('[data-testid=mascot-art]').attributes('data-pose')).toBe('trophy')
    expect(text(wrapper, 'eyebrow')).toContain('Неделя 19 окт. – 25 окт.')
    expect(wrapper.get('[data-testid=eyebrow]').classes()).toEqual(expect.arrayContaining(['chip', 'chip-neutral']))
    expect(wrapper.get('[data-testid=tier]').classes()).toContain('t-display-m')
    expect(text(wrapper, 'tier')).toBe('Серебряная лига')
    expect(wrapper.get('[data-testid=standings] h2').text()).toBe('Таблица группы')
    expect(text(wrapper, 'my-place')).toBe('Вы на 3-м месте')
    expect(text(wrapper, 'my-points')).toBe('41 балл')
    expect(wrapper.get('[data-testid=my-points]').classes()).toContain('tabular-nums')
    expect(text(wrapper, 'to-next')).toBe('до 2-го места — 10 баллов') // 50 − 41 + 1
    // the place above is the last one of the promotion zone: "to promotion" would say the same number again
    expect(wrapper.find('[data-testid=to-promote]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=to-safety]').exists()).toBe(false)

    const rows = wrapper.findAll('[data-testid=row], [data-testid=row-me]')
    expect(rows).toHaveLength(4)
    expect(rows[0]?.text()).toContain('Dilnoza')
    expect(rows[0]?.get('[data-testid=points]').text()).toBe('90')
    expect(rows[2]?.attributes('data-testid')).toBe('row-me')
    expect(rows[2]?.text()).toContain('Aziz · вы')
    expect(rows[3]?.text()).toContain('Ученик')

    expect(text(wrapper, 'last-week')).toContain('Бронза: 2-е место, 120 баллов — повышение!')
    expect(wrapper.get('[data-testid=settings-link]').attributes('href')).toBe('/settings')
  })

  it('counts "to the place above" from the standings: one more than the difference, never less than one', async () => {
    const tie = membership({
      rank: 2,
      points: 50,
      standings: [
        { rank: 1, name: 'Dilnoza', points: 50, is_me: false },
        { rank: 2, name: 'Aziz', points: 50, is_me: true },
      ],
    })
    const { wrapper } = await render(response({ membership: tie }))

    expect(text(wrapper, 'to-next')).toBe('до 1-го места — 1 балл')
  })

  it('"How to climb higher": what gives points and the way to the plan of the day, no primary button on the screen', async () => {
    const { wrapper, router } = await render()

    const climb = wrapper.get('[data-testid=climb]')
    expect(text(wrapper, 'climb-title')).toBe('Как подняться выше')
    expect(text(wrapper, 'climb-text')).toBe('Баллы дают уроки и повторения')
    expect(text(wrapper, 'climb-link')).toBe('К плану дня')
    // a row of a list of the kit: the plate with the target, not the soft accent (that is the "you" row only)
    expect(climb.classes()).toEqual(expect.arrayContaining(['list-row', 'list-row-icon']))
    expect(climb.classes()).not.toContain('bg-accent-soft')
    expect(wrapper.findAll('.btn-primary')).toHaveLength(0)
    // practice games give no league points: "games" are never promised
    expect(climb.text()).not.toMatch(/игр/i)

    await climb.trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('today')
  })

  it('names the Caravan among what gives points only while the game is open to the learner', async () => {
    useBillingStore().game = { available: true, pro_promise: 'none', coins: 0, tickets: 0 }
    const { wrapper } = await render()

    expect(text(wrapper, 'climb-text')).toBe('Баллы дают уроки, повторения и «Караван»')
  })

  it('asks /me for the Caravan line without waiting for it, and not again while it is fresh', async () => {
    vi.mocked(api.me.get).mockResolvedValue({
      entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 10 },
      paywall: { mode: 'coming_soon', contact: null },
      game: { available: true, pro_promise: 'none', coins: 0, tickets: 0, limits: null },
    } as never)
    const { wrapper } = await render()

    expect(api.me.get).toHaveBeenCalledTimes(1)
    expect(text(wrapper, 'climb-text')).toBe('Баллы дают уроки, повторения и «Караван»')
    wrapper.unmount()

    const again = await render()
    expect(api.me.get).toHaveBeenCalledTimes(1)
    expect(text(again.wrapper, 'climb-text')).toBe('Баллы дают уроки, повторения и «Караван»')
  })

  it('on the first place: "Вы впереди всех" in the card and "how to keep the first place" in the block', async () => {
    const { wrapper } = await render(response({ membership: membership({ rank: 1, points_to_promote: null, zone: 'promotion', standings: group(4, 1) }) }))

    expect(text(wrapper, 'to-next')).toBe('Вы впереди всех в группе')
    expect(text(wrapper, 'climb-title')).toBe('Как удержать первое место')
  })

  it('puts the card "Choose your region" before the table', async () => {
    const { wrapper } = await render()

    const html = wrapper.html()
    const region = html.indexOf('<!-- "Choose your region"')
    expect(region).toBeGreaterThan(html.indexOf('data-testid="my-card"'))
    expect(region).toBeLessThan(html.indexOf('data-testid="standings"'))
  })

  describe('the header', () => {
    it('is the "Rating" tab with a Лига | Месяц | Друзья switch, League active', async () => {
      const { wrapper, router } = await render()

      expect(wrapper.get('h1').text()).toBe('Недельная лига')
      expect(wrapper.get('[data-testid=seg-league]').text()).toBe('Неделя')
      expect(wrapper.get('[data-testid=seg-league]').attributes('aria-current')).toBe('page')
      expect(wrapper.get('[data-testid=seg-month]').text()).toBe('Месяц')
      expect(wrapper.get('[data-testid=seg-month]').attributes('aria-current')).toBeUndefined()
      expect(wrapper.get('[data-testid=seg-friends]').text()).toBe('Друзья')
      expect(wrapper.get('[data-testid=seg-friends]').attributes('aria-current')).toBeUndefined()

      await wrapper.get('[data-testid=seg-friends]').trigger('click')
      await flushPromises()
      expect(router.currentRoute.value.name).toBe('friends')
    })

    it('names the switch as a navigation landmark of its own, besides the tab bar', async () => {
      const { wrapper } = await render()

      expect(wrapper.get('nav').attributes('aria-label')).toBe('Рейтинг')
    })

    it('is a tab root: no system Back button', async () => {
      await render()

      expect(state.showBack).not.toHaveBeenCalled()
      expect(state.hideBack).toHaveBeenCalled()
    })
  })

  describe('in the card: the week and how long is left', () => {
    afterEach(() => vi.useRealTimers())

    it('counts the days to the close of the week', async () => {
      vi.useFakeTimers({ toFake: ['Date'] })
      vi.setSystemTime(new Date('2026-10-23T12:00:00+00:00'))
      const { wrapper } = await render()

      expect(text(wrapper, 'eyebrow')).toBe('Неделя 19 окт. – 25 окт. · осталось 3 дн.')
    })

    it('says "last day" on the last day', async () => {
      vi.useFakeTimers({ toFake: ['Date'] })
      vi.setSystemTime(new Date('2026-10-25T10:00:00+00:00'))
      const { wrapper } = await render()

      expect(text(wrapper, 'eyebrow')).toBe('Неделя 19 окт. – 25 окт. · последний день')
    })

    it('says nothing about the days once the close has passed (the week is being summed up)', async () => {
      vi.useFakeTimers({ toFake: ['Date'] })
      vi.setSystemTime(new Date('2026-10-25T19:30:00+00:00'))
      const { wrapper } = await render()

      expect(text(wrapper, 'eyebrow')).toBe('Неделя 19 окт. – 25 окт.')
    })

    it('leaves the days out when the server sends no usable moment', async () => {
      const { wrapper } = await render(response({ week: { starts_on: '2026-10-19', ends_on: '2026-10-25', closes_at: 'not a date' } }))

      expect(text(wrapper, 'eyebrow')).toBe('Неделя 19 окт. – 25 окт.')
      expect(wrapper.find('[data-testid=closes]').exists()).toBe(false)
    })

    it('tells the exact moment in the rules, in the time of this device, written once', async () => {
      const { wrapper } = await render()

      const local = new Intl.DateTimeFormat('ru', { weekday: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(CLOSES_AT))
      expect(text(wrapper, 'closes')).toBe(`Итоги недели — ${local}`)
    })
  })

  describe('my card', () => {
    it('in the demotion zone: what is missing to be safe, in the danger colour', async () => {
      const { wrapper } = await render(response({ membership: membership({ zone: 'demotion', points_to_safety: 7, points_to_promote: 49 }) }))

      expect(text(wrapper, 'to-safety')).toBe('До безопасной зоны: 7 баллов')
      expect(wrapper.get('[data-testid=to-safety]').classes()).toContain('text-danger-ink')
      expect(wrapper.find('[data-testid=to-promote]').exists()).toBe(false)
    })

    it('says what is missing to the promotion zone when it is further than the place above', async () => {
      const { wrapper } = await render(response({ membership: membership({ points_to_promote: 49 }) }))

      expect(text(wrapper, 'to-promote')).toBe('До зоны повышения: 49 баллов')
    })

    it('at the top of the group: no place to pass', async () => {
      const { wrapper } = await render(response({ membership: membership({ rank: 1, points_to_promote: null, zone: 'promotion', standings: group(4, 1) }) }))

      expect(text(wrapper, 'to-next')).toBe('Вы впереди всех в группе')
    })

    it('the top league has no one to catch up: a congratulation instead of "to promotion"', async () => {
      const { wrapper } = await render(response({ membership: membership({ tier: 4, tier_key: 'diamond', zone: 'safe', points_to_promote: null }) }))

      expect(text(wrapper, 'top-tier')).toBe('Это высшая лига — так держать!')
      expect(wrapper.find('[data-testid=to-promote]').exists()).toBe(false)
    })

    it('has no coloured tile; the week is the one neutral chip of a v1 week', async () => {
      const { wrapper } = await render()

      expect(wrapper.find('[data-testid=my-card] .icon-tile').exists()).toBe(false)
      expect(wrapper.findAll('[data-testid=my-card] .chip').map((chip) => chip.attributes('data-testid'))).toEqual(['eyebrow'])
    })
  })

  describe('the tier', () => {
    it.each([
      ['bronze', 'Бронзовая лига'],
      ['silver', 'Серебряная лига'],
      ['gold', 'Золотая лига'],
      ['platinum', 'Платиновая лига'],
      ['diamond', 'Алмазная лига'],
    ])('%s: the card names «%s»', async (key, title) => {
      const { wrapper } = await render(response({ membership: membership({ tier_key: key }) }))

      expect(text(wrapper, 'tier')).toBe(title)
    })

    it('a tier this build does not know is shown as bronze, never nameless', async () => {
      const { wrapper } = await render(
        response({
          membership: membership({ tier_key: 'legend' }),
          last_week: { starts_on: '2026-10-12', tier: 0, tier_key: 'legend', result: 'stayed', rank: 9, points: 5 },
        }),
      )

      expect(text(wrapper, 'tier')).toBe('Бронзовая лига')
      expect(text(wrapper, 'last-week')).toContain('Бронза: 9-е место, 5 баллов — остались в лиге')
    })

    it('without a place in a group: the title stays, the week goes under it, there is no card', async () => {
      const { wrapper } = await render(response({ membership: null }))

      expect(wrapper.get('h1').text()).toBe('Недельная лига')
      expect(text(wrapper, 'subtitle')).toContain('Неделя 19 окт. – 25 окт.')
      expect(wrapper.find('[data-testid=my-card]').exists()).toBe(false)
    })
  })

  describe('the standings', () => {
    const zones = (w: Wrapper): string[] => w.findAll('[data-testid=rank]').map((r) => r.attributes('data-zone') ?? '')
    const order = (w: Wrapper): string[] =>
      w.findAll('[data-testid=standings] > [data-testid]').map((el) => {
        const id = el.attributes('data-testid') ?? ''
        return id === 'row' || id === 'row-me' ? (el.get('[data-testid=rank]').text()) : id
      })

    it('draws the line of the promotion zone under the last place that goes up and of the demotion zone above the first that goes down', async () => {
      const data = response({
        rules: { capacity: 30, promote: 3, demote: 3, tiers: ['bronze', 'silver', 'gold', 'platinum', 'diamond'] },
        membership: membership({ tier: 2, tier_key: 'gold', rank: 7, members_count: 8, zone: 'safe', standings: group(8, 7) }),
      })
      const { wrapper } = await render(data)

      expect(order(wrapper)).toEqual(['1', '2', '3', 'line-promotion', '4', '5', 'line-demotion', '6', '7', '8'])
      expect(zones(wrapper)).toEqual(['promotion', 'promotion', 'promotion', 'safe', 'safe', 'demotion', 'demotion', 'demotion'])
      expect(text(wrapper, 'line-promotion')).toBe('выше этой линии — переход в лигу «Платина»')
      // the divider of the kit: a caption in the accent of text between two 1 px lines of the same colour
      const promotion = wrapper.get('[data-testid=line-promotion]')
      expect(promotion.classes()).toContain('text-accent')
      expect(promotion.findAll('.h-px.bg-current')).toHaveLength(2)
      expect(promotion.get('.t-caption').text()).toBe('выше этой линии — переход в лигу «Платина»')
      expect(text(wrapper, 'line-demotion')).toBe('ниже этой линии — переход в лигу «Серебро»')
      expect(wrapper.get('[data-testid=line-demotion]').classes()).toContain('text-danger-ink')
    })

    it('has no promotion line in the top league and no demotion line in the lowest one (as LeagueService)', async () => {
      const top = await render(
        response({ membership: membership({ tier: 4, tier_key: 'diamond', rank: 6, members_count: 8, standings: group(8, 6) }) }),
      )
      // 8 members, demote 5: ranks 4-8 fall; nobody is promoted from the top league
      expect(zones(top.wrapper)).toEqual(['safe', 'safe', 'safe', 'demotion', 'demotion', 'demotion', 'demotion', 'demotion'])
      expect(top.wrapper.find('[data-testid=line-promotion]').exists()).toBe(false)
      expect(top.wrapper.find('[data-testid=line-demotion]').exists()).toBe(true)

      const bottom = await render(
        response({ membership: membership({ tier: 0, tier_key: 'bronze', rank: 6, members_count: 8, standings: group(8, 6) }) }),
      )
      expect(zones(bottom.wrapper)).toEqual(['promotion', 'promotion', 'promotion', 'promotion', 'promotion', 'safe', 'safe', 'safe'])
      expect(bottom.wrapper.find('[data-testid=line-demotion]').exists()).toBe(false)
      expect(text(bottom.wrapper, 'line-promotion')).toContain('«Серебро»')
    })

    it('lets promotion win when a small group overlaps both zones', async () => {
      const { wrapper } = await render(
        response({ membership: membership({ tier: 2, tier_key: 'gold', rank: 2, members_count: 4, standings: group(4, 2) }) }),
      )

      expect(zones(wrapper)).toEqual(['promotion', 'promotion', 'promotion', 'promotion'])
      expect(wrapper.find('[data-testid=line-demotion]').exists()).toBe(false)
    })

    it('marks my row as the "You" LeagueRow of the kit: the soft accent, sticky, the avatar in muhr, the place and points in the accent', async () => {
      const { wrapper } = await render()

      const me = wrapper.get('[data-testid=row-me]')
      expect(me.classes()).toEqual(expect.arrayContaining(['league-row', 'league-row-sticky']))
      expect(me.attributes('data-me')).toBe('true')
      expect(me.text()).toContain('Aziz · вы')
      expect(me.get('[data-testid=rank]').classes()).toContain('text-accent')
      expect(me.get('[data-testid=points]').classes()).toEqual(expect.arrayContaining(['text-accent', 'tabular-nums']))
      expect(me.get('[aria-hidden=true].rounded-full').classes()).toEqual(expect.arrayContaining(['bg-muhr', 'text-on-muhr']))

      const other = wrapper.findAll('[data-testid=row]')[0]!
      expect(other.attributes('data-me')).toBeUndefined()
      expect(other.classes()).not.toContain('league-row-sticky')
      expect(other.get('[data-testid=rank]').classes()).toEqual(expect.arrayContaining(['text-muted', 'tabular-nums']))
      expect(other.get('[data-testid=points]').classes()).toContain('tabular-nums')
    })

    it('draws a 32 px avatar with the initial in every row and no podium', async () => {
      const { wrapper } = await render()

      const avatars = wrapper.findAll('[data-testid=standings] [aria-hidden=true].rounded-full')
      expect(avatars).toHaveLength(4)
      expect(avatars[0]?.classes()).toEqual(expect.arrayContaining(['size-8', 'bg-fill']))
      expect(avatars[0]?.text()).toBe('D')
      expect(wrapper.text()).not.toMatch(/[🥇🥈🥉]/u)
    })

    it('folds a long group to the top and my neighbours; "Вся таблица" opens it and folds it back', async () => {
      const data = response({ membership: membership({ rank: 15, members_count: 20, standings: group(20, 15) }) })
      const { wrapper } = await render(data)

      // promote 5 and demote 5 of 20: both boundaries fall between the shown rows, the lines are drawn across the fold
      expect(order(wrapper)).toEqual(['1', '2', '3', 'line-promotion', 'gap', '14', '15', 'line-demotion', '16', 'table-toggle'])
      const toggle = wrapper.get('[data-testid=table-toggle]')
      expect(toggle.text()).toBe('Вся таблица · 20 участников')
      expect(toggle.attributes('aria-expanded')).toBe('false')

      await toggle.trigger('click')
      expect(wrapper.findAll('[data-testid=row], [data-testid=row-me]')).toHaveLength(20)
      expect(wrapper.get('[data-testid=table-toggle]').text()).toBe('Свернуть таблицу')
      expect(wrapper.get('[data-testid=table-toggle]').attributes('aria-expanded')).toBe('true')

      await wrapper.get('[data-testid=table-toggle]').trigger('click')
      expect(wrapper.findAll('[data-testid=row], [data-testid=row-me]')).toHaveLength(6)
    })

    it('shows the first seven while I am among them, with the promotion line in place', async () => {
      const data = response({ membership: membership({ rank: 4, members_count: 12, standings: group(12, 4) }) })
      const { wrapper } = await render(data)

      expect(order(wrapper)).toEqual(['1', '2', '3', '4', '5', 'line-promotion', '6', '7', 'table-toggle'])
    })

    it('shows a short group whole, with no toggle', async () => {
      const { wrapper } = await render()

      expect(wrapper.find('[data-testid=table-toggle]').exists()).toBe(false)
    })
  })

  describe('last week', () => {
    it.each([
      ['promoted', 'Бронза: 2-е место, 120 баллов — повышение!'],
      ['stayed', 'Бронза: 2-е место, 120 баллов — остались в лиге'],
      ['demoted', 'Бронза: 2-е место, 120 баллов — понижение'],
    ] as const)('%s: the sentence %s, without an icon tile', async (result, sentence) => {
      const { wrapper } = await render(
        response({ last_week: { starts_on: '2026-10-12', tier: 0, tier_key: 'bronze', result, rank: 2, points: 120 } }),
      )

      expect(text(wrapper, 'last-week')).toContain(sentence)
      expect(wrapper.find('[data-testid=last-week] .icon-tile').exists()).toBe(false)
    })

    it.each([0, null])('a learner out of the ranks of that week (rank %s): "not counted", never a "0th place"', async (rank) => {
      const { wrapper } = await render(
        response({ last_week: { starts_on: '2026-10-12', tier: 0, tier_key: 'bronze', result: 'stayed', rank, points: 120 } }),
      )

      expect(text(wrapper, 'last-week')).toContain('Бронза: вне зачёта, 120 баллов')
      expect(text(wrapper, 'last-week')).not.toMatch(/\d+-е место/)

      setLocale('uz')
      await flushPromises()
      expect(text(wrapper, 'last-week')).toContain('Bronza: hisobdan tashqari, 120 ball')
    })

    it('is absent in a new league', async () => {
      const { wrapper } = await render(response({ last_week: null }))

      expect(wrapper.find('[data-testid=last-week]').exists()).toBe(false)
    })
  })

  describe('the rules', () => {
    it('are a collapsed "How leagues work" block with the numbers of the server', async () => {
      const { wrapper } = await render()

      const rules = wrapper.get('[data-testid=rules]')
      expect(rules.element.tagName).toBe('DETAILS')
      expect(rules.attributes('open')).toBeUndefined()
      expect(rules.get('summary').text()).toBe('Как работают лиги')
      expect(rules.text()).toContain('Группы до 30 человек. Топ-5 переходят выше, последние 5 — ниже.')
    })
  })

  describe('states without a place in a group', () => {
    it('explains an empty week with the mascot holding the cup (SCREENS.md), and keeps the way to the plan of the day', async () => {
      const empty = await render(response({ membership: null, last_week: null }))

      expect(text(empty.wrapper, 'not-placed')).toBe('На этой неделе вы ещё не в группе: повторите несколько карточек — и вы в лиге.')
      expect(empty.wrapper.get('[data-testid=not-placed] [data-testid=mascot-art]').attributes('data-pose')).toBe('trophy')
      expect(empty.wrapper.find('[data-testid=not-placed] .icon-tile').exists()).toBe(false)
      expect(text(empty.wrapper, 'climb-title')).toBe('Как попасть в лигу')
      expect(empty.wrapper.get('[data-testid=climb]').attributes('href')).toBe('/today')
      expect(empty.wrapper.find('[data-testid=last-week]').exists()).toBe(false)
      expect(empty.wrapper.find('[data-testid=standings]').exists()).toBe(false)
    })

    it('explains an opt-out and keeps the way to the settings, without a lesson button', async () => {
      const out = await render(response({ membership: null, opted_out: true }))

      expect(text(out.wrapper, 'opted-out')).toBe('Вы вышли из лиг. Включить их снова можно в настройках.')
      expect(out.wrapper.find('[data-testid=climb]').exists()).toBe(false)
      expect(out.wrapper.find('[data-testid=not-placed]').exists()).toBe(false)
      expect(out.wrapper.get('[data-testid=settings-link]').text()).toBe('Настройки лиг')
    })

    it('says that leagues are switched off', async () => {
      const off = await render(response({ enabled: false, membership: null }))

      expect(text(off.wrapper, 'disabled')).toBe('Лиги сейчас выключены.')
      expect(off.wrapper.find('[data-testid=climb]').exists()).toBe(false)
      expect(off.wrapper.find('[data-testid=not-placed]').exists()).toBe(false)
      expect(off.wrapper.find('[data-testid=my-card]').exists()).toBe(false)
    })
  })

  describe('the settings link', () => {
    it('is a plain button to the settings', async () => {
      const { wrapper, router } = await render()

      const link = wrapper.get('[data-testid=settings-link]')
      expect(link.classes()).toEqual(expect.arrayContaining(['btn', 'btn-plain']))
      expect(link.classes()).not.toContain('btn-primary')

      await link.trigger('click')
      await flushPromises()
      expect(router.currentRoute.value.name).toBe('settings')
    })
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const { wrapper } = await render()

    expect(wrapper.get('h1').text()).toBe('Haftalik liga')
    expect(text(wrapper, 'tier')).toBe('Kumush liga')
    expect(wrapper.get('[data-testid=seg-league]').text()).toBe('Hafta')
    expect(wrapper.get('[data-testid=seg-friends]').text()).toBe("Do'stlar")
    expect(text(wrapper, 'my-place')).toBe("Siz 3-o'rindasiz")
    expect(text(wrapper, 'my-points')).toBe('41 ball')
    expect(text(wrapper, 'to-next')).toBe("2-o'ringacha — 10 ball")
    expect(text(wrapper, 'climb-title')).toBe('Qanday qilib yuqoriga chiqish mumkin')
    expect(text(wrapper, 'climb-link')).toBe('Kun rejasiga')
    expect(text(wrapper, 'closes')).toContain('Hafta yakuni — ')
    expect(text(wrapper, 'rules')).toContain('Ligalar qanday ishlaydi')
    expect(text(wrapper, 'settings-link')).toBe('Liga sozlamalari')
    expect(/\p{Script=Cyrillic}/u.test(wrapper.text())).toBe(false)
  })

  describe('loading and errors', () => {
    it('shows a skeleton while loading, with the header and the switch already in place', async () => {
      vi.mocked(api.league.get).mockReturnValue(new Promise(() => {}))
      const router = makeRouter()
      await router.push('/league')
      const wrapper = mount(LeagueView, { global: { plugins: [router] } })
      await flushPromises()

      expect(wrapper.find('[data-testid=spinner]').exists()).toBe(true)
      // the skeleton of the screen: sunken blocks of the hero and of the rows, announced as busy
      expect(wrapper.get('[data-testid=spinner]').attributes('aria-busy')).toBe('true')
      expect(wrapper.findAll('[data-testid=spinner] .league-row')).toHaveLength(5)
      expect(wrapper.findAll('[data-testid=spinner] .skeleton').length).toBeGreaterThan(5)
      expect(wrapper.get('h1').text()).toBe('Недельная лига')
      expect(wrapper.find('[data-testid=seg-friends]').exists()).toBe(true)
      expect(wrapper.find('[data-testid=my-card]').exists()).toBe(false)
    })

    it('shows the error with a retry; the switch to Friends stays reachable', async () => {
      vi.mocked(api.league.get).mockRejectedValueOnce(new ApiClientError('network', 0, 'network', 'x'))
      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=error]').text()).toContain('Нет связи с сервером')
      expect(wrapper.find('[data-testid=seg-friends]').exists()).toBe(true)

      vi.mocked(api.league.get).mockResolvedValue(response())
      await wrapper.get('[data-testid=retry-btn]').trigger('click')
      await flushPromises()

      expect(wrapper.find('[data-testid=error]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=my-card]').exists()).toBe(true)
    })

    it('shows the error when the sign-in fails and does not ask the league', async () => {
      state.ensure.mockRejectedValue(new ApiClientError('network', 0, 'network', 'x'))
      const { wrapper } = await render()

      expect(wrapper.find('[data-testid=error]').exists()).toBe(true)
      expect(api.league.get).not.toHaveBeenCalled()
    })

    it('keeps the last data with a red notice when a reload fails', async () => {
      const { wrapper } = await render()
      expect(wrapper.find('[data-testid=reload-error]').exists()).toBe(false)

      vi.mocked(api.league.get).mockRejectedValueOnce(new ApiClientError('network', 0, 'network', 'x'))
      await useLeagueStore().load()
      await flushPromises()

      const notice = wrapper.get('[data-testid=reload-error]')
      expect(notice.attributes('role')).toBe('alert')
      expect(notice.classes()).toContain('text-danger-ink')
      expect(wrapper.find('[data-testid=my-card]').exists()).toBe(true)
      expect(wrapper.find('[data-testid=error]').exists()).toBe(false)
    })
  })

  it('uses only the tokens of the design system: no raw palette colours', async () => {
    const { wrapper } = await render(response({ membership: membership({ zone: 'demotion', points_to_safety: 3 }) }))

    expect(wrapper.html()).not.toMatch(/\b(?:text|bg|border)-(?:red|green|gray|black|white)\b|\b(?:text|bg|border)-(?:red|green|gray|blue)-\d{2,3}\b/)
  })
  describe('a week of the word points v2 (docs/32 §8.3, §12.9)', () => {
    /** A v2 group of five: shared places 1, 2, 2, 4, 5; the learner is one of the two on the 2nd place unless said otherwise. */
    const v2Standings = (meName = 'Aziz'): LeagueStandingItem[] => [
      { rank: 1, name: 'Dilnoza', points: 90, is_me: false, pro_emoji: '🦁', long_words: 4, active_days: 5 },
      { rank: 2, name: 'Bek', points: 50, is_me: meName === 'Bek', pro_emoji: null, long_words: 2, active_days: 3 },
      { rank: 2, name: 'Aziz', points: 50, is_me: meName === 'Aziz', pro_emoji: '  ', long_words: 2, active_days: 3 },
      { rank: 4, name: 'Malika', points: 41, is_me: meName === 'Malika', pro_emoji: null, long_words: 0, active_days: 2 },
      { rank: 5, name: null, points: 2, is_me: false, pro_emoji: null, long_words: 0, active_days: 1 },
    ]
    const v2 = (overrides: Partial<LeagueMembership> = {}): LeagueResponse =>
      response({
        rules_version: 2,
        membership: membership({
          rank: 2, points: 50, members_count: 5, points_to_promote: null, standings: v2Standings(), category: 'pro', rules_version: 2, next_gap: 41,
          ...overrides,
        }),
      })

    it('a v1 week (no `rules_version`): no table pill, no facts under the names, the v1 rules', async () => {
      const { wrapper } = await render()

      expect(wrapper.find('[data-testid=category]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=row-facts]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=rules-best-days]').exists()).toBe(false)
      expect(wrapper.get('[data-testid=rules]').text()).toContain('выполненная цель дня (+10)')
    })

    it('the table of the group in the big card; "to the place above" from `next_gap` to the nearest strictly better place', async () => {
      const { wrapper } = await render(v2())

      expect(text(wrapper, 'category')).toBe('Группа Pro')
      expect(text(wrapper, 'to-next')).toBe('до 1-го места — 41 балл')
    })

    it('shared places: the nearest better place is the 2nd for the 4th, no "…" between 2, 2 and 4, one row per member', async () => {
      const { wrapper } = await render(v2({ rank: 4, points: 41, next_gap: 10, standings: v2Standings('Malika') }))

      expect(text(wrapper, 'to-next')).toBe('до 2-го места — 10 баллов')
      const rows = wrapper.findAll('[data-testid=row], [data-testid=row-me]')
      expect(rows.map((row) => row.get('[data-testid=rank]').text())).toEqual(['1', '2', '2', '4', '5'])
      expect(wrapper.find('[data-testid=gap]').exists()).toBe(false)
    })

    it('a tie on points: the place is the nearest better rank, whatever its points (`next_gap` counts the tie-break facts)', async () => {
      // places 1 / 2 / 4, points 150 / 100 / 100: the 2nd wins on the long repetitions, the server says 1 point to pass it
      const standings: LeagueStandingItem[] = [
        { rank: 1, name: 'Dilnoza', points: 150, is_me: false, long_words: 4, active_days: 5 },
        { rank: 2, name: 'Bek', points: 100, is_me: false, long_words: 3, active_days: 5 },
        { rank: 2, name: 'Malika', points: 100, is_me: false, long_words: 3, active_days: 5 },
        { rank: 4, name: 'Aziz', points: 100, is_me: true, long_words: 1, active_days: 5 },
      ]
      const { wrapper } = await render(v2({ rank: 4, points: 100, next_gap: 1, standings }))
      expect(text(wrapper, 'to-next')).toBe('до 2-го места — 1 балл')

      // everyone above has as many points: still the 2nd, never a made-up 3rd place
      const even = standings.map((row) => ({ ...row, points: 100 }))
      even[0] = { ...even[0]!, rank: 1 }
      const all = await render(v2({ rank: 4, points: 100, next_gap: 1, standings: even }))
      expect(text(all.wrapper, 'to-next')).toBe('до 2-го места — 1 балл')
    })

    it('the row above is not among the rows sent: "to the place above", no number', async () => {
      const standings: LeagueStandingItem[] = [{ rank: 4, name: 'Aziz', points: 100, is_me: true, long_words: 1, active_days: 5 }]
      const { wrapper } = await render(v2({ rank: 4, points: 100, next_gap: 3, standings }))
      expect(text(wrapper, 'to-next')).toBe('до места выше — 3 балла')

      setLocale('uz')
      await flushPromises()
      expect(text(wrapper, 'to-next')).toBe("yuqoridagi o'ringacha — 3 ball")
    })

    it('a leader has no "to the next place"; a shared first place is said as shared', async () => {
      const leader = v2Standings('none')
      leader[0] = { ...leader[0]!, is_me: true }
      const alone = await render(v2({ rank: 1, points: 90, next_gap: null, standings: leader }))
      expect(text(alone.wrapper, 'to-next')).toBe('Вы впереди всех в группе')

      const shared = v2Standings('none')
      shared[1] = { ...shared[1]!, rank: 1, points: 90, is_me: true }
      const tie = await render(v2({ rank: 1, points: 90, next_gap: null, standings: shared }))
      expect(text(tie.wrapper, 'to-next')).toBe('Вы делите первое место')
    })

    it('the tie-break facts under each name, compact (days, then the long repetitions when there are any)', async () => {
      const { wrapper } = await render(v2())

      const facts = wrapper.findAll('[data-testid=row-facts]').map((el) => el.text())
      expect(facts).toEqual(['5 дн. · долгих: 4', '3 дн. · долгих: 2', '3 дн. · долгих: 2', '2 дн.', '1 дн.'])
      expect(wrapper.get('[data-testid=row-facts]').classes()).toContain('truncate')
    })

    it('the emoji of Pro after the name only for a non-empty value, hidden from a screen reader, never cut by a long name', async () => {
      const { wrapper } = await render(v2())

      const rows = wrapper.findAll('[data-testid=row], [data-testid=row-me]')
      expect(rows[0]?.get('[data-testid=pro-emoji]').text()).toBe('🦁')
      expect(rows[0]?.get('[data-testid=pro-emoji]').attributes('aria-hidden')).toBe('true')
      expect(rows[0]?.get('[data-testid=pro-emoji]').classes()).toContain('shrink-0')
      expect(rows[0]?.get('[data-testid=player-name-text]').classes()).toContain('truncate')
      // null and a blank value: nothing
      expect(rows[1]?.find('[data-testid=pro-emoji]').exists()).toBe(false)
      expect(rows[2]?.find('[data-testid=pro-emoji]').exists()).toBe(false)
      expect(rows[2]?.text()).toContain('Aziz · вы')
    })

    it('the rules of v2: what gives points (lessons and reviews; the Caravan only while the game is open) and the 5 best days', async () => {
      const { wrapper } = await render(v2())

      expect(text(wrapper, 'rules-groups')).toBe('Группы до 30 человек. Топ-5 переходят выше, последние 5 — ниже.')
      expect(text(wrapper, 'rules-points')).toContain('Баллы дают уроки и повторения')
      expect(text(wrapper, 'rules-points')).not.toContain('Игры')
      expect(text(wrapper, 'rules-best-days')).toContain('В зачёт недели идут 5 лучших дней')
      expect(wrapper.get('[data-testid=rules]').text()).not.toContain('(+10)')

      useBillingStore().game = { available: true, pro_promise: 'none', coins: 0, tickets: 0 }
      await flushPromises()
      expect(text(wrapper, 'rules-points')).toContain('Баллы дают уроки, повторения и «Караван»')
    })

    it('the number of best days is the server\'s (`best_days`); an older server, or a broken value, gives the 5 of the build', async () => {
      const seven = await render({ ...v2(), best_days: 7 })
      expect(text(seven.wrapper, 'rules-best-days')).toContain('В зачёт недели идут 7 лучших дней')

      const old = await render(v2())
      expect(text(old.wrapper, 'rules-best-days')).toContain('В зачёт недели идут 5 лучших дней')

      const broken = await render({ ...v2(), best_days: 0 })
      expect(text(broken.wrapper, 'rules-best-days')).toContain('В зачёт недели идут 5 лучших дней')
    })

    it('in Uzbek', async () => {
      setLocale('uz')
      const { wrapper } = await render(v2())

      expect(text(wrapper, 'category')).toBe('Pro guruhi')
      expect(wrapper.findAll('[data-testid=row-facts]')[0]?.text()).toBe('5 kun · uzoq: 4')
      expect(text(wrapper, 'rules-best-days')).toContain('eng yaxshi 5 kun')
    })
  })
})
