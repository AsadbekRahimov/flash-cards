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
      { path: '/profile', name: 'profile', component: { template: '<div />' } },
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

type Wrapper = Awaited<ReturnType<typeof render>>['wrapper']

const text = (w: Wrapper, id: string): string => w.get(`[data-testid=${id}]`).text()
const card = (w: Wrapper, key: string) => w.get(`[data-testid=item-${key}]`)

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

    expect(text(wrapper, 'summary')).toBe('Получено 2 из 4')
    const keys = wrapper.findAll('li').map((li) => li.attributes('data-testid'))
    expect(keys).toEqual(['item-streak_7', 'item-first_session', 'item-words_100', 'item-league_top1'])

    expect(card(wrapper, 'first_session').attributes('data-unlocked')).toBe('true')
    expect(card(wrapper, 'first_session').get('[data-testid=unlocked-on]').text()).toContain('Получено')

    const words = card(wrapper, 'words_100')
    expect(words.attributes('data-unlocked')).toBe('false')
    expect(words.get('[data-testid=progress]').text()).toBe('40 из 100')
    expect(words.get('[data-testid=bar]').attributes('style')).toContain('width: 40%')
    expect(words.find('[data-testid=share-btn]').exists()).toBe(false)

    expect(card(wrapper, 'league_top1').get('[data-testid=locked]').text()).toBe('Ещё не получено')
    expect(wrapper.find('[data-testid=empty-hint]').exists()).toBe(false)
  })

  describe('the header and the summary', () => {
    it('has a plain title (no emoji) and a summary with a progress bar of the unlocked share', async () => {
      const { wrapper } = await render()

      expect(wrapper.get('h1').text()).toBe('Достижения')
      const bar = wrapper.get('[data-testid=summary-bar]')
      // 2 of 4
      expect(bar.attributes('style')).toContain('width: 50%')
      expect(bar.element.parentElement?.classList.contains('progress')).toBe(true)
      expect(bar.element.parentElement?.getAttribute('role')).toBe('progressbar')
      expect(bar.element.parentElement?.getAttribute('aria-valuenow')).toBe('50')
    })

    it('does not divide by zero for an empty catalog', async () => {
      const { wrapper } = await render(response({ unlocked_count: 0, total: 0, items: [] }))

      expect(wrapper.get('[data-testid=summary-bar]').attributes('style')).toContain('width: 0%')
      expect(text(wrapper, 'summary')).toBe('Получено 0 из 0')
    })

    it('encourages to study when nothing is unlocked yet', async () => {
      const { wrapper } = await render(
        response({ unlocked_count: 0, items: response().items.map((i) => ({ ...i, unlocked_at: null, shared_at: null, share_text: null })) }),
      )

      expect(text(wrapper, 'empty-hint')).toBe('Занимайтесь каждый день — достижения появятся сами.')
      expect(wrapper.get('[data-testid=summary-bar]').attributes('style')).toContain('width: 0%')
    })
  })

  describe('the grid', () => {
    it('is two columns of cards', async () => {
      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=list]').classes()).toEqual(expect.arrayContaining(['grid', 'grid-cols-2']))
      expect(wrapper.findAll('[data-testid=list] > li').every((li) => li.classes().includes('card'))).toBe(true)
    })

    it('draws a trophy on warning for an unlocked card and a lock on the fill for a locked one', async () => {
      const { wrapper } = await render()

      const unlocked = card(wrapper, 'first_session').get('[data-testid=item-tile]')
      expect(unlocked.classes()).toEqual(expect.arrayContaining(['bg-warning-soft', 'text-warning-ink']))
      expect(unlocked.get('svg').attributes('fill')).toBe('currentColor')

      const locked = card(wrapper, 'league_top1').get('[data-testid=item-tile]')
      expect(locked.classes()).toEqual(expect.arrayContaining(['bg-fill', 'text-tg-hint']))
      expect(locked.classes()).not.toContain('bg-warning-soft')
      // the lock is an outline icon, the trophy a filled one
      expect(locked.get('svg').attributes('fill')).toBe('none')
      expect(locked.get('svg path').attributes('d')).not.toBe(unlocked.get('svg path').attributes('d'))
    })

    it('shows no emoji on the cards: the icons are drawn', async () => {
      const { wrapper } = await render()

      expect(wrapper.text()).not.toMatch(/[🏅🔒🏆]/u)
    })

    it('limits the description to three lines and the title to two', async () => {
      const { wrapper } = await render()

      const first = card(wrapper, 'first_session')
      expect(first.get('[data-testid=item-description]').classes()).toContain('line-clamp-3')
      expect(first.get('[data-testid=item-title]').classes()).toContain('line-clamp-2')
      expect(first.get('[data-testid=item-title]').text()).toBe('Первый шаг')
      expect(first.get('[data-testid=item-description]').text()).toBe('Описание')
    })

    it('shows the unlock date with no break inside it', async () => {
      const { wrapper } = await render()

      const date = card(wrapper, 'first_session').get('[data-testid=unlocked-on]').text()
      // "Получено 1 окт. 2026 г." in the device's time zone; the spaces of the date itself are no-break
      const local = new Intl.DateTimeFormat('ru', { day: 'numeric', month: 'short', year: 'numeric' })
        .format(new Date('2026-10-01T10:00:00+00:00'))
        .replace(/\s+/g, '\u00a0')
      expect(date).toBe(`Получено ${local}`)
      // a plain space only after the word, none inside the date
      expect(date.match(/ /g)).toHaveLength(1)
    })
  })

  describe('sharing', () => {
    it('has a small tinted "Поделиться" on every unlocked card, none on a locked one', async () => {
      const { wrapper } = await render()

      for (const key of ['first_session', 'streak_7']) {
        const button = card(wrapper, key).get('[data-testid=share-btn]')
        expect(button.text()).toBe('Поделиться')
        expect(button.classes()).toEqual(expect.arrayContaining(['btn', 'btn-tinted', 'btn-sm']))
      }
      expect(card(wrapper, 'words_100').find('[data-testid=share-btn]').exists()).toBe(false)
      expect(card(wrapper, 'league_top1').find('[data-testid=share-btn]').exists()).toBe(false)
    })

    it('opens the Telegram share dialog with the link and the prefilled text, then marks the key as shared', async () => {
      const refreshed = response({
        items: response().items.map((i) => (i.key === 'first_session' ? { ...i, shared_at: '2026-10-21T10:00:00+00:00' } : i)),
      })
      vi.mocked(api.achievements.shared).mockResolvedValue(refreshed)
      const { wrapper } = await render()

      expect(card(wrapper, 'first_session').find('[data-testid=shared]').exists()).toBe(false)
      await card(wrapper, 'first_session').get('[data-testid=share-btn]').trigger('click')
      await flushPromises()

      expect(state.shareUrl).toHaveBeenCalledWith('https://t.me/LexiFlowBot?start=ref_ABCD2345', 'Я получил(а) «Первый шаг»')
      expect(state.haptic).toHaveBeenCalled()
      expect(api.achievements.shared).toHaveBeenCalledWith('first_session')
      expect(card(wrapper, 'first_session').get('[data-testid=shared]').text()).toContain('Вы уже поделились')
    })

    it('marks a shared card with a check footnote', async () => {
      const { wrapper } = await render()

      const shared = card(wrapper, 'streak_7').get('[data-testid=shared]')
      expect(shared.text()).toBe('Вы уже поделились')
      expect(shared.find('svg').exists()).toBe(true)
    })

    it('still opened the dialog when marking as shared fails', async () => {
      vi.mocked(api.achievements.shared).mockRejectedValue(new ApiClientError('network', 0, 'network', 'x'))
      const { wrapper } = await render()

      await card(wrapper, 'first_session').get('[data-testid=share-btn]').trigger('click')
      await flushPromises()

      expect(state.shareUrl).toHaveBeenCalledTimes(1)
      expect(wrapper.find('[data-testid=error]').exists()).toBe(false)
    })

    it('hides the share buttons while there is no invite link', async () => {
      const { wrapper } = await render(response({ share_link: null }))

      expect(wrapper.find('[data-testid=share-btn]').exists()).toBe(false)
      expect(card(wrapper, 'first_session').text()).toContain('Первый шаг')
    })

    it('does not share a card that has no text yet', async () => {
      const { wrapper } = await render(
        response({ items: [item({ key: 'odd', title: 'Odd', unlocked_at: '2026-10-01T10:00:00+00:00', share_text: null })], unlocked_count: 1, total: 1 }),
      )

      await card(wrapper, 'odd').get('[data-testid=share-btn]').trigger('click')

      expect(state.shareUrl).not.toHaveBeenCalled()
      expect(api.achievements.shared).not.toHaveBeenCalled()
    })
  })

  describe('progress', () => {
    it.each([
      [0, 0],
      [40, 40],
      [100, 100],
      [250, 100],
    ])('a bar of %i of 100 is %i percent wide, never past the end', async (current, width) => {
      const { wrapper } = await render(
        response({ items: [item({ key: 'count', title: 'Count', progress: { current, target: 100 } })], unlocked_count: 0, total: 1 }),
      )

      const bar = card(wrapper, 'count').get('[data-testid=bar]')
      expect(bar.attributes('style')).toContain(`width: ${width}%`)
      expect(bar.element.parentElement?.getAttribute('aria-valuenow')).toBe(String(width))
    })

    it('shows an empty bar for a target of zero', async () => {
      const { wrapper } = await render(
        response({ items: [item({ key: 'zero', title: 'Zero', progress: { current: 0, target: 0 } })], unlocked_count: 0, total: 1 }),
      )

      expect(card(wrapper, 'zero').get('[data-testid=bar]').attributes('style')).toContain('width: 0%')
    })
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const { wrapper } = await render()

    expect(wrapper.get('h1').text()).toBe('Yutuqlar')
    expect(text(wrapper, 'summary')).toBe('4 tadan 2 tasi olindi')
    expect(card(wrapper, 'first_session').get('[data-testid=share-btn]').text()).toBe('Ulashish')
    expect(card(wrapper, 'words_100').get('[data-testid=progress]').text()).toBe('100 tadan 40 tasi')
    expect(card(wrapper, 'league_top1').get('[data-testid=locked]').text()).toBe('Hali olinmagan')
    expect(card(wrapper, 'first_session').get('[data-testid=unlocked-on]').text()).toMatch(/da olindi$/)
  })

  describe('the Back button', () => {
    it('goes back to the Profile and is removed on leaving', async () => {
      const { wrapper, router } = await render()

      state.back?.()
      await flushPromises()
      expect(router.currentRoute.value.name).toBe('profile')

      wrapper.unmount()
      expect(state.hideBack).toHaveBeenCalled()
    })
  })

  describe('loading and errors', () => {
    it('shows a skeleton while loading, under the title', async () => {
      vi.mocked(api.achievements.list).mockReturnValue(new Promise(() => {}))
      const router = makeRouter()
      await router.push('/achievements')
      const wrapper = mount(AchievementsView, { global: { plugins: [router] } })
      await flushPromises()

      expect(wrapper.find('[data-testid=spinner]').exists()).toBe(true)
      expect(wrapper.get('h1').text()).toBe('Достижения')
      expect(wrapper.find('[data-testid=list]').exists()).toBe(false)
    })

    it('shows the error with a retry', async () => {
      vi.mocked(api.achievements.list).mockRejectedValueOnce(new ApiClientError('network', 0, 'network', 'x'))
      const { wrapper } = await render()

      expect(wrapper.find('[data-testid=error]').exists()).toBe(true)
      vi.mocked(api.achievements.list).mockResolvedValue(response())
      await wrapper.get('[data-testid=retry-btn]').trigger('click')
      await flushPromises()

      expect(wrapper.find('[data-testid=error]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=list]').exists()).toBe(true)
    })

    it('shows the error when the sign-in fails and loads nothing', async () => {
      state.ensure.mockRejectedValue(new ApiClientError('network', 0, 'network', 'x'))
      const { wrapper } = await render()

      expect(wrapper.find('[data-testid=error]').exists()).toBe(true)
      expect(api.achievements.list).not.toHaveBeenCalled()
    })
  })

  it('uses only the tokens of the design system: no raw palette colours', async () => {
    const { wrapper } = await render()

    expect(wrapper.html()).not.toMatch(/\b(?:text|bg|border)-(?:red|green|gray|black|white)\b|\b(?:text|bg|border)-(?:red|green|gray|blue)-\d{2,3}\b|bg-black\/\d+/)
  })
})
