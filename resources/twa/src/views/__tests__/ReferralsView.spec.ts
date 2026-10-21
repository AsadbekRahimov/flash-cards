import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import ReferralsView from '../ReferralsView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import type { ReferralsResponse } from '@/api/types'

const state = vi.hoisted(() => ({
  ensure: vi.fn(),
  back: undefined as undefined | (() => void),
  hideBack: vi.fn(),
  shareUrl: vi.fn(),
  haptic: vi.fn(),
}))

vi.mock('@/api', () => ({ api: { referrals: { get: vi.fn() } } }))

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

const response = (overrides: Partial<ReferralsResponse> = {}): ReferralsResponse => ({
  code: 'ABCD2345',
  link: 'https://t.me/LexiFlowBot?start=ref_ABCD2345',
  reward_days: 7,
  activation: { days: 3, reviews_per_day: 10, window_days: 14 },
  counts: { invited: 3, activated: 2, rewarded: 1, capped: 0 },
  referrals: [
    { first_name: 'Dilnoza', status: 'rewarded', linked_at: '2026-10-20T10:00:00+00:00' },
    { first_name: null, status: 'activated', linked_at: '2026-10-21T10:00:00+00:00' },
    { first_name: 'Bek', status: 'pending', linked_at: '2026-10-22T10:00:00+00:00' },
  ],
  ...overrides,
})

const makeRouter = () =>
  createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: { template: '<div />' } },
      { path: '/referrals', name: 'referrals', component: ReferralsView },
      { path: '/friends', name: 'friends', component: { template: '<div />' } },
    ],
  })

async function render(data: ReferralsResponse = response()) {
  vi.mocked(api.referrals.get).mockResolvedValue(data)
  const router = makeRouter()
  await router.push('/referrals')
  await router.isReady()
  const wrapper = mount(ReferralsView, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

describe('ReferralsView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    state.back = undefined
    state.ensure.mockResolvedValue(undefined)
  })
  afterEach(() => setLocale('ru'))

  it('shows the rule, the link, the counts and the invited learners', async () => {
    const { wrapper } = await render()

    expect(wrapper.get('[data-testid=rule]').text()).toBe(
      'Когда друг позанимается 3 дн. (от 10 карточек в день) в течение 14 дней, вы оба получите 7 дн. Pro.',
    )
    expect(wrapper.get('[data-testid=link]').text()).toBe('https://t.me/LexiFlowBot?start=ref_ABCD2345')
    expect(wrapper.get('[data-testid=count-invited]').text()).toBe('3')
    expect(wrapper.get('[data-testid=count-activated]').text()).toBe('2')
    expect(wrapper.get('[data-testid=count-rewarded]').text()).toBe('1')

    const rows = wrapper.findAll('[data-testid=referral-row]')
    expect(rows).toHaveLength(3)
    expect(rows[0]?.text()).toContain('Dilnoza')
    expect(rows[0]?.text()).toContain('Pro получен')
    expect(rows[1]?.text()).toContain('Друг')
    expect(rows[1]?.text()).toContain('занимается')
    expect(rows[2]?.text()).toContain('ещё не активен')
    expect(wrapper.find('[data-testid=list-empty]').exists()).toBe(false)
  })

  it('opens the Telegram share dialog with the link and the prefilled text', async () => {
    const { wrapper } = await render()

    await wrapper.get('[data-testid=share-btn]').trigger('click')

    expect(state.shareUrl).toHaveBeenCalledWith(
      'https://t.me/LexiFlowBot?start=ref_ABCD2345',
      'Учу английский в LexiFlow — присоединяйся, получим Pro вместе!',
    )
    expect(state.haptic).toHaveBeenCalled()
  })

  it('copies the link', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
    const { wrapper } = await render()

    await wrapper.get('[data-testid=copy-btn]').trigger('click')
    await flushPromises()

    expect(writeText).toHaveBeenCalledWith('https://t.me/LexiFlowBot?start=ref_ABCD2345')
    expect(wrapper.get('[data-testid=copy-btn]').text()).toContain('Скопировано')
  })

  it('shows the code and an explanation while the bot username is not configured', async () => {
    const { wrapper } = await render(response({ link: null, referrals: [], counts: { invited: 0, activated: 0, rewarded: 0, capped: 0 } }))

    expect(wrapper.get('[data-testid=code]').text()).toBe('ABCD2345')
    expect(wrapper.get('[data-testid=no-link]').text()).toContain('/start ref_ABCD2345')
    expect(wrapper.find('[data-testid=share-btn]').exists()).toBe(false)
    expect(wrapper.get('[data-testid=list-empty]').text()).toBe('Пока никто не пришёл по вашей ссылке.')
  })

  it('explains the reached reward limit only when some referrals were capped', async () => {
    const plain = await render()
    expect(plain.wrapper.find('[data-testid=capped-hint]').exists()).toBe(false)

    const { wrapper } = await render(response({ counts: { invited: 3, activated: 3, rewarded: 1, capped: 2 } }))
    expect(wrapper.get('[data-testid=capped-hint]').text()).toContain('Лимит наград достигнут')
    expect(wrapper.get('[data-testid=capped-hint]').text()).toContain('2')
  })

  it('links to the friends screen', async () => {
    const { wrapper } = await render()

    expect(wrapper.get('[data-testid=friends-link]').attributes('href')).toBe('/friends')
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const { wrapper } = await render()

    expect(wrapper.get('h1').text()).toContain("Do'stni taklif qilish")
    expect(wrapper.get('[data-testid=share-btn]').text()).toBe('Ulashish')
  })

  it('shows the error with a retry and goes back to Today with the Telegram back button', async () => {
    vi.mocked(api.referrals.get).mockRejectedValueOnce(new ApiClientError('network', 0, 'network', 'x'))
    const { wrapper, router } = await render()

    expect(wrapper.find('[data-testid=error]').exists()).toBe(true)
    vi.mocked(api.referrals.get).mockResolvedValue(response())
    await wrapper.get('[data-testid=retry-btn]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid=link]').exists()).toBe(true)

    state.back?.()
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('today')

    wrapper.unmount()
    expect(state.hideBack).toHaveBeenCalled()
  })
})
