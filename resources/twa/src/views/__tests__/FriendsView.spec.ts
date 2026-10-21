import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import FriendsView from '../FriendsView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import type { ChallengeItem, ChallengesResponse, FriendsResponse } from '@/api/types'

const state = vi.hoisted(() => ({
  ensure: vi.fn(),
  back: undefined as undefined | (() => void),
  hideBack: vi.fn(),
  shareUrl: vi.fn(),
  haptic: vi.fn(),
}))

vi.mock('@/api', () => ({
  api: {
    friends: { get: vi.fn(), accept: vi.fn(), decline: vi.fn(), remove: vi.fn() },
    challenges: { list: vi.fn(), propose: vi.fn(), accept: vi.fn(), decline: vi.fn() },
  },
}))

vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: state.ensure }) }))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    shareUrl: state.shareUrl,
    haptic: state.haptic,
    showBackButton: (handler: () => void) => {
      state.back = handler
      return state.hideBack
    },
  }),
}))

const friendsResponse = (overrides: Partial<FriendsResponse> = {}): FriendsResponse => ({
  invite_link: 'https://t.me/LexiFlowBot?start=f_ABCD2345',
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
      { path: '/referrals', name: 'referrals', component: { template: '<div />' } },
      { path: '/friends', name: 'friends', component: FriendsView },
    ],
  })

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

