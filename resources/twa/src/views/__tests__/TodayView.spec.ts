import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import TodayView from '../TodayView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useTrainingStore } from '@/stores/training'
import { settingsFixture } from '@/api/__tests__/settingsFixture'
import type { AssignmentItem, MeResponse, TodayResponse } from '@/api/types'

const state = vi.hoisted(() => ({ ensure: vi.fn() }))

vi.mock('@/api', () => ({ api: { today: { get: vi.fn() }, assignments: { list: vi.fn() }, training: { open: vi.fn() }, me: { get: vi.fn() } } }))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ ensure: state.ensure }),
}))

const today = (overrides: Partial<TodayResponse> = {}): TodayResponse => ({
  due_count: 23,
  new_words_remaining: null,
  goal: { target: 20, done: 8, met: false },
  streak: { current: 5, best: 12, met_today: false, at_risk: true, freezes: 0, frozen_yesterday: false },
  active_course: {
    id: 3,
    slug: 'english-a1',
    title: 'English A1',
    progress: { learned: 120, total: 900 },
    next_lesson: { id: 41, stage: 2, lesson: 4, title: 'Food' },
  },
  open_session_id: null,
  entitlement: { plan: 'free', source: 'free', until: null },
  onboarding_completed: true,
  league: null,
  ...overrides,
})

const assignment = (overrides: Partial<AssignmentItem> = {}): AssignmentItem => ({
  id: 7,
  title: 'Food',
  group: 'Group A',
  lesson_id: 41,
  stage: 2,
  lesson: 4,
  due_at: '2026-10-25T13:00:00+00:00',
  overdue: false,
  progress_pct: 40,
  completed: false,
  completed_at: null,
  late: false,
  ...overrides,
})

const makeRouter = () =>
  createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: { template: '<div />' } },
      { path: '/courses', name: 'courses', component: { template: '<div />' } },
      { path: '/decks', name: 'decks', component: { template: '<div />' } },
      { path: '/settings', name: 'settings', component: { template: '<div />' } },
      { path: '/billing', name: 'billing', component: { template: '<div />' } },
      { path: '/referrals', name: 'referrals', component: { template: '<div />' } },
      { path: '/league', name: 'league', component: { template: '<div />' } },
      { path: '/training/:sessionId(\\d+)', name: 'training', component: { template: '<div />' } },
    ],
  })

async function render(response: TodayResponse = today()) {
  vi.mocked(api.today.get).mockResolvedValue(response)
  const router = makeRouter()
  const wrapper = mount(TodayView, { global: { plugins: [router] } })
  await flushPromises()
  return Object.assign(wrapper, { router })
}

const text = (w: Awaited<ReturnType<typeof render>>, id: string): string => w.get(`[data-testid=${id}]`).text()

