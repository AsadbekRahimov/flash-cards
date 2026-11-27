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
  haptic: vi.fn(),
  /** `useShare().share`: by default the message went out (the mark runs); a test may hold it or end it otherwise. */
  share: vi.fn(),
  pending: { value: false },
}))

vi.mock('@/api', () => ({ api: { achievements: { list: vi.fn(), shared: vi.fn() } } }))

vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: state.ensure }) }))

vi.mock('@/composables/useShare', async () => {
  const { ref } = await import('vue')
  const pending = ref(false)
  state.pending = pending
  return { useShare: () => ({ pending, share: state.share }) }
})

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
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
    state.pending.value = false
    state.share.mockImplementation(async (_kind: string, _subject: string | null, _fallback: unknown, onShared?: () => void) => {
      onShared?.()
      return 'sent'
    })
  })
  afterEach(() => setLocale('ru'))

  it('shows the newest received in the hero, then "Received" (newest first) and "Ahead" (progress first) as grids of badges', async () => {
    const { wrapper } = await render()

    expect(text(wrapper, 'hero-title')).toBe('Серия 7 дней')
    expect(text(wrapper, 'summary')).toBe('Получено 2 из 4')
    expect(wrapper.get('[data-testid=group-received] h2').text()).toBe('Получены')
    expect(wrapper.get('[data-testid=group-ahead] h2').text()).toBe('Впереди')
    expect(text(wrapper, 'ahead-count')).toBe('2')

    const keys = (id: string) => wrapper.findAll(`[data-testid=${id}] [data-testid^=item-]:not([data-testid=item-chosen])`).map((el) => el.attributes('data-testid'))
    expect(keys('list')).toEqual(['item-streak_7', 'item-first_session'])
    expect(keys('list-ahead')).toEqual(['item-words_100', 'item-league_top1'])

    expect(card(wrapper, 'first_session').attributes('data-unlocked')).toBe('true')
    expect(card(wrapper, 'words_100').attributes('data-unlocked')).toBe('false')
    // the badges of the kit: received, in progress with the bar and "40 of 100", locked
    const state = (key: string) => card(wrapper, key).get('[data-testid=achievement-badge]').attributes('data-state')
    expect([state('streak_7'), state('words_100'), state('league_top1')]).toEqual(['unlocked', 'progress', 'locked'])
    expect(card(wrapper, 'words_100').text()).toContain('40 из 100')
    expect(card(wrapper, 'words_100').get('[data-testid=achievement-progress] .progress-fill').attributes('style')).toContain('width: 40%')
    expect(wrapper.find('[data-testid=empty-hint]').exists()).toBe(false)
  })

  describe('the hero', () => {
    it('is the 112 px stamp of the chosen achievement on the centre axis, with its description and the day it was received', async () => {
      const { wrapper } = await render()

      const hero = wrapper.get('[data-testid=hero]')
      expect(hero.classes()).toEqual(expect.arrayContaining(['items-center', 'text-center']))
      const art = hero.get('[data-testid=achievement-art]')
      expect(art.attributes('src')).toContain('achievements/streak-7.webp')
      expect(art.attributes('width')).toBe('112')
      expect(wrapper.get('[data-testid=hero-title]').classes()).toContain('t-display-m')
      expect(text(wrapper, 'hero-description')).toBe('Описание')
      expect(text(wrapper, 'unlocked-on')).toContain('Получено')
    })

    it('shows a tapped badge in the hero and marks it as chosen', async () => {
      const { wrapper } = await render()

      expect(card(wrapper, 'streak_7').attributes('aria-pressed')).toBe('true')
      await card(wrapper, 'words_100').trigger('click')

      expect(text(wrapper, 'hero-title')).toBe('100 слов')
      expect(card(wrapper, 'words_100').attributes('aria-pressed')).toBe('true')
      expect(card(wrapper, 'words_100').find('[data-testid=item-chosen]').exists()).toBe(true)
      expect(card(wrapper, 'streak_7').attributes('aria-pressed')).toBe('false')
      expect(text(wrapper, 'progress')).toBe('40 из 100')
      expect(wrapper.get('[data-testid=bar] .progress-fill').attributes('style')).toContain('width: 40%')
      expect(wrapper.find('[data-testid=share-btn]').exists()).toBe(false)

      await card(wrapper, 'league_top1').trigger('click')
      expect(text(wrapper, 'locked')).toBe('Ещё не получено')
      // the grey picture of the kit for a locked one
      expect(wrapper.get('[data-testid=hero] [data-testid=achievement-art]').attributes('src')).toContain('league-locked.webp')
    })

    it('draws the even fallback stamp for an achievement the kit has no picture for, grey while it is ahead', async () => {
      const { wrapper } = await render(
        response({
          items: [
            item({ key: 'streak_30', title: '30 дней', unlocked_at: '2026-10-01T10:00:00+00:00' }),
            item({ key: 'first_friend', title: 'Первый друг' }),
          ],
          unlocked_count: 1,
          total: 2,
        }),
      )

      const hero = wrapper.get('[data-testid=hero] [data-testid=achievement-fallback]')
      expect(hero.attributes('style')).toContain('width: 112px')
      expect(hero.classes()).toEqual(expect.arrayContaining(['rounded-full', 'bg-accent-soft', 'border-muhr']))
      const ahead = card(wrapper, 'first_friend').get('[data-testid=achievement-fallback]')
      expect(ahead.attributes('data-locked')).toBe('true')
      expect(ahead.classes()).toEqual(expect.arrayContaining(['bg-sunken', 'text-muted']))
      expect(ahead.attributes('style')).toContain('width: 88px')
      expect(ahead.find('svg').exists()).toBe(true)
    })

    it('colours the fallback of one in progress, as the badge of the kit colours its picture then', async () => {
      const { wrapper } = await render(
        response({ items: [item({ key: 'words_500', title: '500 слов', progress: { current: 380, target: 500 } })], unlocked_count: 0, total: 1 }),
      )

      expect(card(wrapper, 'words_500').get('[data-testid=achievement-fallback]').attributes('data-locked')).toBe('false')
      expect(wrapper.get('[data-testid=hero] [data-testid=achievement-fallback]').attributes('data-locked')).toBe('false')
    })

    it('shows the first one ahead and encourages to study while nothing is received', async () => {
      const { wrapper } = await render(
        response({ unlocked_count: 0, items: response().items.map((i) => ({ ...i, unlocked_at: null, shared_at: null, share_text: null })) }),
      )

      expect(text(wrapper, 'empty-hint')).toBe('Занимайтесь каждый день — достижения появятся сами.')
      expect(text(wrapper, 'hero-title')).toBe('100 слов')
      expect(wrapper.find('[data-testid=group-received]').exists()).toBe(false)
    })

    it('greets with the mascot for an empty catalog, never dividing by zero', async () => {
      const { wrapper } = await render(response({ unlocked_count: 0, total: 0, items: [] }))

      expect(wrapper.get('[data-testid=hero] [data-testid=mascot-art]').attributes('data-pose')).toBe('hello')
      expect(wrapper.find('[data-testid=hero-title]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=list]').exists()).toBe(false)
    })

    it('has a plain title (no emoji) and no emoji on the badges: the pictures and icons are drawn', async () => {
      const { wrapper } = await render()

      expect(wrapper.get('h1').text()).toBe('Достижения')
      expect(wrapper.text()).not.toMatch(/[🏅🔒🏆]/u)
    })

    it('limits the description to three lines and the title to two', async () => {
      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=hero-description]').classes()).toContain('line-clamp-3')
      expect(wrapper.get('[data-testid=hero-title]').classes()).toContain('line-clamp-2')
    })

    it('shows the unlock date with no break inside it', async () => {
      const { wrapper } = await render()
      await card(wrapper, 'first_session').trigger('click')

      const date = text(wrapper, 'unlocked-on')
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
    const hero = (w: Wrapper) => w.get('[data-testid=hero]')

    it('has a secondary "Поделиться" in the hero of a received achievement, none for one ahead', async () => {
      const { wrapper } = await render()

      for (const key of ['first_session', 'streak_7']) {
        await card(wrapper, key).trigger('click')
        const button = hero(wrapper).get('[data-testid=share-btn]')
        expect(button.text()).toBe('Поделиться')
        expect(button.classes()).toEqual(expect.arrayContaining(['btn', 'btn-secondary']))
      }
      for (const key of ['words_100', 'league_top1']) {
        await card(wrapper, key).trigger('click')
        expect(wrapper.find('[data-testid=share-btn]').exists()).toBe(false)
      }
    })

    it('shares the achievement through the bot (the key as the subject, the link and the text as the fallback), then marks it', async () => {
      const refreshed = response({
        items: response().items.map((i) => (i.key === 'first_session' ? { ...i, shared_at: '2026-10-21T10:00:00+00:00' } : i)),
      })
      vi.mocked(api.achievements.shared).mockResolvedValue(refreshed)
      const { wrapper } = await render()
      await card(wrapper, 'first_session').trigger('click')

      expect(wrapper.find('[data-testid=shared]').exists()).toBe(false)
      await wrapper.get('[data-testid=share-btn]').trigger('click')
      await flushPromises()

      expect(state.share).toHaveBeenCalledWith(
        'achievement',
        'first_session',
        { link: 'https://t.me/LexiFlowBot?start=ref_ABCD2345', text: 'Я получил(а) «Первый шаг»' },
        expect.any(Function),
      )
      expect(state.haptic).toHaveBeenCalled()
      expect(api.achievements.shared).toHaveBeenCalledWith('first_session')
      expect(text(wrapper, 'shared')).toContain('Вы уже поделились')
    })

    it('marks a shared achievement with a check footnote', async () => {
      const { wrapper } = await render()

      const shared = hero(wrapper).get('[data-testid=shared]')
      expect(shared.text()).toBe('Вы уже поделились')
      expect(shared.find('svg').exists()).toBe(true)
    })

    it('stays quiet when marking as shared fails', async () => {
      vi.mocked(api.achievements.shared).mockRejectedValue(new ApiClientError('network', 0, 'network', 'x'))
      const { wrapper } = await render()

      await wrapper.get('[data-testid=share-btn]').trigger('click')
      await flushPromises()

      expect(state.share).toHaveBeenCalledTimes(1)
      expect(wrapper.find('[data-testid=error]').exists()).toBe(false)
    })

    it('puts no mark when the learner closed the chat picker', async () => {
      state.share.mockResolvedValue('cancelled')
      const { wrapper } = await render()
      await card(wrapper, 'first_session').trigger('click')

      await wrapper.get('[data-testid=share-btn]').trigger('click')
      await flushPromises()

      expect(api.achievements.shared).not.toHaveBeenCalled()
      expect(wrapper.find('[data-testid=shared]').exists()).toBe(false)
    })

    it('spins the button while the share is on its way and takes no second share meanwhile', async () => {
      let finish: (outcome: string) => void = () => undefined
      state.share.mockImplementation(() => {
        state.pending.value = true
        return new Promise((resolve) => {
          finish = (outcome) => {
            state.pending.value = false
            resolve(outcome)
          }
        })
      })
      const { wrapper } = await render()
      await card(wrapper, 'first_session').trigger('click')

      await wrapper.get('[data-testid=share-btn]').trigger('click')
      const busy = wrapper.get('[data-testid=share-btn]')
      expect(busy.attributes('disabled')).toBeDefined()
      expect(busy.attributes('aria-busy')).toBe('true')
      expect(busy.find('[data-testid=share-busy]').exists()).toBe(true)

      // another received one: its button waits too, without the spinner
      await card(wrapper, 'streak_7').trigger('click')
      const other = wrapper.get('[data-testid=share-btn]')
      expect(other.attributes('disabled')).toBeDefined()
      expect(other.find('[data-testid=share-busy]').exists()).toBe(false)
      await other.trigger('click')
      expect(state.share).toHaveBeenCalledTimes(1)

      finish('cancelled')
      await flushPromises()
      expect(wrapper.get('[data-testid=share-btn]').attributes('disabled')).toBeUndefined()
      expect(wrapper.find('[data-testid=share-busy]').exists()).toBe(false)
    })

    it('hides the share button while there is no invite link', async () => {
      const { wrapper } = await render(response({ share_link: null }))

      expect(wrapper.find('[data-testid=share-btn]').exists()).toBe(false)
      expect(text(wrapper, 'hero-title')).toBe('Серия 7 дней')
    })

    it('does not share an achievement that has no text yet', async () => {
      const { wrapper } = await render(
        response({ items: [item({ key: 'odd', title: 'Odd', unlocked_at: '2026-10-01T10:00:00+00:00', share_text: null })], unlocked_count: 1, total: 1 }),
      )

      await wrapper.get('[data-testid=share-btn]').trigger('click')

      expect(state.share).not.toHaveBeenCalled()
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

      const bar = wrapper.get('[data-testid=bar]')
      expect(bar.get('.progress-fill').attributes('style')).toContain(`width: ${width}%`)
      expect(bar.attributes('aria-valuenow')).toBe(String(width))
      expect(bar.attributes('aria-label')).toBe('Count')
    })

    it('shows an empty bar for a target of zero', async () => {
      const { wrapper } = await render(
        response({ items: [item({ key: 'zero', title: 'Zero', progress: { current: 0, target: 0 } })], unlocked_count: 0, total: 1 }),
      )

      expect(wrapper.get('[data-testid=bar] .progress-fill').attributes('style')).toContain('width: 0%')
      expect(card(wrapper, 'zero').get('[data-testid=achievement-badge]').attributes('data-state')).toBe('locked')
    })
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const { wrapper } = await render()

    expect(wrapper.get('h1').text()).toBe('Yutuqlar')
    expect(text(wrapper, 'summary')).toBe('4 tadan 2 tasi olindi')
    expect(wrapper.get('[data-testid=group-received] h2').text()).toBe('Olinganlar')
    expect(wrapper.get('[data-testid=group-ahead] h2').text()).toBe('Oldinda')
    expect(text(wrapper, 'share-btn')).toBe('Ulashish')
    expect(text(wrapper, 'unlocked-on')).toMatch(/kuni olindi$/)
    await card(wrapper, 'words_100').trigger('click')
    expect(text(wrapper, 'progress')).toBe('100 tadan 40 tasi')
    await card(wrapper, 'league_top1').trigger('click')
    expect(text(wrapper, 'locked')).toBe('Hali olinmagan')
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
      expect(wrapper.findAll('[data-testid=spinner] .skeleton.rounded-full').length).toBeGreaterThan(1)
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
