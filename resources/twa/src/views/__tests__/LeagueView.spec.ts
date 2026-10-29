import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import LeagueView from '../LeagueView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useLeagueStore } from '@/stores/league'
import type { LeagueMembership, LeagueResponse, LeagueStandingItem } from '@/api/types'

const state = vi.hoisted(() => ({
  ensure: vi.fn(),
  showBack: vi.fn(),
  hideBack: vi.fn(),
}))

vi.mock('@/api', () => ({ api: { league: { get: vi.fn() } } }))

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

/** The colour as the DOM serialises it (`#9ca3af` -> `rgb(156, 163, 175)`). */
function normalised(colour: string): string {
  const el = document.createElement('i')
  el.style.background = colour
  return el.style.background
}

const tileBackground = (w: Wrapper): string => (w.get('[data-testid=tier-tile]').element as HTMLElement).style.background

describe('LeagueView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    state.ensure.mockResolvedValue(undefined)
  })
  afterEach(() => setLocale('ru'))

  it('shows the week, my place, points, zone, what is missing, the standings and last week', async () => {
    const { wrapper } = await render()

    expect(text(wrapper, 'week')).toContain('Неделя 19 окт. – 25 окт.')
    expect(text(wrapper, 'my-card')).toContain('Серебро')
    expect(text(wrapper, 'my-place')).toBe('3-е место из 4')
    expect(text(wrapper, 'my-points')).toBe('41 очко')
    expect(text(wrapper, 'zone')).toBe('Безопасная зона')
    expect(text(wrapper, 'to-promote')).toBe('До зоны повышения: 10 очков')
    expect(wrapper.find('[data-testid=to-safety]').exists()).toBe(false)

    const rows = wrapper.findAll('[data-testid=row], [data-testid=row-me]')
    expect(rows).toHaveLength(4)
    expect(rows[0]?.text()).toContain('Dilnoza')
    expect(rows[0]?.text()).toContain('90 очков')
    expect(rows[2]?.attributes('data-testid')).toBe('row-me')
    expect(rows[2]?.text()).toContain('Aziz')
    expect(rows[2]?.text()).toContain('вы')
    expect(rows[3]?.text()).toContain('Ученик')
    expect(rows[3]?.text()).toContain('2 очка')

    expect(text(wrapper, 'last-week')).toContain('Бронза: 2-е место, 120 очков — повышение!')
    expect(wrapper.get('[data-testid=settings-link]').attributes('href')).toBe('/settings')
  })

  describe('the header', () => {
    it('is the "Rating" tab with a Лига | Друзья switch, League active', async () => {
      const { wrapper, router } = await render()

      expect(wrapper.get('h1').text()).toBe('Рейтинг')
      expect(wrapper.get('[data-testid=seg-league]').text()).toBe('Лига')
      expect(wrapper.get('[data-testid=seg-league]').attributes('aria-current')).toBe('page')
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

  describe('the closing time of the week', () => {
    it('is "Итоги недели — <day and time on this device>" with the time written once', async () => {
      const { wrapper } = await render()

      const local = new Intl.DateTimeFormat('ru', { weekday: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(CLOSES_AT))
      const week = text(wrapper, 'week')

      expect(week).toContain(`Итоги недели — ${local}`)
      // docs/14 C6: «Итоги — в понедельник в 00:00 (пн 00:00)» said the time twice and named the league's zone
      expect(week).not.toContain('понедельник')
      expect(week).not.toContain('(')
      expect(week.match(/\d{2}:\d{2}/g)).toHaveLength(1)
    })

    it('follows the device, not the league zone: another instant gives another day and time', async () => {
      const closes = '2026-11-02T08:30:00+00:00'
      const { wrapper } = await render(response({ week: { starts_on: '2026-10-26', ends_on: '2026-11-01', closes_at: closes } }))

      const local = new Intl.DateTimeFormat('ru', { weekday: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(closes))
      expect(text(wrapper, 'week')).toContain(`Итоги недели — ${local}`)
      expect(text(wrapper, 'week')).toContain('Неделя 26 окт. – 1 нояб.')
    })

    it('is left out when the server sends no usable moment', async () => {
      const { wrapper } = await render(response({ week: { starts_on: '2026-10-19', ends_on: '2026-10-25', closes_at: 'not a date' } }))

      expect(text(wrapper, 'week')).toBe('Неделя 19 окт. – 25 окт.')
    })
  })

  describe('the zone', () => {
    it('promotion: a green chip, nothing is missing', async () => {
      const { wrapper } = await render(response({ membership: membership({ zone: 'promotion', points_to_promote: null }) }))

      expect(text(wrapper, 'zone')).toBe('Зона повышения')
      expect(wrapper.get('[data-testid=zone]').classes()).toContain('chip-success')
      expect(wrapper.find('[data-testid=zone] svg').exists()).toBe(true)
      expect(wrapper.find('[data-testid=to-promote]').exists()).toBe(false)
    })

    it('safe: a neutral chip without an icon', async () => {
      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=zone]').classes()).toContain('chip-neutral')
      expect(wrapper.find('[data-testid=zone] svg').exists()).toBe(false)
    })

    it('demotion: a red chip and what is missing to be safe, in the danger colour', async () => {
      const { wrapper } = await render(response({ membership: membership({ zone: 'demotion', points_to_safety: 7, points_to_promote: 49 }) }))

      expect(text(wrapper, 'zone')).toBe('Зона понижения')
      expect(wrapper.get('[data-testid=zone]').classes()).toContain('chip-danger')
      expect(wrapper.find('[data-testid=zone] svg').exists()).toBe(true)
      expect(text(wrapper, 'to-safety')).toBe('До безопасной зоны: 7 очков')
      expect(wrapper.get('[data-testid=to-safety]').classes()).toContain('text-danger-ink')
    })

    it('uses no arrow emoji in the zone texts', async () => {
      for (const zone of ['promotion', 'safe', 'demotion'] as const) {
        const { wrapper } = await render(response({ membership: membership({ zone }) }))
        expect(text(wrapper, 'zone'), zone).not.toMatch(/[⬆⬇]/u)
      }
    })

    it('the top league has no one to catch up: a congratulation instead of "to promotion"', async () => {
      const { wrapper } = await render(response({ membership: membership({ tier: 4, tier_key: 'diamond', zone: 'safe', points_to_promote: null }) }))

      expect(text(wrapper, 'top-tier')).toBe('Это высшая лига — так держать!')
      expect(wrapper.find('[data-testid=to-promote]').exists()).toBe(false)
    })
  })

  describe('the tier', () => {
    it.each([
      ['bronze', '#cd7f32', 'Бронза'],
      ['silver', '#9ca3af', 'Серебро'],
      ['gold', '#eab308', 'Золото'],
      ['platinum', '#14b8a6', 'Платина'],
      ['diamond', '#6366f1', 'Алмаз'],
    ])('%s: a trophy tile in %s with the name %s', async (key, colour, name) => {
      const { wrapper } = await render(response({ membership: membership({ tier_key: key }) }))

      expect(tileBackground(wrapper)).toBe(normalised(colour))
      expect(wrapper.get('[data-testid=tier-tile]').classes()).toContain('icon-tile')
      expect(wrapper.find('[data-testid=tier-tile] svg').exists()).toBe(true)
      expect(text(wrapper, 'tier-name')).toBe(name)
    })

    it('a tier this build does not know is shown as bronze, never nameless or colourless', async () => {
      const { wrapper } = await render(
        response({
          membership: membership({ tier_key: 'legend' }),
          last_week: { starts_on: '2026-10-12', tier: 0, tier_key: 'legend', result: 'stayed', rank: 9, points: 5 },
        }),
      )

      expect(tileBackground(wrapper)).toBe(normalised('#cd7f32'))
      expect(text(wrapper, 'tier-name')).toBe('Бронза')
      expect(text(wrapper, 'last-week')).toContain('Бронза: 9-е место, 5 очков — остались в лиге')
    })
  })

  describe('the standings', () => {
    const zones = (w: Wrapper): string[] => w.findAll('[data-testid=rank]').map((r) => r.attributes('data-zone') ?? '')

    it('colours the numbers of the promotion zone (top `promote`) and the demotion zone (bottom `demote`)', async () => {
      const data = response({
        rules: { capacity: 30, promote: 3, demote: 3, tiers: ['bronze', 'silver', 'gold', 'platinum', 'diamond'] },
        membership: membership({ tier: 2, tier_key: 'gold', rank: 11, members_count: 12, zone: 'demotion', standings: group(12, 11) }),
      })
      const { wrapper } = await render(data)

      expect(zones(wrapper)).toEqual([
        'promotion', 'promotion', 'promotion',
        'safe', 'safe', 'safe', 'safe', 'safe', 'safe',
        'demotion', 'demotion', 'demotion',
      ])
      const ranks = wrapper.findAll('[data-testid=rank]')
      expect(ranks[0]?.classes()).toContain('text-success-ink')
      expect(ranks[4]?.classes()).toContain('text-tg-hint')
      expect(ranks[11]?.classes()).toContain('text-danger-ink')
      expect(ranks[0]?.classes()).toContain('w-7')
    })

    it('has no promotion zone in the top league and no demotion zone in the lowest one (as LeagueService)', async () => {
      const top = await render(
        response({ membership: membership({ tier: 4, tier_key: 'diamond', rank: 6, members_count: 8, standings: group(8, 6) }) }),
      )
      // 8 members, demote 5: ranks 4-8 fall; nobody is promoted from the top league
      expect(zones(top.wrapper)).toEqual(['safe', 'safe', 'safe', 'demotion', 'demotion', 'demotion', 'demotion', 'demotion'])

      const bottom = await render(
        response({ membership: membership({ tier: 0, tier_key: 'bronze', rank: 6, members_count: 8, standings: group(8, 6) }) }),
      )
      // promote 5: ranks 1-5 rise; nobody is demoted from the lowest league
      expect(zones(bottom.wrapper)).toEqual(['promotion', 'promotion', 'promotion', 'promotion', 'promotion', 'safe', 'safe', 'safe'])
    })

    it('lets promotion win when a small group overlaps both zones', async () => {
      const { wrapper } = await render(
        response({ membership: membership({ tier: 2, tier_key: 'gold', rank: 2, members_count: 4, standings: group(4, 2) }) }),
      )

      // promote 5 and demote 5 in a group of 4: everybody is in both zones, the top zone wins
      expect(zones(wrapper)).toEqual(['promotion', 'promotion', 'promotion', 'promotion'])
    })

    it('marks my row: accent background, accent avatar, a "вы" chip', async () => {
      const { wrapper } = await render()

      const me = wrapper.get('[data-testid=row-me]')
      expect(me.classes()).toContain('bg-accent-soft')
      expect(me.get('[data-testid=me-chip]').text()).toBe('вы')
      expect(me.get('[aria-hidden=true].bg-accent').text()).toBe('A')

      const other = wrapper.findAll('[data-testid=row]')[0]!
      expect(other.classes()).not.toContain('bg-accent-soft')
      expect(other.find('[data-testid=me-chip]').exists()).toBe(false)
    })

    it('shows an initial avatar for everybody, "Ученик" for an unnamed member', async () => {
      const { wrapper } = await render()

      const rows = wrapper.findAll('[data-testid=row], [data-testid=row-me]')
      expect(rows[0]?.get('[aria-hidden=true]').text()).toBe('D')
      expect(rows[3]?.get('[aria-hidden=true]').text()).toBe('У')
    })
  })

  describe('last week', () => {
    it.each([
      ['promoted', 'Бронза: 2-е место, 120 очков — повышение!', 'tile-green'],
      ['stayed', 'Бронза: 2-е место, 120 очков — остались в лиге', 'tile-gray'],
      ['demoted', 'Бронза: 2-е место, 120 очков — понижение', 'tile-red'],
    ] as const)('%s: the sentence with an icon tile %s', async (result, sentence, tile) => {
      const { wrapper } = await render(
        response({ last_week: { starts_on: '2026-10-12', tier: 0, tier_key: 'bronze', result, rank: 2, points: 120 } }),
      )

      expect(text(wrapper, 'last-week')).toContain(sentence)
      expect(wrapper.get('[data-testid=last-week] .icon-tile').classes()).toContain(tile)
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
    it('explains an empty week with an icon', async () => {
      const empty = await render(response({ membership: null, last_week: null }))

      expect(text(empty.wrapper, 'not-placed')).toBe('На этой неделе вы ещё не в группе: повторите несколько карточек — и вы в лиге.')
      expect(empty.wrapper.find('[data-testid=not-placed] .icon-tile svg').exists()).toBe(true)
      expect(empty.wrapper.find('[data-testid=last-week]').exists()).toBe(false)
      expect(empty.wrapper.find('[data-testid=standings]').exists()).toBe(false)
    })

    it('explains an opt-out with an icon and keeps the way to the settings', async () => {
      const out = await render(response({ membership: null, opted_out: true }))

      expect(text(out.wrapper, 'opted-out')).toBe('Вы вышли из лиг. Включить их снова можно в настройках.')
      expect(out.wrapper.find('[data-testid=opted-out] .icon-tile svg').exists()).toBe(true)
      expect(out.wrapper.find('[data-testid=not-placed]').exists()).toBe(false)
      expect(out.wrapper.get('[data-testid=settings-link]').text()).toBe('Настройки лиг')
    })

    it('says that leagues are switched off', async () => {
      const off = await render(response({ enabled: false, membership: null }))

      expect(text(off.wrapper, 'disabled')).toBe('Лиги сейчас выключены.')
      expect(off.wrapper.find('[data-testid=disabled] .icon-tile svg').exists()).toBe(true)
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

    expect(wrapper.get('h1').text()).toBe('Reyting')
    expect(wrapper.get('[data-testid=seg-league]').text()).toBe('Liga')
    expect(wrapper.get('[data-testid=seg-friends]').text()).toBe("Do'stlar")
    expect(text(wrapper, 'my-place')).toBe("4 tadan 3-o'rin")
    expect(text(wrapper, 'my-points')).toBe('41 ochko')
    expect(text(wrapper, 'my-card')).toContain('Kumush')
    expect(text(wrapper, 'zone')).toBe('Xavfsiz zona')
    expect(text(wrapper, 'week')).toContain('Hafta yakuni — ')
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
      expect(wrapper.get('h1').text()).toBe('Рейтинг')
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
})