describe('FriendsView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    state.back = undefined
    state.ensure.mockResolvedValue(undefined)
  })
  afterEach(() => setLocale('ru'))

  it('shows the friends with streak and learned words, the requests and the invite link', async () => {
    const { wrapper } = await render()

    expect(wrapper.get('[data-testid=link]').text()).toBe('https://t.me/LexiFlowBot?start=f_ABCD2345')
    expect(wrapper.get('[data-testid=friends-count]').text()).toBe('2 из 100')

    const rows = wrapper.findAll('[data-testid=friend-row]')
    expect(rows).toHaveLength(2)
    expect(rows[0]?.text()).toContain('Dilnoza')
    expect(rows[0]?.text()).toContain('серия 5 дн.')
    expect(rows[0]?.text()).toContain('слов выучено: 120')
    expect(rows[1]?.text()).toContain('Друг')

    expect(wrapper.get('[data-testid=incoming-row]').text()).toContain('Bek')
    expect(wrapper.get('[data-testid=outgoing-row]').text()).toContain('Sam')
    expect(wrapper.find('[data-testid=friends-empty]').exists()).toBe(false)
    expect(wrapper.get('[data-testid=challenges-empty]').text()).toBe('Вызовов пока нет.')
  })

  it('opens the Telegram share dialog with the f_ link and copies it', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
    const { wrapper } = await render()

    await wrapper.get('[data-testid=share-btn]').trigger('click')
    expect(state.shareUrl).toHaveBeenCalledWith(
      'https://t.me/LexiFlowBot?start=f_ABCD2345',
      'Давай дружить в LexiFlow: сравним серии и устроим вызов!',
    )

    await wrapper.get('[data-testid=copy-btn]').trigger('click')
    await flushPromises()
    expect(writeText).toHaveBeenCalledWith('https://t.me/LexiFlowBot?start=f_ABCD2345')
    expect(wrapper.get('[data-testid=copy-btn]').text()).toContain('Скопировано')
  })

  it('explains a missing link instead of the share buttons', async () => {
    const { wrapper } = await render(friendsResponse({ invite_link: null, friends: [], incoming: [], outgoing: [] }))

    expect(wrapper.find('[data-testid=share-btn]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=no-link]').exists()).toBe(true)
    expect(wrapper.get('[data-testid=friends-empty]').text()).toContain('Пока нет друзей')
    expect(wrapper.find('[data-testid=incoming]').exists()).toBe(false)
  })

  it('accepts and declines an incoming request and shows the refreshed list', async () => {
    const { wrapper } = await render()
    vi.mocked(api.friends.accept).mockResolvedValue(friendsResponse({ incoming: [] }))

    await wrapper.get('[data-testid=accept-btn]').trigger('click')
    await flushPromises()

    expect(api.friends.accept).toHaveBeenCalledWith(21)
    expect(wrapper.find('[data-testid=incoming]').exists()).toBe(false)

    const second = await render()
    vi.mocked(api.friends.decline).mockResolvedValue(friendsResponse({ incoming: [] }))
    await second.wrapper.get('[data-testid=decline-btn]').trigger('click')
    await flushPromises()
    expect(api.friends.decline).toHaveBeenCalledWith(21)
  })

  it('withdraws an outgoing request', async () => {
    const { wrapper } = await render()
    vi.mocked(api.friends.remove).mockResolvedValue(friendsResponse({ outgoing: [] }))

    await wrapper.get('[data-testid=cancel-request-btn]').trigger('click')
    await flushPromises()

    expect(api.friends.remove).toHaveBeenCalledWith(31)
    expect(wrapper.find('[data-testid=outgoing]').exists()).toBe(false)
  })

  it('removes a friend only after the confirmation, then reloads the challenges', async () => {
    const { wrapper } = await render()
    vi.mocked(api.friends.remove).mockResolvedValue(friendsResponse({ friends: [friendsResponse().friends[1]!] }))

    await wrapper.findAll('[data-testid=remove-btn]')[0]!.trigger('click')
    expect(api.friends.remove).not.toHaveBeenCalled()
    expect(wrapper.get('[data-testid=remove-confirm]').text()).toContain('Удалить Dilnoza из друзей?')

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
  })

  it('proposes a challenge: pick the metric, send, see it in the list', async () => {
    const { wrapper } = await render()
    vi.mocked(api.challenges.propose).mockResolvedValue(challenge({ status: 'pending', my_score: null, their_score: null, starts_on: null, ends_on: null }))
    vi.mocked(api.challenges.list).mockResolvedValue(challengesResponse({ open: [challenge({ status: 'pending', my_score: null, their_score: null, starts_on: null, ends_on: null })] }))

    await wrapper.findAll('[data-testid=challenge-btn]')[0]!.trigger('click')
    expect(wrapper.get('[data-testid=metric-picker]').text()).toContain('Кто больше за 7 дн.?')

    await wrapper.get('[data-testid=metric-new_words]').trigger('click')
    await flushPromises()

    expect(api.challenges.propose).toHaveBeenCalledWith(11, 'new_words')
    expect(wrapper.find('[data-testid=metric-picker]').exists()).toBe(false)
    expect(wrapper.get('[data-testid=challenge-status]').text()).toBe('ждём ответа друга')
    // The friend with an open challenge cannot get a second one.
    expect(wrapper.findAll('[data-testid=challenge-btn]')).toHaveLength(1)
  })

  it('shows the live score and the period of an active challenge', async () => {
    const { wrapper } = await render(friendsResponse(), challengesResponse({ open: [challenge()] }))

    expect(wrapper.get('[data-testid=challenge-score]').text()).toBe('14 : 9')
    expect(wrapper.get('[data-testid=challenge-status]').text()).toContain('идёт')
    expect(wrapper.find('[data-testid=challenge-accept]').exists()).toBe(false)
  })

  it('answers a challenge from a friend', async () => {
    const pending = challenge({ status: 'pending', role: 'opponent', my_score: null, their_score: null, starts_on: null, ends_on: null })
    const { wrapper } = await render(friendsResponse(), challengesResponse({ open: [pending] }))
    vi.mocked(api.challenges.accept).mockResolvedValue({ ...pending, status: 'active' })
    vi.mocked(api.challenges.list).mockResolvedValue(challengesResponse({ open: [challenge({ role: 'opponent' })] }))

    expect(wrapper.get('[data-testid=challenge-status]').text()).toBe('вам бросили вызов')
    await wrapper.get('[data-testid=challenge-accept]').trigger('click')
    await flushPromises()

    expect(api.challenges.accept).toHaveBeenCalledWith(1)
    expect(wrapper.find('[data-testid=challenge-accept]').exists()).toBe(false)
    expect(wrapper.get('[data-testid=challenge-status]').text()).toContain('идёт')
  })

  it('lists finished challenges with the result', async () => {
    const { wrapper } = await render(
      friendsResponse(),
      challengesResponse({ finished: [challenge({ status: 'finished', result: 'won', my_score: 30, their_score: 27 }), challenge({ id: 2, status: 'finished', result: 'draw', my_score: 5, their_score: 5 })] }),
    )

    const rows = wrapper.findAll('[data-testid=finished-row]')
    expect(rows).toHaveLength(2)
    expect(rows[0]?.text()).toContain('победа · 30 : 27')
    expect(rows[1]?.text()).toContain('ничья · 5 : 5')
  })

  it('shows the localized error of a refused action and reloads what is true now', async () => {
    const { wrapper } = await render()
    vi.mocked(api.friends.accept).mockRejectedValue(new ApiClientError('conflict', 409, 'friend_limit', 'x'))
    vi.mocked(api.friends.get).mockResolvedValue(friendsResponse({ incoming: [] }))

    await wrapper.get('[data-testid=accept-btn]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid=action-error]').text()).toBe('У вас уже максимальное число друзей.')
    expect(wrapper.find('[data-testid=incoming]').exists()).toBe(false)
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const { wrapper } = await render()

    expect(wrapper.get('h1').text()).toContain("Do'stlar")
    expect(wrapper.get('[data-testid=share-btn]').text()).toBe('Havolani ulashish')
  })

  it('shows the error with a retry and goes back with the Telegram back button', async () => {
    vi.mocked(api.friends.get).mockRejectedValueOnce(new ApiClientError('network', 0, 'network', 'x'))
    vi.mocked(api.challenges.list).mockResolvedValue(challengesResponse())
    const router = makeRouter()
    await router.push('/friends')
    await router.isReady()
    const wrapper = mount(FriendsView, { global: { plugins: [router] } })
    await flushPromises()

    expect(wrapper.find('[data-testid=error]').exists()).toBe(true)
    vi.mocked(api.friends.get).mockResolvedValue(friendsResponse())
    await wrapper.get('[data-testid=retry-btn]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid=link]').exists()).toBe(true)

    state.back?.()
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('referrals')

    wrapper.unmount()
    expect(state.hideBack).toHaveBeenCalled()
  })
})
