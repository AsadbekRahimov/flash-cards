import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import FriendsView from '../FriendsView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { useToast } from '@/composables/useToast'
import { setLocale } from '@/i18n'
import { useBattleStore } from '@/stores/battle'
import { useBillingStore } from '@/stores/billing'
import { useFriendsStore } from '@/stores/friends'
import { settingsFixture } from '@/api/__tests__/settingsFixture'
import { activeOf, battleList, closedBattle, incoming, roomBattle } from '@/api/__tests__/battleFixtures'
import { freeLimits, inHours } from '@/api/__tests__/practiceFixtures'
import type { BattleListResponse, ChallengeItem, ChallengesResponse, FriendsResponse, MeResponse, PracticeAccess } from '@/api/types'

const state = vi.hoisted(() => ({
  ensure: vi.fn(),
  showBack: vi.fn(),
  hideBack: vi.fn(),
  shareUrl: vi.fn(),
  haptic: vi.fn(),
}))

vi.mock('@/api', () => ({
  api: {
    friends: { get: vi.fn(), accept: vi.fn(), decline: vi.fn(), remove: vi.fn() },
    challenges: { list: vi.fn(), propose: vi.fn(), accept: vi.fn(), decline: vi.fn() },
    me: { get: vi.fn() },
    battles: { list: vi.fn(), accept: vi.fn(), decline: vi.fn() },
  },
}))

vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: state.ensure }) }))

// The sheet has its own spec; here only that it opens, for which reason, and that it closes.
vi.mock('@/components/PaywallSheet.vue', async () => {
  const { defineComponent, h } = await import('vue')
  return {
    default: defineComponent({
      props: { reason: { type: String, default: 'info' }, limitHit: { type: Object, default: null } },
      emits: ['close', 'review'],
      setup: (props, { emit }) => () =>
        h('div', { 'data-testid': 'paywall', 'data-reason': props.reason, 'data-kind': props.limitHit?.kind ?? '', 'data-limit': String(props.limitHit?.limit ?? '') }, [
          h('button', { 'data-testid': 'paywall-close', onClick: () => emit('close') }),
        ]),
    }),
  }
})

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    shareUrl: state.shareUrl,
    haptic: state.haptic,
    showBackButton: state.showBack,
    webApp: { BackButton: { hide: state.hideBack } },
  }),
}))

const LINK = 'https://t.me/LexiFlowBot?start=f_ABCD2345'

const friendsResponse = (overrides: Partial<FriendsResponse> = {}): FriendsResponse => ({
  invite_link: LINK,
  limit: 100,
  friends: [
    { id: 11, first_name: 'Dilnoza', streak: 5, words_learned: 120, since: '2026-10-20T10:00:00+00:00' },
    { id: 12, first_name: null, streak: 0, words_learned: 3, since: null },
  ],
  incoming: [{ id: 21, first_name: 'Bek', requested_at: '2026-10-22T10:00:00+00:00' }],
  outgoing: [{ id: 31, first_name: 'Sam', requested_at: '2026-10-22T11:00:00+00:00' }],
  ...overrides,
})

const challenge = (overrides: Partial<ChallengeItem> = {}): ChallengeItem => ({
  id: 1,
  status: 'active',
  metric: 'reviews',
  days: 7,
  role: 'challenger',
  friend_name: 'Dilnoza',
  friendship_id: 11,
  starts_on: '2026-10-23',
  ends_on: '2026-10-29',
  created_at: '2026-10-22T10:00:00+00:00',
  my_score: 14,
  their_score: 9,
  result: null,
  ...overrides,
})

const pending = (overrides: Partial<ChallengeItem> = {}): ChallengeItem =>
  challenge({ status: 'pending', my_score: null, their_score: null, starts_on: null, ends_on: null, ...overrides })

const challengesResponse = (overrides: Partial<ChallengesResponse> = {}): ChallengesResponse => ({
  days: 7,
  open: [],
  finished: [],
  ...overrides,
})

const makeRouter = () =>
  createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/league', name: 'league', component: { template: '<div />' } },
      { path: '/friends', name: 'friends', component: FriendsView },
      { path: '/battles/new', name: 'battle-new', component: { template: '<div />' } },
      { path: '/battles/:id(\\d+)', name: 'battle', component: { template: '<div />' } },
    ],
  })

/** `GET /me` of a learner; `practice` is the block of docs/16 §3.3 (absent: an older server). */
const meWith = (practice?: PracticeAccess): MeResponse => ({
  learner: { id: 1, first_name: 'Асадбек', locale: 'ru', onboarding_completed: true, username: null },
  groups: [],
  stats: { words_learned: 0, words_due_today: 0 },
  entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 20 },
  paywall: { mode: 'purchase', contact: null },
  settings: settingsFixture(),
  ...(practice ? { practice } : {}),
})

const BATTLES_ON: PracticeAccess = { available: true, pro_required: false, modes: ['pairs', 'collocation', 'sentence', 'dictation'], battle: true, pro_promise: 'both' }

