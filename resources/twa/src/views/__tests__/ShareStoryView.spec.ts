import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import ShareStoryView from '../ShareStoryView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useAchievementsStore } from '@/stores/achievements'
import { useFriendsStore } from '@/stores/friends'
import { useTodayStore } from '@/stores/today'
import type { StoryShare } from '@/api/shareTypes'
import type { AchievementsResponse, FriendsResponse, TodayResponse } from '@/api/types'

/*
 * F3 of the kit (03-new-features/F3-share-stories.png, docs/40 §2.4): the preview drawn from what the app holds (no request on open),
 * "Поделиться в сторис" asks the server for the picture once and opens the story editor, "Позвать друга на баттл" is the invitation of a
 * friend. The API is counted in every scenario.
 */

const tg = vi.hoisted(() => ({
  canStory: true,
  canMessage: false,
  shareToStory: vi.fn(),
  shareMessage: vi.fn(),
  shareUrl: vi.fn(),
  haptic: vi.fn(),
  back: undefined as undefined | (() => void),
}))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    webApp: null,
    haptic: tg.haptic,
    canShareToStory: () => tg.canStory,
    shareToStory: tg.shareToStory,
    canShareMessage: () => tg.canMessage,
    shareMessage: tg.shareMessage,
    shareUrl: tg.shareUrl,
    showBackButton: (handler: () => void) => {
      tg.back = handler
      return () => undefined
    },
  }),
}))

vi.mock('@/api', () => ({
  api: {
    share: { story: vi.fn(), prepare: vi.fn(), event: vi.fn() },
    referrals: { get: vi.fn() },
    friends: { get: vi.fn() },
    achievements: { shared: vi.fn(), list: vi.fn() },
    today: { get: vi.fn() },
  },
}))

const LINK = 'https://t.me/MuhrlaBot?start=ref_ABCD2345'
const FRIEND_LINK = 'https://t.me/MuhrlaBot?start=f_QWERTY12'

const STORY: StoryShare = {
  media_url: 'https://muhrla.test/storage/share-cards/story_streak_12-ru-0123456789abcdef0123.jpg',
  text: '12 дней подряд в Muhrla · t.me/MuhrlaBot?start=ref_ABCD2345',
  widget_link: { url: LINK, name: 'Muhrla' },
}

function todayData(current = 12): TodayResponse {
  return {
    due_count: 0,
    new_words_remaining: null,
    goal: { target: 20, done: 0, met: false },
    streak: { current, best: current, met_today: false, at_risk: true, freezes: 1, frozen_yesterday: true },
    active_course: null,
    open_session_id: null,
    entitlement: { plan: 'pro', source: 'subscription', until: null, lapsed: null },
    onboarding_completed: true,
    league: null,
    timezone: 'Asia/Tashkent',
  }
}

const achievementsData = (): AchievementsResponse => ({
  unlocked_count: 1,
  total: 2,
  share_link: LINK,
  items: [
    {
      key: 'first_session',
      title: 'Первый шаг',
      description: 'Пройдите первый урок',
      unlocked_at: '2026-10-01T10:00:00+00:00',
      shared_at: null,
      progress: null,
      share_text: 'Я получил(а) «Первый шаг»',
    },
    { key: 'words_100', title: '100 слов', description: 'Выучите 100 слов', unlocked_at: null, shared_at: null, progress: null, share_text: null },
  ],
})

const friendsData = (): FriendsResponse => ({ invite_link: FRIEND_LINK, limit: 50, friends: [], incoming: [], outgoing: [] })

/** Every call of the API the screen made, by name. */
function apiCalls(): Record<string, number> {
  const calls: Record<string, number> = {
    story: vi.mocked(api.share.story).mock.calls.length,
    prepare: vi.mocked(api.share.prepare).mock.calls.length,
    referrals: vi.mocked(api.referrals.get).mock.calls.length,
    friends: vi.mocked(api.friends.get).mock.calls.length,
    shared: vi.mocked(api.achievements.shared).mock.calls.length,
    achievements: vi.mocked(api.achievements.list).mock.calls.length,
    today: vi.mocked(api.today.get).mock.calls.length,
  }
  return Object.fromEntries(Object.entries(calls).filter(([, count]) => count > 0))
}

