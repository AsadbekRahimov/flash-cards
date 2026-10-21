import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import AchievementsView from '../AchievementsView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import type { AchievementItem, AchievementsResponse } from '@/api/types'

const state = vi.hoisted(() => ({
  ensure: vi.fn(),
  back: undefined as undefined | (() => void),
  hideBack: vi.fn(),
  shareUrl: vi.fn(),
  haptic: vi.fn(),
}))

vi.mock('@/api', () => ({ api: { achievements: { list: vi.fn(), shared: vi.fn() } } }))

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

const item = (overrides: Partial<AchievementItem> & Pick<AchievementItem, 'key' | 'title'>): AchievementItem => ({
  description: 'Описание',
  unlocked_at: null,
  shared_at: null,
  progress: null,
  share_text: null,
  ...overrides,
})

const response = (overrides: Partial<AchievementsResponse> = {}): AchievementsResponse => ({
  unlocked_count: 2,
  total: 4,
  share_link: 'https://t.me/LexiFlowBot?start=ref_ABCD2345',
  items: [
    item({ key: 'first_session', title: 'Первый шаг', unlocked_at: '2026-10-01T10:00:00+00:00', share_text: 'Я получил(а) «Первый шаг»' }),
    item({ key: 'streak_7', title: 'Серия 7 дней', unlocked_at: '2026-10-08T10:00:00+00:00', shared_at: '2026-10-08T11:00:00+00:00', share_text: 'Я получил(а) «Серия 7 дней»' }),
    item({ key: 'words_100', title: '100 слов', progress: { current: 40, target: 100 } }),
    item({ key: 'league_top1', title: 'Победитель лиги' }),
  ],
  ...overrides,
})

const makeRouter = () =>
  createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: { template: '<div />' } },
      { path: '/achievements', name: 'achievements', component: AchievementsView },
    ],
  })

async function render(data: AchievementsResponse = response()) {
  vi.mocked(api.achievements.list).mockResolvedValue(data)
  const router = makeRouter()
  await router.push('/achievements')
  await router.isReady()
  const wrapper = mount(AchievementsView, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

describe('AchievementsView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    state.back = undefined
    state.ensure.mockResolvedValue(undefined)
  })
  afterEach(() => setLocale('ru'))

  it('lists unlocked achievements first (newest first), then the locked ones with their progress', async () => {
    const { wrapper } = await render()

    expect(wrapper.get('[data-testid=summary]').text()).toBe('Получено 2 из 4')
    const keys = wrapper.findAll('li').map((li) => li.attributes('data-testid'))
    expect(keys).toEqual(['item-streak_7', 'item-first_session', 'item-words_100', 'item-league_top1'])

    expect(wrapper.get('[data-testid=item-first_session]').attributes('data-unlocked')).toBe('true')
    expect(wrapper.get('[data-testid=item-first_session] [data-testid=unlocked-on]').text()).toContain('Получено')

    const words = wrapper.get('[data-testid=item-words_100]')
    expect(words.attributes('data-unlocked')).toBe('false')
    expect(words.get('[data-testid=progress]').text()).toBe('40 из 100')
    expect(words.get('[data-testid=bar]').attributes('style')).toContain('width: 40%')
    expect(words.find('[data-testid=share-btn]').exists()).toBe(false)

    expect(wrapper.get('[data-testid=item-league_top1] [data-testid=locked]').text()).toBe('Ещё не получено')
    expect(wrapper.find('[data-testid=empty-hint]').exists()).toBe(false)
  })

  it('opens the Telegram share dialog with the link and the prefilled text, then marks the key as shared', async () => {
    const refreshed = response({
      items: response().items.map((i) => (i.key === 'first_session' ? { ...i, shared_at: '2026-10-21T10:00:00+00:00' } : i)),
    })
    vi.mocked(api.achievements.shared).mockResolvedValue(refreshed)
    const { wrapper } = await render()

    expect(wrapper.find('[data-testid=item-first_session] [data-testid=shared]').exists()).toBe(false)
    await wrapper.get('[data-testid=item-first_session] [data-testid=share-btn]').trigger('click')
    await flushPromises()

    expect(state.shareUrl).toHaveBeenCalledWith('https://t.me/LexiFlowBot?start=ref_ABCD2345', 'Я получил(а) «Первый шаг»')
    expect(state.haptic).toHaveBeenCalled()
    expect(api.achievements.shared).toHaveBeenCalledWith('first_session')
    expect(wrapper.get('[data-testid=item-first_session] [data-testid=shared]').text()).toContain('Вы уже поделились')
  })

  it('still opened the dialog when marking as shared fails', async () => {
    vi.mocked(api.achievements.shared).mockRejectedValue(new ApiClientError('network', 0, 'network', 'x'))
    const { wrapper } = await render()

    await wrapper.get('[data-testid=item-first_session] [data-testid=share-btn]').trigger('click')
    await flushPromises()

    expect(state.shareUrl).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-testid=error]').exists()).toBe(false)
  })

  it('hides the share buttons while there is no invite link', async () => {
    const { wrapper } = await render(response({ share_link: null }))

    expect(wrapper.find('[data-testid=share-btn]').exists()).toBe(false)
    expect(wrapper.get('[data-testid=item-first_session]').text()).toContain('Первый шаг')
  })

  it('encourages to study when nothing is unlocked yet', async () => {
    const { wrapper } = await render(
      response({ unlocked_count: 0, items: response().items.map((i) => ({ ...i, unlocked_at: null, shared_at: null, share_text: null })) }),
    )

    expect(wrapper.get('[data-testid=empty-hint]').text()).toBe('Занимайтесь каждый день — достижения появятся сами.')
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const { wrapper } = await render()

    expect(wrapper.get('h1').text()).toContain('Yutuqlar')
    expect(wrapper.get('[data-testid=summary]').text()).toBe('4 tadan 2 tasi olindi')
    expect(wrapper.get('[data-testid=item-first_session] [data-testid=share-btn]').text()).toBe('Ulashish')
    expect(wrapper.get('[data-testid=item-words_100] [data-testid=progress]').text()).toBe('100 tadan 40 tasi')
  })

  it('shows the error with a retry and goes back to Today with the Telegram back button', async () => {
    vi.mocked(api.achievements.list).mockRejectedValueOnce(new ApiClientError('network', 0, 'network', 'x'))
    const { wrapper, router } = await render()

    expect(wrapper.find('[data-testid=error]').exists()).toBe(true)
    vi.mocked(api.achievements.list).mockResolvedValue(response())
    await wrapper.get('[data-testid=retry-btn]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid=list]').exists()).toBe(true)

    state.back?.()
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('today')

    wrapper.unmount()
    expect(state.hideBack).toHaveBeenCalled()
  })
})