describe('TodayView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    state.ensure.mockResolvedValue(undefined)
    vi.mocked(api.assignments.list).mockResolvedValue({ assignments: [] })
    // The "Pro ended" banner asks /me for a Free learner; by default nothing lapsed.
    vi.mocked(api.me.get).mockResolvedValue({
      entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 20, renewal: null, lapsed: null },
      paywall: { mode: 'coming_soon', contact: null },
    } as never)
  })
  afterEach(() => setLocale('ru'))

  it('shows the league line: the place of the week, or an invitation to study, linking to the league screen', async () => {
    const placed = await render(today({ league: { tier: 1, tier_key: 'silver', rank: 3, points: 41, members_count: 28 } }))
    expect(text(placed, 'league-line')).toBe('🏆 Серебро · 3-е место из 28 · 41 очко')
    expect(placed.get('[data-testid=league-line]').attributes('href')).toBe('/league')

    const bare = await render(today({ league: null }))
    expect(text(bare, 'league-line')).toBe('🏆 Лига: повторите карточки, чтобы вступить на этой неделе')
  })

  it('shows the goal as a ring with done / target and what is left', async () => {
    const w = await render()

    expect(text(w, 'goal-text')).toBe('8 из 20')
    expect(text(w, 'goal-left')).toBe('Осталось 12 карточек')
    expect(text(w, 'goal-done')).toBe('8')
    // 8/20 of the circumference is drawn: the dash offset is 60% of it.
    const arc = w.get('[data-testid=goal-ring-arc]')
    const circumference = Number(arc.attributes('stroke-dasharray'))
    expect(Number(arc.attributes('stroke-dashoffset'))).toBeCloseTo(circumference * 0.6, 3)
    expect(w.find('[data-testid=goal-met]').exists()).toBe(false)
  })

  it('shows a met goal with a full ring even past the target', async () => {
    const w = await render(today({ goal: { target: 20, done: 25, met: true } }))

    expect(text(w, 'goal-met')).toBe('Цель выполнена')
    expect(w.find('[data-testid=goal-left]').exists()).toBe(false)
    expect(Number(w.get('[data-testid=goal-ring-arc]').attributes('stroke-dashoffset'))).toBeCloseTo(0, 3)
  })

  it('shows the streak, the warning when it is at risk and the record', async () => {
    const w = await render()

    expect(text(w, 'streak-current')).toBe('5 дней')
    expect(text(w, 'streak-at-risk')).toBe('Серия под угрозой: выполните цель до полуночи')
    expect(text(w, 'streak-best')).toBe('Рекорд: 12 дней')
  })

  it('shows a safe streak after the goal, and an invitation when there is none', async () => {
    const safe = await render(today({ streak: { current: 6, best: 6, met_today: true, at_risk: false, freezes: 0, frozen_yesterday: false } }))
    expect(text(safe, 'streak-safe')).toBe('На сегодня серия продлена')
    expect(safe.find('[data-testid=streak-at-risk]').exists()).toBe(false)
    expect(safe.find('[data-testid=streak-best]').exists()).toBe(false)

    const none = await render(today({ streak: { current: 0, best: 3, met_today: false, at_risk: false, freezes: 0, frozen_yesterday: false } }))
    expect(text(none, 'streak-none')).toBe('Выполните цель дня, чтобы начать серию')
    expect(none.find('[data-testid=streak-at-risk]').exists()).toBe(false)
  })

  it('shows a Pro learner the streak freezes in stock and that yesterday was frozen', async () => {
    const pro = { plan: 'pro', source: 'subscription', until: null } as const
    const two = await render(today({ entitlement: pro, streak: { current: 5, best: 12, met_today: false, at_risk: true, freezes: 2, frozen_yesterday: false } }))
    expect(text(two, 'streak-freezes')).toBe('❄️ 2 заморозки')
    expect(two.find('[data-testid=streak-frozen]').exists()).toBe(false)
    expect(two.find('[data-testid=streak-freeze-teaser]').exists()).toBe(false)

    const frozen = await render(today({ entitlement: pro, streak: { current: 5, best: 12, met_today: false, at_risk: true, freezes: 1, frozen_yesterday: true } }))
    expect(text(frozen, 'streak-freezes')).toBe('❄️ 1 заморозка')
    expect(text(frozen, 'streak-frozen')).toBe('Вчерашний день заморожен — серия сохранена')
    expect(text(frozen, 'streak-at-risk')).toBe('Серия под угрозой: выполните цель до полуночи')

    const none = await render(today({ entitlement: pro, streak: { current: 5, best: 12, met_today: true, at_risk: false, freezes: 0, frozen_yesterday: false } }))
    expect(text(none, 'streak-freezes')).toBe('❄️ Заморозок нет — новая в понедельник')
  })

  it('offers a Free learner the streak-freeze teaser that opens the paywall with its own lead', async () => {
    const w = await render()
    expect(w.find('[data-testid=streak-freezes]').exists()).toBe(false)
    expect(text(w, 'streak-freeze-teaser')).toBe('❄️ Заморозки серии — в Pro')

    await w.get('[data-testid=streak-freeze-teaser]').trigger('click')
    await flushPromises()
    expect(w.get('[data-testid=paywall-lead]').text()).toContain('Заморозка серии — в Pro')

    // The review card still opens the sheet with its own reason.
    await w.get('[data-testid=paywall-close]').trigger('click')
    await w.get('[data-testid=paywall-open]').trigger('click')
    await flushPromises()
    expect(w.get('[data-testid=paywall-lead]').text()).not.toContain('Заморозка серии')
  })

  it('shows the due count with the right Russian form and an empty state', async () => {
    expect(text(await render(today({ due_count: 1 })), 'due-count')).toBe('1 слово к повторению')
    expect(text(await render(today({ due_count: 3 })), 'due-count')).toBe('3 слова к повторению')
    expect(text(await render(today({ due_count: 0 })), 'due-count')).toBe('Повторять пока нечего')
  })

  it('shows the new-word quota only when it is limited', async () => {
    expect((await render()).find('[data-testid=new-remaining]').exists()).toBe(false)
    expect(text(await render(today({ new_words_remaining: 12 })), 'new-remaining')).toBe('Новых слов на сегодня осталось: 12')
    expect(text(await render(today({ new_words_remaining: 0 })), 'new-remaining')).toBe('Лимит новых слов на сегодня исчерпан')
  })

  it('takes the plan only from the entitlement: a Free learner without a quota number is still Free', async () => {
    const w = await render(today({ new_words_remaining: null, entitlement: { plan: 'free', source: 'free', until: null } }))

    expect(text(w, 'plan-badge')).toBe('Free')
    expect(w.find('[data-testid=paywall-open]').exists()).toBe(true)
  })

  describe('"Pro ended" banner (2A.6)', () => {
    const lapsedMe = (lapsed: boolean): MeResponse => ({
      learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
      groups: [],
      stats: { words_learned: 0, words_due_today: 0 },
      entitlement: {
        plan: 'free', source: 'free', until: null, new_words_per_day: 20, renewal: null,
        lapsed: lapsed ? { provider: 'payme', ended_at: '2026-10-18T09:30:00+00:00' } : null,
      },
      paywall: { mode: 'purchase', contact: null },
      settings: settingsFixture(),
    })

    beforeEach(() => window.localStorage.clear())

    it('offers renewal to a Free learner whose Pro lapsed and leads to the subscription screen', async () => {
      vi.mocked(api.me.get).mockResolvedValue(lapsedMe(true))
      const w = await render()

      expect(text(w, 'lapsed-title')).toBe('Pro закончился')

      await w.router.push('/today')
      await w.get('[data-testid=lapsed-renew]').trigger('click')
      await flushPromises()
      expect(w.router.currentRoute.value.name).toBe('billing')
    })

    it('shows nothing when nothing lapsed, and does not even ask /me of a Pro learner', async () => {
      vi.mocked(api.me.get).mockResolvedValue(lapsedMe(false))
      expect((await render()).find('[data-testid=lapsed-banner]').exists()).toBe(false)

      vi.mocked(api.me.get).mockClear()
      setActivePinia(createPinia())
      await render(today({ entitlement: { plan: 'pro', source: 'subscription', until: null } }))
      expect(api.me.get).not.toHaveBeenCalled()
    })
  })

  it('opens the daily review and goes to the training screen', async () => {
    vi.mocked(api.training.open).mockResolvedValue({ session_id: 918, mode: 'daily_review', lesson: null, total_words: 20 })
    const w = await render()

    expect(text(w, 'review-btn')).toBe('Повторить')
    await w.get('[data-testid=review-btn]').trigger('click')
    await flushPromises()

    expect(api.training.open).toHaveBeenCalledWith({ mode: 'daily_review', source: 'self' })
    expect(w.router.currentRoute.value.name).toBe('training')
    expect(w.router.currentRoute.value.params.sessionId).toBe('918')
  })

  describe('opened from a reminder (?from=reminder)', () => {
    async function renderAt(path: string, response: TodayResponse = today()) {
      vi.mocked(api.today.get).mockResolvedValue(response)
      const router = makeRouter()
      await router.push(path)
      await router.isReady()
      const wrapper = mount(TodayView, { global: { plugins: [router] } })
      await flushPromises()
      return Object.assign(wrapper, { router })
    }

    it('opens the daily review as a reminder session right away and drops the marker from the URL', async () => {
      vi.mocked(api.training.open).mockResolvedValue({ session_id: 918, mode: 'daily_review', lesson: null, total_words: 20 })

      const w = await renderAt('/today?from=reminder')

      expect(api.today.get).toHaveBeenCalledTimes(1)
      expect(api.training.open).toHaveBeenCalledTimes(1)
      expect(api.training.open).toHaveBeenCalledWith({ mode: 'daily_review', source: 'reminder' })
      expect(w.router.currentRoute.value.name).toBe('training')
      expect(w.router.currentRoute.value.params.sessionId).toBe('918')
      // The `today` entry behind us no longer carries the marker: going back does not reopen a session.
      w.router.back()
      await flushPromises()
      expect(w.router.currentRoute.value.query.from).toBeUndefined()
    })

    it('stays on the screen with the error when the session cannot be opened', async () => {
      vi.mocked(api.training.open).mockRejectedValue(new ApiClientError('rate_limited', 429, 'unknown', 'Too Many Attempts.'))

      const w = await renderAt('/today?from=reminder')

      expect(w.router.currentRoute.value.name).toBe('today')
      expect(w.router.currentRoute.value.query.from).toBeUndefined()
      expect(text(w, 'open-error')).toBe('Слишком много запросов. Подождите немного и повторите.')
      expect(text(w, 'goal-text')).toBe('8 из 20')
    })

    it('does not open anything when the Today data failed to load, nor for other sources', async () => {
      vi.mocked(api.today.get).mockRejectedValueOnce(new ApiClientError('server', 500, 'server_error', 'boom'))
      const failed = await renderAt('/today?from=reminder')
      expect(api.training.open).not.toHaveBeenCalled()
      expect(failed.find('[data-testid=error]').exists()).toBe(true)

      const other = await renderAt('/today?from=today')
      expect(api.training.open).not.toHaveBeenCalled()
      expect(other.router.currentRoute.value.name).toBe('today')
      expect(other.router.currentRoute.value.query.from).toBe('today')
    })
  })

  describe('assignments of the center', () => {
    it('shows no block without assignments', async () => {
      const w = await render()

      expect(w.find('[data-testid=assignments-card]').exists()).toBe(false)
    })

    it('lists the assignments with the learner\'s own progress and starts the lesson as an assignment session', async () => {
      vi.mocked(api.assignments.list).mockResolvedValue({
        assignments: [assignment(), assignment({ id: 8, title: 'Done', lesson_id: 42, progress_pct: 100, completed: true, late: true })],
      })
      vi.mocked(api.training.open).mockResolvedValue({ session_id: 55, mode: 'lesson', lesson: null, total_words: 10 })

      const w = await render()

      expect(w.findAll('[data-testid=assignment]')).toHaveLength(2)
      expect(w.findAll('[data-testid=assignment-title]')[0].text()).toBe('Food')
      expect(w.findAll('[data-testid=assignment-progress]')[0].text()).toBe('Пройдено 40% слов урока')
      expect(w.findAll('[data-testid=assignment-done]')[0].text()).toBe('Выполнено с опозданием')
      // A completed assignment has no button.
      expect(w.findAll('[data-testid=assignment-btn]')).toHaveLength(1)

      await w.get('[data-testid=assignment-btn]').trigger('click')
      await flushPromises()

      expect(api.training.open).toHaveBeenCalledWith({ mode: 'lesson', lesson_id: 41, source: 'assignment' })
      expect(w.router.currentRoute.value.name).toBe('training')
    })

    it('marks an overdue open assignment', async () => {
      vi.mocked(api.assignments.list).mockResolvedValue({ assignments: [assignment({ overdue: true })] })

      const w = await render()

      expect(text(w, 'assignment-due')).toContain('Срок прошёл')
    })

    it('keeps Today working when the assignments cannot be loaded', async () => {
      vi.mocked(api.assignments.list).mockRejectedValue(new ApiClientError('server', 500, 'server_error', 'boom'))

      const w = await render()

      expect(w.find('[data-testid=assignments-card]').exists()).toBe(false)
      expect(text(w, 'goal-text')).toBe('8 из 20')
    })

    it('opens the lesson right away from the group announcement (?from=assignment&lesson=41) and drops the marker', async () => {
      vi.mocked(api.today.get).mockResolvedValue(today())
      vi.mocked(api.training.open).mockResolvedValue({ session_id: 56, mode: 'lesson', lesson: null, total_words: 10 })
      const router = makeRouter()
      await router.push('/today?from=assignment&lesson=41')
      await router.isReady()
      mount(TodayView, { global: { plugins: [router] } })
      await flushPromises()

      expect(api.training.open).toHaveBeenCalledWith({ mode: 'lesson', lesson_id: 41, source: 'assignment' })
      expect(router.currentRoute.value.name).toBe('training')
      router.back()
      await flushPromises()
      expect(router.currentRoute.value.query.from).toBeUndefined()
    })

    it('ignores a broken lesson id in the announcement link', async () => {
      vi.mocked(api.today.get).mockResolvedValue(today())
      const router = makeRouter()
      await router.push('/today?from=assignment&lesson=abc')
      await router.isReady()
      mount(TodayView, { global: { plugins: [router] } })
      await flushPromises()

      expect(api.training.open).not.toHaveBeenCalled()
      expect(router.currentRoute.value.name).toBe('today')
    })
  })

  it('offers to continue an open daily review', async () => {
    const w = await render(today({ open_session_id: 918 }))

    expect(text(w, 'review-btn')).toBe('Продолжить')
  })

  it('stays on the screen and explains a failed open', async () => {
    vi.mocked(api.training.open).mockRejectedValue(new ApiClientError('rate_limited', 429, 'unknown', 'Too Many Attempts.'))
    const w = await render()

    await w.get('[data-testid=review-btn]').trigger('click')
    await flushPromises()

    expect(w.router.currentRoute.value.name).not.toBe('training')
    expect(text(w, 'open-error')).toBe('Слишком много запросов. Подождите немного и повторите.')
  })

  it('forgets the open error when the learner leaves the screen', async () => {
    vi.mocked(api.training.open).mockRejectedValue(new ApiClientError('rate_limited', 429, 'unknown', 'Too Many Attempts.'))
    const w = await render()
    await w.get('[data-testid=review-btn]').trigger('click')
    await flushPromises()
    expect(useTrainingStore().openError).not.toBe('')

    w.unmount()

    expect(useTrainingStore().openError).toBe('')
  })

  it('shows the active course with its progress and opens the next lesson as a lesson session', async () => {
    vi.mocked(api.training.open).mockResolvedValue({
      session_id: 55,
      mode: 'lesson',
      lesson: { stage: 2, lesson: 4, title: 'Food' },
      total_words: 10,
    })
    const w = await render()

    expect(text(w, 'course-title')).toBe('English A1')
    expect(text(w, 'course-progress')).toBe('Изучено 120 из 900')
    expect(text(w, 'next-lesson')).toBe('Урок 4 · Food')

    await w.get('[data-testid=next-lesson-btn]').trigger('click')
    await flushPromises()

    expect(api.training.open).toHaveBeenCalledWith({ mode: 'lesson', lesson_id: 41, source: 'self' })
    expect(w.router.currentRoute.value.params.sessionId).toBe('55')
  })

  it('says so when every word of the course has been shown, and when the next lesson has no title', async () => {
    const course = today().active_course!
    const done = await render(today({ active_course: { ...course, next_lesson: null } }))
    expect(text(done, 'course-completed')).toBe('Все слова курса уже показаны')
    expect(done.find('[data-testid=next-lesson-btn]').exists()).toBe(false)

    const untitled = await render(today({ active_course: { ...course, next_lesson: { id: 1, stage: null, lesson: 2, title: null } } }))
    expect(text(untitled, 'next-lesson')).toBe('Урок 2')
  })

  it('asks to choose a course when there is none, and always links to the catalog', async () => {
    const w = await render(today({ active_course: null }))

    expect(text(w, 'no-course')).toBe('Выберите курс, чтобы учить новые слова')
    expect(w.find('[data-testid=next-lesson-btn]').exists()).toBe(false)
    const link = w.get('[data-testid=catalog-link]')
    expect(link.attributes('href')).toBe('/courses')
    expect(link.text()).toBe('Каталог курсов')
  })

  it('links to the learner decks', async () => {
    const link = (await render()).get('[data-testid=decks-link]')

    expect(link.attributes('href')).toBe('/decks')
    expect(link.text()).toBe('Мои колоды')
  })

  it('links to the settings', async () => {
    const link = (await render()).get('[data-testid=settings-link]')

    expect(link.attributes('href')).toBe('/settings')
    expect(link.text()).toContain('Настройки')
  })

  it('links to "Invite a friend"', async () => {
    const link = (await render()).get('[data-testid=referrals-link]')

    expect(link.attributes('href')).toBe('/referrals')
    expect(link.text()).toContain('Пригласить друга')
  })

  it('badges the plan', async () => {
    expect(text(await render(), 'plan-badge')).toBe('Free')
    expect(text(await render(today({ entitlement: { plan: 'pro', source: 'subscription', until: null } })), 'plan-badge')).toBe('Pro')
  })

  it('hints at finishing the onboarding in the bot', async () => {
    expect((await render()).find('[data-testid=onboarding-hint]').exists()).toBe(false)
    expect(text(await render(today({ onboarding_completed: false })), 'onboarding-hint')).toBe('Завершите настройку: отправьте боту /start')
  })

  it('shows an error with a retry that reloads', async () => {
    vi.mocked(api.today.get).mockRejectedValueOnce(new ApiClientError('server', 500, 'server_error', 'boom'))
    const w = mount(TodayView, { global: { plugins: [makeRouter()] } })
    await flushPromises()

    expect(w.get('[data-testid=error]').text()).toContain('Сервер временно недоступен. Попробуйте позже.')

    vi.mocked(api.today.get).mockResolvedValue(today())
    await w.get('[data-testid=retry-btn]').trigger('click')
    await flushPromises()
    expect(w.find('[data-testid=error]').exists()).toBe(false)
    expect(w.get('[data-testid=goal-text]').text()).toBe('8 из 20')
  })

  it('explains a failed authentication without calling the API', async () => {
    state.ensure.mockRejectedValue(new Error('opened_outside_telegram'))
    const w = mount(TodayView, { global: { plugins: [makeRouter()] } })
    await flushPromises()

    expect(api.today.get).not.toHaveBeenCalled()
    expect(w.get('[data-testid=error]').text()).toContain('Откройте приложение через Telegram.')
  })

  it('renders in Uzbek', async () => {
    setLocale('uz')
    const w = await render()

    expect(text(w, 'goal-text')).toBe('20 tadan 8 tasi')
    expect(text(w, 'review-btn')).toBe('Takrorlash')
    expect(text(w, 'streak-current')).toBe('5 kun')
    expect(text(w, 'due-count')).toBe("Takrorlash uchun 23 ta so'z")
    expect(text(w, 'catalog-link')).toBe('Kurslar katalogi')
  })
})