async function render(kind: 'streak' | 'achievement', subject: string | null = null, from: string | null = '/streak/saved') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: { render: () => h('section', { 'data-testid': 'today' }) } },
      { path: '/streak/saved', name: 'streak-saved', component: { render: () => h('section') } },
      { path: '/achievements', name: 'achievements', component: { render: () => h('section') } },
      { path: '/share/story', name: 'share-story', component: ShareStoryView, props: () => ({ kind, subject }) },
    ],
  })
  if (from !== null) await router.push(from)
  await router.push({ name: 'share-story', query: subject === null ? { kind } : { kind, subject } })
  await router.isReady()
  const wrapper = mount(ShareStoryView, { props: { kind, subject }, global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

type Wrapper = Awaited<ReturnType<typeof render>>['wrapper']
const text = (w: Wrapper, id: string): string => w.get(`[data-testid=${id}]`).text()

describe('ShareStoryView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    tg.canStory = true
    tg.canMessage = false
    tg.back = undefined
    tg.shareToStory.mockReturnValue('opened')
    tg.shareMessage.mockResolvedValue(true)
    vi.mocked(api.share.story).mockResolvedValue(STORY)
    vi.mocked(api.share.event).mockResolvedValue(undefined)
    vi.mocked(api.share.prepare).mockResolvedValue({ id: 'prep-1', expires_at: '2026-11-28T12:00:00+00:00' })
    vi.mocked(api.friends.get).mockResolvedValue(friendsData())
    vi.mocked(api.achievements.shared).mockResolvedValue(achievementsData())
    useTodayStore().data = todayData()
  })
  afterEach(() => setLocale('ru'))

  describe('the streak', () => {
    it('draws the card of the kit from "Today" and asks the server nothing on open', async () => {
      const { wrapper: w } = await render('streak')

      expect(text(w, 'story-title')).toBe('12 дней подряд')
      expect(text(w, 'story-text')).toBe('Учу английский в Muhrla — каждый день по 20 карточек.')
      expect(text(w, 'story-brand')).toBe('Muhrla')
      expect(w.get('[data-testid=story-card]').attributes('aria-label')).toBe('Так будет выглядеть сторис: 12 дней подряд')
      expect(w.find('[data-testid=achievement-art]').exists()).toBe(true)
      expect(text(w, 'story-share')).toBe('Поделиться в сторис')
      expect(w.get('[data-testid=story-share]').classes()).toContain('btn-primary')
      expect(text(w, 'story-battle')).toBe('Позвать друга на баттл')
      expect(w.get('[data-testid=story-battle]').classes()).toContain('btn-secondary')
      expect(apiCalls()).toEqual({})
    })

    it('promises days of Pro for friends, never league points (docs/40 §3, В4)', async () => {
      const { wrapper: w } = await render('streak')

      expect(text(w, 'story-hint')).toContain('дни Pro')
      expect(w.text()).not.toMatch(/балл|\+100/)
    })

    it('opens the story editor with the picture of the server: one request, reused by the next tap; nothing says "posted"', async () => {
      const { wrapper: w } = await render('streak')

      await w.get('[data-testid=story-share]').trigger('click')
      await flushPromises()
      expect(tg.haptic).toHaveBeenCalledWith('light')
      expect(api.share.story).toHaveBeenCalledWith('streak', null)
      expect(tg.shareToStory).toHaveBeenCalledWith(STORY.media_url, { text: STORY.text, widget_link: STORY.widget_link })
      expect(api.share.event).toHaveBeenCalledWith({ event: 'story_sent', kind: 'streak' })
      expect(w.find('[data-testid=story-error]').exists()).toBe(false)
      expect(w.text()).not.toMatch(/опубликован/i)

      await w.get('[data-testid=story-share]').trigger('click')
      await flushPromises()
      expect(tg.shareToStory).toHaveBeenCalledTimes(2)
      expect(apiCalls()).toEqual({ story: 1 })
    })

    it('takes no second tap while the picture is on its way: the button is busy', async () => {
      let release!: (story: StoryShare) => void
      vi.mocked(api.share.story).mockReturnValueOnce(new Promise((resolve) => (release = resolve)))
      const { wrapper: w } = await render('streak')

      await w.get('[data-testid=story-share]').trigger('click')
      const button = w.get('[data-testid=story-share]')
      expect(button.attributes('aria-busy')).toBe('true')
      expect(button.attributes('aria-label')).toBe('Готовим картинку…')
      await button.trigger('click')
      await w.get('[data-testid=story-battle]').trigger('click')
      release(STORY)
      await flushPromises()

      expect(apiCalls()).toEqual({ story: 1 })
      expect(tg.shareToStory).toHaveBeenCalledTimes(1)
      expect(w.get('[data-testid=story-share]').attributes('aria-busy')).toBeUndefined()
    })

    it.each([
      ['rate_limited', 429, 'Слишком много запросов. Подождите немного и повторите.'],
      ['network', 0, 'Нет связи с сервером. Попробуйте ещё раз.'],
      ['server', 500, 'Сервер временно недоступен. Попробуйте позже.'],
    ] as const)('%s: says why and "Повторить" asks again', async (kind, status, message) => {
      vi.mocked(api.share.story).mockRejectedValueOnce(new ApiClientError(kind, status, kind, kind))
      const { wrapper: w } = await render('streak')

      await w.get('[data-testid=story-share]').trigger('click')
      await flushPromises()
      expect(text(w, 'story-error')).toContain(message)
      expect(tg.shareToStory).not.toHaveBeenCalled()

      await w.get('[data-testid=story-retry]').trigger('click')
      await flushPromises()
      expect(w.find('[data-testid=story-error]').exists()).toBe(false)
      expect(tg.shareToStory).toHaveBeenCalledTimes(1)
      expect(apiCalls()).toEqual({ story: 2 })
    })

    it('403 share_unavailable: the plain share with the invite link (asked once), and no picture is asked again', async () => {
      vi.mocked(api.share.story).mockRejectedValue(new ApiClientError('forbidden', 403, 'share_unavailable', 'no'))
      vi.mocked(api.referrals.get).mockResolvedValue({ link: LINK } as never)
      const { wrapper: w } = await render('streak')

      await w.get('[data-testid=story-share]').trigger('click')
      await flushPromises()
      expect(api.share.event).toHaveBeenCalledWith({ event: 'story_fallback', kind: 'streak', reason: 'unavailable' })
      expect(tg.shareUrl).toHaveBeenCalledWith(LINK, '12 дней подряд учу английский в Muhrla!')

      await w.get('[data-testid=story-share]').trigger('click')
      await flushPromises()
      expect(tg.shareUrl).toHaveBeenCalledTimes(2)
      expect(apiCalls()).toEqual({ story: 1, referrals: 1 })
    })

    it('below 7.8 the button reads «Поделиться» and shares through the bot without asking for a picture', async () => {
      tg.canStory = false
      tg.canMessage = true
      vi.mocked(api.referrals.get).mockResolvedValue({ link: LINK } as never)
      const { wrapper: w } = await render('streak')

      expect(text(w, 'story-share')).toBe('Поделиться')
      await w.get('[data-testid=story-share]').trigger('click')
      await flushPromises()

      expect(api.share.story).not.toHaveBeenCalled()
      expect(tg.shareToStory).not.toHaveBeenCalled()
      expect(api.share.prepare).toHaveBeenCalledWith('invite', null)
      expect(tg.shareMessage).toHaveBeenCalledWith('prep-1')
      expect(api.share.event).toHaveBeenCalledWith({ event: 'story_fallback', kind: 'streak', reason: 'old_client' })
    })

    it('an SDK refusal of the picture shares the link of the answer, asking nothing more', async () => {
      tg.shareToStory.mockReturnValue('failed')
      const { wrapper: w } = await render('streak')

      await w.get('[data-testid=story-share]').trigger('click')
      await flushPromises()

      expect(tg.shareUrl).toHaveBeenCalledWith(LINK, '12 дней подряд учу английский в Muhrla!')
      expect(apiCalls()).toEqual({ story: 1 })
    })

    it('leaves for "Today" when there is no streak to show (a reload on this address)', async () => {
      useTodayStore().data = null
      const { router } = await render('streak', null, null)

      expect(router.currentRoute.value.name).toBe('today')
      expect(apiCalls()).toEqual({})
    })

    it('Telegram\'s Back without a screen before it in the app leads to where the card lives ("Today")', async () => {
      const { router } = await render('streak')

      tg.back?.()
      await flushPromises()
      expect(router.currentRoute.value.name).toBe('today')
    })
  })

  describe('an achievement', () => {
    beforeEach(() => {
      useAchievementsStore().data = achievementsData()
    })

    it('draws the achievement and the bot of its link; asks nothing on open', async () => {
      const { wrapper: w } = await render('achievement', 'first_session', '/achievements')

      expect(text(w, 'story-title')).toBe('Первый шаг')
      expect(text(w, 'story-text')).toBe('Пройдите первый урок')
      expect(text(w, 'story-brand')).toMatch(/^Muhrla\s*· @MuhrlaBot$/)
      expect(apiCalls()).toEqual({})

      await w.get('[data-testid=story-share]').trigger('click')
      await flushPromises()
      expect(api.share.story).toHaveBeenCalledWith('achievement', 'first_session')
    })

    it('404: shares the achievement as before (its text and link) and marks it shared', async () => {
      vi.mocked(api.share.story).mockRejectedValue(new ApiClientError('not_found', 404, 'share_subject_not_found', 'no'))
      const { wrapper: w } = await render('achievement', 'first_session', '/achievements')

      await w.get('[data-testid=story-share]').trigger('click')
      await flushPromises()

      expect(tg.shareUrl).toHaveBeenCalledWith(LINK, 'Я получил(а) «Первый шаг»')
      expect(api.achievements.shared).toHaveBeenCalledWith('first_session')
      expect(apiCalls()).toEqual({ story: 1, shared: 1 })
    })

    it('leaves for Achievements when the achievement is not received', async () => {
      const { router } = await render('achievement', 'words_100', null)
      expect(router.currentRoute.value.name).toBe('achievements')
      expect(apiCalls()).toEqual({})
    })
  })

  describe('«Позвать друга на баттл»', () => {
    it('is the invitation of a friend with the f_ link the friends store holds: no request of its own', async () => {
      tg.canMessage = true
      useFriendsStore().friends = friendsData()
      const { wrapper: w } = await render('streak')

      await w.get('[data-testid=story-battle]').trigger('click')
      await flushPromises()

      expect(api.share.prepare).toHaveBeenCalledWith('friend_invite', null)
      expect(tg.shareMessage).toHaveBeenCalledWith('prep-1')
      expect(apiCalls()).toEqual({ prepare: 1 })
    })

    it('asks GET /friends for the link only on the tap, once for the screen', async () => {
      const { wrapper: w } = await render('streak')

      await w.get('[data-testid=story-battle]').trigger('click')
      await flushPromises()
      await w.get('[data-testid=story-battle]').trigger('click')
      await flushPromises()

      expect(tg.shareUrl).toHaveBeenCalledWith(FRIEND_LINK, 'Давай дружить в Muhrla: сравним серии и устроим вызов!')
      expect(tg.shareUrl).toHaveBeenCalledTimes(2)
      expect(apiCalls()).toEqual({ friends: 1 })
    })

    it('a failed read of the link says why and "Повторить" tries the invitation again', async () => {
      vi.mocked(api.friends.get).mockRejectedValueOnce(new ApiClientError('network', 0, 'network', 'offline'))
      const { wrapper: w } = await render('streak')

      await w.get('[data-testid=story-battle]').trigger('click')
      await flushPromises()
      expect(text(w, 'story-error')).toContain('Нет связи с сервером')

      await w.get('[data-testid=story-retry]').trigger('click')
      await flushPromises()
      expect(tg.shareUrl).toHaveBeenCalledWith(FRIEND_LINK, expect.any(String))
      expect(apiCalls()).toEqual({ friends: 2 })
    })
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const { wrapper: w } = await render('streak')

    expect(text(w, 'story-title')).toBe('12 kun ketma-ket')
    expect(text(w, 'story-share')).toBe('Storisga ulashish')
    expect(text(w, 'story-battle')).toBe("Do'stni bellashuvga chaqirish")
  })

  it('uses only the tokens of the design system: no hex colours in the screen', () => {
    const source = readFileSync(resolve(__dirname, '../ShareStoryView.vue'), 'utf8')
    expect(source).not.toMatch(/#[0-9a-fA-F]{3,8}\b/)
  })
})