async function render(friends: FriendsResponse = friendsResponse(), challenges: ChallengesResponse = challengesResponse()) {
  vi.mocked(api.friends.get).mockResolvedValue(friends)
  vi.mocked(api.challenges.list).mockResolvedValue(challenges)
  const router = makeRouter()
  await router.push('/friends')
  await router.isReady()
  const wrapper = mount(FriendsView, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

type Wrapper = Awaited<ReturnType<typeof render>>['wrapper']

const text = (w: Wrapper, id: string): string => w.get(`[data-testid=${id}]`).text()

function setClipboard(value: unknown): void {
  Object.defineProperty(navigator, 'clipboard', { value, configurable: true })
}

/** The old way of copying (a selected field): what the browser answers to `execCommand('copy')`. */
function setExecCommand(result: boolean | undefined): void {
  Object.defineProperty(document, 'execCommand', { value: result === undefined ? undefined : () => result, configurable: true, writable: true })
}

describe('FriendsView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    state.ensure.mockResolvedValue(undefined)
    // A learner who has no practice: the screen is the Friends screen of before the battles.
    vi.mocked(api.me.get).mockResolvedValue(meWith())
    vi.mocked(api.battles.list).mockResolvedValue(battleList())
    useToast().hide()
  })
  afterEach(() => {
    vi.useRealTimers()
    setLocale('ru')
    useToast().hide()
  })

  it('shows the friends with streak and learned words, the requests and the invite link', async () => {
    const { wrapper } = await render()

    expect(text(wrapper, 'link')).toBe(LINK)
    expect(text(wrapper, 'friends-count')).toBe('2 из 100')

    const rows = wrapper.findAll('[data-testid=friend-row]')
    expect(rows).toHaveLength(2)
    expect(rows[0]?.text()).toContain('Dilnoza')
    expect(rows[0]?.get('[data-testid=friend-subtitle]').text()).toBe('🔥 5 · 120 слов')
    expect(rows[1]?.text()).toContain('Друг')
    expect(rows[1]?.get('[data-testid=friend-subtitle]').text()).toBe('🔥 0 · 3 слова')

    expect(wrapper.get('[data-testid=incoming-row]').text()).toContain('Bek')
    expect(wrapper.get('[data-testid=outgoing-row]').text()).toContain('Sam')
    expect(wrapper.find('[data-testid=friends-empty]').exists()).toBe(false)
    expect(text(wrapper, 'challenges-empty')).toBe('Вызовов пока нет.')
  })

  it.each([
    [1, '1 слово'],
    [2, '2 слова'],
    [5, '5 слов'],
    [11, '11 слов'],
    [21, '21 слово'],
  ])('agrees "слов" with the number of learned words: %i -> %s', async (words, expected) => {
    const { wrapper } = await render(
      friendsResponse({ friends: [{ id: 11, first_name: 'Dilnoza', streak: 4, words_learned: words, since: null }] }),
    )

    expect(text(wrapper, 'friend-subtitle')).toBe(`🔥 4 · ${expected}`)
  })

  describe('the header', () => {
    it('is the "Rating" tab with a Лига | Друзья switch, Friends active', async () => {
      const { wrapper, router } = await render()

      expect(wrapper.get('h1').text()).toBe('Рейтинг')
      expect(wrapper.get('[data-testid=seg-friends]').attributes('aria-current')).toBe('page')
      expect(wrapper.get('[data-testid=seg-league]').attributes('aria-current')).toBeUndefined()

      await wrapper.get('[data-testid=seg-league]').trigger('click')
      await flushPromises()
      expect(router.currentRoute.value.name).toBe('league')
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

  describe('the invitation', () => {
    it('is a card with a blue tile, the link in a read-only field, a primary share and a tinted copy button', async () => {
      const { wrapper } = await render()

      const invite = wrapper.get('[data-testid=invite]')
      expect(invite.get('.icon-tile').classes()).toContain('tile-blue')
      expect(invite.text()).toContain('Пригласить в друзья')

      const link = wrapper.get('[data-testid=link]')
      expect(link.classes()).toEqual(expect.arrayContaining(['field', 'font-mono', 'break-all']))
      expect(link.element.tagName).toBe('DIV')

      const share = wrapper.get('[data-testid=share-btn]')
      expect(share.text()).toBe('Поделиться ссылкой')
      expect(share.classes()).toEqual(expect.arrayContaining(['btn', 'btn-primary']))
      const copy = wrapper.get('[data-testid=copy-btn]')
      expect(copy.text()).toBe('Скопировать')
      expect(copy.classes()).toEqual(expect.arrayContaining(['btn', 'btn-tinted']))
    })

    it('opens the Telegram share dialog with the f_ link', async () => {
      const { wrapper } = await render()

      await wrapper.get('[data-testid=share-btn]').trigger('click')

      expect(state.shareUrl).toHaveBeenCalledWith(LINK, 'Давай дружить в LexiFlow: сравним серии и устроим вызов!')
      expect(state.haptic).toHaveBeenCalled()
    })

    it('copies the link and says "Скопировано" in a toast instead of changing the button', async () => {
      const writeText = vi.fn().mockResolvedValue(undefined)
      setClipboard({ writeText })
      const { wrapper } = await render()

      await wrapper.get('[data-testid=copy-btn]').trigger('click')
      await flushPromises()

      expect(writeText).toHaveBeenCalledWith(LINK)
      expect(useToast().toast.value?.text).toBe('Скопировано')
      expect(text(wrapper, 'copy-btn')).toBe('Скопировать')
    })

    it('copies the old way and says "Скопировано" when the clipboard API is missing', async () => {
      setClipboard(undefined)
      setExecCommand(true)
      const { wrapper } = await render()

      await wrapper.get('[data-testid=copy-btn]').trigger('click')
      await flushPromises()

      expect(useToast().toast.value?.text).toBe('Скопировано')
    })

    it('tells that it could not copy when nothing works, and the link is on the screen to select and copy by hand', async () => {
      setClipboard({ writeText: vi.fn().mockRejectedValue(new Error('NotAllowedError')) })
      setExecCommand(false)
      const { wrapper } = await render()

      await wrapper.get('[data-testid=copy-btn]').trigger('click')
      await flushPromises()

      expect(useToast().toast.value?.text).toBe('Не удалось скопировать. Выделите ссылку и скопируйте вручную.')
      expect(wrapper.get('[data-testid=link]').text()).toBe(LINK)
      expect(wrapper.get('[data-testid=link]').classes()).toContain('select-all')
    })

    it('explains a missing link instead of the share buttons', async () => {
      const { wrapper } = await render(friendsResponse({ invite_link: null, friends: [], incoming: [], outgoing: [] }))

      expect(wrapper.find('[data-testid=share-btn]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=copy-btn]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=link]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=no-link]').exists()).toBe(true)
      expect(wrapper.find('[data-testid=incoming]').exists()).toBe(false)
    })
  })

  describe('requests', () => {
    it('accepts an incoming request with a tinted button (the one primary button of the screen is «Share») and shows the refreshed list', async () => {
      const { wrapper } = await render()
      vi.mocked(api.friends.accept).mockResolvedValue(friendsResponse({ incoming: [] }))

      const accept = wrapper.get('[data-testid=accept-btn]')
      expect(accept.classes()).toEqual(expect.arrayContaining(['btn-tinted', 'btn-sm']))
      expect(accept.classes()).not.toContain('btn-primary')
      await accept.trigger('click')
      await flushPromises()

      expect(api.friends.accept).toHaveBeenCalledWith(21)
      expect(wrapper.find('[data-testid=incoming]').exists()).toBe(false)
    })

    it('declines an incoming request with a secondary button', async () => {
      const { wrapper } = await render()
      vi.mocked(api.friends.decline).mockResolvedValue(friendsResponse({ incoming: [] }))

      const decline = wrapper.get('[data-testid=decline-btn]')
      expect(decline.classes()).toEqual(expect.arrayContaining(['btn-secondary', 'btn-sm']))
      await decline.trigger('click')
      await flushPromises()

      expect(api.friends.decline).toHaveBeenCalledWith(21)
      expect(wrapper.find('[data-testid=incoming]').exists()).toBe(false)
    })

    it('has one primary button on the whole screen even with a request and a challenge to answer: «Share»', async () => {
      const { wrapper } = await render(friendsResponse(), challengesResponse({ open: [pending({ role: 'opponent' })] }))

      expect(wrapper.find('[data-testid=accept-btn]').exists()).toBe(true)
      expect(wrapper.find('[data-testid=challenge-accept]').exists()).toBe(true)
      expect(wrapper.findAll('.btn-primary').map((b) => b.attributes('data-testid'))).toEqual(['share-btn'])
    })

    it('shows an initial avatar and the name of a request', async () => {
      const { wrapper } = await render()

      const row = wrapper.get('[data-testid=incoming-row]')
      expect(row.get('[aria-hidden=true]').text()).toBe('B')
      expect(row.text()).toContain('Bek')
    })

    it('withdraws an outgoing request with a plain button', async () => {
      const { wrapper } = await render()
      vi.mocked(api.friends.remove).mockResolvedValue(friendsResponse({ outgoing: [] }))

      const cancel = wrapper.get('[data-testid=cancel-request-btn]')
      expect(cancel.classes()).toEqual(expect.arrayContaining(['btn-plain', 'btn-sm']))
      expect(cancel.text()).toBe('Отменить')
      await cancel.trigger('click')
      await flushPromises()

      expect(api.friends.remove).toHaveBeenCalledWith(31)
      expect(wrapper.find('[data-testid=outgoing]').exists()).toBe(false)
    })

    it('disables the buttons of a request while its action runs', async () => {
      const { wrapper } = await render()
      let release: (value: FriendsResponse) => void = () => {}
      vi.mocked(api.friends.accept).mockReturnValue(new Promise((resolve) => (release = resolve)))

      await wrapper.get('[data-testid=accept-btn]').trigger('click')
      expect(wrapper.get('[data-testid=accept-btn]').attributes('disabled')).toBeDefined()
      expect(wrapper.get('[data-testid=decline-btn]').attributes('disabled')).toBeDefined()

      release(friendsResponse({ incoming: [] }))
      await flushPromises()
    })
  })

  describe('the friends', () => {
    it('shows an empty state with an icon, and the 0 of the limit', async () => {
      const { wrapper } = await render(friendsResponse({ friends: [], incoming: [], outgoing: [] }))

      expect(text(wrapper, 'friends-empty')).toContain('Пока нет друзей')
      expect(wrapper.find('[data-testid=friends-empty] svg').exists()).toBe(true)
      expect(text(wrapper, 'friends-count')).toBe('0 из 100')
      expect(wrapper.find('[data-testid=friend-row]').exists()).toBe(false)
    })

    it('has icon buttons with names for assistive technology: challenge (bolt) and remove (trash)', async () => {
      const { wrapper } = await render()

      const row = wrapper.get('[data-testid=friend-row]')
      const challengeBtn = row.get('[data-testid=challenge-btn]')
      const removeBtn = row.get('[data-testid=remove-btn]')
      expect(challengeBtn.attributes('aria-label')).toBe('Бросить вызов: Dilnoza')
      expect(removeBtn.attributes('aria-label')).toBe('Удалить из друзей: Dilnoza')
      expect(challengeBtn.find('svg').exists()).toBe(true)
      expect(removeBtn.find('svg').exists()).toBe(true)
      expect(challengeBtn.text()).toBe('')
      expect(removeBtn.text()).toBe('')
    })

    it('names the icon buttons of every row after the friend: the rows are told apart', async () => {
      const { wrapper } = await render()

      const names = (id: string) => wrapper.findAll(`[data-testid=${id}]`).map((b) => b.attributes('aria-label'))

      expect(names('challenge-btn')).toEqual(['Бросить вызов: Dilnoza', 'Бросить вызов: Друг'])
      expect(names('remove-btn')).toEqual(['Удалить из друзей: Dilnoza', 'Удалить из друзей: Друг'])
    })

    it('names them in Uzbek too', async () => {
      setLocale('uz')
      const { wrapper } = await render()

      const row = wrapper.get('[data-testid=friend-row]')
      expect(row.get('[data-testid=challenge-btn]').attributes('aria-label')).toBe('Musobaqaga chaqirish: Dilnoza')
      expect(row.get('[data-testid=remove-btn]').attributes('aria-label')).toBe("Do'stlardan o'chirish: Dilnoza")
    })

    it('removes a friend only after the confirmation, then reloads the challenges', async () => {
      const { wrapper } = await render()
      vi.mocked(api.friends.remove).mockResolvedValue(friendsResponse({ friends: [friendsResponse().friends[1]!] }))

      await wrapper.findAll('[data-testid=remove-btn]')[0]!.trigger('click')
      expect(api.friends.remove).not.toHaveBeenCalled()
      expect(text(wrapper, 'remove-confirm')).toContain('Удалить Dilnoza из друзей?')
      expect(wrapper.get('[data-testid=remove-yes]').classes()).toContain('btn-danger')
      expect(wrapper.get('[data-testid=remove-no]').classes()).toContain('btn-secondary')

      await wrapper.get('[data-testid=remove-yes]').trigger('click')
      await flushPromises()

      expect(api.friends.remove).toHaveBeenCalledWith(11)
      expect(api.challenges.list).toHaveBeenCalledTimes(2)
      expect(wrapper.findAll('[data-testid=friend-row]')).toHaveLength(1)
    })

    it('keeps the friend when the removal is cancelled', async () => {
      const { wrapper } = await render()

      await wrapper.findAll('[data-testid=remove-btn]')[0]!.trigger('click')
      await wrapper.get('[data-testid=remove-no]').trigger('click')

      expect(api.friends.remove).not.toHaveBeenCalled()
      expect(wrapper.find('[data-testid=remove-confirm]').exists()).toBe(false)
      expect(wrapper.findAll('[data-testid=remove-btn]')).toHaveLength(2)
    })

    it('hides the row buttons while the confirmation of that row is open', async () => {
      const { wrapper } = await render()

      await wrapper.findAll('[data-testid=remove-btn]')[0]!.trigger('click')

      const row = wrapper.findAll('[data-testid=friend-row]')[0]!
      expect(row.find('[data-testid=challenge-btn]').exists()).toBe(false)
      expect(row.find('[data-testid=remove-btn]').exists()).toBe(false)
      // the other friend keeps his
      expect(wrapper.findAll('[data-testid=friend-row]')[1]!.find('[data-testid=remove-btn]').exists()).toBe(true)
    })
  })

  describe('challenges', () => {
    it('proposes one: pick the metric (tinted buttons), send, see it in the list', async () => {
      const { wrapper } = await render()
      vi.mocked(api.challenges.propose).mockResolvedValue(pending())
      vi.mocked(api.challenges.list).mockResolvedValue(challengesResponse({ open: [pending()] }))

      await wrapper.findAll('[data-testid=challenge-btn]')[0]!.trigger('click')
      expect(text(wrapper, 'metric-picker')).toContain('Кто больше за 7 дн.?')
      expect(wrapper.get('[data-testid=metric-reviews]').classes()).toContain('btn-tinted')
      expect(wrapper.get('[data-testid=metric-new_words]').classes()).toContain('btn-tinted')
      expect(wrapper.get('[data-testid=picker-cancel]').classes()).toContain('btn-plain')

      await wrapper.get('[data-testid=metric-new_words]').trigger('click')
      await flushPromises()

      expect(api.challenges.propose).toHaveBeenCalledWith(11, 'new_words')
      expect(wrapper.find('[data-testid=metric-picker]').exists()).toBe(false)
      expect(text(wrapper, 'challenge-status')).toBe('ждём ответа друга')
      // The friend with an open challenge cannot get a second one.
      expect(wrapper.findAll('[data-testid=challenge-btn]')).toHaveLength(1)
    })

    it('closes the picker without sending', async () => {
      const { wrapper } = await render()

      await wrapper.findAll('[data-testid=challenge-btn]')[0]!.trigger('click')
      await wrapper.get('[data-testid=picker-cancel]').trigger('click')

      expect(api.challenges.propose).not.toHaveBeenCalled()
      expect(wrapper.find('[data-testid=metric-picker]').exists()).toBe(false)
    })

    it('shows the live score, the share of mine in a thin bar, the status and the period of an active challenge', async () => {
      const { wrapper } = await render(friendsResponse(), challengesResponse({ open: [challenge()] }))

      expect(text(wrapper, 'challenge-score')).toBe('14 : 9')
      // 14 of 23 points = 61 %
      expect(wrapper.get('[data-testid=challenge-bar]').attributes('style')).toContain('width: 61%')
      expect(wrapper.get('[data-testid=challenge-bar]').element.parentElement?.classList.contains('progress')).toBe(true)
      expect(text(wrapper, 'challenge-status')).toBe('идёт')
      expect(wrapper.get('[data-testid=challenge-status]').classes()).toContain('chip-accent')
      expect(text(wrapper, 'challenge-period')).toBe('23 окт. — 29 окт.')
      expect(text(wrapper, 'challenge-row')).toContain('Повторит карточек')
      expect(text(wrapper, 'challenge-row')).toContain('с Dilnoza')
      expect(wrapper.find('[data-testid=challenge-accept]').exists()).toBe(false)
    })

    it.each([
      [0, 0, 50],
      [10, 0, 100],
      [0, 10, 0],
      [3, 1, 75],
    ])('the bar of %i : %i is %i percent mine (a level start is half)', async (mine, theirs, percent) => {
      const { wrapper } = await render(friendsResponse(), challengesResponse({ open: [challenge({ my_score: mine, their_score: theirs })] }))

      expect(wrapper.get('[data-testid=challenge-bar]').attributes('style')).toContain(`width: ${percent}%`)
    })

    it('has no score and no bar before the challenge starts, and tells who has to answer', async () => {
      const { wrapper } = await render(
        friendsResponse(),
        challengesResponse({ open: [pending(), pending({ id: 2, role: 'opponent', friendship_id: 12, friend_name: null })] }),
      )

      const rows = wrapper.findAll('[data-testid=challenge-row]')
      expect(rows).toHaveLength(2)
      for (const row of rows) {
        expect(row.find('[data-testid=challenge-score]').exists()).toBe(false)
        expect(row.find('[data-testid=challenge-bar]').exists()).toBe(false)
        expect(row.find('[data-testid=challenge-period]').exists()).toBe(false)
      }
      expect(rows[0]?.get('[data-testid=challenge-status]').text()).toBe('ждём ответа друга')
      expect(rows[0]?.get('[data-testid=challenge-status]').classes()).toContain('chip-neutral')
      expect(rows[1]?.get('[data-testid=challenge-status]').text()).toBe('вам бросили вызов')
      expect(rows[1]?.get('[data-testid=challenge-status]').classes()).toContain('chip-warning')
      expect(rows[1]?.text()).toContain('с Друг')
    })

    it('answers a challenge from a friend', async () => {
      const incoming = pending({ role: 'opponent' })
      const { wrapper } = await render(friendsResponse(), challengesResponse({ open: [incoming] }))
      vi.mocked(api.challenges.accept).mockResolvedValue({ ...incoming, status: 'active' })
      vi.mocked(api.challenges.list).mockResolvedValue(challengesResponse({ open: [challenge({ role: 'opponent' })] }))

      expect(text(wrapper, 'challenge-status')).toBe('вам бросили вызов')
      expect(wrapper.get('[data-testid=challenge-accept]').classes()).toEqual(expect.arrayContaining(['btn-tinted', 'btn-sm']))
      expect(wrapper.get('[data-testid=challenge-accept]').classes()).not.toContain('btn-primary')
      expect(wrapper.get('[data-testid=challenge-decline]').classes()).toEqual(expect.arrayContaining(['btn-secondary', 'btn-sm']))

      await wrapper.get('[data-testid=challenge-accept]').trigger('click')
      await flushPromises()

      expect(api.challenges.accept).toHaveBeenCalledWith(1)
      expect(wrapper.find('[data-testid=challenge-accept]').exists()).toBe(false)
      expect(text(wrapper, 'challenge-status')).toBe('идёт')
    })

    it('declines a challenge from a friend', async () => {
      const incoming = pending({ role: 'opponent' })
      const { wrapper } = await render(friendsResponse(), challengesResponse({ open: [incoming] }))
      vi.mocked(api.challenges.decline).mockResolvedValue({ ...incoming, status: 'declined' })
      vi.mocked(api.challenges.list).mockResolvedValue(challengesResponse())

      await wrapper.get('[data-testid=challenge-decline]').trigger('click')
      await flushPromises()

      expect(api.challenges.decline).toHaveBeenCalledWith(1)
      expect(wrapper.find('[data-testid=challenge-row]').exists()).toBe(false)
    })

    it('lists finished challenges with a result chip: win green, loss red, draw neutral', async () => {
      const { wrapper } = await render(
        friendsResponse(),
        challengesResponse({
          finished: [
            challenge({ status: 'finished', result: 'won', my_score: 30, their_score: 27 }),
            challenge({ id: 2, status: 'finished', result: 'lost', my_score: 5, their_score: 9 }),
            challenge({ id: 3, status: 'finished', result: 'draw', my_score: 5, their_score: 5 }),
          ],
        }),
      )

      const rows = wrapper.findAll('[data-testid=finished-row]')
      expect(rows).toHaveLength(3)
      expect(rows[0]?.get('[data-testid=finished-result]').text()).toBe('победа')
      expect(rows[0]?.get('[data-testid=finished-result]').classes()).toContain('chip-success')
      expect(rows[0]?.get('[data-testid=finished-score]').text()).toBe('30 : 27')
      expect(rows[1]?.get('[data-testid=finished-result]').text()).toBe('поражение')
      expect(rows[1]?.get('[data-testid=finished-result]').classes()).toContain('chip-danger')
      expect(rows[2]?.get('[data-testid=finished-result]').text()).toBe('ничья')
      expect(rows[2]?.get('[data-testid=finished-result]').classes()).toContain('chip-neutral')
      expect(rows[2]?.get('[data-testid=finished-score]').text()).toBe('5 : 5')
      // nothing is open, so no empty message and no open list
      expect(wrapper.find('[data-testid=challenges-empty]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=open-challenges]').exists()).toBe(false)
    })

    it('shows the open and the finished challenges in separate groups', async () => {
      const { wrapper } = await render(
        friendsResponse(),
        challengesResponse({ open: [challenge()], finished: [challenge({ id: 2, status: 'finished', result: 'won' })] }),
      )

      expect(wrapper.findAll('[data-testid=open-challenges] [data-testid=challenge-row]')).toHaveLength(1)
      expect(wrapper.findAll('[data-testid=finished-challenges] [data-testid=finished-row]')).toHaveLength(1)
      expect(text(wrapper, 'challenges')).toContain('Завершённые')
    })

    it('an empty list gets an empty state with an icon', async () => {
      const { wrapper } = await render()

      expect(wrapper.find('[data-testid=challenges-empty] svg').exists()).toBe(true)
      expect(wrapper.find('[data-testid=challenge-row]').exists()).toBe(false)
    })
  })

  describe('a refused action', () => {
    it('is told in a localized toast, not in an alert at the top of the long page, and what is true now is reloaded', async () => {
      const { wrapper } = await render()
      vi.mocked(api.friends.accept).mockRejectedValue(new ApiClientError('conflict', 409, 'friend_limit', 'x'))
      vi.mocked(api.friends.get).mockResolvedValue(friendsResponse({ incoming: [] }))

      await wrapper.get('[data-testid=accept-btn]').trigger('click')
      await flushPromises()

      expect(useToast().toast.value?.text).toBe('У вас уже максимальное число друзей.')
      expect(wrapper.find('[data-testid=action-error]').exists()).toBe(false)
      expect(wrapper.find('[role=alert]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=incoming]').exists()).toBe(false)
    })

    it('is told for a challenge too, and the same refusal again is news again', async () => {
      const incoming = pending({ role: 'opponent' })
      const { wrapper } = await render(friendsResponse(), challengesResponse({ open: [incoming] }))
      vi.mocked(api.challenges.accept).mockRejectedValue(new ApiClientError('conflict', 409, 'friend_limit', 'x'))

      await wrapper.get('[data-testid=challenge-accept]').trigger('click')
      await flushPromises()
      expect(useToast().toast.value?.text).toBe('У вас уже максимальное число друзей.')

      useToast().hide()
      await wrapper.get('[data-testid=challenge-accept]').trigger('click')
      await flushPromises()
      expect(useToast().toast.value?.text).toBe('У вас уже максимальное число друзей.')
    })

    it('leaves nothing in the store once it is told', async () => {
      const { wrapper } = await render()
      vi.mocked(api.friends.accept).mockRejectedValue(new ApiClientError('conflict', 409, 'friend_limit', 'x'))

      await wrapper.get('[data-testid=accept-btn]').trigger('click')
      await flushPromises()

      expect(useFriendsStore().actionError).toBe('')
    })
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const { wrapper } = await render(friendsResponse(), challengesResponse({ open: [challenge()] }))

    expect(wrapper.get('h1').text()).toBe('Reyting')
    expect(wrapper.get('[data-testid=seg-friends]').text()).toBe("Do'stlar")
    expect(wrapper.get('[data-testid=seg-league]').text()).toBe('Liga')
    expect(wrapper.get('[data-testid=share-btn]').text()).toBe('Havolani ulashish')
    expect(wrapper.get('[data-testid=copy-btn]').text()).toBe('Nusxalash')
    expect(text(wrapper, 'friends-count')).toBe('2 / 100')
    expect(wrapper.findAll('[data-testid=friend-subtitle]')[0]?.text()).toBe("🔥 5 · 120 ta so'z")
    expect(wrapper.get('[data-testid=challenge-row]').text()).toContain('Dilnoza bilan')
    expect(wrapper.get('[data-testid=challenge-row]').text()).not.toMatch(/\p{Script=Cyrillic}/u)
  })

  describe('loading and errors', () => {
    it('shows a skeleton while loading, with the header and the switch already in place', async () => {
      vi.mocked(api.friends.get).mockReturnValue(new Promise(() => {}))
      vi.mocked(api.challenges.list).mockReturnValue(new Promise(() => {}))
      const router = makeRouter()
      await router.push('/friends')
      const wrapper = mount(FriendsView, { global: { plugins: [router] } })
      await flushPromises()

      expect(wrapper.find('[data-testid=spinner]').exists()).toBe(true)
      expect(wrapper.get('h1').text()).toBe('Рейтинг')
      expect(wrapper.find('[data-testid=seg-league]').exists()).toBe(true)
      expect(wrapper.find('[data-testid=invite]').exists()).toBe(false)
    })

    it('shows the error with a retry; the switch to the League stays reachable', async () => {
      vi.mocked(api.friends.get).mockRejectedValueOnce(new ApiClientError('network', 0, 'network', 'x'))
      vi.mocked(api.challenges.list).mockResolvedValue(challengesResponse())
      const router = makeRouter()
      await router.push('/friends')
      await router.isReady()
      const wrapper = mount(FriendsView, { global: { plugins: [router] } })
      await flushPromises()

      expect(wrapper.find('[data-testid=error]').exists()).toBe(true)
      expect(wrapper.find('[data-testid=seg-league]').exists()).toBe(true)

      vi.mocked(api.friends.get).mockResolvedValue(friendsResponse())
      await wrapper.get('[data-testid=retry-btn]').trigger('click')
      await flushPromises()

      expect(wrapper.find('[data-testid=error]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=link]').exists()).toBe(true)
    })

    it('shows the error when the sign-in fails and loads nothing', async () => {
      state.ensure.mockRejectedValue(new ApiClientError('network', 0, 'network', 'x'))
      const { wrapper } = await render()

      expect(wrapper.find('[data-testid=error]').exists()).toBe(true)
      expect(api.friends.get).not.toHaveBeenCalled()
    })
  })

  describe('battles 1 on 1 (docs/16 §11.1)', () => {
    async function renderBattles(practice: PracticeAccess | undefined, list: BattleListResponse = battleList()) {
      vi.mocked(api.me.get).mockResolvedValue(meWith(practice))
      vi.mocked(api.battles.list).mockResolvedValue(list)
      return render()
    }

    const rowOf = (wrapper: Wrapper, name: string) => wrapper.findAll('[data-testid=friend-row]').find((row) => row.text().includes(name))!

    describe('the “Battle” button of a friend', () => {
      it('is there at every friend: a small tinted button with the name of the friend in its name', async () => {
        const { wrapper } = await renderBattles(BATTLES_ON)

        const buttons = wrapper.findAll('[data-testid=battle-btn]')
        expect(buttons).toHaveLength(2)
        expect(buttons[0]?.text()).toBe('Баттл')
        expect(buttons[0]?.classes()).toEqual(expect.arrayContaining(['btn', 'btn-tinted', 'btn-sm']))
        expect(buttons[0]?.attributes('aria-label')).toBe('Баттл с другом: Dilnoza')
        expect(buttons[1]?.attributes('aria-label')).toBe('Баттл с другом: Друг')
      })

      it('leads to the setup of a battle with that friend', async () => {
        const { wrapper, router } = await renderBattles(BATTLES_ON)

        await rowOf(wrapper, 'Dilnoza').get('[data-testid=battle-btn]').trigger('click')
        await flushPromises()

        expect(router.currentRoute.value.name).toBe('battle-new')
        expect(router.currentRoute.value.query).toEqual({ friendship: '11' })
        expect(state.haptic).toHaveBeenCalledWith('light')
      })

      it('is in the same row as the name, under the line of the streak, not beside the other buttons that would not fit at 320 px', async () => {
        const { wrapper } = await renderBattles(BATTLES_ON)

        const row = rowOf(wrapper, 'Dilnoza')
        const column = row.get('[data-testid=friend-subtitle]').element.parentElement!
        expect(column.contains(row.get('[data-testid=battle-btn]').element)).toBe(true)
        expect(row.find('[data-testid=challenge-btn]').exists()).toBe(true)
        expect(row.find('[data-testid=remove-btn]').exists()).toBe(true)
      })

      it('is there while the practice is behind Pro: the setup explains it and opens the paywall', async () => {
        const { wrapper } = await renderBattles({ ...BATTLES_ON, pro_required: true })

        expect(wrapper.findAll('[data-testid=battle-btn]')).toHaveLength(2)
      })

      it.each<[string, PracticeAccess | undefined]>([
        ['practice is not available', { ...BATTLES_ON, available: false, modes: [], battle: false, pro_promise: 'none' }],
        ['battles are switched off', { ...BATTLES_ON, battle: false }],
        ['practice is switched off but the flag of battles is on', { ...BATTLES_ON, available: false }],
        ['the server sends no practice block (an older one)', undefined],
      ])('is not there when %s', async (_name, practice) => {
        const { wrapper } = await renderBattles(practice, battleList({ incoming: [incoming()], active: activeOf() }))

        expect(wrapper.find('[data-testid=battle-btn]').exists()).toBe(false)
        expect(wrapper.find('[data-testid=battle-invites]').exists()).toBe(false)
        expect(wrapper.find('[data-testid=active-battle]').exists()).toBe(false)
        expect(api.battles.list).not.toHaveBeenCalled()
      })

      it('is not there when /me cannot be read', async () => {
        vi.mocked(api.me.get).mockRejectedValue(new ApiClientError('network', 0, 'network', 'x'))
        const { wrapper } = await render()

        expect(wrapper.find('[data-testid=battle-btn]').exists()).toBe(false)
        expect(wrapper.find('[data-testid=link]').exists()).toBe(true) // the rest of the screen is there
      })

      it('goes away from the row whose challenge picker or removal question is open', async () => {
        const { wrapper } = await renderBattles(BATTLES_ON)
        const row = rowOf(wrapper, 'Dilnoza')

        await row.get('[data-testid=challenge-btn]').trigger('click')
        expect(row.find('[data-testid=battle-btn]').exists()).toBe(false)
        expect(wrapper.findAll('[data-testid=battle-btn]')).toHaveLength(1)
        await row.get('[data-testid=picker-cancel]').trigger('click')
        expect(wrapper.findAll('[data-testid=battle-btn]')).toHaveLength(2)

        await row.get('[data-testid=remove-btn]').trigger('click')
        expect(row.find('[data-testid=battle-btn]').exists()).toBe(false)
      })

      it('keeps the one primary button of the screen: “Share”', async () => {
        const { wrapper } = await renderBattles(BATTLES_ON, battleList({ incoming: [incoming()], active: activeOf() }))

        expect(wrapper.findAll('button.btn-primary')).toHaveLength(1)
        expect(wrapper.get('button.btn-primary').attributes('data-testid')).toBe('share-btn')
      })

      it('is named in Uzbek too', async () => {
        setLocale('uz')
        const { wrapper } = await renderBattles(BATTLES_ON)

        const button = wrapper.get('[data-testid=battle-btn]')
        expect(button.text()).toBe('Bellashuv')
        expect(button.attributes('aria-label')).toBe('Dilnoza bilan bellashuv')
      })
    })

    describe('invitations to a battle', () => {
      const invitations = () => battleList({ incoming: [incoming({ id: 15 }), incoming({ id: 16, mode: 'dictation', from: { first_name: null }, lesson: null })] })

      it('are listed above everything else, with who calls and what is played where', async () => {
        const { wrapper } = await renderBattles(BATTLES_ON, invitations())

        const rows = wrapper.findAll('[data-testid=battle-invite-row]')
        expect(rows).toHaveLength(2)
        expect(rows[0]?.text()).toContain('Bek зовёт вас на баттл')
        expect(rows[0]?.get('[data-testid=battle-invite-meta]').text()).toBe('Найди пары · Модуль 2 · Урок 4 · Еда')
        expect(rows[1]?.text()).toContain('Друг зовёт вас на баттл')
        expect(rows[1]?.get('[data-testid=battle-invite-meta]').text()).toBe('Слушай и пиши · Английский A1')
        expect(wrapper.get('[data-testid=battle-invites]').text()).toContain('Приглашения на баттл')

        const html = wrapper.html()
        expect(html.indexOf('data-testid="battle-invites"')).toBeLessThan(html.indexOf('data-testid="invite"'))
      })

      it('are answered with a tinted “Accept” and a secondary “Decline”, small, like the requests for friendship', async () => {
        const { wrapper } = await renderBattles(BATTLES_ON, invitations())

        const row = wrapper.get('[data-testid=battle-invite-row]')
        expect(row.get('[data-testid=battle-accept-btn]').text()).toBe('Принять')
        expect(row.get('[data-testid=battle-accept-btn]').classes()).toEqual(expect.arrayContaining(['btn-tinted', 'btn-sm']))
        expect(row.get('[data-testid=battle-decline-btn]').text()).toBe('Отклонить')
        expect(row.get('[data-testid=battle-decline-btn]').classes()).toEqual(expect.arrayContaining(['btn-secondary', 'btn-sm']))
      })

      it('“Accept” takes the invitation and leads into its room', async () => {
        vi.mocked(api.battles.accept).mockResolvedValue(roomBattle({ id: 15, role: 'opponent' }))
        const { wrapper, router } = await renderBattles(BATTLES_ON, invitations())

        await wrapper.get('[data-testid=battle-accept-btn]').trigger('click')
        await flushPromises()

        expect(api.battles.accept).toHaveBeenCalledWith(15)
        expect(router.currentRoute.value.name).toBe('battle')
        expect(router.currentRoute.value.params.id).toBe('15')
      })

      it('“Decline” closes it: the row goes', async () => {
        vi.mocked(api.battles.decline).mockResolvedValue(closedBattle('declined', { id: 15, role: 'opponent' }))
        const { wrapper } = await renderBattles(BATTLES_ON, invitations())

        await wrapper.get('[data-testid=battle-decline-btn]').trigger('click')
        await flushPromises()

        expect(api.battles.decline).toHaveBeenCalledWith(15)
        expect(wrapper.findAll('[data-testid=battle-invite-row]')).toHaveLength(1)
      })

      it('the buttons of an invitation wait while its answer is on its way', async () => {
        let release!: (value: ReturnType<typeof roomBattle>) => void
        vi.mocked(api.battles.accept).mockReturnValue(new Promise((resolve) => (release = resolve)))
        const { wrapper } = await renderBattles(BATTLES_ON, invitations())
        const row = wrapper.get('[data-testid=battle-invite-row]')

        await row.get('[data-testid=battle-accept-btn]').trigger('click')
        expect(row.get('[data-testid=battle-accept-btn]').attributes('disabled')).toBeDefined()
        expect(row.get('[data-testid=battle-decline-btn]').attributes('disabled')).toBeDefined()

        release(roomBattle({ id: 15, role: 'opponent' }))
        await flushPromises()
      })

      it('an invitation that has run out is told in a toast, and the list is read again', async () => {
        vi.mocked(api.battles.accept).mockRejectedValue(new ApiClientError('conflict', 409, 'battle_closed', 'x', undefined, { end_reason: 'invite_expired' }))
        const { wrapper, router } = await renderBattles(BATTLES_ON, invitations())
        vi.mocked(api.battles.list).mockResolvedValue(battleList({ incoming: [incoming({ id: 16 })] }))

        await wrapper.get('[data-testid=battle-accept-btn]').trigger('click')
        await flushPromises()

        expect(useToast().toast.value?.text).toBe('Этот баттл уже закрыт.')
        expect(router.currentRoute.value.name).toBe('friends')
        expect(wrapper.findAll('[data-testid=battle-invite-row]')).toHaveLength(1)
        expect(api.battles.list).toHaveBeenCalledTimes(2)
      })

      it('an invitation to a format that has been switched off is told in words of its own (“Choose another” is for the setup), and the row stays to be declined (re-review Ж-2)', async () => {
        vi.mocked(api.battles.accept).mockRejectedValue(new ApiClientError('conflict', 409, 'practice_mode_disabled', 'x', undefined, { mode: 'pairs' }))
        const { wrapper, router } = await renderBattles(BATTLES_ON, invitations())

        await wrapper.get('[data-testid=battle-accept-btn]').trigger('click')
        await flushPromises()

        expect(useToast().toast.value?.text).toBe('Этот режим сейчас выключен — принять приглашение нельзя.')
        expect(router.currentRoute.value.name).toBe('friends')
        expect(wrapper.findAll('[data-testid=battle-invite-row]')).toHaveLength(2)
      })

      it('the same in Uzbek; and the friend who lost access is told in the old words, which suit the one who accepts', async () => {
        setLocale('uz')
        vi.mocked(api.battles.accept).mockRejectedValueOnce(new ApiClientError('conflict', 409, 'practice_mode_disabled', 'x'))
        const { wrapper } = await renderBattles(BATTLES_ON, invitations())

        await wrapper.get('[data-testid=battle-accept-btn]').trigger('click')
        await flushPromises()
        expect(useToast().toast.value?.text).toBe("Bu rejim hozir o'chirilgan — taklifni qabul qila olmaysiz.")

        useToast().hide()
        vi.mocked(api.battles.accept).mockRejectedValueOnce(new ApiClientError('conflict', 409, 'battle_friend_no_access', 'x'))
        await wrapper.get('[data-testid=battle-accept-btn]').trigger('click')
        await flushPromises()
        expect(useToast().toast.value?.text).toBe("Do'stingiz uchun mashqlar hali ochilmagan.")
      })

      it('none: no group at all', async () => {
        const { wrapper } = await renderBattles(BATTLES_ON, battleList())

        expect(wrapper.find('[data-testid=battle-invites]').exists()).toBe(false)
      })

      it('a list that could not be read does not break the screen', async () => {
        vi.mocked(api.me.get).mockResolvedValue(meWith(BATTLES_ON))
        vi.mocked(api.battles.list).mockRejectedValue(new ApiClientError('network', 0, 'network', 'x'))
        const { wrapper } = await render()

        expect(wrapper.find('[data-testid=link]').exists()).toBe(true)
        expect(wrapper.find('[data-testid=battle-invites]').exists()).toBe(false)
        expect(wrapper.findAll('[data-testid=battle-btn]')).toHaveLength(2)
      })
    })

    describe('a battle that is on', () => {
      it('is one tap back into it: the title, the format and the opponent, the stage of the battle', async () => {
        const { wrapper, router } = await renderBattles(BATTLES_ON, battleList({ active: activeOf('room') }))

        const row = wrapper.get('[data-testid=active-battle-row]')
        expect(row.text()).toContain('Идёт баттл')
        expect(row.text()).toContain('Собери предложение · Dilnoza')
        expect(row.text()).toContain('Комната')
        expect(row.attributes('href')).toBe('/battles/9')

        await row.trigger('click')
        await flushPromises()
        expect(router.currentRoute.value.name).toBe('battle')
        expect(router.currentRoute.value.params.id).toBe('9')
      })

      it.each([
        ['invited', 'Ждём ответа'],
        ['room', 'Комната'],
        ['countdown', 'Старт'],
        ['playing', 'В баттле'],
      ] as const)('%s: says “%s”', async (status, word) => {
        const { wrapper } = await renderBattles(BATTLES_ON, battleList({ active: activeOf(status) }))

        expect(wrapper.get('[data-testid=active-battle-row]').text()).toContain(word)
      })

      it('is above the invitations', async () => {
        const { wrapper } = await renderBattles(BATTLES_ON, battleList({ active: activeOf('playing'), incoming: [incoming()] }))

        const html = wrapper.html()
        expect(html.indexOf('data-testid="active-battle"')).toBeLessThan(html.indexOf('data-testid="battle-invites"'))
      })

      it('none: no row', async () => {
        const { wrapper } = await renderBattles(BATTLES_ON, battleList())

        expect(wrapper.find('[data-testid=active-battle]').exists()).toBe(false)
      })
    })

    it('asks for /me and for the list once each when the screen opens', async () => {
      await renderBattles(BATTLES_ON)

      expect(api.me.get).toHaveBeenCalledTimes(1)
      expect(api.battles.list).toHaveBeenCalledTimes(1)
    })

    describe('“Retry” after the screen could not be read', () => {
      const offline = () => new ApiClientError('network', 0, 'network', 'x')

      /** The screen opens with the friends failing; what `/me` and the list do is up to the spec. */
      async function renderFailed() {
        vi.mocked(api.friends.get).mockRejectedValueOnce(offline())
        vi.mocked(api.challenges.list).mockResolvedValue(challengesResponse())
        const router = makeRouter()
        await router.push('/friends')
        await router.isReady()
        const wrapper = mount(FriendsView, { global: { plugins: [router] } })
        await flushPromises()
        return wrapper
      }

      it('asks for the list again when /me was known before the screen opened: the invitations and the battle that is on come back with the friends (re-review Ж-1)', async () => {
        // The previous screen read /me, so the screen knows the learner plays battles from the first moment: nothing turns when it retries.
        useBillingStore().apply({ entitlement: meWith(BATTLES_ON).entitlement, paywall: meWith(BATTLES_ON).paywall, practice: BATTLES_ON })
        vi.mocked(api.battles.list).mockRejectedValueOnce(offline()) // no network: the list failed with the rest

        const wrapper = await renderFailed()
        expect(wrapper.find('[data-testid=error]').exists()).toBe(true)
        expect(api.battles.list).toHaveBeenCalledTimes(1)

        // The connection is back.
        vi.mocked(api.friends.get).mockResolvedValue(friendsResponse())
        vi.mocked(api.battles.list).mockResolvedValue(battleList({ active: activeOf('room'), incoming: [incoming({ id: 15 })] }))
        await wrapper.get('[data-testid=retry-btn]').trigger('click')
        await flushPromises()

        expect(api.battles.list).toHaveBeenCalledTimes(2)
        expect(wrapper.find('[data-testid=error]').exists()).toBe(false)
        expect(wrapper.findAll('[data-testid=battle-invite-row]')).toHaveLength(1)
        expect(wrapper.find('[data-testid=active-battle]').exists()).toBe(true)
        expect(api.me.get).not.toHaveBeenCalled() // /me was fresh: it is not asked again
      })

      it('asks once, not twice, when /me comes in the middle of the retry: the first time the learner was not known to play battles', async () => {
        vi.mocked(api.me.get).mockRejectedValueOnce(offline())

        const wrapper = await renderFailed()
        expect(api.battles.list).not.toHaveBeenCalled() // /me did not come: nobody is known to play battles

        vi.mocked(api.friends.get).mockResolvedValue(friendsResponse())
        vi.mocked(api.me.get).mockResolvedValue(meWith(BATTLES_ON))
        vi.mocked(api.battles.list).mockResolvedValue(battleList({ incoming: [incoming({ id: 15 })] }))
        await wrapper.get('[data-testid=retry-btn]').trigger('click')
        await flushPromises()

        expect(api.me.get).toHaveBeenCalledTimes(2)
        expect(api.battles.list).toHaveBeenCalledTimes(1)
        expect(wrapper.findAll('[data-testid=battle-invite-row]')).toHaveLength(1)
        expect(wrapper.findAll('[data-testid=battle-btn]')).toHaveLength(2)
      })

      it('does not ask for the list of a learner who is not to play battles', async () => {
        useBillingStore().apply({ entitlement: meWith().entitlement, paywall: meWith().paywall, practice: { ...BATTLES_ON, battle: false } })

        const wrapper = await renderFailed()
        vi.mocked(api.friends.get).mockResolvedValue(friendsResponse())
        await wrapper.get('[data-testid=retry-btn]').trigger('click')
        await flushPromises()

        expect(wrapper.find('[data-testid=link]').exists()).toBe(true)
        expect(api.battles.list).not.toHaveBeenCalled()
      })
    })

    describe('when /me is on its way already for another screen (review Ж4)', () => {
      // Another screen (the banner of Today, the course) asked for /me a moment ago and it has not come; then Friends opens.
      it('the battles still load when /me comes: the invitations and the battle that is on appear, not only the buttons', async () => {
        vi.mocked(api.me.get).mockReset()
        let answerMe!: (me: MeResponse) => void
        vi.mocked(api.me.get).mockReturnValue(new Promise<MeResponse>((resolve) => (answerMe = resolve)))
        vi.mocked(api.battles.list).mockResolvedValue(battleList({ active: activeOf('room'), incoming: [incoming({ id: 15 })] }))
        void useBillingStore().load()

        const { wrapper } = await render()
        expect(api.battles.list).not.toHaveBeenCalled() // the learner is not known to play battles yet
        expect(wrapper.find('[data-testid=battle-btn]').exists()).toBe(false)

        answerMe(meWith(BATTLES_ON))
        await flushPromises()

        expect(wrapper.findAll('[data-testid=battle-btn]')).toHaveLength(2)
        expect(api.battles.list).toHaveBeenCalledTimes(1)
        expect(wrapper.findAll('[data-testid=battle-invite-row]')).toHaveLength(1)
        expect(wrapper.find('[data-testid=active-battle]').exists()).toBe(true)
        expect(api.me.get).toHaveBeenCalledTimes(1) // this screen did not ask again
      })

      it('and does not ask for a learner who is not to play battles, when /me comes', async () => {
        vi.mocked(api.me.get).mockReset()
        let answerMe!: (me: MeResponse) => void
        vi.mocked(api.me.get).mockReturnValue(new Promise<MeResponse>((resolve) => (answerMe = resolve)))
        void useBillingStore().load()

        const { wrapper } = await render()
        answerMe(meWith({ ...BATTLES_ON, battle: false }))
        await flushPromises()

        expect(api.battles.list).not.toHaveBeenCalled()
        expect(wrapper.find('[data-testid=battle-btn]').exists()).toBe(false)
      })

      it('a learner whose practice is known already (the screen was opened before) gets the list right away, once', async () => {
        useBillingStore().apply({ entitlement: meWith(BATTLES_ON).entitlement, paywall: meWith(BATTLES_ON).paywall, practice: BATTLES_ON })
        vi.mocked(api.battles.list).mockResolvedValue(battleList({ incoming: [incoming({ id: 15 })] }))

        const { wrapper } = await render()

        expect(api.battles.list).toHaveBeenCalledTimes(1)
        expect(wrapper.findAll('[data-testid=battle-invite-row]')).toHaveLength(1)
      })
    })

    describe('“Accept” of a learner without Pro (docs/16 §3.2)', () => {
      it('opens the paywall of the practice, with no toast and no way into the battle', async () => {
        vi.mocked(api.battles.accept).mockRejectedValue(new ApiClientError('forbidden', 403, 'practice_pro_required', 'x'))
        const { wrapper, router } = await renderBattles(BATTLES_ON, battleList({ incoming: [incoming({ id: 15 })] }))

        await wrapper.get('[data-testid=battle-accept-btn]').trigger('click')
        await flushPromises()

        expect(wrapper.get('[data-testid=paywall]').attributes('data-reason')).toBe('practice')
        expect(useToast().toast.value).toBeNull()
        expect(router.currentRoute.value.name).toBe('friends')

        await wrapper.get('[data-testid=paywall-close]').trigger('click')
        expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
      })

      it('no paywall until it is refused', async () => {
        const { wrapper } = await renderBattles(BATTLES_ON, battleList({ incoming: [incoming({ id: 15 })] }))

        expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
      })
    })

    // docs/18 §3 (G1): Free has one battle a day. The list of friends says how many are left, in a quiet line under it;
    // with none left "Battle" opens the paywall of the limit at once. Accepting an invitation is never limited.
    describe('the Free limit of battles', () => {
      const withLimits = (limits: PracticeAccess['limits']): PracticeAccess => ({ ...BATTLES_ON, limits })
      const line = (wrapper: Wrapper) => wrapper.find('[data-testid=battle-limit]')

      it('says how many battles of the day are left, quietly, under the list of friends', async () => {
        const { wrapper } = await renderBattles(withLimits(freeLimits(0, 0)))

        expect(line(wrapper).text()).toBe('Баттлов сегодня: осталось 1 из 1')
        const section = wrapper.get('[data-testid=friends]').html()
        expect(section.indexOf('friend-row')).toBeLessThan(section.indexOf('battle-limit'))
      })

      it('is plain secondary text that wraps on a narrow screen: no button, no chip, no alert, no colour of its own', async () => {
        const { wrapper } = await renderBattles(withLimits(freeLimits(0, 0)))

        expect(line(wrapper).element.tagName).toBe('P')
        expect(line(wrapper).classes()).toEqual(expect.arrayContaining(['text-tg-hint', 'text-[13px]']))
        expect(line(wrapper).classes()).not.toContain('truncate')
        expect(line(wrapper).classes()).not.toContain('whitespace-nowrap')
        expect(line(wrapper).find('.btn, .chip, [role=alert], svg').exists()).toBe(false)
      })

      it('speaks Uzbek', async () => {
        setLocale('uz')
        const { wrapper } = await renderBattles(withLimits(freeLimits(0, 0)))

        expect(line(wrapper).text()).toBe('Bugungi bellashuvlar: 1 tadan 1 tasi qoldi')
      })

      it.each<[string, PracticeAccess['limits']]>([
        ['Pro has none', null],
        ['an older server does not send them', undefined],
      ])('says nothing when %s', async (_name, limits) => {
        const { wrapper } = await renderBattles(withLimits(limits))

        expect(line(wrapper).exists()).toBe(false)
        expect(wrapper.findAll('[data-testid=battle-btn]')).toHaveLength(2)
      })

      it('says nothing where there are no battles to start: battles switched off', async () => {
        const { wrapper } = await renderBattles({ ...withLimits(freeLimits(0, 0)), battle: false })

        expect(line(wrapper).exists()).toBe(false)
      })

      it('says nothing when there is nobody to play with', async () => {
        vi.mocked(api.me.get).mockResolvedValue(meWith(withLimits(freeLimits(0, 0))))
        vi.mocked(api.battles.list).mockResolvedValue(battleList())
        const { wrapper } = await render(friendsResponse({ friends: [] }))

        expect(line(wrapper).exists()).toBe(false)
      })

      it('the line goes by itself when the day of the counter ends: the app stayed open overnight, and nothing else happened', async () => {
        // Only the timers the store waits with and the date: `flushPromises` waits on `setImmediate`, which keeps running.
        vi.useFakeTimers({ toFake: ['Date', 'setTimeout', 'clearTimeout'] })
        vi.setSystemTime(new Date('2026-11-02T10:00:00Z'))
        const { wrapper } = await renderBattles(withLimits(freeLimits(0, 0, '2026-11-02T19:00:00Z')))
        expect(line(wrapper).text()).toBe('Баттлов сегодня: осталось 1 из 1')

        vi.advanceTimersByTime(9 * 3_600_000 + 500)
        await flushPromises()

        expect(line(wrapper).exists()).toBe(false)
        expect(wrapper.findAll('[data-testid=battle-btn]')).toHaveLength(2)
      })

      it('says nothing for a counter whose day is over: the app stayed open overnight, and the server decides', async () => {
        const stale = freeLimits(0, 1, new Date(Date.now() - 60_000).toISOString())
        const { wrapper, router } = await renderBattles(withLimits(stale))

        expect(line(wrapper).exists()).toBe(false)
        await rowOf(wrapper, 'Dilnoza').get('[data-testid=battle-btn]').trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
        expect(router.currentRoute.value.name).toBe('battle-new')
      })

      it('"Battle" leads to the setup as it did while a battle is left (spent sessions of practice are another counter)', async () => {
        const { wrapper, router } = await renderBattles(withLimits(freeLimits(2, 0)))

        await rowOf(wrapper, 'Dilnoza').get('[data-testid=battle-btn]').trigger('click')
        await flushPromises()

        expect(router.currentRoute.value.name).toBe('battle-new')
        expect(router.currentRoute.value.query).toEqual({ friendship: '11' })
        expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
      })

      describe('when none is left', () => {
        const spent = () => withLimits(freeLimits(0, 1, inHours(4)))

        it('the line says so in the same words, and every "Battle" button is still there', async () => {
          const { wrapper } = await renderBattles(spent())

          expect(line(wrapper).text()).toBe('Баттлов сегодня: осталось 0 из 1')
          expect(wrapper.findAll('[data-testid=battle-btn]')).toHaveLength(2)
        })

        it('"Battle" opens the paywall of the limit at once - no setup that would end in it, no request, no toast', async () => {
          const { wrapper, router } = await renderBattles(spent())

          await rowOf(wrapper, 'Dilnoza').get('[data-testid=battle-btn]').trigger('click')
          await flushPromises()

          const sheet = wrapper.get('[data-testid=paywall]')
          expect(sheet.attributes('data-reason')).toBe('practice_limit')
          expect(sheet.attributes('data-kind')).toBe('battles')
          expect(sheet.attributes('data-limit')).toBe('1')
          expect(router.currentRoute.value.name).toBe('friends')
          expect(useToast().toast.value).toBeNull()
          expect(state.haptic).toHaveBeenCalledWith('light')
        })

        it('every friend does the same, the sheet closes, and the next press opens it again', async () => {
          const { wrapper, router } = await renderBattles(spent())

          for (const name of ['Dilnoza', 'Друг']) {
            await rowOf(wrapper, name).get('[data-testid=battle-btn]').trigger('click')
            await flushPromises()
            expect(wrapper.get('[data-testid=paywall]').attributes('data-reason')).toBe('practice_limit')
            await wrapper.get('[data-testid=paywall-close]').trigger('click')
            expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
          }
          expect(router.currentRoute.value.name).toBe('friends')
        })

        it('does not touch what is not a new battle: the battle that is on is one tap away', async () => {
          const { wrapper, router } = await renderBattles(spent(), battleList({ active: activeOf('room') }))

          await wrapper.get('[data-testid=active-battle-row]').trigger('click')
          await flushPromises()

          expect(router.currentRoute.value.name).toBe('battle')
          expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
        })

        it('"Accept" is never limited: the invitation is accepted and leads into its room', async () => {
          vi.mocked(api.battles.accept).mockResolvedValue(roomBattle({ id: 15, role: 'opponent' }))
          const { wrapper, router } = await renderBattles(spent(), battleList({ incoming: [incoming({ id: 15 })] }))

          await wrapper.get('[data-testid=battle-accept-btn]').trigger('click')
          await flushPromises()

          expect(api.battles.accept).toHaveBeenCalledWith(15)
          expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
          expect(useToast().toast.value).toBeNull()
          expect(router.currentRoute.value.name).toBe('battle')
          expect(router.currentRoute.value.params.id).toBe('15')
        })

        it('and so is "Decline"', async () => {
          vi.mocked(api.battles.decline).mockResolvedValue(closedBattle('declined'))
          const { wrapper } = await renderBattles(spent(), battleList({ incoming: [incoming({ id: 15 })] }))

          await wrapper.get('[data-testid=battle-decline-btn]').trigger('click')
          await flushPromises()

          expect(api.battles.decline).toHaveBeenCalledWith(15)
          expect(wrapper.find('[data-testid=battle-invite-row]').exists()).toBe(false)
        })

        it('the Pro wall of an "Accept" is the other paywall, as it was', async () => {
          vi.mocked(api.battles.accept).mockRejectedValue(new ApiClientError('forbidden', 403, 'practice_pro_required', 'x'))
          const { wrapper } = await renderBattles(spent(), battleList({ incoming: [incoming({ id: 15 })] }))

          await wrapper.get('[data-testid=battle-accept-btn]').trigger('click')
          await flushPromises()

          expect(wrapper.get('[data-testid=paywall]').attributes('data-reason')).toBe('practice')
          expect(wrapper.get('[data-testid=paywall]').attributes('data-kind')).toBe('')
        })
      })

      // A battle that did not take place (declined, not answered in time, withdrawn) is not counted: its place comes back on the server, and the
      // number on this screen may be from before. So a zero is confirmed by `/me` read again, not believed.
      describe('a zero that the server may have forgiven', () => {
        it.each(['declined', 'invite_expired', 'canceled'] as const)(
          'invited Dilnoza, the invitation did not become a battle (%s): "Battle" at the other friend shows no paywall when the server allows it',
          async (reason) => {
            // the 201 of the invitation to Dilnoza said that no place was left ...
            useBillingStore().apply(meWith(withLimits(freeLimits(0, 1, inHours(4)))))
            // ... and the battle screen saw it close without a match
            useBattleStore().applyBattle(closedBattle(reason, { role: 'challenger' }))

            // Friends opens: the server has given the place back
            const { wrapper, router } = await renderBattles(withLimits(freeLimits(0, 0, inHours(4))))
            expect(line(wrapper).text()).toBe('Баттлов сегодня: осталось 1 из 1')

            await rowOf(wrapper, 'Друг').get('[data-testid=battle-btn]').trigger('click')
            await flushPromises()

            expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
            expect(router.currentRoute.value.name).toBe('battle-new')
            expect(router.currentRoute.value.query).toEqual({ friendship: '12' })
            expect(api.me.get).toHaveBeenCalledTimes(1) // the reading that Friends does itself: the tap had nothing left to ask
          },
        )

        it('after the closure the screen shows no number and stops nobody until /me has said it: the line is not a stale zero', async () => {
          useBillingStore().apply(meWith(withLimits(freeLimits(0, 1, inHours(4)))))
          useBattleStore().applyBattle(closedBattle('declined', { role: 'challenger' }))
          vi.mocked(api.me.get).mockReturnValue(new Promise(() => {})) // /me is still on its way
          vi.mocked(api.battles.list).mockResolvedValue(battleList())

          const { wrapper, router } = await render()
          expect(line(wrapper).exists()).toBe(false)

          await rowOf(wrapper, 'Dilnoza').get('[data-testid=battle-btn]').trigger('click')
          await flushPromises()

          expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
          expect(router.currentRoute.value.name).toBe('battle-new')
        })

        it('a zero that the screen still remembers (the closure was not seen) is confirmed by /me before the paywall: the place was given back', async () => {
          const billing = useBillingStore()
          billing.apply(meWith(withLimits(freeLimits(0, 1, inHours(4))))) // fresh in the cache: Friends does not read /me itself
          const { wrapper, router } = await renderBattles(withLimits(freeLimits(0, 0, inHours(4)))) // ... what the server says now
          expect(api.me.get).not.toHaveBeenCalled()
          expect(line(wrapper).text()).toBe('Баттлов сегодня: осталось 0 из 1')

          await rowOf(wrapper, 'Dilnoza').get('[data-testid=battle-btn]').trigger('click')
          await flushPromises()

          expect(api.me.get).toHaveBeenCalledTimes(1)
          expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
          expect(router.currentRoute.value.name).toBe('battle-new')
          expect(line(wrapper).text()).toBe('Баттлов сегодня: осталось 1 из 1')
        })

        it('the fresh counter still says none: the paywall, after one question and not more', async () => {
          useBillingStore().apply(meWith(withLimits(freeLimits(0, 1, inHours(4)))))
          const { wrapper, router } = await renderBattles(withLimits(freeLimits(0, 1, inHours(4))))

          await rowOf(wrapper, 'Dilnoza').get('[data-testid=battle-btn]').trigger('click')
          await flushPromises()

          expect(api.me.get).toHaveBeenCalledTimes(1)
          expect(wrapper.get('[data-testid=paywall]').attributes('data-kind')).toBe('battles')
          expect(router.currentRoute.value.name).toBe('friends')
        })

        it('with /me out of reach there is no sales pitch on a number that cannot be checked: the setup opens, and the server decides there', async () => {
          vi.spyOn(console, 'warn').mockImplementation(() => {})
          useBillingStore().apply(meWith(withLimits(freeLimits(0, 1, inHours(4)))))
          const { wrapper, router } = await renderBattles(withLimits(freeLimits(0, 1, inHours(4))))
          vi.mocked(api.me.get).mockRejectedValue(new ApiClientError('network', 0, 'network', 'x'))

          await rowOf(wrapper, 'Dilnoza').get('[data-testid=battle-btn]').trigger('click')
          await flushPromises()

          expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
          expect(router.currentRoute.value.name).toBe('battle-new')
        })

        it('the buttons wait while the counter is being confirmed: a second press is not a second question', async () => {
          useBillingStore().apply(meWith(withLimits(freeLimits(0, 1, inHours(4)))))
          const { wrapper } = await renderBattles(withLimits(freeLimits(0, 1, inHours(4))))
          let answer!: (value: MeResponse) => void
          vi.mocked(api.me.get).mockReturnValue(new Promise((resolve) => (answer = resolve)))

          await rowOf(wrapper, 'Dilnoza').get('[data-testid=battle-btn]').trigger('click')
          expect(wrapper.findAll('[data-testid=battle-btn]').every((button) => button.attributes('disabled') !== undefined)).toBe(true)
          await rowOf(wrapper, 'Друг').get('[data-testid=battle-btn]').trigger('click')
          answer(meWith(withLimits(freeLimits(0, 1, inHours(4)))))
          await flushPromises()

          expect(api.me.get).toHaveBeenCalledTimes(1)
          expect(wrapper.findAll('[data-testid=paywall]')).toHaveLength(1)
          expect(wrapper.findAll('[data-testid=battle-btn]').every((button) => button.attributes('disabled') === undefined)).toBe(true)
        })

        // The screen asks for /me at its start when what it has is older than the cache allows, and the learner may press before the answer: the
        // zero from the earlier screen must not decide - that reading is waited for, and its counter is the word.
        describe('a /me that the screen itself began and that is still on its way', () => {
          const staleZero = () => {
            const billing = useBillingStore()
            billing.apply(meWith(withLimits(freeLimits(0, 1, inHours(4))))) // the earlier screen: the practice block and the zero
            billing.invalidate() // ... older than the cache allows, so this screen asks again
          }
          const onItsWay = () => {
            let answer!: (value: MeResponse) => void
            vi.mocked(api.me.get).mockReturnValue(new Promise((resolve) => (answer = resolve)))
            return (value: MeResponse) => answer(value)
          }

          it('"Battle" waits for it, and the place it gives back is a place: no paywall, the setup opens - after ONE reading', async () => {
            staleZero()
            const answer = onItsWay()
            const { wrapper, router } = await render()
            expect(line(wrapper).text()).toBe('Баттлов сегодня: осталось 0 из 1') // the stale zero is what the screen shows meanwhile

            await rowOf(wrapper, 'Dilnoza').get('[data-testid=battle-btn]').trigger('click')
            await flushPromises()
            expect(wrapper.find('[data-testid=paywall]').exists(), 'the stale zero does not decide').toBe(false)
            expect(router.currentRoute.value.name).toBe('friends')
            expect(wrapper.findAll('[data-testid=battle-btn]').every((button) => button.attributes('disabled') !== undefined)).toBe(true)

            answer(meWith(withLimits(freeLimits(0, 0, inHours(4))))) // the server had given the place back
            await flushPromises()

            expect(api.me.get).toHaveBeenCalledTimes(1)
            expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
            expect(router.currentRoute.value.name).toBe('battle-new')
            expect(router.currentRoute.value.query).toEqual({ friendship: '11' })
          })

          it('and when that answer still says none, the paywall opens', async () => {
            staleZero()
            const answer = onItsWay()
            const { wrapper, router } = await render()

            await rowOf(wrapper, 'Dilnoza').get('[data-testid=battle-btn]').trigger('click')
            answer(meWith(withLimits(freeLimits(0, 1, inHours(4)))))
            await flushPromises()

            expect(api.me.get).toHaveBeenCalledTimes(1)
            expect(wrapper.get('[data-testid=paywall]').attributes('data-kind')).toBe('battles')
            expect(router.currentRoute.value.name).toBe('friends')
          })
        })

        it('a learner who left the screen while the counter was being confirmed finds no sheet waiting for the next visit', async () => {
          useBillingStore().apply(meWith(withLimits(freeLimits(0, 1, inHours(4)))))
          const { wrapper, router } = await renderBattles(withLimits(freeLimits(0, 1, inHours(4))))
          const push = vi.spyOn(router, 'push')
          let answer!: (value: MeResponse) => void
          vi.mocked(api.me.get).mockReturnValue(new Promise((resolve) => (answer = resolve)))

          await rowOf(wrapper, 'Dilnoza').get('[data-testid=battle-btn]').trigger('click')
          wrapper.unmount()
          answer(meWith(withLimits(freeLimits(0, 1, inHours(4)))))
          await flushPromises()

          expect(useBattleStore().limitHit).toBeNull()
          expect(push).not.toHaveBeenCalled()
        })

        it('nor is the learner carried to the setup of a battle when the place turns out to be free after they have left', async () => {
          useBillingStore().apply(meWith(withLimits(freeLimits(0, 1, inHours(4)))))
          const { wrapper, router } = await renderBattles(withLimits(freeLimits(0, 1, inHours(4))))
          const push = vi.spyOn(router, 'push')
          let answer!: (value: MeResponse) => void
          vi.mocked(api.me.get).mockReturnValue(new Promise((resolve) => (answer = resolve)))

          await rowOf(wrapper, 'Dilnoza').get('[data-testid=battle-btn]').trigger('click')
          wrapper.unmount()
          answer(meWith(withLimits(freeLimits(0, 0, inHours(4)))))
          await flushPromises()

          expect(push).not.toHaveBeenCalled()
        })

        it('a kind that is for Pro only (limit 0) needs no question', async () => {
          const zero = { sessions: null, battles: { limit: 0, used: 0, remaining: 0, resets_at: inHours(4) } }
          const { wrapper, router } = await renderBattles(withLimits(zero))
          expect(line(wrapper).exists()).toBe(false) // "0 of 0 left" says nothing: no footnote line

          await rowOf(wrapper, 'Dilnoza').get('[data-testid=battle-btn]').trigger('click')
          await flushPromises()

          expect(api.me.get).toHaveBeenCalledTimes(1) // Friends' own reading at mount
          expect(wrapper.get('[data-testid=paywall]').attributes('data-limit')).toBe('0')
          expect(wrapper.get('[data-testid=paywall]').attributes('data-kind')).toBe('battles')
          expect(router.currentRoute.value.name).toBe('friends')
          expect(line(wrapper).exists()).toBe(false)
        })

        it('and "Accept" of an invitation is still never limited, though the learner cannot call a friend', async () => {
          const zero = { sessions: null, battles: { limit: 0, used: 0, remaining: 0, resets_at: inHours(4) } }
          vi.mocked(api.battles.accept).mockResolvedValue(roomBattle({ id: 15, role: 'opponent' }))
          const { wrapper, router } = await renderBattles(withLimits(zero), battleList({ incoming: [incoming({ id: 15 })] }))

          await wrapper.get('[data-testid=battle-accept-btn]').trigger('click')
          await flushPromises()

          expect(api.battles.accept).toHaveBeenCalledWith(15)
          expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
          expect(router.currentRoute.value.name).toBe('battle')
        })
      })

      // An empty env of the server means that kind has no limit: `limits` carries `null` for it and the other kind keeps its counter.
      describe('one kind of limit without a counter (null inside the limits)', () => {
        it('battles without a limit: no line, and "Battle" leads to the setup, with the sessions spent as well', async () => {
          const { wrapper, router } = await renderBattles(withLimits({ sessions: freeLimits(2, 0).sessions, battles: null }))
          expect(line(wrapper).exists()).toBe(false)

          await rowOf(wrapper, 'Dilnoza').get('[data-testid=battle-btn]').trigger('click')
          await flushPromises()

          expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
          expect(router.currentRoute.value.name).toBe('battle-new')
        })

        it('sessions without a limit: the line of the battles as it is, and "Battle" meets its counter', async () => {
          const { wrapper } = await renderBattles(withLimits({ sessions: null, battles: freeLimits(0, 1, inHours(4)).battles }))
          expect(line(wrapper).text()).toBe('Баттлов сегодня: осталось 0 из 1')

          await rowOf(wrapper, 'Dilnoza').get('[data-testid=battle-btn]').trigger('click')
          await flushPromises()

          expect(wrapper.get('[data-testid=paywall]').attributes('data-kind')).toBe('battles')
        })

        it('both kinds without a limit: nothing shown, nothing stopped', async () => {
          const { wrapper, router } = await renderBattles(withLimits({ sessions: null, battles: null }))
          expect(line(wrapper).exists()).toBe(false)

          await rowOf(wrapper, 'Dilnoza').get('[data-testid=battle-btn]').trigger('click')
          await flushPromises()

          expect(router.currentRoute.value.name).toBe('battle-new')
        })
      })
    })
  })

  describe('what the friends wear (docs/05 §5b.6)', () => {
    it('draws the camel of a friend next to the name, the base one for null', async () => {
      const { wrapper } = await render(
        friendsResponse({
          friends: [
            { id: 11, first_name: 'Dilnoza', streak: 5, words_learned: 120, since: null, camel_asset: 'camel_golden' },
            { id: 12, first_name: 'Aziz', streak: 0, words_learned: 3, since: null, camel_asset: null },
          ],
        }),
      )

      const camels = wrapper.findAll('[data-testid=friend-camel]')
      expect(camels.map((c) => c.attributes('data-asset'))).toEqual(['camel_golden', 'camel_dromedary'])
      expect(wrapper.findAll('[data-testid=friend-row]')[0]!.text()).toContain('Dilnoza')
    })

    it('draws nothing where the field did not come (the game is closed)', async () => {
      const { wrapper } = await render()

      expect(wrapper.find('[data-testid=friend-camel]').exists()).toBe(false)
    })
  })

  it('uses only the tokens of the design system: no raw palette colours', async () => {
    const { wrapper } = await render(
      friendsResponse(),
      challengesResponse({ open: [challenge(), pending({ id: 2, role: 'opponent' })], finished: [challenge({ id: 3, status: 'finished', result: 'lost' })] }),
    )
    vi.mocked(api.friends.accept).mockRejectedValue(new ApiClientError('conflict', 409, 'friend_limit', 'x'))
    await wrapper.get('[data-testid=accept-btn]').trigger('click')
    await flushPromises()

    expect(wrapper.html()).not.toMatch(/\b(?:text|bg|border)-(?:red|green|gray|black|white)\b|\b(?:text|bg|border)-(?:red|green|gray|blue)-\d{2,3}\b/)
  })
})
